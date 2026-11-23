<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'storage',
        'ram',
        'color',
        'country',
        'condition_grade',
        'attributes',
        'default_purchase_price',
        'default_selling_price',
        'reorder_point',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'default_purchase_price' => 'decimal:2',
            'default_selling_price' => 'decimal:2',
            'reorder_point' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<SerializedUnit, $this> */
    public function serializedUnits(): HasMany
    {
        return $this->hasMany(SerializedUnit::class);
    }

    /** @return HasMany<AccessoryStockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(AccessoryStockBalance::class);
    }

    public function displayName(): string
    {
        $parts = [
            $this->product?->brand?->name,
            $this->product?->name,
            $this->storage,
            $this->ram,
            $this->color,
            $this->country,
        ];

        return trim(implode(' ', array_filter($parts, fn ($part) => filled($part))));
    }
}
