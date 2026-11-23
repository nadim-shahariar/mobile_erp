<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockTransferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $fillable = [
        'transfer_no',
        'return_of_transfer_id',
        'from_unit_id',
        'to_unit_id',
        'status',
        'notes',
        'requested_by',
        'requested_at',
        'dispatched_by',
        'dispatched_at',
        'received_by',
        'received_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'requested_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function originalTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'return_of_transfer_id');
    }

    /** @return HasMany<StockTransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
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

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @param Builder<StockTransfer> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [StockTransferStatus::Requested->value, StockTransferStatus::Dispatched->value]);
    }

    /** @param Builder<StockTransfer> $query */
    public function scopeVisibleTo(Builder $query, array $unitIds): void
    {
        $query->where(function (Builder $q) use ($unitIds) {
            $q->whereIn('from_unit_id', $unitIds)->orWhereIn('to_unit_id', $unitIds);
        });
    }
}
