<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\AcquisitionSource;
use App\Enums\CycleCloseReason;
use App\Exceptions\DomainRuleViolationException;
use App\Models\BusinessUnit;
use App\Models\InventoryCycle;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens and closes acquisition cycles. An active cycle is the handset's current
 * owned cost basis; the unique guard index makes "one open cycle" a database
 * invariant, not just an application convention.
 */
final class InventoryCycleService
{
    public function open(
        SerializedUnit $unit,
        AcquisitionSource $source,
        string|Money $acquisitionValue,
        string|Money $allocatedDirectCost,
        BusinessUnit $initialUnit,
        User $actor,
        CarbonInterface|string|null $acquiredAt = null,
        ?Model $sourceModel = null,
        ?string $sourceLabel = null,
        ?string $note = null,
        ?string $buyingPartyName = null,
    ): InventoryCycle {
        $acquisition = Money::of($acquisitionValue);
        $allocated = Money::of($allocatedDirectCost);

        if ($acquisition->isNegative() || $allocated->isNegative()) {
            throw DomainRuleViolationException::message(
                'Acquisition value and allocated cost cannot be negative.',
                ['acquisition_value' => 'Must be zero or greater.'],
            );
        }

        try {
            return DB::transaction(function () use ($unit, $source, $acquisition, $allocated, $initialUnit, $actor, $acquiredAt, $sourceModel, $sourceLabel, $note, $buyingPartyName): InventoryCycle {
                /** @var SerializedUnit $locked */
                $locked = SerializedUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

                if (InventoryCycle::query()->where('serialized_unit_id', $locked->getKey())->whereNull('closed_at')->exists()) {
                    throw DomainRuleViolationException::message(
                        'This handset already has an open acquisition cycle. Close it before opening a new one.',
                        ['cycle' => 'An active owned cycle already exists.'],
                    );
                }

                $cycleNo = (int) InventoryCycle::query()
                    ->where('serialized_unit_id', $locked->getKey())
                    ->max('cycle_no') + 1;

                $cycle = InventoryCycle::create([
                    'serialized_unit_id' => $locked->getKey(),
                    'cycle_no' => $cycleNo,
                    'source_type' => $source->value,
                    'source_ref_type' => $sourceModel?->getMorphClass(),
                    'source_ref_id' => $sourceModel?->getKey(),
                    'source_label' => $sourceLabel,
                    'buying_party_name' => $buyingPartyName !== null ? mb_substr(trim($buyingPartyName), 0, 180) : null,
                    'acquisition_value' => $acquisition->amount,
                    'allocated_direct_cost' => $allocated->amount,
                    'effective_cost_basis' => $acquisition->add($allocated)->amount,
                    'initial_unit_id' => $initialUnit->getKey(),
                    'acquired_at' => BusinessClock::toUtc($acquiredAt),
                    'acquired_by' => $actor->getKey(),
                    'notes' => $note,
                    'active_cycle_guard' => 1,
                ]);

                AuditLogger::record(
                    'inventory.cycle_opened',
                    $cycle,
                    [],
                    $cycle->only(['serialized_unit_id', 'cycle_no', 'source_type', 'effective_cost_basis', 'initial_unit_id']),
                    null,
                    $initialUnit->getKey(),
                    $actor,
                );

                return $cycle;
            });
        } catch (UniqueConstraintViolationException) {
            throw DomainRuleViolationException::message(
                'This handset already has an open acquisition cycle.',
                ['cycle' => 'Concurrent cycle creation was blocked.'],
            );
        }
    }

    public function close(
        InventoryCycle $cycle,
        CycleCloseReason $reason,
        User $actor,
        CarbonInterface|string|null $closedAt = null,
        ?string $note = null,
    ): InventoryCycle {
        return DB::transaction(function () use ($cycle, $reason, $actor, $closedAt, $note): InventoryCycle {
            /** @var InventoryCycle $locked */
            $locked = InventoryCycle::query()->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw DomainRuleViolationException::message(
                    'This acquisition cycle is already closed.',
                    ['cycle' => 'Cycle already closed.'],
                );
            }

            $old = $locked->only(['closed_at', 'closing_type']);

            $locked->forceFill([
                'closed_at' => BusinessClock::toUtc($closedAt),
                'closing_type' => $reason,
                'closed_by' => $actor->getKey(),
                'active_cycle_guard' => null,
                'notes' => $note ?? $locked->notes,
            ])->save();

            AuditLogger::record(
                'inventory.cycle_closed',
                $locked,
                $old,
                $locked->only(['closed_at', 'closing_type']),
                $note,
                $locked->initial_unit_id,
                $actor,
            );

            return $locked;
        });
    }

    public function activeCycle(SerializedUnit $unit): ?InventoryCycle
    {
        return InventoryCycle::query()
            ->where('serialized_unit_id', $unit->getKey())
            ->whereNull('closed_at')
            ->first();
    }
}
