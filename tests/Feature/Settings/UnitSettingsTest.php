<?php

namespace Tests\Feature\Settings;

use App\Livewire\Inventory\PhoneIntake;
use App\Livewire\Pos\PosTerminal;
use App\Livewire\Purchases\PurchaseForm;
use App\Livewire\Settings\SettingsPage;
use App\Livewire\Transfers\TransferForm;
use App\Models\Setting;
use App\Support\UnitContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class UnitSettingsTest extends TestCase
{
    use BuildsErpScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
        Setting::flushMemo();
    }

    public function test_default_and_names_apply_to_new_work_and_existing_sessions(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->actingAs($owner);
        app(UnitContext::class)->set($owner, $this->unitTwo);
        Livewire::actingAs($owner)->test(SettingsPage::class)->set('defaultUnitId', (string) $this->unitOne->id)
            ->set("unitDetails.{$this->unitOne->id}.name", 'Garage main showroom')->set("unitDetails.{$this->unitOne->id}.address", 'Dhaka showroom')->set("unitDetails.{$this->unitOne->id}.phone", '01711112222')
            ->call('save')->assertHasNoErrors();
        $this->assertSame($this->unitOne->id, app(UnitContext::class)->requireCurrent($owner)->id);
        $this->assertSame('Garage main showroom', $this->unitOne->refresh()->name);
        foreach ([PhoneIntake::class, PosTerminal::class, PurchaseForm::class] as $component) {
            Livewire::actingAs($owner)->test($component)->assertSet('unitId', (string) $this->unitOne->id)->assertSee('Garage main showroom');
        }
        Livewire::actingAs($owner)->test(TransferForm::class)->assertSet('fromUnitId', (string) $this->unitOne->id);
        app(UnitContext::class)->set($owner, $this->unitTwo);
        Livewire::actingAs($owner)->test(PhoneIntake::class)->assertSet('unitId', (string) $this->unitTwo->id);
    }

    public function test_inaccessible_default_falls_back_and_inactive_unit_is_not_selected(): void
    {
        $actor = $this->staff('owner', [$this->unitOne->id]);
        $this->actingAs($actor);
        Setting::put('units.default_id', $this->unitTwo->id, 'units');
        $this->assertSame($this->unitOne->id, app(UnitContext::class)->requireCurrent($actor)->id);
        Livewire::actingAs($actor)->test(SettingsPage::class)->set('defaultUnitId', (string) $this->unitTwo->id)->call('save')->assertHasErrors('defaultUnitId');
        $this->unitOne->update(['is_active' => false]);
        $this->assertNull(app(UnitContext::class)->current($actor));
    }

    public function test_unit_names_validate_and_settings_require_permission(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        Livewire::actingAs($owner)->test(SettingsPage::class)->set("unitDetails.{$this->unitOne->id}.name", '')->call('save')->assertHasErrors("unitDetails.{$this->unitOne->id}.name");
        $this->assertSame('Unit 1', $this->unitOne->refresh()->name);
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        $this->actingAs($cashier)->get(route('settings.index'))->assertForbidden();
    }
}
