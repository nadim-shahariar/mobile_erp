<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Domain\Inventory\AccessoryStockService;
use App\Domain\Inventory\StockStateMachine;
use App\Enums\AccessoryMovementReason;
use App\Enums\Custody;
use App\Enums\InventoryEventType;
use App\Enums\LineType;
use App\Enums\Sellability;
use App\Enums\StockTransferStatus;
use App\Exceptions\DomainRuleViolationException;
use App\Exceptions\InvalidStockTransitionException;
use App\Exceptions\UnitAccessDeniedException;
use App\Models\BusinessUnit;
use App\Models\InventoryEvent;
use App\Models\SerializedUnit;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\DocumentNumberService;
use Illuminate\Support\Facades\DB;

/**
 * Cross-unit stock transfers: REQUESTED -> DISPATCHED -> RECEIVED.
 *
 * Requesting reserves serialized handsets (so they cannot be sold while the
 * transfer is pending); dispatching moves custody to IN_TRANSIT; receiving puts
 * them back into a unit. In-transit stock is never sellable.
 */
final class StockTransferService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockStateMachine $states,
        private readonly AccessoryStockService $accessoryStock,
    ) {}

    /**
     * @param  list<int>  $phoneUnitIds
     * @param  list<array{product_variant_id: int, quantity: int}>  $accessoryLines
     */
    public function request(
        BusinessUnit $from,
        BusinessUnit $to,
        array $phoneUnitIds,
        array $accessoryLines,
        User $actor,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): StockTransfer {
        if ($from->getKey() === $to->getKey()) {
            throw DomainRuleViolationException::message(
                'Source and destination units must be different.',
                ['to_unit_id' => 'Choose another unit.'],
            );
        }

        if (! $actor->hasUnitAccess($from->getKey())) {
            throw UnitAccessDeniedException::forUnit($from->getKey());
        }

        if (! $from->is_active || ! $to->is_active) {
            throw DomainRuleViolationException::message(
                'Transfers require two active business units.',
                ['from_unit_id' => 'Inactive unit.'],
            );
        }

        if ($idempotencyKey !== null) {
            $existing = StockTransfer::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing->load('lines');
            }
        }

        $phoneUnitIds = array_values(array_unique(array_map('intval', $phoneUnitIds)));

        if ($phoneUnitIds === [] && $accessoryLines === []) {
            throw DomainRuleViolationException::message(
                'A transfer needs at least one phone or accessory line.',
                ['lines' => 'Select stock to transfer.'],
            );
        }

        return DB::transaction(function () use ($from, $to, $phoneUnitIds, $accessoryLines, $actor, $note, $idempotencyKey): StockTransfer {
            $units = SerializedUnit::query()
                ->whereIn('id', $phoneUnitIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($phoneUnitIds as $unitId) {
                $unit = $units->get($unitId);

                if ($unit === null) {
                    throw DomainRuleViolationException::message(
                        "Handset #{$unitId} does not exist.",
                        ['lines' => "Unknown handset #{$unitId}."],
                    );
                }

                if ((int) $unit->current_unit_id !== (int) $from->getKey()) {
                    throw InvalidStockTransitionException::unitMismatch($from->getKey(), $unit->current_unit_id);
                }

                if ($unit->current_custody !== Custody::InUnit || $unit->sellability_status !== Sellability::Available) {
                    throw DomainRuleViolationException::message(
                        "Handset #{$unit->primary_imei} is not available for transfer (state: {$unit->sellability_status->label()}).",
                        ['lines' => 'Only available handsets in the source unit can be transferred.'],
                    );
                }

                $this->assertNotInOpenTransfer((int) $unit->getKey());
            }

            $transfer = StockTransfer::create([
                'transfer_no' => $this->numbers->next('transfer'),
                'from_unit_id' => $from->getKey(),
                'to_unit_id' => $to->getKey(),
                'status' => StockTransferStatus::Requested,
                'notes' => $note,
                'requested_by' => $actor->getKey(),
                'requested_at' => BusinessClock::utcNow(),
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($phoneUnitIds as $unitId) {
                $transfer->lines()->create([
                    'line_type' => LineType::Phone,
                    'serialized_unit_id' => $unitId,
                ]);
            }

            foreach ($accessoryLines as $line) {
                $quantity = (int) ($line['quantity'] ?? 0);

                if ($quantity < 1) {
                    throw DomainRuleViolationException::message(
                        'Accessory transfer quantity must be at least one.',
                        ['lines' => 'Invalid accessory quantity.'],
                    );
                }

                $transfer->lines()->create([
                    'line_type' => LineType::Accessory,
                    'product_variant_id' => (int) $line['product_variant_id'],
                    'quantity' => $quantity,
                ]);
            }

            foreach ($phoneUnitIds as $unitId) {
                $this->states->transition(
                    unit: $units->get($unitId),
                    toCustody: Custody::InUnit,
                    toUnitId: $from->getKey(),
                    eventType: InventoryEventType::TransferRequested,
                    actor: $actor,
                    toSellability: Sellability::Reserved,
                    note: $note ?? "Transfer {$transfer->transfer_no} requested",
                    reference: $transfer,
                    metadata: ['transfer_no' => $transfer->transfer_no, 'to_unit_id' => $to->getKey()],
                    idempotencyKey: "transfer:{$transfer->getKey()}:request:unit:{$unitId}",
                );
            }

            foreach ($transfer->lines()->where('line_type', LineType::Accessory->value)->get() as $line) {
                $this->accessoryStock->reserve(
                    (int) $from->getKey(),
                    (int) $line->product_variant_id,
                    (int) $line->quantity,
                );
            }

            AuditLogger::record(
                'transfer.requested',
                $transfer,
                [],
                $transfer->only(['transfer_no', 'from_unit_id', 'to_unit_id', 'status']),
                $note,
                $from->getKey(),
                $actor,
            );

            return $transfer->load('lines');
        });
    }

    public function dispatch(StockTransfer $transfer, User $actor): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $actor): StockTransfer {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockTransferStatus::Requested) {
                throw DomainRuleViolationException::message(
                    "Transfer {$locked->transfer_no} cannot be dispatched from status {$locked->status->label()}.",
                    ['status' => 'Only requested transfers can be dispatched.'],
                );
            }

            if (! $actor->hasUnitAccess($locked->from_unit_id)) {
                throw UnitAccessDeniedException::forUnit($locked->from_unit_id);
            }

            $from = BusinessUnit::query()->findOrFail($locked->from_unit_id);

            $phoneLines = $locked->lines()->where('line_type', LineType::Phone->value)->get();
            $accessoryLines = $locked->lines()->where('line_type', LineType::Accessory->value)->get();

            foreach ($phoneLines as $line) {
                /** @var SerializedUnit $unit */
                $unit = SerializedUnit::query()->whereKey($line->serialized_unit_id)->lockForUpdate()->firstOrFail();

                $this->assertLastEventReferences($unit, $locked, InventoryEventType::TransferRequested);

                if ((int) $unit->current_unit_id !== (int) $locked->from_unit_id) {
                    throw InvalidStockTransitionException::unitMismatch((int) $locked->from_unit_id, $unit->current_unit_id);
                }

                $this->states->transition(
                    unit: $unit,
                    toCustody: Custody::InTransit,
                    toUnitId: null,
                    eventType: InventoryEventType::TransferDispatched,
                    actor: $actor,
                    toSellability: Sellability::NotForSale,
                    note: "Transfer {$locked->transfer_no} dispatched",
                    reference: $locked,
                    metadata: ['transfer_no' => $locked->transfer_no, 'to_unit_id' => $locked->to_unit_id],
                    idempotencyKey: "transfer:{$locked->getKey()}:dispatch:unit:{$unit->getKey()}",
                );
            }

            foreach ($accessoryLines as $line) {
                $this->accessoryStock->releaseReservation(
                    (int) $from->getKey(),
                    (int) $line->product_variant_id,
                    (int) $line->quantity,
                );

                $balance = $this->accessoryStock->balanceFor((int) $from->getKey(), (int) $line->product_variant_id);

                $this->accessoryStock->issue(
                    businessUnitId: (int) $from->getKey(),
                    variantId: (int) $line->product_variant_id,
                    quantity: (int) $line->quantity,
                    reason: AccessoryMovementReason::TransferOut,
                    actor: $actor,
                    source: $locked,
                    note: "Transfer {$locked->transfer_no} dispatched",
                );

                $line->forceFill(['unit_cost' => $balance->average_cost])->save();
            }

            $locked->forceFill([
                'status' => StockTransferStatus::Dispatched,
                'dispatched_by' => $actor->getKey(),
                'dispatched_at' => BusinessClock::utcNow(),
            ])->save();

            AuditLogger::record(
                'transfer.dispatched',
                $locked,
                ['status' => StockTransferStatus::Requested->value],
                ['status' => StockTransferStatus::Dispatched->value],
                null,
                $locked->from_unit_id,
                $actor,
            );

            return $locked->refresh()->load('lines');
        });
    }

    public function receive(StockTransfer $transfer, User $actor): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $actor): StockTransfer {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === StockTransferStatus::Received) {
                throw DomainRuleViolationException::message(
                    "Transfer {$locked->transfer_no} has already been received.",
                    ['status' => 'Stock was already received; refusing to double it.'],
                );
            }

            if ($locked->status !== StockTransferStatus::Dispatched) {
                throw DomainRuleViolationException::message(
                    "Transfer {$locked->transfer_no} must be dispatched before it can be received.",
                    ['status' => 'Only dispatched transfers can be received.'],
                );
            }

            if (! $actor->hasUnitAccess($locked->to_unit_id)) {
                throw UnitAccessDeniedException::forUnit($locked->to_unit_id);
            }

            $to = BusinessUnit::query()->findOrFail($locked->to_unit_id);

            foreach ($locked->lines()->where('line_type', LineType::Phone->value)->get() as $line) {
                /** @var SerializedUnit $unit */
                $unit = SerializedUnit::query()->whereKey($line->serialized_unit_id)->lockForUpdate()->firstOrFail();

                $this->assertLastEventReferences($unit, $locked, InventoryEventType::TransferDispatched);

                if ($unit->current_custody !== Custody::InTransit) {
                    throw new InvalidStockTransitionException(
                        "Handset {$unit->primary_imei} is not in transit; refusing receipt.",
                        409,
                        ['lines' => 'The handset is not in transit.'],
                    );
                }

                $this->states->transition(
                    unit: $unit,
                    toCustody: Custody::InUnit,
                    toUnitId: $to->getKey(),
                    eventType: InventoryEventType::TransferReceived,
                    actor: $actor,
                    toSellability: Sellability::Available,
                    note: "Transfer {$locked->transfer_no} received",
                    reference: $locked,
                    metadata: ['transfer_no' => $locked->transfer_no, 'from_unit_id' => $locked->from_unit_id],
                    idempotencyKey: "transfer:{$locked->getKey()}:receive:unit:{$unit->getKey()}",
                );
            }

            foreach ($locked->lines()->where('line_type', LineType::Accessory->value)->get() as $line) {
                $this->accessoryStock->receive(
                    businessUnitId: (int) $to->getKey(),
                    variantId: (int) $line->product_variant_id,
                    quantity: (int) $line->quantity,
                    unitCost: $line->unit_cost,
                    reason: AccessoryMovementReason::TransferIn,
                    actor: $actor,
                    source: $locked,
                    note: "Transfer {$locked->transfer_no} received",
                );
            }

            $locked->forceFill([
                'status' => StockTransferStatus::Received,
                'received_by' => $actor->getKey(),
                'received_at' => BusinessClock::utcNow(),
            ])->save();

            AuditLogger::record(
                'transfer.received',
                $locked,
                ['status' => StockTransferStatus::Dispatched->value],
                ['status' => StockTransferStatus::Received->value],
                null,
                $locked->to_unit_id,
                $actor,
            );

            return $locked->refresh()->load('lines');
        });
    }

    public function cancel(StockTransfer $transfer, User $actor, ?string $reason = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $actor, $reason): StockTransfer {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockTransferStatus::Requested) {
                throw DomainRuleViolationException::message(
                    'Only requested transfers can be cancelled. A dispatched transfer must be received (or reversed by approval in a later phase).',
                    ['status' => 'Cannot cancel after dispatch.'],
                );
            }

            foreach ($locked->lines()->where('line_type', LineType::Phone->value)->get() as $line) {
                /** @var SerializedUnit $unit */
                $unit = SerializedUnit::query()->whereKey($line->serialized_unit_id)->lockForUpdate()->firstOrFail();

                $this->states->transition(
                    unit: $unit,
                    toCustody: Custody::InUnit,
                    toUnitId: (int) $locked->from_unit_id,
                    eventType: InventoryEventType::TransferCancelled,
                    actor: $actor,
                    toSellability: Sellability::Available,
                    note: $reason ?? "Transfer {$locked->transfer_no} cancelled",
                    reference: $locked,
                    idempotencyKey: "transfer:{$locked->getKey()}:cancel:unit:{$unit->getKey()}",
                );
            }

            foreach ($locked->lines()->where('line_type', LineType::Accessory->value)->get() as $line) {
                $this->accessoryStock->releaseReservation(
                    (int) $locked->from_unit_id,
                    (int) $line->product_variant_id,
                    (int) $line->quantity,
                );
            }

            $locked->forceFill([
                'status' => StockTransferStatus::Cancelled,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => BusinessClock::utcNow(),
                'cancellation_reason' => $reason,
            ])->save();

            AuditLogger::record(
                'transfer.cancelled',
                $locked,
                ['status' => StockTransferStatus::Requested->value],
                ['status' => StockTransferStatus::Cancelled->value],
                $reason,
                $locked->from_unit_id,
                $actor,
            );

            return $locked->refresh()->load('lines');
        });
    }

    private function assertNotInOpenTransfer(int $serializedUnitId): void
    {
        $exists = StockTransferLine::query()
            ->where('serialized_unit_id', $serializedUnitId)
            ->whereHas('transfer', fn ($query) => $query->open())
            ->exists();

        if ($exists) {
            throw DomainRuleViolationException::message(
                'This handset is already in an open transfer.',
                ['lines' => 'Remove handsets that already have a pending transfer.'],
            );
        }
    }

    private function assertLastEventReferences(SerializedUnit $unit, StockTransfer $transfer, InventoryEventType $expected): void
    {
        /** @var InventoryEvent|null $lastEvent */
        $lastEvent = $unit->lastEvent()->first();

        if (
            $lastEvent === null
            || (int) $lastEvent->reference_id !== (int) $transfer->getKey()
            || $lastEvent->reference_type !== $transfer->getMorphClass()
            || $lastEvent->event_type !== $expected
        ) {
            throw DomainRuleViolationException::message(
                "Handset {$unit->primary_imei} history does not match transfer {$transfer->transfer_no}. Refresh and retry.",
                ['lines' => 'Transfer/handset history mismatch.'],
            );
        }
    }
}
