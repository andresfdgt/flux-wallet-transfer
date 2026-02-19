<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Transfer;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;

class TransferService
{
    public function create(array $data): Transfer
    {
        $payload = $this->normalizePayload($data);

        $existingTransfer = Transfer::where('idempotency_key', $payload['idempotency_key'])->first();
        if ($existingTransfer) {
            $this->validateIdempotencyPayload($existingTransfer, $payload);
            return $existingTransfer;
        }

        try {
            return DB::transaction(function () use ($payload) {
                [$sourceWallet, $destinationWallet] = $this->lockWallets($payload['source_wallet_id'], $payload['destination_wallet_id']);

                $this->validateBusinessRules($sourceWallet, $destinationWallet, $payload);

                $transfer = $this->createTransfer($payload);

                $this->createLedgerTransaction($transfer, $sourceWallet, $destinationWallet);

                $this->updateWalletBalances($sourceWallet, $destinationWallet, $payload['amount']);

                $this->markTransferCompleted($transfer);

                return $transfer->fresh();
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateEntry($e)) {
                $existingTransfer = Transfer::where('idempotency_key', $payload['idempotency_key'])->first();

                if ($existingTransfer) {
                    $this->validateIdempotencyPayload($existingTransfer, $payload);
                    return $existingTransfer;
                }
            }

            throw $e;
        }
    }

    private function normalizePayload(array $payload): array
    {
        $currency = strtoupper($payload['currency'] ?? '');

        return [
            'idempotency_key' => $payload['idempotency_key'],
            'source_wallet_id' => (int) $payload['source_wallet_id'],
            'destination_wallet_id' => (int) $payload['destination_wallet_id'],
            'amount' => (string) $payload['amount'],
            'currency' => $currency,
            'description' => $payload['description'] ?? null,
        ];
    }

    private function validateIdempotencyPayload(Transfer $existingTransfer, array $newPayload): void
    {
        $same =
            (int) $existingTransfer->source_wallet_id === (int) $newPayload['source_wallet_id'] &&
            (int) $existingTransfer->destination_wallet_id === (int) $newPayload['destination_wallet_id'] &&
            bccomp((string) $existingTransfer->amount, (string) $newPayload['amount'], 2) === 0 &&
            strtoupper((string) $existingTransfer->currency) === strtoupper((string) $newPayload['currency']) &&
            (($existingTransfer->description ?? null) === ($newPayload['description'] ?? null));

        if (!$same) {
            throw new IdempotencyConflictException('Idempotency key already used with different payload');
        }
    }

    private function lockWallets(int $sourceWalletId, int $destinationWalletId): array
    {
        // Lock wallets in consistent order to prevent deadlocks
        $walletIds = [$sourceWalletId, $destinationWalletId];
        sort($walletIds);

        $wallet1 = Wallet::where('id', $walletIds[0])->lockForUpdate()->firstOrFail();
        $wallet2 = Wallet::where('id', $walletIds[1])->lockForUpdate()->firstOrFail();

        $walletSource = $wallet1->id === $sourceWalletId ? $wallet1 : $wallet2;
        $walletDestination = $wallet1->id === $destinationWalletId ? $wallet1 : $wallet2;

        return [$walletSource, $walletDestination];
    }

    private function validateBusinessRules(Wallet $sourceWallet, Wallet $destinationWallet, array $payload): void
    {
        if ($sourceWallet->id === $destinationWallet->id) {
            throw new InvalidArgumentException('Source and destination wallets must be different');
        }

        if ($sourceWallet->currency !== $payload['currency'] || $destinationWallet->currency !== $payload['currency']) {
            throw new InvalidArgumentException('currency must match source and destination wallets');
        }

        if (bccomp($payload['amount'], '0', 2) !== 1) {
            throw new InvalidArgumentException('amount must be greater than 0');
        }

        if (bccomp((string) $sourceWallet->balance, (string) $payload['amount'], 2) === -1) {
            throw new InsufficientFundsException();
        }
    }

    private function createTransfer($payload): Transfer
    {
        return Transfer::create([
            'idempotency_key' => $payload['idempotency_key'],
            'source_wallet_id' => $payload['source_wallet_id'],
            'destination_wallet_id' => $payload['destination_wallet_id'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'description' => $payload['description'] ?? null,
            'status' => 'PENDING',
        ]);
    }

    private function createLedgerTransaction(Transfer $transfer, Wallet $sourceWallet, Wallet $destinationWallet): LedgerTransaction
    {
        $ledgerTransaction = LedgerTransaction::create([
            'transfer_id' => $transfer->id,
            'currency' => $transfer->currency,
            'description' => $transfer->description,
        ]);

        LedgerEntry::create([
            'ledger_transaction_id' => $ledgerTransaction->id,
            'wallet_id' => $sourceWallet->id,
            'type' => 'DEBIT',
            'amount' => $transfer->amount,
            'currency' => $transfer->currency,
        ]);

        LedgerEntry::create([
            'ledger_transaction_id' => $ledgerTransaction->id,
            'wallet_id' => $destinationWallet->id,
            'type' => 'CREDIT',
            'amount' => $transfer->amount,
            'currency' => $transfer->currency,
        ]);

        return $ledgerTransaction;
    }

    private function updateWalletBalances(Wallet $sourceWallet, Wallet $destinationWallet, string $amount): void
    {
        // Update balances
        $sourceWallet->balance = bcsub((string) $sourceWallet->balance, $amount, 2);
        $destinationWallet->balance = bcadd((string) $destinationWallet->balance, $amount, 2);

        $sourceWallet->save();
        $destinationWallet->save();
    }

    private function markTransferCompleted(Transfer $transfer): void
    {
        $transfer->status = 'COMPLETED';
        $transfer->save();
    }

    private function isDuplicateEntry(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
