<?php

namespace Tests\Unit;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Transfer;
use App\Models\Wallet;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransferService $transferService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transferService = new TransferService();
    }

    private function makeWallet(string $currency, string $balance): Wallet
    {
        return Wallet::create([
            'owner_id' => null,
            'currency' => $currency,
            'balance' => $balance,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'idempotency_key'       => uniqid(),
            'source_wallet_id'      => 1,
            'destination_wallet_id' => 2,
            'amount'                => '100.00',
            'currency'              => 'USD',
            'description'           => 'Test transfer',
        ], $overrides);
    }

    public function test_tranfer_debits_source_and_credits_destination(): void
    {
        $sourceWallet = $this->makeWallet('USD', '1000.00');
        $destinationWallet = $this->makeWallet('USD', '500.00');

        $this->transferService->create($this->payload([
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
            'amount' => '200.00',
            'currency' => 'USD',
        ]));

        $this->assertEquals('800.00', $sourceWallet->fresh()->balance);
        $this->assertEquals('700.00', $destinationWallet->fresh()->balance);
    }

    public function test_transfer_returns_completed_status(): void
    {
        $sourceWallet = $this->makeWallet('USD', '1000.00');
        $destinationWallet = $this->makeWallet('USD', '500.00');

        $transfer = $this->transferService->create($this->payload([
            'source_wallet_id'      => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
            'currency'              => 'USD',
        ]));

        $this->assertEquals('COMPLETED', $transfer->status);
    }

    public function test_idempotency_returns_same_transfer(): void
    {
        $sourceWallet = $this->makeWallet('USD', '1000.00');
        $destinationWallet = $this->makeWallet('USD', '500.00');

        $payload = $this->payload([
            'idempotency_key' => 'test-key-idempotent',
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
            'amount' => '100.00',
            'currency' => 'USD',
        ]);

        $transfer1 = $this->transferService->create($payload);
        $transfer2 = $this->transferService->create($payload);

        $this->assertEquals($transfer1->id, $transfer2->id);
        $this->assertEquals(1, Transfer::count());
    }

    public function test_idempotency_conflict_throws_exception(): void
    {
        $sourceWallet = $this->makeWallet('USD', '1000.00');
        $destinationWallet = $this->makeWallet('USD', '500.00');

        $key = 'test-key-conflict';

        $this->transferService->create($this->payload([
            'idempotency_key' => $key,
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
            'amount' => '100.00',
            'currency' => 'USD',
        ]));

        $this->expectException(IdempotencyConflictException::class);

        $this->transferService->create($this->payload([
            'idempotency_key' => $key,
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
            'amount' => '999.00',
            'currency' => 'USD',
        ]));
    }

    public function test_insufficient_funds_throws_exception(): void
    {
        $sourceWallet = $this->makeWallet('USD', '50.00');
        $destinationWallet = $this->makeWallet('USD', '500.00');

        $this->expectException(InsufficientFundsException::class);

        $this->transferService->create($this->payload([
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
            'amount' => '100.00',
            'currency' => 'USD',
        ]));
    }

    public function test_currency_mismatch_throws_exception(): void
    {
        $sourceWallet = $this->makeWallet('USD', '1000.00');
        $destinationWallet = $this->makeWallet('EUR', '500.00');

        $this->expectException(InvalidArgumentException::class);

        $this->transferService->create($this->payload([
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
            'amount' => '100.00',
            'currency' => 'USD',
        ]));
    }

    public function test_creates_ledger_transaction_and_two_entries(): void
    {
        $sourceWallet = $this->makeWallet('USD', '1000.00');
        $destinationWallet = $this->makeWallet('USD', '500.00');

        $transfer = $this->transferService->create($this->payload([
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
            'amount' => '200.00',
            'currency' => 'USD',
        ]));

        $ledgerTransaction = LedgerTransaction::where('transfer_id', $transfer->id)->first();
        $this->assertNotNull($ledgerTransaction);

        $this->assertEquals(2, LedgerEntry::where('ledger_transaction_id', $ledgerTransaction->id)->count());

        $this->assertTrue(
            LedgerEntry::where('ledger_transaction_id', $ledgerTransaction->id)
                ->where('wallet_id', $sourceWallet->id)
                ->where('type', 'DEBIT')
                ->where('amount', '200.00')
                ->exists()
        );

        $this->assertTrue(
            LedgerEntry::where('ledger_transaction_id', $ledgerTransaction->id)
                ->where('wallet_id', $destinationWallet->id)
                ->where('type', 'CREDIT')
                ->where('amount', '200.00')
                ->exists()
        );
    }
}
