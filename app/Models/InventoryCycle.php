<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AcquisitionSource;
use App\Enums\CycleCloseReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryCycle extends Model
{
    protected $fillable = [
        'serialized_unit_id',
        'cycle_no',
        'source_type',
        'source_ref_type',
        'source_ref_id',
        'source_label',
        'buying_party_name',
        'acquisition_value',
        'allocated_direct_cost',
        'effective_cost_basis',
        'initial_unit_id',
        'acquired_at',
        'acquired_by',
        'closed_at',
        'closing_type',
        'closed_by',
        'notes',
        'active_cycle_guard',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => AcquisitionSource::class,
            'closing_type' => CycleCloseReason::class,
            'acquisition_value' => 'decimal:2',
            'allocated_direct_cost' => 'decimal:2',
            'effective_cost_basis' => 'decimal:2',
            'acquired_at' => 'datetime',
            'closed_at' => 'datetime',
            'cycle_no' => 'integer',
            'active_cycle_guard' => 'integer',
        ];
    }

    /** @return BelongsTo<SerializedUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(SerializedUnit::class, 'serialized_unit_id');
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function initialUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'initial_unit_id');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_ref_type', 'source_ref_id');
    }

    /** @param Builder<InventoryCycle> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
