<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Custody;
use App\Enums\ImeiStatus;
use App\Enums\Sellability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SerializedUnit extends Model
{
    protected $fillable = [
        'product_variant_id',
        'primary_imei',
        'secondary_imei',
        'imei_status',
        'imei_flag_reason',
        'battery_health',
        'cycle_count',
        'condition_grade',
        'box_kit',
        'expected_selling_price',
        'status_note',
        'current_custody',
        'current_unit_id',
        'sellability_status',
        'last_event_id',
        'version',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'imei_status' => ImeiStatus::class,
            'current_custody' => Custody::class,
            'sellability_status' => Sellability::class,
            'battery_health' => 'integer',
            'cycle_count' => 'integer',
            'version' => 'integer',
            'expected_selling_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function currentUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'current_unit_id');
    }

    /** @return HasMany<InventoryCycle, $this> */
    public function cycles(): HasMany
    {
        return $this->hasMany(InventoryCycle::class);
    }

    /** @return HasOne<InventoryCycle, $this> */
    public function activeCycle(): HasOne
    {
        return $this->hasOne(InventoryCycle::class)->whereNull('closed_at');
    }

    /** @return HasMany<InventoryEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(InventoryEvent::class);
    }

    /** @return HasMany<SerializedUnitAccessory, $this> */
    public function accessories(): HasMany
    {
        return $this->hasMany(SerializedUnitAccessory::class);
    }

    /** @return HasMany<SerializedIdentifier, $this> */
    public function identifiers(): HasMany
    {
        return $this->hasMany(SerializedIdentifier::class);
    }

    /** @return BelongsTo<InventoryEvent, $this> */
    public function lastEvent(): BelongsTo
    {
        return $this->belongsTo(InventoryEvent::class, 'last_event_id');
    }

    /** @param Builder<SerializedUnit> $query */
    public function scopeSellable(Builder $query): void
    {
        $query->where('current_custody', Custody::InUnit->value)
            ->where('sellability_status', Sellability::Available->value);
    }

    /** @param Builder<SerializedUnit> $query */
    public function scopeInUnit(Builder $query, int $unitId): void
    {
        $query->where('current_unit_id', $unitId)
            ->where('current_custody', Custody::InUnit->value);
    }

    /** @param Builder<SerializedUnit> $query */
    public function scopeOwned(Builder $query): void
    {
        $query->whereIn('current_custody', [
            Custody::InUnit->value,
            Custody::InTransit->value,
            Custody::AtRepairVendor->value,
            Custody::ReturnPendingInspection->value,
        ]);
    }

    public function isSellable(): bool
    {
        return $this->current_custody === Custody::InUnit
            && $this->sellability_status === Sellability::Available
            && $this->activeCycle()->exists();
    }

    public function displayName(): string
    {
        $variant = $this->relationLoaded('variant') ? $this->variant : $this->variant()->with('product.brand')->first();

        return $variant?->displayName() ?? 'Unknown handset';
    }

    public function primaryIdentifier(): ?string
    {
        return $this->primary_imei ?? $this->secondary_imei;
    }

    public function batteryHealthLabel(): string
    {
        return $this->battery_health === null ? 'Unknown' : $this->battery_health.'%';
    }

    public function cycleCountLabel(): string
    {
        return $this->cycle_count === null ? 'Unknown' : (string) $this->cycle_count;
    }
}
