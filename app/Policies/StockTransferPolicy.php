<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;

class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.transfer.view') && $user->accessibleUnitIds() !== [];
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock.transfer.view')
            && ($user->hasUnitAccess($transfer->from_unit_id) || $user->hasUnitAccess($transfer->to_unit_id));
    }

    public function create(User $user, int $fromUnitId): bool
    {
        return $user->can('stock.transfer.create') && $user->hasUnitAccess($fromUnitId);
    }

    public function dispatch(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock.transfer.approve') && $user->hasUnitAccess($transfer->from_unit_id);
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $user->can('stock.transfer.approve') && $user->hasUnitAccess($transfer->to_unit_id);
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $user->hasUnitAccess($transfer->from_unit_id)
            && ($user->can('stock.transfer.approve') || (int) $transfer->requested_by === (int) $user->getKey());
    }
}
