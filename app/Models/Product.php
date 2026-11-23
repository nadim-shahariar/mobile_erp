<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_type',
        'brand_id',
        'category_id',
        'name',
        'model',
        'sku',
        'specification',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'specification' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @param Builder<Product> $query */
    public function scopePhones(Builder $query): void
    {
        $query->where('product_type', ProductType::Phone->value);
    }

    /** @param Builder<Product> $query */
    public function scopeAccessories(Builder $query): void
    {
        $query->where('product_type', ProductType::Accessory->value);
    }

    public function displayName(): string
    {
        return trim(($this->brand?->name ?? '').' '.$this->name);
    }
}
