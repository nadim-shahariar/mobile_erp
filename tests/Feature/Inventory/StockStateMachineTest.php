<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryCycleService;
use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Inventory\StockStateMachine;
use App\Enums\AcquisitionSource;
use App\Enums\Custody;
use App\Enums\CycleCloseReason;
use App\Enums\InventoryEventType;
use App\Enums\Sellability;
use App\Exceptions\DomainRuleViolationException;
use App\Exceptions\ErpException;
use App\Exceptions\InvalidStockTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class StockStateMachineTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_only_allowed_custody_transitions_are_accepted(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(701));

        $machine = app(StockStateMachine::class);

        $event = $machine->transition(
            unit: $unit,
            toCustody: Custody::InTransit,
            toUnitId: null,
            eventType: InventoryEventType::TransferDispatched,
            actor: $owner,
            note: 'Dispatch test',
        );

        $this->assertSame(Custody::InUnit, $event->from_custody);
        $this->assertSame(Custody::InTransit, $event->to_custody);
        $this->assertSame(Sellability::NotForSale, $unit->refresh()->sellability_status);

        $this->expectException(InvalidStockTransitionException::class);
        $machine->transition(
            unit: $unit,
            toCustody: Custody::WithCustomer,
            toUnitId: null,
            eventType: InventoryEventType::Sold,
            actor: $owner,
        );
    }

    public function test_invalid_transition_returns_http_409(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(702));

        $machine = app(StockStateMachine::class);
        $machine->transition($unit, Custody::InTransit, null, InventoryEventType::TransferDispatched, $owner);

        try {
            $machine->transition($unit, Custody::WithCustomer, null, InventoryEventType::Sold, $owner);
            $this->fail('Expected invalid transition.');
        } catch (InvalidStockTransitionException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertArrayHasKey('state', $exception->errors());
        }
    }

    public function test_a_unit_cannot_be_assigned_to_in_transit_stock(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(703));

        $this->expectException(InvalidStockTransitionException::class);

        app(StockStateMachine::class)->transition(
            unit: $unit,
            toCustody: Custody::InTransit,
            toUnitId: $this->unitOne->id,
            eventType: InventoryEventType::TransferDispatched,
            actor: $owner,
        );
    }

    public function test_sellability_can_be_reserved_without_moving_custody(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(704));

        app(StockStateMachine::class)->transition(
            unit: $unit,
            toCustody: Custody::InUnit,
            toUnitId: $this->unitOne->id,
            eventType: InventoryEventType::Reserved,
            actor: $owner,
            toSellability: Sellability::Reserved,
        );

        $this->assertSame(Sellability::Reserved, $unit->refresh()->sellability_status);
        $this->assertSame(Custody::InUnit, $unit->current_custody);
    }

    public function test_selling_closes_custody_and_marks_it_sold(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(705));

        app(StockStateMachine::class)->transition(
            unit: $unit,
            toCustody: Custody::WithCustomer,
            toUnitId: null,
            eventType: InventoryEventType::Sold,
            actor: $owner,
            toSellability: Sellability::Sold,
        );

        $unit->refresh();
        $this->assertSame(Custody::WithCustomer, $unit->current_custody);
        $this->assertSame(Sellability::Sold, $unit->sellability_status);
        $this->assertNull($unit->current_unit_id);
        $this->assertFalse($unit->isSellable());
    }

    public function test_idempotency_key_prevents_duplicate_events(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(706));

        $machine = app(StockStateMachine::class);

        $first = $machine->transition(
            unit: $unit,
            toCustody: Custody::InTransit,
            toUnitId: null,
            eventType: InventoryEventType::TransferDispatched,
            actor: $owner,
            idempotencyKey: 'test-key-1',
        );

        $second = $machine->transition(
            unit: $unit,
            toCustody: Custody::InTransit,
            toUnitId: null,
            eventType: InventoryEventType::TransferDispatched,
            actor: $owner,
            idempotencyKey: 'test-key-1',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $unit->events()->where('idempotency_key', 'test-key-1')->count());
    }

    public function test_a_handset_cannot_have_two_open_acquisition_cycles(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(707));

        $this->expectException(DomainRuleViolationException::class);

        app(InventoryCycleService::class)->open(
            unit: $unit,
            source: AcquisitionSource::Adjustment,
            acquisitionValue: '1000',
            allocatedDirectCost: '0',
            initialUnit: $this->unitOne,
            actor: $owner,
        );
    }

    public function test_cycle_service_closes_the_active_cycle(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(708));

        $cycle = $unit->activeCycle;
        $closed = app(InventoryCycleService::class)->close(
            $cycle,
            CycleCloseReason::Adjustment,
            $owner,
            null,
            'Test close',
        );

        $this->assertNotNull($closed->closed_at);
        $this->assertNull($closed->active_cycle_guard);
        $this->assertNull(app(InventoryCycleService::class)->activeCycle($unit->refresh()));
    }

    public function test_identifiers_cannot_be_edited_while_with_customer(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(709));

        app(StockStateMachine::class)->transition(
            $unit,
            Custody::WithCustomer,
            null,
            InventoryEventType::Sold,
            $owner,
            Sellability::Sold,
        );

        $this->expectException(ErpException::class);
        app(SerialIdentityService::class)->updateIdentifiers($unit->refresh(), $this->validImei(710), null, $owner);
    }
}
