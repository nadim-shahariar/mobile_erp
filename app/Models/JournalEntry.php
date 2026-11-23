<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    protected $fillable = [
        'entry_no',
        'entry_date',
        'source_type',
        'source_id',
        'source_key',
        'memo',
        'posted_at',
        'created_by',
        'reversal_of_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function totalDebit(): string
    {
        return (string) $this->lines()->sum('debit');
    }

    public function totalCredit(): string
    {
        return (string) $this->lines()->sum('credit');
    }
}
