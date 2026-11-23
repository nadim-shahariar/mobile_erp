<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\AccessoryStockService;
use App\Enums\AccessoryMovementReason;
use App\Enums\Custody;
use App\Enums\InventoryEventType;
use App\Enums\PurchaseStatus;
use App\Enums\Sellability;
use App\Exceptions\ErpException;
use App\Exceptions\InsufficientStockException;
use App\Models\AccessoryStockBalance;
use App\Models\AccessoryStockMovement;
use App\Models\Account;
use App\Models\InventoryCycle;
use App\Models\JournalEntry;
use App\Models\SerializedUnit;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PurchaseReceiptTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_receiving_a_purchase_registers_phones_cycles_events_and_accessory_stock(): void
    {
        $owner = $this->staff('owner', [$this->unitTwo->id]);
        $phone = $this->phoneVariant();
        $accessory = $this->accessoryVariant();
        $imeiA = $this->validImei(101);
        $imeiB = $this->validImei(102);

        $purchase = $this->createPurchase($owner, $this->unitTwo, [
            [
                'product_variant_id' => $phone->id,
                'line_type' => 'PHONE',
                'quantity' => 1,
                'unit_cost' => '50000.00',
                'imei_1' => $imeiA,
                'serial_payload' => ['battery_health' => 96, 'condition_grade' => 'A'],
            ],
            [
                'product_variant_id' => $phone->id,
                'line_type' => 'PHONE',
                'quantity' => 1,
                'unit_cost' => '48000.00',
                'imei_1' => $imeiB,
            ],
            [
                'product_variant_id' => $accessory->id,
                'line_type' => 'ACCESSORY',
                'quantity' => 5,
                'unit_cost' => '1000.00',
            ],
        ], [
            'paid_amount' => '30000.00',
            'discount_total' => '500.00',
            'other_charges' => '200.00',
        ]);

        $this->assertSame(PurchaseStatus::Draft, $purchase->status);
        $this->assertSame('103000.00', $purchase->subtotal);
        $this->assertSame('102700.00', $purchase->total);
        $this->assertSame('72700.00', $purchase->due_amount);
        $this->assertSame(0, SerializedUnit::count());
        $this->assertSame(0, AccessoryStockBalance::count());

        $this->receivePurchase($owner, $purchase);

        $this->assertSame(2, SerializedUnit::count());
        $unitA = SerializedUnit::query()->where('primary_imei', $imeiA)->firstOrFail();

        $this->assertSame($this->unitTwo->id, $unitA->current_unit_id);
        $this->assertSame(Custody::InUnit, $unitA->current_custody);
        $this->assertSame(Sellability::Available, $unitA->sellability_status);
        $this->assertSame(96, $unitA->battery_health);

        $cycle = InventoryCycle::query()->where('serialized_unit_id', $unitA->id)->firstOrFail();
        $this->assertSame('50000.00', $cycle->acquisition_value);
        $this->assertSame('50000.00', $cycle->effective_cost_basis);
        $this->assertSame($this->unitTwo->id, $cycle->initial_unit_id);

        $event = $unitA->lastEvent;
        $this->assertSame(InventoryEventType::PurchaseReceived, $event->event_type);
        $this->assertSame($cycle->id, $event->inventory_cycle_id);
        $this->assertSame($purchase->document_no, $event->reference?->purchase?->document_no ?? $event->metadata['source_label']);

        $balance = AccessoryStockBalance::query()
            ->where('product_variant_id', $accessory->id)
            ->where('business_unit_id', $this->unitTwo->id)
            ->firstOrFail();

        $this->assertSame(5, $balance->qty_on_hand);
        $this->assertSame('1000.0000', $balance->average_cost);
        $this->assertSame(1, AccessoryStockMovement::query()->where('product_variant_id', $accessory->id)->count());

        $this->assertSame(PurchaseStatus::Received, $purchase->refresh()->status);
        $this->assertNotNull($purchase->received_at);
        $this->assertSame($owner->id, $purchase->received_by);
    }

    public function test_sheet_fields_and_bundle_are_propagated_on_receipt(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $imei = $this->validImei(107);

        $purchase = $this->createPurchase($owner, $this->unitOne, [[
            'product_variant_id' => $phone->id,
            'line_type' => 'PHONE',
            'quantity' => 1,
            'unit_cost' => '48000.00',
            'imei_1' => $imei,
            'expected_selling_price' => '60000.00',
            'status_note' => 'No box',
            'received_at' => '2026-02-01',
            'serial_payload' => [
                'adapter_name' => 'Original 20W adapter',
                'adapter_price' => '1500',
                'cable_name' => 'USB-C cable',
                'cable_price' => '350',
                'condition_grade' => 'A',
            ],
        ]], ['buying_party_name' => 'Rahim Mobile Mart']);

        $this->receivePurchase($owner, $purchase);

        $unit = SerializedUnit::query()->where('primary_imei', $imei)->firstOrFail();

        $this->assertSame('60000.00', $unit->expected_selling_price);
        $this->assertSame('No box', $unit->status_note);
        $this->assertSame('Rahim Mobile Mart', $unit->activeCycle->buying_party_name);
        $this->assertSame('2026-02-01', $unit->activeCycle->acquired_at->timezone(config('erp.timezone'))->toDateString());
        $this->assertCount(2, $unit->accessories);
        $this->assertSame('1500.00', (string) $unit->accessories->firstWhere('accessory_type', 'ADAPTER')->price);
        $this->assertSame('350.00', (string) $unit->accessories->firstWhere('accessory_type', 'CABLE')->price);
    }

    public function test_purchase_line_buying_party_overrides_the_document_party(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $imei = $this->validImei(108);

        $purchase = $this->createPurchase($owner, $this->unitOne, [[
            'product_variant_id' => $phone->id,
            'line_type' => 'PHONE',
            'quantity' => 1,
            'unit_cost' => '45000.00',
            'imei_1' => $imei,
            'buying_party_name' => 'Individual Seller',
        ]], ['buying_party_name' => 'Document Party']);

        $this->receivePurchase($owner, $purchase);

        $unit = SerializedUnit::query()->where('primary_imei', $imei)->firstOrFail();
        $this->assertSame('Individual Seller', $unit->activeCycle->buying_party_name);
    }

    public function test_receipt_posts_balanced_inventory_cash_and_payable_journal(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $accessory = $this->accessoryVariant();

        $purchase = $this->createPurchase($owner, $this->unitOne, [
            ['product_variant_id' => $phone->id, 'line_type' => 'PHONE', 'quantity' => 1, 'unit_cost' => '50000.00', 'imei_1' => $this->validImei(109)],
            ['product_variant_id' => $accessory->id, 'line_type' => 'ACCESSORY', 'quantity' => 5, 'unit_cost' => '1000.00'],
        ], ['paid_amount' => '20000.00', 'payment_method' => 'CASH']);

        $this->receivePurchase($owner, $purchase);

        $entry = JournalEntry::query()->where('source_key', 'purchase:'.$purchase->id)->firstOrFail();

        $this->assertSame(
            Money::of((string) $entry->totalDebit())->amount,
            Money::of((string) $entry->totalCredit())->amount,
        );

        $accountDebit = fn (string $code) => (float) $entry->lines()
            ->where('account_id', Account::query()->where('code', $code)->value('id'))
            ->sum('debit');
        $accountCredit = fn (string $code) => (float) $entry->lines()
            ->where('account_id', Account::query()->where('code', $code)->value('id'))
            ->sum('credit');

        $this->assertSame(50000.0, $accountDebit('1200'));
        $this->assertSame(5000.0, $accountDebit('1210'));
        $this->assertSame(20000.0, $accountCredit('CASH-U1'));
        $this->assertSame(35000.0, $accountCredit('2000'));
        $this->assertSame($entry->id, $purchase->refresh()->journal_entry_id);
    }

    public function test_purchase_cannot_be_received_twice(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();

        $purchase = $this->createPurchase($owner, $this->unitOne, [[
            'product_variant_id' => $phone->id,
            'line_type' => 'PHONE',
            'quantity' => 1,
            'unit_cost' => '50000.00',
            'imei_1' => $this->validImei(103),
        ]]);

        $this->receivePurchase($owner, $purchase);

        try {
            $this->receivePurchase($owner, $purchase);
            $this->fail('A second receipt should be rejected.');
        } catch (ErpException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertStringContainsString('already been received', $exception->getMessage());
        }

        $this->assertSame(1, SerializedUnit::count());
        $this->assertSame(1, InventoryCycle::count());
    }

    public function test_a_failed_receipt_rolls_back_every_line(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $existingImei = $this->validImei(104);

        $this->addPhoneToStock($owner, $this->unitOne, $phone, $existingImei);

        $newImei = $this->validImei(105);

        $purchase = $this->createPurchase($owner, $this->unitOne, [
            [
                'product_variant_id' => $phone->id,
                'line_type' => 'PHONE',
                'quantity' => 1,
                'unit_cost' => '40000.00',
                'imei_1' => $newImei,
            ],
            [
                'product_variant_id' => $phone->id,
                'line_type' => 'PHONE',
                'quantity' => 1,
                'unit_cost' => '41000.00',
                'imei_1' => $existingImei,
            ],
        ]);

        try {
            $this->receivePurchase($owner, $purchase);
            $this->fail('Receiving a purchase with a duplicate IMEI should fail.');
        } catch (ErpException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertSame(1, SerializedUnit::count());
        $this->assertSame(1, InventoryCycle::count());
        $this->assertNull(SerializedUnit::query()->where('primary_imei', $newImei)->first());
        $this->assertSame(PurchaseStatus::Draft, $purchase->refresh()->status);
    }

    public function test_accessory_receipts_use_weighted_average_cost(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $accessory = $this->accessoryVariant();
        $stock = app(AccessoryStockService::class);

        $stock->receive($this->unitOne->id, $accessory->id, 5, '100.00', AccessoryMovementReason::PurchaseReceipt, $owner);
        $balance = $stock->receive($this->unitOne->id, $accessory->id, 5, '300.00', AccessoryMovementReason::PurchaseReceipt, $owner);

        $this->assertSame(10, $balance->qty_on_hand);
        $this->assertSame('200.0000', $balance->average_cost);

        $stock->issue($this->unitOne->id, $accessory->id, 4, AccessoryMovementReason::Sale, $owner);

        $this->assertSame(6, $balance->refresh()->qty_on_hand);
        $this->assertSame('200.0000', $balance->average_cost);
    }

    public function test_accessory_stock_cannot_go_negative(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $accessory = $this->accessoryVariant();
        $stock = app(AccessoryStockService::class);

        $this->expectException(InsufficientStockException::class);

        $stock->issue($this->unitOne->id, $accessory->id, 1, AccessoryMovementReason::Sale, $owner);
    }

    public function test_purchase_with_negative_total_is_rejected(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();

        $this->expectException(ErpException::class);

        $this->createPurchase($owner, $this->unitOne, [[
            'product_variant_id' => $phone->id,
            'line_type' => 'PHONE',
            'quantity' => 1,
            'unit_cost' => '1000.00',
            'imei_1' => $this->validImei(106),
        ]], ['discount_total' => '2000.00']);
    }
}
