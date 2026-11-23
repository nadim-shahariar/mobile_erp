<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LineType;
use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    protected $fillable = [
        'document_no',
        'supplier_id',
        'buying_party_name',
        'business_unit_id',
        'purchase_date',
        'status',
        'subtotal',
        'discount_total',
        'other_charges',
        'total',
        'paid_amount',
        'due_amount',
        'payment_method',
        'reference',
        'notes',
        'received_at',
        'received_by',
        'created_by',
        'idempotency_key',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'purchase_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return HasMany<PurchaseLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @param Builder<Purchase> $query */
    public function scopeForUnit(Builder $query, int|array $unitIds): void
    {
        $query->whereIn('business_unit_id', (array) $unitIds);
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseStatus::Draft;
    }

    public function isReceived(): bool
    {
        return $this->status === PurchaseStatus::Received;
    }

    public function phoneLineCount(): int
    {
        return $this->lines->where('line_type', LineType::Phone)->count();
    }
}
