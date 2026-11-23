<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'timezone',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_business_units')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /** @return HasMany<SerializedUnit, $this> */
    public function serializedUnits(): HasMany
    {
        return $this->hasMany(SerializedUnit::class, 'current_unit_id');
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

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
