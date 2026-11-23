<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\UnitAccessDeniedException;
use App\Models\BusinessUnit;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * Resolves the active business unit for the current session and enforces unit
 * scoping server-side. Every inventory/finance query must be filtered with an
 * id from this class; never trust a unit id posted by a hidden form field.
 */
final class UnitContext
{
    public const SESSION_KEY = 'erp.active_unit_id';

    public function __construct(private readonly Request $request) {}

    /** @return Collection<int, BusinessUnit> */
    public function availableUnits(User $user): Collection
    {
        $ids = $user->accessibleUnitIds();

        return BusinessUnit::query()
            ->whereIn('id', $ids)
            ->ordered()
            ->get();
    }

    public function current(User $user): ?BusinessUnit
    {
        $units = $this->availableUnits($user);
        $configuredDefault = (int) Setting::get('units.default_id', 0);
        // A changed shop default takes effect even in existing signed-in sessions.
        if ($configuredDefault > 0 && (int) Session::get('erp.default_unit_seen', 0) !== $configuredDefault) {
            $preferred = $units->first(fn (BusinessUnit $unit) => $unit->id === $configuredDefault && $unit->is_active);
            Session::put('erp.default_unit_seen', $configuredDefault);
            if ($preferred) {
                Session::put(self::SESSION_KEY, $preferred->id);
            } else {
                Session::forget(self::SESSION_KEY);
            }
        }
        $sessionUnitId = (int) Session::get(self::SESSION_KEY, 0);

        if ($sessionUnitId > 0) {
            $selected = $units->first(fn (BusinessUnit $unit) => $unit->id === $sessionUnitId && $unit->is_active);

            if ($selected) {
                return $selected;
            }
        }

        return $units->first(fn (BusinessUnit $unit) => $unit->id === $configuredDefault && $unit->is_active)
            ?? $units->first(fn (BusinessUnit $unit) => $unit->code === 'U2' && $unit->is_active)
            ?? $units->firstWhere('is_active', true);
    }

    public function set(User $user, BusinessUnit $unit): void
    {
        if (! $unit->is_active || ! $user->hasUnitAccess($unit->id)) {
            throw UnitAccessDeniedException::forUnit($unit->id);
        }

        Session::put(self::SESSION_KEY, $unit->id);
        Session::put('erp.default_unit_seen', (int) Setting::get('units.default_id', 0));
    }

    public function requireCurrent(User $user): BusinessUnit
    {
        $unit = $this->current($user);

        if ($unit === null) {
            throw UnitAccessDeniedException::forUnit(null);
        }

        return $unit;
    }

    public function authorize(User $user, int|string|null $unitId): int
    {
        if ($unitId === null || ! $user->hasUnitAccess($unitId)) {
            throw UnitAccessDeniedException::forUnit($unitId);
        }

        return (int) $unitId;
    }

    /** @return list<int> */
    public function authorizedIds(User $user): array
    {
        return $user->accessibleUnitIds();
    }
}
