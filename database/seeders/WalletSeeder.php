<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Wallet;

class WalletSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create some sample wallets
        Wallet::create([
            'owner_id' => 1,
            'currency' => 'USD',
            'balance' => 1000.00,
        ]);

        Wallet::create([
            'owner_id' => 2,
            'currency' => 'USD',
            'balance' => 500.00,
        ]);

        Wallet::create([
            'owner_id' => 3,
            'currency' => 'EUR',
            'balance' => 750.00,
        ]);
    }
}
