<?php

declare(strict_types=1);

namespace Tests\Feature\Transfers;

use App\Domain\Transfers\StockTransferService;
use App\Enums\Custody;
use App\Enums\InventoryEventType;
use App\Enums\Sellability;
use App\Enums\StockTransferStatus;
use App\Exceptions\ErpException;
use App\Exceptions\UnitAccessDeniedException;
use App\Models\AccessoryStockBalance;
use App\Models\SerializedUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_a_phone_and_accessories_survive_a_round_trip_between_units(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $phone = $this->phoneVariant();
        $accessory = $this->accessoryVariant();

        $purchase = $this->createPurchase($owner, $this->unitTwo, [
            ['product_variant_id' => $phone->id, 'line_type' => 'PHONE', 'quantity' => 1, 'unit_cost' => '50000.00', 'imei_1' => $this->validImei(201)],
            ['product_variant_id' => $phone->id, 'line_type' => 'PHONE', 'quantity' => 1, 'unit_cost' => '50000.00', 'imei_1' => $this->validImei(202)],
            ['product_variant_id' => $accessory->id, 'line_type' => 'ACCESSORY', 'quantity' => 10, 'unit_cost' => '1000.00'],
        ]);
        $this->receivePurchase($owner, $purchase);

        $unit = SerializedUnit::query()->where('primary_imei', $this->validImei(201))->firstOrFail();
        $cycleId = $unit->activeCycle->id;

        $transfers = app(StockTransferService::class);

        $request = $transfers->request(
            from: $this->unitTwo,
            to: $this->unitOne,
            phoneUnitIds: [$unit->id],
            accessoryLines: [['product_variant_id' => $accessory->id, 'quantity' => 3]],
            actor: $owner,
            note: 'Round trip test',
        );

        $this->assertSame(StockTransferStatus::Requested, $request->status);
        $this->assertStringStartsWith('TRF-', $request->transfer_no);

        $unit->refresh();
        $this->assertSame(Custody::InUnit, $unit->current_custody);
        $this->assertSame(Sellability::Reserved, $unit->sellability_status);
        $this->assertSame($this->unitTwo->id, $unit->current_unit_id);

        $balance = AccessoryStockBalance::query()
            ->where('product_variant_id', $accessory->id)
            ->where('business_unit_id', $this->unitTwo->id)
            ->firstOrFail();
        $this->assertSame(10, $balance->qty_on_hand);
        $this->assertSame(3, $balance->qty_reserved);

        $transfers->dispatch($request, $owner);

        $unit->refresh();
        $this->assertSame(Custody::InTransit, $unit->current_custody);
        $this->assertNull($unit->current_unit_id);
        $this->assertSame(Sellability::NotForSale, $unit->sellability_status);
        $this->assertFalse($unit->isSellable());
        $this->assertSame(StockTransferStatus::Dispatched, $request->refresh()->status);

        $balance->refresh();
        $this->assertSame(7, $balance->qty_on_hand);
        $this->assertSame(0, $balance->qty_reserved);

        $transfers->receive($request, $owner);

        $unit->refresh();
        $this->assertSame(Custody::InUnit, $unit->current_custody);
        $this->assertSame($this->unitOne->id, $unit->current_unit_id);
        $this->assertSame(Sellability::Available, $unit->sellability_status);
        $this->assertTrue($unit->isSellable());
        $this->assertSame($cycleId, $unit->activeCycle->id, 'Transfers must not create a new cost cycle.');
        $this->assertSame(StockTransferStatus::Received, $request->refresh()->status);

        $receivedBalance = AccessoryStockBalance::query()
            ->where('product_variant_id', $accessory->id)
            ->where('business_unit_id', $this->unitOne->id)
            ->firstOrFail();
        $this->assertSame(3, $receivedBalance->qty_on_hand);

        $return = $transfers->request($this->unitOne, $this->unitTwo, [$unit->id], [], $owner);
        $transfers->dispatch($return, $owner);
        $transfers->receive($return, $owner);

        $unit->refresh();
        $this->assertSame($this->unitTwo->id, $unit->current_unit_id);
        $this->assertSame(Custody::InUnit, $unit->current_custody);

        $this->assertSame(6, $unit->events()->whereIn('event_type', [
            InventoryEventType::TransferRequested->value,
            InventoryEventType::TransferDispatched->value,
            InventoryEventType::TransferReceived->value,
        ])->count());
    }

    public function test_stock_cannot_be_received_twice(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $phone = $this->phoneVariant();

        $purchase = $this->createPurchase($owner, $this->unitTwo, [[
            'product_variant_id' => $phone->id,
            'line_type' => 'PHONE',
            'quantity' => 1,
            'unit_cost' => '50000.00',
            'imei_1' => $this->validImei(203),
        ]]);
        $this->receivePurchase($owner, $purchase);

        $unit = SerializedUnit::query()->firstOrFail();
        $transfers = app(StockTransferService::class);

        $request = $transfers->request($this->unitTwo, $this->unitOne, [$unit->id], [], $owner);
        $transfers->dispatch($request, $owner);
        $transfers->receive($request, $owner);

        try {
            $transfers->receive($request, $owner);
            $this->fail('Receiving twice must be rejected.');
        } catch (ErpException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertSame(1, SerializedUnit::query()->where('current_unit_id', $this->unitOne->id)->count());
    }

    public function test_receiving_unit_requires_unit_access(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $unitOneOnly = $this->staff('manager', [$this->unitOne->id]);
        $phone = $this->phoneVariant();

        $unit = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(204));

        $transfers = app(StockTransferService::class);
        $request = $transfers->request($this->unitOne, $this->unitTwo, [$unit->id], [], $owner);
        $transfers->dispatch($request, $owner);

        $this->expectException(UnitAccessDeniedException::class);
        $transfers->receive($request, $unitOneOnly);
    }

    public function test_cancelling_a_request_releases_reservations(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $phone = $this->phoneVariant();
        $accessory = $this->accessoryVariant();

        $purchase = $this->createPurchase($owner, $this->unitTwo, [
            ['product_variant_id' => $phone->id, 'line_type' => 'PHONE', 'quantity' => 1, 'unit_cost' => '50000.00', 'imei_1' => $this->validImei(205)],
            ['product_variant_id' => $accessory->id, 'line_type' => 'ACCESSORY', 'quantity' => 4, 'unit_cost' => '1000.00'],
        ]);
        $this->receivePurchase($owner, $purchase);

        $unit = SerializedUnit::query()->firstOrFail();
        $transfers = app(StockTransferService::class);

        $request = $transfers->request(
            $this->unitTwo,
            $this->unitOne,
            [$unit->id],
            [['product_variant_id' => $accessory->id, 'quantity' => 2]],
            $owner,
        );

        $transfers->cancel($request, $owner, 'Wrong phone selected');

        $unit->refresh();
        $this->assertSame(Custody::InUnit, $unit->current_custody);
        $this->assertSame(Sellability::Available, $unit->sellability_status);
        $this->assertSame(StockTransferStatus::Cancelled, $request->refresh()->status);

        $balance = AccessoryStockBalance::query()
            ->where('product_variant_id', $accessory->id)
            ->where('business_unit_id', $this->unitTwo->id)
            ->firstOrFail();
        $this->assertSame(0, $balance->qty_reserved);
        $this->assertSame(4, $balance->qty_on_hand);
    }

    public function test_phone_from_another_unit_cannot_be_requested(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $phone = $this->phoneVariant();

        $unitInUnitOne = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(206));

        $this->expectException(ErpException::class);

        app(StockTransferService::class)->request($this->unitTwo, $this->unitOne, [$unitInUnitOne->id], [], $owner);
    }

    public function test_dispatched_transfer_cannot_be_cancelled(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $phone = $this->phoneVariant();

        $purchase = $this->createPurchase($owner, $this->unitTwo, [[
            'product_variant_id' => $phone->id,
            'line_type' => 'PHONE',
            'quantity' => 1,
            'unit_cost' => '50000.00',
            'imei_1' => $this->validImei(207),
        ]]);
        $this->receivePurchase($owner, $purchase);

        $transfers = app(StockTransferService::class);
        $request = $transfers->request($this->unitTwo, $this->unitOne, [SerializedUnit::query()->firstOrFail()->id], [], $owner);
        $transfers->dispatch($request, $owner);

        $this->expectException(ErpException::class);
        $transfers->cancel($request, $owner);
    }
}
