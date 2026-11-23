<?php

namespace Tests\Feature\Sales;

use App\Domain\Reporting\PosStockSummary;
use App\Domain\Sales\SaleService;
use App\Domain\Transfers\PosUnitTransferService;
use App\Livewire\Pos\PosTerminal;
use App\Models\Setting;
use App\Support\UnitContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PosStockAndTenderTest extends TestCase
{
    use BuildsErpScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
        Setting::flushMemo();
    }

    public function test_exact_buttons_change_selected_method_amount_and_visible_state(): void
    {
        $owner = $this->staff('owner', [$this->unitTwo->id]);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1201));
        $form = Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $phone->id)->set('lines.0.unit_price', '10000')->set('payments.0.amount', '2000')->call('addPayment')->set('payments.1.amount', '1000');
        foreach (['BANK_TRANSFER', 'BKASH', 'CASH'] as $i => $method) {
            $form->call('setExactPayment', $method)->assertHasNoErrors()->assertSet('payments', [['method' => $method, 'amount' => '10000.00', 'reference' => '']])->assertViewHas('activeExactMethod', $method)->assertSet('paymentVersion', $i + 1);
            $form->assertSeeHtml('wire:key="payment-'.($i + 1).'-0"');
        }
        $form->set('payments.0.amount', '3000')->assertViewHas('activeExactMethod', null)->call('addPayment')->set('payments.1.method', 'BKASH')->set('payments.1.amount', '7000')->assertViewHas('activeExactMethod', null);
    }

    public function test_exact_payment_after_emi_uses_cash_price_and_clears_installment_terms(): void
    {
        $owner = $this->staff('owner', [$this->unitTwo->id]);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1202));
        Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $phone->id)->set('lines.0.unit_price', '10000')->set('saleTerms', 'INSTALLMENT')->set('emiRate', '10')->set('payments.0.amount', '2000')
            ->call('setExactPayment', 'BKASH')->assertSet('saleTerms', 'PARTIAL')->assertSet('payments.0.amount', '10000.00')->assertViewHas('activeExactMethod', 'BKASH')->assertSee('bKash transaction ID (optional)')->call('complete')->assertHasNoErrors();
    }

    public function test_stock_counts_show_current_units_and_outgoing_phones_still_at_destination(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->actingAs($owner);
        app(UnitContext::class)->set($owner, $this->unitTwo);
        $variant = $this->phoneVariant();
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $variant, $this->validImei(1203));
        $this->addPhoneToStock($owner, $this->unitTwo, $variant, $this->validImei(1204));
        $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(1205));
        $service = app(PosUnitTransferService::class);
        $transfer = $service->move($this->unitTwo->id, $this->unitOne->id, [$phone->id], $owner, (string) Str::uuid());
        $summary = collect(app(PosStockSummary::class)->forUnit($owner, $this->unitTwo->id))->keyBy('id');
        $this->assertSame(2, $summary[$this->unitOne->id]['stock']);
        $this->assertSame(1, $summary[$this->unitOne->id]['transferred']);
        $this->assertSame(1, $summary[$this->unitTwo->id]['stock']);
        Livewire::actingAs($owner)->test(PosTerminal::class)->assertSee('Phone stock by unit')->assertSee('Unit 2 → Unit 1:');
        $service->move($this->unitOne->id, $this->unitTwo->id, [$phone->id], $owner, (string) Str::uuid(), 'Returned', $transfer->id);
        $summary = collect(app(PosStockSummary::class)->forUnit($owner, $this->unitTwo->id))->keyBy('id');
        $this->assertSame(0, $summary[$this->unitOne->id]['transferred']);
        $this->assertSame(2, $summary[$this->unitTwo->id]['stock']);
    }

    public function test_sold_transferred_phone_and_inaccessible_units_are_excluded(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->actingAs($owner);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1206));
        app(PosUnitTransferService::class)->move($this->unitTwo->id, $this->unitOne->id, [$phone->id], $owner, (string) Str::uuid());
        app(SaleService::class)->post(['business_unit_id' => $this->unitOne->id, 'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $phone->id, 'unit_price' => '10000']], 'payments' => [['method' => 'CASH', 'amount' => '10000']]], $owner);
        $summary = collect(app(PosStockSummary::class)->forUnit($owner, $this->unitTwo->id))->keyBy('id');
        $this->assertSame(0, $summary[$this->unitOne->id]['stock']);
        $this->assertSame(0,$summary[$this->unitOne->id]['transferred']);
        $restricted = $this->staff('cashier',[$this->unitTwo->id]);
        $this->assertCount(1,app(PosStockSummary::class)->forUnit($restricted,$this->unitTwo->id));
    }
}
