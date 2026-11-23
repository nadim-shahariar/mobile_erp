<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Exchange extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['buying_price' => 'decimal:2', 'settlement_amount' => 'decimal:2', 'condition_snapshot' => 'array', 'settlement_payments' => 'array'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
