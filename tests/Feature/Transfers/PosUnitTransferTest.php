<?php

namespace Tests\Feature\Transfers;

use App\Domain\Transfers\PosUnitTransferService;
use App\Enums\InventoryEventType;
use App\Enums\StockTransferStatus;
use App\Livewire\Pos\PosTerminal;
use App\Livewire\Pos\StockHandoff;
use App\Models\InventoryCycle;
use App\Models\Sale;
use App\Models\SerializedUnit;
use App\Models\Setting;
use App\Models\StockTransfer;
use App\Support\UnitContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PosUnitTransferTest extends TestCase
{
    use BuildsErpScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
        Setting::flushMemo();
    }

    public function test_selected_pos_phone_moves_immediately_and_print_memo_is_available(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->actingAs($owner);
        app(UnitContext::class)->set($owner, $this->unitTwo);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1101));
        $cycle = $phone->activeCycle->id;
        $form = Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $phone->id)->call('openUnitTransfer', $phone->id)->assertSet('transferDestination', (string) $this->unitOne->id)
            ->call('transferSelectedPhone')->assertHasNoErrors()->assertSet('lines', [])->assertSet('unitTransferOpen', false)->assertSee('Print transfer cash memo');
        $transfer = StockTransfer::sole();
        $this->assertSame(StockTransferStatus::Received, $transfer->status);
        $this->assertSame($this->unitOne->id, $phone->refresh()->current_unit_id);
        $this->assertTrue($phone->isSellable());
        $this->assertSame($cycle, $phone->activeCycle->id);
        $this->assertSame(0, Sale::count());
        $this->get(route('transfers.memo', $transfer))->assertOk()->assertSee('UNIT TRANSFER MEMO')->assertSee($phone->primary_imei)->assertSee('Unit 2')->assertSee('Unit 1')->assertSee('Print transfer cash memo');
    }

    public function test_scan_returns_from_previous_destination_to_current_unit_and_links_original(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->actingAs($owner);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1102));
        $cycle = $phone->activeCycle->id;
        $original = app(PosUnitTransferService::class)->move($this->unitTwo->id, $this->unitOne->id, [$phone->id], $owner, (string) Str::uuid());
        Livewire::actingAs($owner)->test(StockHandoff::class, ['unitId' => (string) $this->unitTwo->id])->set('open', true)->set('imei', $phone->primary_imei)->call('lookup')->assertSee('Return direction:')->call('returnUnitPhone')->assertHasNoErrors()->assertDispatched('unit-phone-moved')->assertSee('Print transfer cash memo');
        $return = StockTransfer::latest('id')->first();
        $this->assertSame($original->id, $return->return_of_transfer_id);
        $this->assertSame($this->unitOne->id, $return->from_unit_id);
        $this->assertSame($this->unitTwo->id, $return->to_unit_id);
        $this->assertSame($this->unitTwo->id, $phone->refresh()->current_unit_id);
        $this->assertSame($cycle, $phone->activeCycle->id);
        $this->assertSame(1, InventoryCycle::count());
        $this->assertSame(1, SerializedUnit::count());
        $this->assertSame(2, $phone->events()->where('event_type', InventoryEventType::TransferReceived->value)->count());
        $this->get(route('transfers.memo', $return))->assertOk()->assertSee('RETURN TRANSFER MEMO')->assertSee($original->transfer_no);
    }

    public function test_retry_is_idempotent_and_unrelated_phone_cannot_be_returned(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->actingAs($owner);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1103));
        $key = (string) Str::uuid();
        $service = app(PosUnitTransferService::class);
        $original = $service->move($this->unitTwo->id, $this->unitOne->id, [$phone->id], $owner, $key);
        $this->assertSame($original->id, $service->move($this->unitTwo->id, $this->unitOne->id, [$phone->id], $owner, $key)->id);
        $this->assertSame(1, StockTransfer::count());
        $other = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(1104));
        Livewire::actingAs($owner)->test(StockHandoff::class, ['unitId' => (string) $this->unitTwo->id])->set('imei', $other->primary_imei)->call('returnUnitPhone')->assertHasErrors('delivery');
        $this->assertSame(1, StockTransfer::count());
    }

    public function test_missing_destination_access_cannot_move_or_print_unrelated_memo(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->actingAs($owner);
        $phone = $this->addPhoneToStock($owner, $this->unitTwo, $this->phoneVariant(), $this->validImei(1105));
        $restricted = $this->staff('manager', [$this->unitTwo->id]);
        $this->actingAs($restricted);
        app(UnitContext::class)->set($restricted, $this->unitTwo);
        Livewire::actingAs($restricted)->test(PosTerminal::class)->call('openUnitTransfer', $phone->id)->set('transferDestination',(string) $this->unitOne->id)->call('transferSelectedPhone')->assertForbidden();
        $this->assertSame(0,StockTransfer::count());
        $this->assertSame($this->unitTwo->id,$phone->refresh()->current_unit_id);
    }
}
