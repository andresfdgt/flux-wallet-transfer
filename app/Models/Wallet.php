<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = ['owner_id', 'currency', 'balance'];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'source_wallet_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'destination_wallet_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
