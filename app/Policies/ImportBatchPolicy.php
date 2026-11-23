<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class ImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.import');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.import') && $user->accessibleUnitIds() !== [];
    }

    public function commit(User $user): bool
    {
        return $user->can('inventory.import');
    }
}
