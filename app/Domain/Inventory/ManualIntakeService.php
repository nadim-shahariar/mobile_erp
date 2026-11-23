<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\AcquisitionSource;
use App\Enums\Custody;
use App\Enums\InventoryEventType;
use App\Enums\Sellability;
use App\Exceptions\DomainRuleViolationException;
use App\Models\BusinessUnit;
use App\Models\ProductVariant;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Manual "Add Phone" intake. This is a real acquisition cycle (source
 * ADJUSTMENT) with a real cost basis, not a fake inventory row.
 *
 * Mirrors the shop's Excel row: buying party, expected selling price, status
 * reason and the adapter/cable bundle are captured alongside the handset.
 */
final class ManualIntakeService
{
    public function __construct(
        private readonly SerialIdentityService $identities,
        private readonly InventoryIntakeService $intake,
        private readonly UnitAccessoryService $accessories,
    ) {}

    /**
     * @param  array{primary_imei?: ?string, secondary_imei?: ?string, allow_legacy?: bool, generate_internal_serial?: bool, flag_reason?: ?string}  $identity
     * @param  array{battery_health?: ?int, cycle_count?: ?int, condition_grade?: ?string, box_kit?: ?string, notes?: ?string, expected_selling_price?: string|int|float|null, status_note?: ?string}  $attributes
     * @param  array{adapter_name?: ?string, adapter_price?: string|int|float|null, cable_name?: ?string, cable_price?: string|int|float|null}  $bundle
     */
    public function addPhone(
        ProductVariant $variant,
        array $identity,
        array $attributes,
        BusinessUnit $businessUnit,
        string|Money $acquisitionCost,
        User $actor,
        CarbonInterface|string|null $acquiredAt = null,
        ?string $note = null,
        ?string $buyingParty = null,
        array $bundle = [],
        ?SerializedUnit $existingUnit = null,
        AcquisitionSource $source = AcquisitionSource::Adjustment,
        ?Model $sourceModel = null,
    ): SerializedUnit {
        if ($variant->product->product_type->value !== 'PHONE') {
            throw DomainRuleViolationException::message(
                'Only phone variants can be registered as serialized handsets.',
                ['product_variant_id' => 'Choose a phone model.'],
            );
        }

        $cost = Money::of($acquisitionCost);

        if ($cost->isNegative()) {
            throw DomainRuleViolationException::message(
                'Acquisition cost cannot be negative.',
                ['acquisition_cost' => 'Enter zero or a positive amount.'],
            );
        }

        return DB::transaction(function () use ($variant, $identity, $attributes, $businessUnit, $cost, $actor, $acquiredAt, $note, $buyingParty, $bundle, $existingUnit, $source, $sourceModel): SerializedUnit {
            if ($existingUnit !== null) {
                $unit = SerializedUnit::whereKey($existingUnit->id)->lockForUpdate()->firstOrFail();
                if (! $actor->can('update', $unit)) {
                    throw DomainRuleViolationException::message('You cannot restock this handset.', ['primary_imei' => 'No permission to update this handset.']);
                }
                if ($unit->activeCycle()->exists() || ! in_array($unit->current_custody, [Custody::WithCustomer, Custody::ReturnPendingInspection], true)) {
                    throw DomainRuleViolationException::message(
                        'This handset is already owned or is not eligible for restocking. Use Transfers or Returns for an active handset.',
                        ['primary_imei' => 'Cannot restock a handset with an active cycle or an unresolved stock movement.'],
                    );
                }
                if ($variant->product_id !== $unit->variant->product_id) {
                    throw DomainRuleViolationException::message('A restock must keep the original handset model.', ['product_variant_id' => 'Keep the existing model when restocking.']);
                }
                $old = $unit->only(['product_variant_id', 'battery_health', 'cycle_count', 'condition_grade', 'box_kit', 'expected_selling_price', 'notes', 'status_note']);
                $unit->fill(array_merge($attributes, ['product_variant_id' => $variant->id]))->save();
                $changes = ['before' => $old, 'after' => $unit->only(array_keys($old))];
                app(StockStateMachine::class)->transition(
                    unit: $unit,
                    toCustody: Custody::ReturnPendingInspection,
                    toUnitId: null,
                    eventType: InventoryEventType::Adjustment,
                    actor: $actor,
                    toSellability: Sellability::Quarantined,
                    note: 'Restock inspection: returning handset details updated',
                    metadata: ['restock_condition' => $changes],
                    happenedAt: $acquiredAt,
                );
                AuditLogger::record('inventory.restock_condition_updated', $unit, $old, $changes['after'], 'Restock inspection', $businessUnit->id, $actor);
            } else {
                $unit = $this->identities->register(
                    variant: $variant,
                    identity: $identity,
                    attributes: array_merge($attributes, ['initial_unit_id' => $businessUnit->getKey()]),
                    actor: $actor,
                );
            }

            $this->intake->activate(
                unit: $unit,
                businessUnit: $businessUnit,
                source: $source,
                acquisitionValue: $cost->amount,
                allocatedDirectCost: '0',
                actor: $actor,
                eventType: $source === AcquisitionSource::TradeIn ? InventoryEventType::TradeInAccepted : InventoryEventType::Adjustment,
                sourceModel: $sourceModel,
                sourceLabel: $source === AcquisitionSource::TradeIn ? 'Exchange trade-in' : ($existingUnit ? 'Restocked handset' : 'Manual inventory entry'),
                note: $note,
                acquiredAt: $acquiredAt,
                buyingPartyName: $buyingParty,
            );

            if ($bundle !== []) {
                $this->accessories->syncBundle($unit->refresh(), $bundle, $unit->activeCycle, $actor);
            }

            return $unit->refresh();
        });
    }
}
