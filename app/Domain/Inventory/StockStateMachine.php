<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\Custody;
use App\Enums\InventoryEventType;
use App\Enums\Sellability;
use App\Exceptions\DomainRuleViolationException;
use App\Exceptions\InvalidStockTransitionException;
use App\Models\BusinessUnit;
use App\Models\InventoryCycle;
use App\Models\InventoryEvent;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned way to change a handset's physical state.
 *
 * Every transition appends an immutable inventory_event with actor, business
 * time and before/after state. Invalid transitions throw HTTP 409 instead of
 * silently corrupting stock.
 */
final class StockStateMachine
{
    /** @var array<string, list<string>> */
    private const CUSTODY_TRANSITIONS = [
        Custody::AtExternalParty->value => [Custody::InUnit->value, Custody::WithCustomer->value],
        Custody::InUnit->value => [
            Custody::AtExternalParty->value,
            Custody::InTransit->value,
            Custody::AtRepairVendor->value,
            Custody::WithCustomer->value,
            Custody::ReturnPendingInspection->value,
            Custody::WrittenOff->value,
        ],
        Custody::InTransit->value => [
            Custody::InUnit->value,
            Custody::ReturnPendingInspection->value,
        ],
        Custody::AtRepairVendor->value => [
            Custody::ReturnPendingInspection->value,
            Custody::InUnit->value,
            Custody::WrittenOff->value,
        ],
        Custody::ReturnPendingInspection->value => [
            Custody::InUnit->value,
            Custody::AtRepairVendor->value,
            Custody::WrittenOff->value,
        ],
        Custody::WithCustomer->value => [
            Custody::ReturnPendingInspection->value,
        ],
        Custody::WrittenOff->value => [],
    ];

    /** @var array<string, list<string>> */
    private const SELLABILITY_TRANSITIONS = [
        Sellability::Available->value => [
            Sellability::Reserved->value,
            Sellability::Quarantined->value,
            Sellability::NotForSale->value,
            Sellability::Sold->value,
        ],
        Sellability::Reserved->value => [
            Sellability::Available->value,
            Sellability::NotForSale->value,
        ],
        Sellability::Quarantined->value => [
            Sellability::Available->value,
            Sellability::NotForSale->value,
            Sellability::Sold->value,
        ],
        Sellability::NotForSale->value => [
            Sellability::Available->value,
        ],
        Sellability::Sold->value => [
            Sellability::Quarantined->value,
        ],
    ];

    public function transition(
        SerializedUnit $unit,
        Custody $toCustody,
        ?int $toUnitId,
        InventoryEventType $eventType,
        User|int|null $actor,
        ?Sellability $toSellability = null,
        ?string $note = null,
        Model|int|null $reference = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
        CarbonInterface|string|null $happenedAt = null,
        ?InventoryCycle $cycle = null,
    ): InventoryEvent {
        return DB::transaction(function () use (
            $unit,
            $toCustody,
            $toUnitId,
            $eventType,
            $actor,
            $toSellability,
            $note,
            $reference,
            $metadata,
            $idempotencyKey,
            $happenedAt,
            $cycle,
        ): InventoryEvent {
            if ($idempotencyKey !== null) {
                $existing = InventoryEvent::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    if ((int) $existing->serialized_unit_id !== (int) $unit->getKey()) {
                        throw DomainRuleViolationException::message(
                            'This idempotency key was already used for a different handset.',
                            ['idempotency_key' => 'Duplicate request key.'],
                        );
                    }

                    return $existing;
                }
            }

            /** @var SerializedUnit $locked */
            $locked = SerializedUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            $fromCustody = $locked->current_custody;
            $fromSellability = $locked->sellability_status;
            $fromUnitId = $locked->current_unit_id;

            if ($fromCustody !== $toCustody) {
                $this->assertCustodyTransition($fromCustody, $toCustody);
            }

            $targetSellability = $toSellability
                ?? ($fromCustody === $toCustody ? $fromSellability : $this->defaultSellability($toCustody));

            if ($targetSellability !== $fromSellability) {
                $this->assertSellabilityTransition($fromSellability, $targetSellability);
            }

            $this->assertUnitRules($toCustody, $toUnitId);

            [$referenceType, $referenceId] = $this->resolveReference($reference);

            $actorId = $actor instanceof User ? $actor->getKey() : $actor;

            $event = InventoryEvent::create([
                'serialized_unit_id' => $locked->getKey(),
                'inventory_cycle_id' => $cycle?->getKey() ?? $locked->activeCycle()->value('id'),
                'event_type' => $eventType,
                'from_custody' => $fromCustody,
                'to_custody' => $toCustody,
                'from_unit_id' => $fromUnitId,
                'to_unit_id' => $toUnitId,
                'actor_id' => $actorId,
                'happened_at' => BusinessClock::toUtc($happenedAt),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata === null ? null : array_merge($metadata, [
                    'from_sellability' => $fromSellability->value,
                    'to_sellability' => $targetSellability->value,
                ]),
                'created_at' => BusinessClock::utcNow(),
            ]);

            $locked->forceFill([
                'current_custody' => $toCustody,
                'current_unit_id' => $toUnitId,
                'sellability_status' => $targetSellability,
                'last_event_id' => $event->getKey(),
                'version' => (int) $locked->version + 1,
            ])->save();

            $unit->refresh();

            return $event;
        });
    }

    public function assertCustodyTransition(Custody $from, Custody $to): void
    {
        $allowed = self::CUSTODY_TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw InvalidStockTransitionException::custody($from, $to);
        }
    }

    public function assertSellabilityTransition(Sellability $from, Sellability $to): void
    {
        $allowed = self::SELLABILITY_TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw InvalidStockTransitionException::sellability($from, $to);
        }
    }

    public function defaultSellability(Custody $custody): Sellability
    {
        return match ($custody) {
            Custody::InUnit => Sellability::Available,
            Custody::InTransit => Sellability::NotForSale,
            Custody::AtExternalParty => Sellability::NotForSale,
            Custody::AtRepairVendor => Sellability::NotForSale,
            Custody::WithCustomer => Sellability::Sold,
            Custody::ReturnPendingInspection => Sellability::Quarantined,
            Custody::WrittenOff => Sellability::NotForSale,
        };
    }

    private function assertUnitRules(Custody $custody, ?int $unitId): void
    {
        if ($custody === Custody::InUnit) {
            if ($unitId === null) {
                throw InvalidStockTransitionException::unitRequired($custody);
            }

            if (! BusinessUnit::query()->whereKey($unitId)->exists()) {
                throw DomainRuleViolationException::message(
                    'The destination business unit does not exist.',
                    ['unit_id' => 'Unknown business unit.'],
                );
            }

            return;
        }

        if ($unitId !== null) {
            throw InvalidStockTransitionException::unitNotAllowed($custody);
        }
    }

    /** @return array{0: ?string, 1: ?int} */
    private function resolveReference(Model|int|null $reference): array
    {
        if ($reference instanceof Model) {
            return [$reference->getMorphClass(), $reference->getKey()];
        }

        return [null, $reference];
    }
}
