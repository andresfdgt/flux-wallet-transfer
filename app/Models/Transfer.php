<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transfer extends Model
{
    protected $fillable = ['idempotency_key', 'source_wallet_id', 'destination_wallet_id', 'amount', 'currency', 'description', 'status'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function sourceWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'source_wallet_id');
    }

    public function destinationWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'destination_wallet_id');
    }

    public function ledgerTransaction(): HasOne
    {
        return $this->hasOne(LedgerTransaction::class);
    }
}
