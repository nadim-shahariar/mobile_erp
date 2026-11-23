<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Custody;
use App\Models\Sale;
use App\Models\SerializedUnit;
use App\Models\User;

class SerializedUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view') && $user->accessibleUnitIds() !== [];
    }

    public function view(User $user, SerializedUnit $unit): bool
    {
        return $user->can('inventory.view') && $this->canSeeUnit($user, $unit);
    }

    public function timeline(User $user, SerializedUnit $unit): bool
    {
        return $user->can('inventory.view') && $this->canSeeUnit($user, $unit);
    }

    public function viewCost(User $user, ?SerializedUnit $unit = null): bool
    {
        return $user->can('inventory.cost.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.create') && $user->accessibleUnitIds() !== [];
    }

    public function update(User $user, SerializedUnit $unit): bool
    {
        return $user->can('inventory.update') && $this->canSeeUnit($user, $unit);
    }

    public function adjust(User $user, SerializedUnit $unit): bool
    {
        return $user->can('inventory.adjust') && $this->canSeeUnit($user, $unit);
    }

    public function import(User $user): bool
    {
        return $user->can('inventory.import') && $user->accessibleUnitIds() !== [];
    }

    public function overrideImei(User $user): bool
    {
        return $user->can('inventory.imei.override');
    }

    public function delete(User $user, SerializedUnit $unit): bool
    {
        // Handset identities and their history are never deleted.
        return false;
    }

    /**
     * Hands in transit or with a customer have no current unit; scope those via
     * the unit recorded on their most recent event.
     */
    private function canSeeUnit(User $user, SerializedUnit $unit): bool
    {
        if ($unit->current_unit_id !== null) {
            return $user->hasUnitAccess($unit->current_unit_id);
        }

        if ($unit->current_custody === Custody::AtExternalParty && $unit->activeCycle && $user->hasUnitAccess($unit->activeCycle->initial_unit_id)) {
            return true;
        }
        $lastEvent = $unit->lastEvent;
        if ($lastEvent?->reference instanceof Sale && $user->hasUnitAccess($lastEvent->reference->business_unit_id)) {
            return true;
        }

        if ($lastEvent?->from_unit_id !== null && $user->hasUnitAccess($lastEvent->from_unit_id)) {
            return true;
        }

        return $lastEvent?->to_unit_id !== null && $user->hasUnitAccess($lastEvent->to_unit_id);
    }
}
