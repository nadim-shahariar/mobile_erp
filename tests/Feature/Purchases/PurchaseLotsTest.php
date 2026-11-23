<?php

namespace Tests\Feature\Purchases;

use App\Domain\Purchases\PurchaseLotService;
use App\Domain\Purchases\SupplierPaymentService;
use App\Livewire\Purchases\PurchaseLots;
use App\Livewire\Settings\SettingsPage;
use App\Livewire\Suppliers\SupplierIndex;
use App\Models\Purchase;
use App\Models\PurchaseLot;
use App\Models\SerializedUnit;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PurchaseLotsTest extends TestCase
{
    use BuildsErpScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
        Setting::flushMemo();
    }

    private function order($actor, int $count = 3)
    {
        $variant = $this->phoneVariant();
        $supplier = Supplier::create(['name' => 'Lot supplier', 'type' => 'BUSINESS', 'is_active' => true]);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['product_variant_id' => $variant->id, 'unit_cost' => '10000', 'expected_imei' => $this->validImei(970 + $i), 'pending_reason' => 'Shipment pending'];
        }

        return app(PurchaseLotService::class)->create($supplier->id, $rows, '2026-11-20', 'Test shipment', $actor);
    }

    public function test_lot_form_duplicates_without_copying_imei_and_scanner_selects_pending_phone(): void
    {
        $actor = $this->staff('owner', [$this->unitTwo->id]);
        $variant = $this->phoneVariant();
        $supplier = Supplier::create(['name' => 'Form supplier', 'type' => 'BUSINESS', 'is_active' => true]);
        $form = Livewire::actingAs($actor)->test(PurchaseLots::class);
        $key = array_key_first($form->get('rows'));
        $imei = $this->validImei(995);
        $form->set("rows.$key.product_variant_id", $variant->id)->set("rows.$key.unit_cost", '500')
            ->set("rows.$key.expected_imei", $imei)->call('duplicateLine', $key);
        $rows = $form->get('rows');
        $this->assertCount(2, $rows);
        $this->assertSame('', end($rows)['expected_imei']);
        $form->set('supplierId', (string) $supplier->id)->call('save')->assertHasNoErrors()
            ->set('scan', $imei)->call('scanImei')->assertSet('scanResult', 'PENDING — IMEI '.$imei);
        $lot = PurchaseLot::sole();
        $line = $lot->lines()->where('expected_imei', $imei)->sole();
        $form->assertSet("receipt.$line->id.selected", true);
        $this->assertSame(0, SerializedUnit::count());
    }

    public function test_feature_settings_can_be_disabled_and_reenabled_without_deleting_lots(): void
    {
        $actor = $this->staff('owner', [$this->unitTwo->id]);
        $this->actingAs($actor);
        $lot = $this->order($actor, 1);
        $form = Livewire::actingAs($actor)->test(SettingsPage::class)
            ->set('supplierAccounts', false)->set('purchaseLots', false)->call('save')->assertHasNoErrors();
        Setting::flushMemo();
        $this->assertFalse(Setting::get('features.supplier_accounts', true));
        $this->get(route('purchase-lots'))->assertForbidden();
        Livewire::actingAs($actor)->test(SupplierIndex::class)->assertDontSee('Pay due')->call('openPayment', $lot->supplier_id)->assertForbidden();
        $form->set('supplierAccounts', true)->set('purchaseLots', true)->call('save')->assertHasNoErrors();
        $this->get(route('purchase-lots'))->assertOk()->assertSee($lot->lot_no);
    }

    public function test_partial_receipt_payment_and_cancellation(): void
    {
        $actor = $this->staff('owner', [$this->unitTwo->id]);
        $this->actingAs($actor);
        $lot = $this->order($actor);
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, SerializedUnit::count());
        $lines = $lot->lines;
        $items = [];
        foreach ($lines->take(2) as $line) {
            $items[$line->id] = ['imei' => $line->expected_imei, 'battery_health' => 91];
        }
        $key = (string) Str::uuid();
        $service = app(PurchaseLotService::class);
        $purchase = $service->receive($lot, $items, '5000', 'CASH', $key, $actor);
        $this->assertSame('15000.00', $purchase->due_amount);
        $this->assertSame(2, SerializedUnit::count());
        $this->assertSame($purchase->id, $service->receive($lot, $items, '5000', 'CASH', $key, $actor)->id);
        $this->assertSame(1, Purchase::count());
        $paymentKey = (string) Str::uuid();
        app(SupplierPaymentService::class)->pay($lot->supplier_id, '7000', 'BANK_TRANSFER', $paymentKey, $actor);
        $this->assertSame('8000.00', $purchase->refresh()->due_amount);
        $this->assertSame('12000.00', $purchase->paid_amount);
        app(SupplierPaymentService::class)->pay($lot->supplier_id, '7000', 'BANK_TRANSFER', $paymentKey, $actor);
        $this->assertSame(1, SupplierPayment::count());
        $service->cancel($lot, [$lines->last()->id], 'Supplier could not deliver', $actor);
        $this->assertSame(2, $lot->lines()->where('status', 'RECEIVED')->count());
        $this->assertSame(1, $lot->lines()->where('status', 'CANCELLED')->count());
        Livewire::actingAs($actor)->test(SupplierIndex::class)->assertSee('20,000.00')->assertSee('12,000.00')->assertSee('8,000.00');
        Livewire::actingAs($actor)->test(PurchaseLots::class)->set('search', $lines->first()->expected_imei)->assertSee($lot->lot_no)->call('openLot', $lot->id)->assertSee('RECEIVED')->assertSee('Supplier could not deliver');
    }

    public function test_received_phone_cannot_cancel_or_receive_again(): void
    {
        $actor = $this->staff('owner', [$this->unitTwo->id]);
        $this->actingAs($actor);
        $lot = $this->order($actor, 1);
        $line = $lot->lines->first();
        app(PurchaseLotService::class)->receive($lot, [$line->id => ['imei' => $line->expected_imei]], '10000', 'CASH', (string) Str::uuid(), $actor);
        try {
            app(PurchaseLotService::class)->cancel($lot, [$line->id], 'Cancel', $actor);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame('RECEIVED', $line->refresh()->status);
        }
        $this->expectException(ValidationException::class);
        app(PurchaseLotService::class)->receive($lot, [$line->id => ['imei' => $line->expected_imei]], '0', 'CASH', (string) Str::uuid(), $actor);
    }

    public function test_overpayment_rollback_and_branch_scope(): void
    {
        $actor = $this->staff('owner', [$this->unitTwo->id]);
        $this->actingAs($actor);
        $lot = $this->order($actor, 1);
        $line = $lot->lines->first();
        app(PurchaseLotService::class)->receive($lot, [$line->id => ['imei' => $line->expected_imei]], '0', 'CASH', (string) Str::uuid(), $actor);
        try {
            app(SupplierPaymentService::class)->pay($lot->supplier_id, '10001', 'CASH', (string) Str::uuid(), $actor);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertSame(0, SupplierPayment::count());
            $this->assertSame('10000.00', Purchase::first()->due_amount);
        }
        $other = $this->staff('owner', [$this->unitOne->id]);
        Livewire::actingAs($other)->test(PurchaseLots::class)->assertDontSee($lot->lot_no);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($other)->test(PurchaseLots::class)->call('openLot', $lot->id);
    }

    public function test_unknown_imei_receipt_and_feature_toggle(): void
    {
        $actor = $this->staff('owner', [$this->unitTwo->id]);
        $this->actingAs($actor);
        $supplier = Supplier::create(['name' => 'Unknown IMEI', 'is_active' => true, 'type' => 'BUSINESS']);
        $lot = app(PurchaseLotService::class)->create($supplier->id, [['product_variant_id' => $this->phoneVariant()->id, 'unit_cost' => '100', 'expected_imei' => '']], '2026-11-20', null, $actor);
        $line = $lot->lines->first();
        Livewire::actingAs($actor)->test(PurchaseLots::class)->call('openLot', $lot->id)->set("receipt.$line->id.selected", true)->set("receipt.$line->id.imei", $this->validImei(985))->call('receive')->assertHasNoErrors()->assertSee('RECEIVED');
        Setting::put('features.purchase_lots', false, 'features');
        Livewire::actingAs($actor)->test(PurchaseLots::class)->assertForbidden();
    }
}
