<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\SaleStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'type',
        'name',
        'business_name',
        'phone',
        'email',
        'address',
        'credit_limit',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasMany<CustomerPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function displayName(): string
    {
        return $this->business_name ?: $this->name;
    }

    public function outstandingBalance(): Money
    {
        return Money::of((string) $this->sales()
            ->where('status', SaleStatus::Posted->value)
            ->sum('due_total'));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
