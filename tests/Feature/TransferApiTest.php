<?php

namespace Tests\Feature;

use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_successful_transfer(): void
    {
        $sourceWallet = $this->makeWallet('USD', '1000.00');
        $destinationWallet = $this->makeWallet('USD', '500.00');

        $response = $this->postJson('/api/transfers', $this->payload([
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
        ]));

        $response->assertStatus(201)->assertJsonStructure([
            'message',
            'data' => ['id', 'status', 'amount', 'currency'],
        ])->assertJsonPath('data.status', 'COMPLETED');
    }

    public function test_idempotent_request_returns_same_transfer(): void
    {
        $sourceWallet = $this->makeWallet('USD', '1000.00');
        $destinationWallet = $this->makeWallet('USD', '500.00');

        $payload = $this->payload([
            'idempotency_key' => uniqid(),
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
        ]);

        $response1 = $this->postJson('/api/transfers', $payload);
        $response2 = $this->postJson('/api/transfers', $payload);

        $response1->assertStatus(201);
        $response2->assertStatus(201);

        $this->assertEquals($response1->json('data.id'), $response2->json('data.id'));
    }

    public function test_insufficient_funds_returns_409(): void
    {
        $sourceWallet = $this->makeWallet('USD', '50.00');
        $destinationWallet = $this->makeWallet('USD', '500.00');

        $response = $this->postJson('/api/transfers', $this->payload([
            'source_wallet_id' => $sourceWallet->id,
            'destination_wallet_id' => $destinationWallet->id,
            'amount' => '100.00',
        ]));

        $response->assertStatus(409)->assertJsonPath('message', 'Insufficient funds');
    }

    public function test_same_wallet_returns_422(): void
    {
        $wallet = $this->makeWallet('USD', '1000.00');

        $response = $this->postJson('/api/transfers', $this->payload([
            'source_wallet_id' => $wallet->id,
            'destination_wallet_id' => $wallet->id,
        ]));

        $response->assertStatus(422)->assertJsonPath('message', 'Source and destination wallets must be different.');
    }

    public function test_missing_required_fields_returns_422(): void
    {
        $response = $this->postJson('/api/transfers', []);

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors']);
    }

    public function test_nonexistent_wallet_returns_422(): void
    {
        $wallet = $this->makeWallet('USD', '1000.00');

        $response = $this->postJson('/api/transfers', $this->payload([
            'source_wallet_id' => $wallet->id,
            'destination_wallet_id' => 998,
        ]));

        $response->assertStatus(422)->assertJsonPath('errors.destination_wallet_id.0', 'Destination wallet not found');
    }
}
