<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['agreed_price' => 'decimal:2', 'deposit_amount' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function phone(): BelongsTo
    {
        return $this->belongsTo(SerializedUnit::class, 'serialized_unit_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
