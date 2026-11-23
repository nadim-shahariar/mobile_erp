<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseLine extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'product_variant_id',
        'line_type',
        'imei_1',
        'imei_2',
        'buying_party_name',
        'expected_selling_price',
        'status_note',
        'received_at',
        'serial_payload',
        'quantity',
        'unit_cost',
        'line_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'line_type' => LineType::class,
            'serial_payload' => 'array',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'expected_selling_price' => 'decimal:2',
            'received_at' => 'date',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
