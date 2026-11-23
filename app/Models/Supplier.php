<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    public const TYPE_PERSON = 'PERSON';

    public const TYPE_BUSINESS = 'BUSINESS';

    protected $fillable = [
        'type',
        'name',
        'phone',
        'address',
        'contact_person',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
