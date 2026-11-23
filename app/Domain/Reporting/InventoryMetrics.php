<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Inventory\InventoryGridQuery;
use App\Enums\Custody;
use App\Enums\PurchaseStatus;
use App\Enums\Sellability;
use App\Enums\StockTransferStatus;
use App\Exceptions\UnitAccessDeniedException;
use App\Models\AccessoryStockBalance;
use App\Models\BusinessUnit;
use App\Models\InventoryCycle;
use App\Models\InventoryEvent;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SerializedUnit;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Real, permission-scoped inventory metrics for the control center.
 *
 * Sales/P&L metrics intentionally do not appear until the accounting module
 * exists; returning a fabricated zero would misrepresent the business.
 */
final class InventoryMetrics
{
    public function __construct(private readonly InventoryGridQuery $gridQuery) {}

    /**
     * @return array{
     *     counts: array<string, int>,
     *     stock_value: Money,
     *     in_transit_value: Money,
     *     repair_value: Money,
     *     accessory_value: Money,
     *     low_stock_count: int,
     *     today_purchases: Money,
     *     today_purchase_count: int,
     *     pending_transfers: int,
     *     units: array<int, array{id: int, name: string, available: int}>
     * }
     */
    public function summary(User $user, ?int $unitId = null): array
    {
        $scope = $this->scope($user, $unitId);

        $counts = $this->gridQuery->countsByCustody($user, $unitId);

        $stockValue = $this->cycleValue($scope, Custody::InUnit);
        $inTransitValue = $this->cycleValue($scope, Custody::InTransit);
        $repairValue = $this->cycleValue($scope, Custody::AtRepairVendor);

        $accessoryValue = Money::of(
            (string) AccessoryStockBalance::query()
                ->whereIn('business_unit_id', $scope)
                ->selectRaw('COALESCE(SUM(qty_on_hand * average_cost), 0) as value')
                ->value('value'),
        );

        // Reorder-point comparison needs a join; do it explicitly to keep the
        // intent readable and index-friendly.
        $lowStockCount = config('erp.policy.low_stock_alerts', true)
            ? (int) DB::table('accessory_stock_balances as b')
                ->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
                ->whereIn('b.business_unit_id', $scope)
                ->whereColumn('b.qty_on_hand', '<=', 'v.reorder_point')
                ->where('v.is_active', true)
                ->count()
            : 0;

        [$todayPurchaseCount, $todayPurchaseTotal] = $this->todayPurchases($scope);

        $pendingTransfers = (int) StockTransfer::query()
            ->whereIn('status', [StockTransferStatus::Requested->value, StockTransferStatus::Dispatched->value])
            ->where(function ($query) use ($scope): void {
                $query->whereIn('from_unit_id', $scope)->orWhereIn('to_unit_id', $scope);
            })
            ->count();

        $perUnit = BusinessUnit::query()
            ->whereIn('id', $scope)
            ->ordered()
            ->get()
            ->map(fn (BusinessUnit $unit): array => [
                'id' => $unit->getKey(),
                'name' => $unit->name,
                'available' => (int) SerializedUnit::query()
                    ->where('current_unit_id', $unit->getKey())
                    ->where('current_custody', Custody::InUnit->value)
                    ->where('sellability_status', Sellability::Available->value)
                    ->count(),
            ])
            ->all();

        return [
            'counts' => $counts,
            'stock_value' => $stockValue,
            'in_transit_value' => $inTransitValue,
            'repair_value' => $repairValue,
            'external_value' => $this->cycleValue($scope, Custody::AtExternalParty),
            'accessory_value' => $accessoryValue,
            'low_stock_count' => $lowStockCount,
            'today_purchases' => $todayPurchaseTotal,
            'today_purchase_count' => $todayPurchaseCount,
            'pending_transfers' => $pendingTransfers,
            'units' => $perUnit,
        ];
    }

    /** @return list<int> */
    private function scope(User $user, ?int $unitId): array
    {
        $accessible = $user->accessibleUnitIds();

        if ($accessible === []) {
            throw UnitAccessDeniedException::forUnit(null);
        }

        if ($unitId === null) {
            return $accessible;
        }

        if (! in_array($unitId, $accessible, true)) {
            throw UnitAccessDeniedException::forUnit($unitId);
        }

        return [$unitId];
    }

    /** @param list<int> $scope */
    private function cycleValue(array $scope, Custody $custody): Money
    {
        $query = InventoryCycle::query()
            ->whereNull('closed_at')
            ->whereHas('unit', function ($unitQuery) use ($scope, $custody): void {
                $unitQuery->where('current_custody', $custody->value);

                if ($custody === Custody::InUnit) {
                    $unitQuery->whereIn('current_unit_id', $scope);
                } else {
                    $unitQuery->whereHas('lastEvent', fn ($event) => $event->whereIn('from_unit_id', $scope));
                }
            });

        return Money::of((string) $query->sum('effective_cost_basis'));
    }

    /** @param list<int> $scope @return array{0: int, 1: Money} */
    private function todayPurchases(array $scope): array
    {
        $query = Purchase::query()
            ->where('status', PurchaseStatus::Received->value)
            ->whereIn('business_unit_id', $scope)
            ->whereBetween('received_at', [
                BusinessClock::businessDayStartUtc(),
                BusinessClock::businessDayEndUtc(),
            ]);

        return [
            (int) (clone $query)->count(),
            Money::of((string) (clone $query)->sum('total')),
        ];
    }

    /**
     * Latest IMEI events for the recent-activity widget.
     *
     * @return Collection<int, InventoryEvent>
     */
    public function recentEvents(User $user, ?int $unitId, int $limit = 8)
    {
        $scope = $this->scope($user, $unitId);

        return InventoryEvent::query()
            ->with(['unit.variant.product.brand', 'actor', 'fromUnit', 'toUnit'])
            ->where(function ($query) use ($scope): void {
                $query->whereIn('from_unit_id', $scope)->orWhereIn('to_unit_id', $scope);
            })
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /** @param list<int> $scope */
    public function lowStockVariants(array $scope, int $limit = 5)
    {
        return ProductVariant::query()
            ->select('product_variants.*')
            ->join('accessory_stock_balances as balances', 'balances.product_variant_id', '=', 'product_variants.id')
            ->whereIn('balances.business_unit_id', $scope)
            ->whereColumn('balances.qty_on_hand', '<=', 'product_variants.reorder_point')
            ->with('product.brand')
            ->orderBy('balances.qty_on_hand')
            ->limit($limit)
            ->get();
    }
}
