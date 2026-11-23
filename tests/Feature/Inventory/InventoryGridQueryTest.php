<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryGridQuery;
use App\Exceptions\UnitAccessDeniedException;
use App\Livewire\Inventory\InventoryGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class InventoryGridQueryTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_grid_only_returns_handsets_the_user_may_see(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $variant = $this->phoneVariant();

        $imeiOne = $this->validImei(301);
        $imeiTwo = $this->validImei(302);
        $unitOne = $this->addPhoneToStock($owner, $this->unitOne, $variant, $imeiOne);
        $this->addPhoneToStock($owner, $this->unitTwo, $variant, $imeiTwo);

        $unitOneManager = $this->staff('manager', [$this->unitOne->id]);
        $grid = app(InventoryGridQuery::class);

        $results = $grid->build($unitOneManager, [])->get();

        $this->assertCount(1, $results);
        $this->assertSame($unitOne->id, $results->first()->id);

        $this->expectException(UnitAccessDeniedException::class);
        $grid->build($unitOneManager, ['unit_id' => $this->unitTwo->id]);
    }

    public function test_grid_searches_by_imei_brand_or_model(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant('Galaxy S23', '256GB', 'Black');
        $imei = $this->validImei(303);

        $this->addPhoneToStock($owner, $this->unitOne, $variant, $imei);

        $grid = app(InventoryGridQuery::class);

        $this->assertCount(1, $grid->build($owner, ['search' => $imei])->get());
        $this->assertCount(1, $grid->build($owner, ['search' => 'Galaxy'])->get());
        $this->assertCount(1, $grid->build($owner, ['search' => 'Apple'])->get());
        $this->assertCount(0, $grid->build($owner, ['search' => 'Nokia'])->get());
    }

    public function test_custody_counts_are_scoped(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(304));

        $counts = app(InventoryGridQuery::class)->countsByCustody($owner, $this->unitOne->id);

        $this->assertSame(1, $counts['sellable']);
        $this->assertSame(1, $counts['IN_UNIT']);
        $this->assertSame(0, $counts['IN_TRANSIT']);
    }

    public function test_cost_column_is_hidden_from_cashiers(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $variant = $this->phoneVariant();
        $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(305));

        $cashier = $this->staff('cashier', [$this->unitOne->id]);

        $this->actingAs($cashier)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertDontSee('Cost basis')
            ->assertDontSee('Accessory stock value');

        $this->actingAs($owner)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('Cost basis');
    }

    public function test_grid_shows_the_shop_sheet_columns(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(307));
        $unit->forceFill(['expected_selling_price' => '61000.00', 'status_note' => 'Box missing'])->save();
        $unit->activeCycle->forceFill(['buying_party_name' => 'Karim Traders'])->save();

        $this->actingAs($owner)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('Buying party')
            ->assertSee('Selling price')
            ->assertSee('Adapter / cable')
            ->assertSee('Karim Traders')
            ->assertSee('61,000.00')
            ->assertSee('Box missing');
    }

    public function test_inline_edit_updates_price_and_status_with_validation(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(308));

        Livewire::actingAs($owner)
            ->test(InventoryGrid::class)
            ->call('startEdit', $unit->id)
            ->set('edit.expected_selling_price', '62500')
            ->set('edit.status_note', 'Screen guard missing')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $unit->refresh();
        $this->assertSame('62500.00', $unit->expected_selling_price);
        $this->assertSame('Screen guard missing', $unit->status_note);
    }

    public function test_adapter_and_cable_bundle_can_be_edited_from_the_grid(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(309));

        Livewire::actingAs($owner)
            ->test(InventoryGrid::class)
            ->call('openExtras', $unit->id)
            ->set('extrasForm.adapter_name', '20W adapter')
            ->set('extrasForm.adapter_price', '1200')
            ->set('extrasForm.cable_name', 'USB-C cable')
            ->set('extrasForm.cable_price', '300')
            ->call('saveExtras')
            ->assertHasNoErrors();

        $unit->refresh()->load('accessories');
        $this->assertCount(2, $unit->accessories);
        $this->assertSame('1200.00', (string) $unit->accessories->firstWhere('accessory_type', 'ADAPTER')->price);
    }

    public function test_bundle_price_without_name_is_rejected(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(310));

        Livewire::actingAs($owner)
            ->test(InventoryGrid::class)
            ->call('openExtras', $unit->id)
            ->set('extrasForm.adapter_price', '1200')
            ->call('saveExtras')
            ->assertHasErrors('extrasForm.adapter_name');

        $this->assertSame(0, $unit->accessories()->count());
    }

    public function test_search_query_parameter_is_applied(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $imei = $this->validImei(306);
        $this->addPhoneToStock($owner, $this->unitOne, $variant, $imei);

        $this->actingAs($owner)
            ->get(route('inventory.index', ['q' => $imei]))
            ->assertOk()
            ->assertSee($imei);
    }

    public function test_user_without_units_cannot_view_inventory(): void
    {
        $user = $this->staff('staff', [$this->unitOne->id]);
        $user->businessUnits()->detach();

        $this->actingAs($user)->get(route('inventory.index'))->assertForbidden();
    }
}
