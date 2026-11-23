<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'line_type',
        'product_variant_id',
        'serialized_unit_id',
        'inventory_cycle_id',
        'description',
        'details',
        'identifier',
        'quantity',
        'unit_price',
        'discount',
        'line_total',
        'unit_cost',
        'cost_total',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'line_type' => LineType::class,
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'cost_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<SerializedUnit, $this> */
    public function serializedUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedUnit::class);
    }

    /** @return BelongsTo<InventoryCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(InventoryCycle::class, 'inventory_cycle_id');
    }
}
