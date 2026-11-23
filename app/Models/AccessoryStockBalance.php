<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessoryStockBalance extends Model
{
    protected $fillable = [
        'business_unit_id',
        'product_variant_id',
        'qty_on_hand',
        'qty_reserved',
        'average_cost',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'integer',
            'qty_reserved' => 'integer',
            'average_cost' => 'decimal:4',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function availableQty(): int
    {
        return $this->qty_on_hand - $this->qty_reserved;
    }
}
