<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $fillable = [
        'customer_payment_id',
        'sale_id',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<CustomerPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
