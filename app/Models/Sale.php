<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SaleStatus;
use App\Enums\SaleType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    protected $fillable = [
        'invoice_no',
        'business_unit_id',
        'customer_id',
        'type',
        'status',
        'subtotal',
        'line_discount_total',
        'invoice_discount_total',
        'discount_total',
        'tax_total',
        'grand_total',
        'emi_rate', 'emi_charge',
        'deposit_credit',
        'trade_in_credit',
        'paid_total',
        'due_total',
        'notes',
        'posted_at',
        'created_by',
        'journal_entry_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'type' => SaleType::class,
            'status' => SaleStatus::class,
            'subtotal' => 'decimal:2',
            'line_discount_total' => 'decimal:2',
            'invoice_discount_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'emi_rate' => 'decimal:2', 'emi_charge' => 'decimal:2',
            'deposit_credit' => 'decimal:2',
            'trade_in_credit' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'due_total' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** @return HasMany<SalePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(SalePaymentSchedule::class);
    }

    public function externalDelivery(): HasOne
    {
        return $this->hasOne(ExternalDelivery::class);
    }

    public function exchange(): HasOne
    {
        return $this->hasOne(Exchange::class);
    }

    public function isPosted(): bool
    {
        return $this->status === SaleStatus::Posted;
    }

    public function due(): Money
    {
        return Money::of((string) $this->due_total);
    }
}
