<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    public const CLASS_ASSET = 'ASSET';

    public const CLASS_LIABILITY = 'LIABILITY';

    public const CLASS_EQUITY = 'EQUITY';

    public const CLASS_INCOME = 'INCOME';

    public const CLASS_EXPENSE = 'EXPENSE';

    public const TYPE_CASH = 'CASH';

    public const TYPE_BANK = 'BANK';

    public const TYPE_MFS = 'MFS';

    public const TYPE_CARD = 'CARD';

    public const TYPE_OTHER = 'OTHER';

    public const TYPE_RECEIVABLE = 'RECEIVABLE';

    public const TYPE_PAYABLE = 'PAYABLE';

    public const TYPE_INVENTORY_HANDSET = 'INVENTORY_HANDSET';

    public const TYPE_INVENTORY_ACCESSORY = 'INVENTORY_ACCESSORY';

    public const TYPE_REVENUE = 'REVENUE';

    public const TYPE_COGS_HANDSET = 'COGS_HANDSET';

    public const TYPE_COGS_ACCESSORY = 'COGS_ACCESSORY';

    protected $fillable = [
        'code',
        'name',
        'class',
        'type',
        'business_unit_id',
        'is_payment_account',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_payment_account' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return HasMany<JournalLine, $this> */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function debitTotal(): string
    {
        return (string) $this->journalLines()->sum('debit');
    }

    public function creditTotal(): string
    {
        return (string) $this->journalLines()->sum('credit');
    }
}
