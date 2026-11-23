<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** Defaults hydrated on every instance so DB defaults never read as null. */
    protected $attributes = [
        'is_active' => true,
        'locale' => 'en',
        'failed_login_attempts' => 0,
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_active',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
        ];
    }

    /** @return BelongsToMany<BusinessUnit, $this> */
    public function businessUnits(): BelongsToMany
    {
        return $this->belongsToMany(BusinessUnit::class, 'user_business_units')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /** @return HasOne<StaffProfile, $this> */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    /**
     * Unit ids this user may access, resolved from the pivot. Owners are not
     * special-cased here: even an owner must be assigned units explicitly,
     * which keeps every query scoped and auditable.
     *
     * @return list<int>
     */
    public function accessibleUnitIds(): array
    {
        return $this->relationLoaded('businessUnits')
            ? $this->businessUnits->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->businessUnits()->pluck('business_units.id')->map(fn ($id) => (int) $id)->all();
    }

    public function hasUnitAccess(int|string|null $unitId): bool
    {
        if ($unitId === null) {
            return false;
        }

        return in_array((int) $unitId, $this->accessibleUnitIds(), true);
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    public function defaultBusinessUnit(): ?BusinessUnit
    {
        $units = $this->relationLoaded('businessUnits')
            ? $this->businessUnits
            : $this->businessUnits()->get();

        return $units->firstWhere('pivot.is_default', true) ?? $units->first();
    }

    public function isActive(): bool
    {
        return $this->is_active && ($this->locked_until === null || $this->locked_until->isPast());
    }
}
