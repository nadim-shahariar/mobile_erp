<?php

namespace App\Domain\Reporting;

use App\Enums\Custody;
use App\Enums\InventoryEventType;
use App\Enums\Sellability;
use App\Models\BusinessUnit;
use App\Models\PhoneRepair;
use App\Models\SerializedUnit;
use App\Models\StockTransfer;
use App\Models\User;

class PosStockSummary
{
    public function forUnit(User $actor, int $sourceId): array
    {
        abort_unless($actor->hasUnitAccess($sourceId), 403);
        $units = BusinessUnit::active()->whereIn('id', $actor->accessibleUnitIds())->ordered()->get();
        $stock = SerializedUnit::query()->whereIn('current_unit_id', $units->modelKeys())->where('current_custody', Custody::InUnit->value)->where('sellability_status', Sellability::Available->value)->whereHas('activeCycle');
        $counts = (clone $stock)->selectRaw('current_unit_id, count(*) as total')->groupBy('current_unit_id')->pluck('total', 'current_unit_id');
        $transferred = (clone $stock)->where('current_unit_id', '!=', $sourceId)->whereExists(function ($q) use ($sourceId) {
            $q->selectRaw('1')->from('inventory_events as movement')->join('inventory_cycles as cycle', 'cycle.id', '=', 'movement.inventory_cycle_id')->join('stock_transfers as transfer', 'transfer.id', '=', 'movement.reference_id')
                ->whereColumn('movement.serialized_unit_id', 'serialized_units.id')->whereNull('cycle.closed_at')
                ->where('movement.event_type', InventoryEventType::TransferReceived->value)->where('movement.reference_type', (new StockTransfer)->getMorphClass())->where('transfer.from_unit_id', $sourceId)->whereColumn('movement.to_unit_id', 'serialized_units.current_unit_id')
                ->where('movement.id', '=', function ($latest) {
                    $latest->selectRaw('max(last_movement.id)')->from('inventory_events as last_movement')->whereColumn('last_movement.inventory_cycle_id', 'cycle.id')->where('last_movement.event_type', InventoryEventType::TransferReceived->value);
                });
        })->selectRaw('current_unit_id, count(*) as total')->groupBy('current_unit_id')->pluck('total', 'current_unit_id');

        $repairs = PhoneRepair::whereIn('business_unit_id', $units->modelKeys())->where('status', 'OUT')->selectRaw('business_unit_id, count(*) as total')->groupBy('business_unit_id')->pluck('total', 'business_unit_id');

        return $units->map(fn ($unit) => ['id' => $unit->id, 'name' => $unit->name, 'repair' => (int) ($repairs[$unit->id] ?? 0), 'stock' => (int) ($counts[$unit->id] ?? 0), 'transferred' => (int) ($transferred[$unit->id] ?? 0), 'is_current' => $unit->id === $sourceId])->all();
    }
}
