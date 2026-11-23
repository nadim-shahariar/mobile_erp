<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoice.view') && $user->accessibleUnitIds() !== [];
    }

    public function view(User $user, Sale $sale): bool
    {
        return $user->can('invoice.view') && $user->hasUnitAccess($sale->business_unit_id);
    }

    public function print(User $user, Sale $sale): bool
    {
        return $user->can('invoice.print') && $user->hasUnitAccess($sale->business_unit_id);
    }

    public function delete(User $user, Sale $sale): bool
    {
        return false;
    }
}
