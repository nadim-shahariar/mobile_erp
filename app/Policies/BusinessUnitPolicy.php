<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BusinessUnit;
use App\Models\User;

class BusinessUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('unit.view');
    }

    public function view(User $user, BusinessUnit $unit): bool
    {
        return $user->can('unit.view') && $user->hasUnitAccess($unit->id);
    }

    public function update(User $user, BusinessUnit $unit): bool
    {
        return $user->can('settings.manage');
    }
}
