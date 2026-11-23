<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Enums\AcquisitionSource;
use App\Enums\Custody;
use App\Enums\InventoryEventType;
use App\Enums\Sellability;
use App\Models\BusinessUnit;
use App\Models\InventoryCycle;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Makes a registered handset sellable by opening its acquisition cycle and
 * posting the receipt event. Purchase receipts, manual intake and (later)
 * exchange inspection all funnel through here so that the invariant "sellable
 * implies an open owned cycle" holds everywhere.
 */
final class InventoryIntakeService
{
    public function __construct(
        private readonly InventoryCycleService $cycles,
        private readonly StockStateMachine $states,
        private readonly ChartOfAccounts $accounts,
        private readonly JournalPostingService $journal,
    ) {}

    public function activate(
        SerializedUnit $unit,
        BusinessUnit $businessUnit,
        AcquisitionSource $source,
        string $acquisitionValue,
        string $allocatedDirectCost,
        User $actor,
        InventoryEventType $eventType,
        ?Model $sourceModel = null,
        ?string $sourceLabel = null,
        ?string $note = null,
        CarbonInterface|string|null $acquiredAt = null,
        ?string $idempotencyKey = null,
        ?string $buyingPartyName = null,
    ): InventoryCycle {
        return DB::transaction(function () use (
            $unit,
            $businessUnit,
            $source,
            $acquisitionValue,
            $allocatedDirectCost,
            $actor,
            $eventType,
            $sourceModel,
            $sourceLabel,
            $note,
            $acquiredAt,
            $idempotencyKey,
            $buyingPartyName,
        ): InventoryCycle {
            $cycle = $this->cycles->open(
                unit: $unit,
                source: $source,
                acquisitionValue: $acquisitionValue,
                allocatedDirectCost: $allocatedDirectCost,
                initialUnit: $businessUnit,
                actor: $actor,
                acquiredAt: $acquiredAt,
                sourceModel: $sourceModel,
                sourceLabel: $sourceLabel,
                note: $note,
                buyingPartyName: $buyingPartyName,
            );

            $this->states->transition(
                unit: $unit,
                toCustody: Custody::InUnit,
                toUnitId: $businessUnit->getKey(),
                eventType: $eventType,
                actor: $actor,
                toSellability: Sellability::Available,
                note: $note,
                reference: $sourceModel,
                metadata: [
                    'cycle_id' => $cycle->getKey(),
                    'cycle_no' => $cycle->cycle_no,
                    'effective_cost_basis' => $cycle->effective_cost_basis,
                    'source_label' => $sourceLabel,
                ],
                idempotencyKey: $idempotencyKey,
                happenedAt: $acquiredAt,
                cycle: $cycle,
            );

            if (
                in_array($source, [AcquisitionSource::Adjustment, AcquisitionSource::OpeningBalance, AcquisitionSource::LegacyImport], true)
                && Money::of($cycle->effective_cost_basis)->isPositive()
            ) {
                $this->journal->post(
                    lines: [
                        [
                            'account_id' => $this->accounts->handsetInventory()->getKey(),
                            'debit' => (string) $cycle->effective_cost_basis,
                            'memo' => 'Handset opening cost '.($unit->primary_imei ?? $unit->getKey()),
                        ],
                        [
                            'account_id' => $this->accounts->openingEquity()->getKey(),
                            'credit' => (string) $cycle->effective_cost_basis,
                            'memo' => 'Opening balance equity '.($unit->primary_imei ?? $unit->getKey()),
                        ],
                    ],
                    memo: 'Inventory intake '.($unit->primary_imei ?? $unit->getKey()),
                    entryDate: $acquiredAt ?? BusinessClock::today(),
                    sourceKey: 'intake:unit:'.$unit->getKey().($cycle->cycle_no > 1 ? ':cycle:'.$cycle->getKey() : ''),
                    source: $businessUnit,
                    actor: $actor,
                );
            }

            return $cycle;
        });
    }
}
