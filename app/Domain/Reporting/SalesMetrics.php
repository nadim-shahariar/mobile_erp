<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\SaleStatus;
use App\Exceptions\UnitAccessDeniedException;
use App\Models\Sale;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Sales/collection/profit figures derived only from posted invoices, tender
 * rows and cost snapshots. Nothing here is entered by hand.
 */
final class SalesMetrics
{
    /**
     * @return array{
     *     sales_total: Money, sales_count: int, cogs_total: Money, gross_profit: Money,
     *     collected_total: Money, collected_cash: Money, collected_bank: Money,
     *     collected_mfs: Money, collected_card: Money, outstanding_due: Money,
     *     today_due_created: Money
     * }
     */
    public function summary(User $user, ?int $unitId = null): array
    {
        $scope = $this->scope($user, $unitId);
        $start = BusinessClock::businessDayStartUtc();
        $end = BusinessClock::businessDayEndUtc();

        $todaySales = Sale::query()
            ->whereIn('business_unit_id', $scope)
            ->where('status', SaleStatus::Posted->value)
            ->whereBetween('posted_at', [$start, $end]);

        $salesTotal = Money::of((string) (clone $todaySales)->sum('grand_total'));
        $salesCount = (int) (clone $todaySales)->count();
        $todayDue = Money::of((string) (clone $todaySales)->sum('due_total'));

        $cogs = Money::of((string) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.business_unit_id', $scope)
            ->where('sales.status', SaleStatus::Posted->value)
            ->whereBetween('sales.posted_at', [$start, $end])
            ->sum('sale_items.cost_total'));

        $collectionsByType = DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->join('accounts', 'accounts.id', '=', 'sale_payments.account_id')
            ->whereIn('sales.business_unit_id', $scope)
            ->whereBetween('sale_payments.received_at', [$start, $end])
            ->selectRaw('accounts.type as type, COALESCE(SUM(sale_payments.amount), 0) as total')
            ->groupBy('accounts.type')
            ->pluck('total', 'type');

        $customerCollectionsByType = DB::table('customer_payments')
            ->join('accounts', 'accounts.id', '=', 'customer_payments.account_id')
            ->whereIn('customer_payments.business_unit_id', $scope)
            ->whereBetween('customer_payments.received_at', [$start, $end])
            ->selectRaw('accounts.type as type, COALESCE(SUM(customer_payments.amount), 0) as total')
            ->groupBy('accounts.type')
            ->pluck('total', 'type');

        $allCollections = [];

        foreach ([$collectionsByType, $customerCollectionsByType] as $set) {
            foreach ($set as $type => $total) {
                $allCollections[$type] = (float) ($allCollections[$type] ?? 0) + (float) $total;
            }
        }

        $collectedTotal = Money::zero();

        foreach ($allCollections as $total) {
            $collectedTotal = $collectedTotal->add((string) $total);
        }

        $outstanding = Money::of((string) Sale::query()
            ->whereIn('business_unit_id', $scope)
            ->where('status', SaleStatus::Posted->value)
            ->sum('due_total'));

        return [
            'sales_total' => $salesTotal,
            'sales_count' => $salesCount,
            'cogs_total' => $cogs,
            'gross_profit' => $salesTotal->sub($cogs),
            'collected_total' => $collectedTotal,
            'collected_cash' => Money::of((string) ($allCollections['CASH'] ?? 0)),
            'collected_bank' => Money::of((string) ($allCollections['BANK'] ?? 0)),
            'collected_mfs' => Money::of((string) ($allCollections['MFS'] ?? 0)),
            'collected_card' => Money::of((string) ($allCollections['CARD'] ?? 0)),
            'outstanding_due' => $outstanding,
            'today_due_created' => $todayDue,
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
}
