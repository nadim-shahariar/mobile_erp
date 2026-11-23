<?php

namespace Tests\Feature\Repairs;

use App\Domain\Repairs\PhoneRepairService;
use App\Domain\Reporting\PosStockSummary;
use App\Domain\Sales\SaleService;
use App\Enums\Custody;
use App\Livewire\Pos\PosTerminal;
use App\Livewire\Pos\RepairDesk;
use App\Models\InventoryCycle;
use App\Models\JournalEntry;
use App\Models\PhoneRepair;
use App\Models\Setting;
use App\Support\Money;
use App\Support\UnitContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PosRepairTest extends TestCase
{
    use BuildsErpScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
        Setting::flushMemo();
    }

    public function test_pos_sends_phone_to_repair_and_scan_receives_cost_on_same_cycle(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->actingAs($owner);
        app(UnitContext::class)->set($owner, $this->unitTwo);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1301));
        $cycle = $phone->activeCycle;
        $oldCost = $cycle->effective_cost_basis;
        $pos = Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $phone->id)->assertSee('Send to repair');
        $desk = Livewire::actingAs($owner)->test(RepairDesk::class, ['unitId' => (string) $this->unitTwo->id])
            ->dispatch('open-phone-repair', phoneId: $phone->id)->assertSet('sendOpen', true)->set('vendor', 'Repair shop A')->set('notes', 'Replace screen')->call('sendPhone')->assertHasNoErrors()->assertDispatched('unit-phone-moved')->assertSet('sendOpen', false)->assertSee('In repair: 1');
        $this->assertSame(Custody::AtRepairVendor, $phone->refresh()->current_custody);
        $this->assertNull($phone->current_unit_id);
        $this->assertFalse($phone->isSellable());
        $pos->dispatch('unit-phone-moved', phoneId: $phone->id)->assertSet('lines', []);
        $summary = collect(app(PosStockSummary::class)->forUnit($owner, $this->unitTwo->id))->keyBy('id');
        $this->assertSame(0, $summary[$this->unitTwo->id]['stock']);
        $this->assertSame(1, $summary[$this->unitTwo->id]['repair']);
        $repair = PhoneRepair::sole();
        $desk->call('openRepairs', $this->unitTwo->id)->assertSee($phone->primary_imei)->assertSee('Repair shop A')->set('scan', $phone->primary_imei)->call('scanReturn')->assertSet('returnId', $repair->id)
            ->set('cost', '1500')->set('method', 'BKASH')->call('receivePhone')->assertHasNoErrors()->assertSet('returnId', null)->assertSee('In repair: 0');
        $this->assertTrue($phone->refresh()->isSellable());
        $this->assertSame($this->unitTwo->id, $phone->current_unit_id);
        $this->assertSame(1, InventoryCycle::count());
        $this->assertSame(Money::of($oldCost)->add('1500')->amount, $cycle->refresh()->effective_cost_basis);
        $this->assertSame('1500.00', $cycle->allocated_direct_cost);
        $this->assertSame('1500.00', $repair->refresh()->cost);
        $this->assertSame('RECEIVED', $repair->status);
        $entry = JournalEntry::where('source_key', 'repair:'.$repair->id.':cost')->sole();
        $this->assertSame('1500.00', Money::of($entry->totalDebit())->amount);
        $this->assertSame('1500.00', Money::of($entry->totalCredit())->amount);
        app(PhoneRepairService::class)->receive($repair->id, '1500', 'BKASH', $owner);
        $this->assertSame(1, JournalEntry::where('source_key', 'repair:'.$repair->id.':cost')->count());
        $this->assertSame(Money::of($oldCost)->add('1500')->amount, $cycle->refresh()->effective_cost_basis);
        $sale = app(SaleService::class)->post(['business_unit_id' => $this->unitTwo->id, 'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $phone->id, 'unit_price' => '30000']], 'payments' => [['method' => 'CASH', 'amount' => '30000']]], $owner);
        $this->assertSame($cycle->effective_cost_basis, $sale->items->first()->unit_cost);
    }

    public function test_send_is_idempotent_free_return_and_repeat_repair_are_supported(): void
    {
        $owner = $this->staff('owner', [$this->unitTwo->id]);
        $this->actingAs($owner);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1302));
        $service = app(PhoneRepairService::class);
        $key = (string) Str::uuid();
        $repair = $service->send($phone->id, $this->unitTwo->id, $owner, $key);
        $this->assertSame($repair->id, $service->send($phone->id, $this->unitTwo->id, $owner, $key)->id);
        $this->assertSame(1, PhoneRepair::count());
        $service->receive($repair->id, '0', 'CASH', $owner);
        $this->assertSame(0, JournalEntry::where('source_key', 'repair:'.$repair->id.':cost')->count());
        $second = $service->send($phone->id, $this->unitTwo->id, $owner, (string) Str::uuid());
        $this->assertNotSame($repair->id, $second->id);
        $this->assertSame(1, PhoneRepair::where('status', 'OUT')->count());
    }

    public function test_unauthorized_unit_and_invalid_cost_do_not_change_stock_or_cost(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->actingAs($owner);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1303));
        $repair = app(PhoneRepairService::class)->send($phone->id, $this->unitTwo->id, $owner, (string) Str::uuid());
        Livewire::actingAs($owner)->test(RepairDesk::class, ['unitId' => (string) $this->unitTwo->id])->call('openRepairs', $this->unitTwo->id)->call('chooseReturn', $repair->id)->set('cost', '-1')->call('receivePhone')->assertHasErrors('cost');
        $this->assertSame('OUT', $repair->refresh()->status);
        $this->assertSame(Custody::AtRepairVendor, $phone->refresh()->current_custody);
        $other = $this->staff('manager', [$this->unitOne->id]);
        Livewire::actingAs($other)->test(RepairDesk::class, ['unitId' => (string) $this->unitOne->id])->call('openRepairs', $this->unitTwo->id)->assertForbidden();
        $cashier = $this->staff('cashier',[$this->unitTwo->id]);
        Livewire::actingAs($cashier)->test(RepairDesk::class,['unitId' => (string) $this->unitTwo->id])->call('openRepairs',$this->unitTwo->id)->call('chooseReturn',$repair->id)->assertForbidden();
    }
}
