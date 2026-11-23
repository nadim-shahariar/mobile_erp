<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferLine extends Model
{
    protected $fillable = [
        'stock_transfer_id',
        'line_type',
        'serialized_unit_id',
        'product_variant_id',
        'quantity',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'line_type' => LineType::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<StockTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /** @return BelongsTo<SerializedUnit, $this> */
    public function serializedUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedUnit::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
