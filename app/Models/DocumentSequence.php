<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSequence extends Model
{
    protected $fillable = [
        'key',
        'scope_key',
        'business_unit_id',
        'period',
        'prefix',
        'padding',
        'next_number',
    ];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'padding' => 'integer',
        ];
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }
}
