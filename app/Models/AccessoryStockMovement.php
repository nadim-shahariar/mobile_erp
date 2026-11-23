<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessoryMovementReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AccessoryStockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'business_unit_id',
        'supplier_id',
        'product_variant_id',
        'quantity_delta',
        'reason',
        'unit_cost',
        'balance_after',
        'source_type',
        'source_id',
        'actor_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'reason' => AccessoryMovementReason::class,
            'quantity_delta' => 'integer',
            'balance_after' => 'integer',
            'unit_cost' => 'decimal:4',
            'created_at' => 'datetime',
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

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
