<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase.view') && $user->accessibleUnitIds() !== [];
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $user->can('purchase.view') && $user->hasUnitAccess($purchase->business_unit_id);
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.create') && $user->accessibleUnitIds() !== [];
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $user->can('purchase.create')
            && $user->hasUnitAccess($purchase->business_unit_id)
            && $purchase->isDraft();
    }

    public function receive(User $user, Purchase $purchase): bool
    {
        return $user->can('purchase.approve') && $user->hasUnitAccess($purchase->business_unit_id);
    }

    public function cancel(User $user, Purchase $purchase): bool
    {
        return $user->can('purchase.approve') && $user->hasUnitAccess($purchase->business_unit_id);
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return false;
    }
}
