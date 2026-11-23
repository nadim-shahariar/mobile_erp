<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Custody;
use App\Enums\InventoryEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryEvent extends Model
{
    /** Append-only ledger: never updated. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'serialized_unit_id',
        'inventory_cycle_id',
        'event_type',
        'from_custody',
        'to_custody',
        'from_unit_id',
        'to_unit_id',
        'actor_id',
        'happened_at',
        'reference_type',
        'reference_id',
        'note',
        'idempotency_key',
        'reversal_of_event_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => InventoryEventType::class,
            'from_custody' => Custody::class,
            'to_custody' => Custody::class,
            'happened_at' => 'datetime',
            'created_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<SerializedUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(SerializedUnit::class, 'serialized_unit_id');
    }

    /** @return BelongsTo<InventoryCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(InventoryCycle::class, 'inventory_cycle_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'from_unit_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'to_unit_id');
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
