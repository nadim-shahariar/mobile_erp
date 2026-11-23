<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryCycleService;
use App\Domain\Inventory\StockStateMachine;
use App\Enums\Custody;
use App\Enums\CycleCloseReason;
use App\Enums\ImeiStatus;
use App\Enums\InventoryEventType;
use App\Enums\ProductType;
use App\Enums\Sellability;
use App\Livewire\Inventory\PhoneIntake;
use App\Models\AccessoryStockBalance;
use App\Models\Brand;
use App\Models\InventoryCycle;
use App\Models\InventoryEvent;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\Money;
use App\Support\UnitContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PhoneIntakeTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    private function sellForRestock(User $owner, SerializedUnit $unit): void
    {
        app(StockStateMachine::class)->transition(
            unit: $unit, toCustody: Custody::WithCustomer, toUnitId: null,
            eventType: InventoryEventType::Sold, actor: $owner, toSellability: Sellability::Sold,
        );
        app(InventoryCycleService::class)->close(
            $unit->activeCycle()->firstOrFail(), CycleCloseReason::Sold, $owner,
        );
    }

    public function test_existing_imei_loads_details_and_restocks_same_identity_with_new_cycle(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $imei = $this->validImei(111);
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $variant, $imei);
        $unit->update(['battery_health' => 95]);
        $this->sellForRestock($owner, $unit);
        $oldCycle = $unit->cycles()->firstOrFail()->getRawOriginal();
        $journalCount = JournalEntry::count();
        $component = Livewire::actingAs($owner)->test(PhoneIntake::class);
        $key = array_key_first($component->get('phoneRows'));
        $component->set("phoneRows.{$key}.primaryImei", $imei)
            ->assertSet("phoneRows.{$key}.modelName", $variant->product->name)
            ->assertSet("phoneRows.{$key}.batteryHealth", '95')
            ->assertSee('Previously registered handset')->assertSee('Restock line')
            ->set("phoneRows.{$key}.batteryHealth", '88')
            ->set("phoneRows.{$key}.acquisitionCost", '25000')
            ->call('restockLine', $key)->assertHasNoErrors();
        $this->assertSame(1, SerializedUnit::count());
        $unit->refresh();
        $this->assertSame(88, $unit->battery_health);
        $this->assertSame(Custody::InUnit, $unit->current_custody);
        $this->assertSame(2, $unit->activeCycle->cycle_no);
        $this->assertSame('25000.00', $unit->activeCycle->acquisition_value);
        $this->assertSame($oldCycle, $unit->cycles()->where('cycle_no', 1)->firstOrFail()->getRawOriginal());
        $this->assertSame($journalCount + 1, JournalEntry::count());
        $event = $unit->events()->where('note', 'Restock inspection: returning handset details updated')->firstOrFail();
        $this->assertSame(95, $event->metadata['restock_condition']['before']['battery_health']);
        $this->assertSame(88, $event->metadata['restock_condition']['after']['battery_health']);
        $this->actingAs($owner)->get(route('imei.history', $imei))->assertOk()->assertSee('95')->assertSee('88');
    }

    public function test_later_batch_failure_rolls_back_restock_condition_cycle_and_journal(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(114));
        $unit->update(['battery_health' => 90]);
        $this->sellForRestock($owner, $unit);
        $journalCount = JournalEntry::count();
        $component = Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->set('phoneRows', ['restock' => $this->phoneRow(''), 'bad' => $this->phoneRow('123456789012345')]);
        $component->set('phoneRows.restock.primaryImei', $unit->primary_imei)
            ->set('phoneRows.restock.restock', true)
            ->set('phoneRows.restock.batteryHealth', '80')
            ->set('phoneRows.restock.acquisitionCost', '10000')
            ->call('saveRows')->assertHasErrors('phoneRows.bad.primaryImei');
        $this->assertSame(90, $unit->refresh()->battery_health);
        $this->assertSame(Custody::WithCustomer, $unit->current_custody);
        $this->assertSame(1, $unit->cycles()->count());
        $this->assertSame($journalCount, JournalEntry::count());
    }

    public function test_restock_requires_explicit_selection_and_rejects_active_stock(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(112));
        $component = Livewire::actingAs($owner)->test(PhoneIntake::class);
        $key = array_key_first($component->get('phoneRows'));
        $component->set("phoneRows.{$key}.primaryImei", $unit->primary_imei)
            ->set("phoneRows.{$key}.acquisitionCost", '10000')
            ->call('saveRows')->assertHasErrors("phoneRows.{$key}.primaryImei")
            ->call('restockLine', $key)->assertHasErrors("phoneRows.{$key}.primaryImei");
        $this->assertSame(1, $unit->cycles()->count());
        $this->assertSame(1, SerializedUnit::count());
    }

    public function test_restock_line_preserves_other_unsaved_rows_and_secondary_imei_lookup(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(113));
        $this->sellForRestock($owner, $unit);
        $component = Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->set('phoneRows', ['restock' => $this->phoneRow(''), 'draft' => $this->phoneRow('unfinished')]);
        $component->set('phoneRows.restock.secondaryImei', $unit->primary_imei)
            ->assertSet('phoneRows.restock.primaryImei', $unit->primary_imei)
            ->set('phoneRows.restock.acquisitionCost', '20000')
            ->call('restockLine', 'restock')->assertHasNoErrors()
            ->assertSet('phoneRows.draft.primaryImei', 'unfinished');
        $this->assertCount(1, $component->get('phoneRows'));
        $this->assertSame(2, $unit->cycles()->count());
    }

    private function phoneRow(string $imei): array
    {
        return [
            'unitId' => (string) $this->unitOne->id, 'brandName' => 'Row Brand',
            'modelName' => 'Row Model', 'country' => 'Japan', 'colour' => 'Black',
            'primaryImei' => $imei, 'batteryHealth' => '95', 'acquisitionCost' => '30000',
            'expectedSellingPrice' => '35000', 'acquiredAt' => '2026-11-20',
        ];
    }

    public function test_multiple_phone_rows_save_together(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->set('phoneRows', ['first' => $this->phoneRow($this->validImei(101)), 'second' => $this->phoneRow($this->validImei(102))])
            ->call('saveRows')->assertHasNoErrors();
        $this->assertSame(2, SerializedUnit::count());
        $this->assertSame(2, InventoryCycle::count());
        $this->assertSame(1, Product::where('name', 'Row Model')->count());
    }

    public function test_duplicate_line_clears_imeis_and_keeps_product_prices_and_battery(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $row = $this->phoneRow($this->validImei(103));
        $row['secondaryImei'] = $this->validImei(104);
        $component = Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->set('phoneRows', ['source' => $row])->call('duplicateLine', 'source');
        $rows = array_values($component->get('phoneRows'));
        $this->assertCount(2, $rows);
        $this->assertSame('', $rows[1]['primaryImei']);
        $this->assertNull($rows[1]['secondaryImei']);
        $this->assertSame('Row Model', $rows[1]['modelName']);
        $this->assertSame('30000', $rows[1]['acquisitionCost']);
        $this->assertSame('95', $rows[1]['batteryHealth']);
        $component->set('selectedRows', ['source'])->call('removeSelectedLines');
        $this->assertCount(1, $component->get('phoneRows'));
        $this->assertArrayNotHasKey('source', $component->get('phoneRows'));
    }

    public function test_invalid_second_row_rolls_back_entire_batch_and_preserves_inputs(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->set('phoneRows', ['first' => $this->phoneRow($this->validImei(105)), 'second' => $this->phoneRow('123456789012345')])
            ->call('saveRows')->assertHasErrors('phoneRows.second.primaryImei')
            ->assertSet('phoneRows.first.primaryImei', $this->validImei(105));
        $this->assertSame(0, SerializedUnit::count());
        $this->assertSame(0, InventoryCycle::count());
        $this->assertDatabaseMissing('products', ['name' => 'Row Model']);
    }

    public function test_duplicate_imei_inside_batch_is_rejected_and_rolled_back(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $row = $this->phoneRow($this->validImei(106));
        Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->set('phoneRows', ['first' => $row, 'second' => $row])
            ->call('saveRows')->assertHasErrors('phoneRows.second.primaryImei');
        $this->assertSame(0, SerializedUnit::count());
    }

    public function test_product_rows_lookup_sku_and_add_multiple_stock_lines(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->accessoryVariant();
        $component = Livewire::actingAs($owner)->test(PhoneIntake::class)->set('mode', 'product');
        $key = array_key_first($component->get('productRows'));
        $component->set("productRows.{$key}.productSku", $variant->sku)
            ->assertSet("productRows.{$key}.productName", $variant->product->name)
            ->set("productRows.{$key}.productBuyingPrice", '100')
            ->set("productRows.{$key}.productStock", '2')
            ->call('duplicateLine', $key)->call('saveRows')->assertHasNoErrors();
        $this->assertDatabaseHas('accessory_stock_balances', [
            'business_unit_id' => $this->unitOne->id, 'product_variant_id' => $variant->id, 'qty_on_hand' => 4,
        ]);
    }

    public function test_product_batch_failure_rolls_back_new_product_and_stock(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $row = ['unitId' => (string) $this->unitOne->id, 'productSku' => 'BATCH-CABLE',
            'productName' => 'Batch cable', 'productBuyingPrice' => '100', 'productStock' => '3'];
        Livewire::actingAs($owner)->test(PhoneIntake::class)->set('mode', 'product')
            ->set('productRows', ['first' => $row, 'second' => array_merge($row, ['productStock' => '0'])])
            ->call('saveRows')->assertHasErrors('productRows.second.productStock');
        $this->assertDatabaseMissing('products', ['name' => 'Batch cable']);
        $this->assertSame(0, AccessoryStockBalance::count());
    }

    public function test_receiving_respects_explicit_session_unit(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->unitTwo->update(['code' => 'U2']);
        $this->withSession([UnitContext::SESSION_KEY => $this->unitOne->id]);

        Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->assertSet('unitId', (string) $this->unitOne->id);
    }

    public function test_country_variants_are_distinct_reused_and_suggested(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        foreach (['Japan', 'Canada', 'canada'] as $index => $country) {
            Livewire::actingAs($owner)->test(PhoneIntake::class)
                ->set('variantId', (string) $variant->id)
                ->set('country', $country)
                ->set('primaryImei', $this->validImei(94 + $index))
                ->set('acquisitionCost', '20000')
                ->call('save')->assertHasNoErrors();
        }
        $japan = SerializedUnit::where('primary_imei', $this->validImei(94))->sole();
        $canada = SerializedUnit::where('primary_imei', $this->validImei(95))->sole();
        $repeat = SerializedUnit::where('primary_imei', $this->validImei(96))->sole();
        $this->assertSame('Japan', $japan->variant->country);
        $this->assertSame('Canada', $canada->variant->country);
        $this->assertNotSame($japan->product_variant_id, $canada->product_variant_id);
        $this->assertSame($canada->product_variant_id, $repeat->product_variant_id);
        $this->assertNull($variant->refresh()->country);
        $this->assertStringContainsString('Canada', $canada->variant->displayName());
        Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->assertViewHas('countries', fn ($countries) => $countries->contains('Canada'));
    }

    public function test_brand_and_model_can_be_created_and_reused_from_the_entry_row(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        foreach ([92, 93] as $seed) {
            Livewire::actingAs($owner)->test(PhoneIntake::class)
                ->set('brandName', 'Example Brand')
                ->set('modelName', 'Model X100')
                ->set('colour', 'Blue')->set('storage', '256GB')
                ->set('primaryImei', $this->validImei($seed))
                ->set('acquisitionCost', '20000')
                ->call('save')->assertHasNoErrors();
        }
        $this->assertSame(1, Brand::where('name', 'Example Brand')->count());
        $model = Product::where('name', 'Model X100')->sole();
        $this->assertSame('Example Brand', $model->brand->name);
        $this->assertSame(1, $model->variants()->count());
        $this->assertSame(2, SerializedUnit::count());
    }

    public function test_invalid_imei_does_not_leave_new_brand_or_model(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->set('brandName', 'Unsaved Brand')->set('modelName', 'Unsaved Model')
            ->set('primaryImei', '123456789012345')->set('acquisitionCost', '100')
            ->call('save')->assertHasErrors('primary_imei');
        $this->assertDatabaseMissing('brands', ['name' => 'Unsaved Brand']);
        $this->assertDatabaseMissing('products', ['name' => 'Unsaved Model']);
    }

    public function test_row_saves_new_supplier_specs_and_category_without_changing_original_variant(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $originalColour = $variant->color;
        $imei = $this->validImei(91);

        Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', $imei)
            ->set('buyingParty', 'New Supplier')
            ->set('colour', 'Sunset Orange')
            ->set('storage', '512GB')
            ->set('ram', '12GB')
            ->set('category', 'NEW')
            ->set('acquisitionCost', '40000')
            ->call('save')->assertHasNoErrors();

        $unit = SerializedUnit::where('primary_imei', $imei)->firstOrFail();
        $this->assertSame('NEW', $unit->inventory_category);
        $this->assertNotNull($unit->supplier_id);
        $this->assertDatabaseHas('suppliers', ['id' => $unit->supplier_id, 'name' => 'New Supplier']);
        $this->assertSame('Sunset Orange', $unit->variant->color);
        $this->assertSame('512GB', $unit->variant->storage);
        $this->assertSame('12GB', $unit->variant->ram);
        $this->assertSame($originalColour, $variant->refresh()->color);
        $this->assertNull($unit->battery_health);
        $this->assertNull($unit->cycle_count);
    }

    public function test_failed_identity_rolls_back_new_supplier_and_variant(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $count = ProductVariant::count();

        Livewire::actingAs($owner)->test(PhoneIntake::class)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', '123456789012345')
            ->set('buyingParty', 'Unsaved Supplier')
            ->set('colour', 'Unsaved Colour')
            ->set('acquisitionCost', '40000')
            ->call('save')->assertHasErrors('primary_imei');

        $this->assertDatabaseMissing('suppliers', ['name' => 'Unsaved Supplier']);
        $this->assertSame($count, ProductVariant::count());
    }

    public function test_adding_a_phone_creates_an_identity_open_cycle_and_event(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $imei = $this->validImei(11);

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', $imei)
            ->set('acquisitionCost', '52000.50')
            ->set('batteryHealth', '97')
            ->set('conditionGrade', 'A')
            ->call('save')
            ->assertHasNoErrors();

        $unit = SerializedUnit::query()->where('primary_imei', $imei)->firstOrFail();

        $this->assertSame($this->unitOne->id, $unit->current_unit_id);
        $this->assertSame(Custody::InUnit, $unit->current_custody);
        $this->assertSame(Sellability::Available, $unit->sellability_status);
        $this->assertSame(97, $unit->battery_health);

        $cycle = InventoryCycle::query()->where('serialized_unit_id', $unit->id)->firstOrFail();
        $this->assertSame(1, $cycle->cycle_no);
        $this->assertSame('52000.50', $cycle->acquisition_value);
        $this->assertSame('52000.50', $cycle->effective_cost_basis);
        $this->assertNull($cycle->closed_at);
        $this->assertSame(1, $cycle->active_cycle_guard);

        $event = InventoryEvent::query()->where('serialized_unit_id', $unit->id)->firstOrFail();
        $this->assertSame(InventoryEventType::Adjustment, $event->event_type);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($event->id, $unit->last_event_id);
    }

    public function test_sheet_row_fields_are_stored_with_the_handset(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $imei = $this->validImei(16);

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', $imei)
            ->set('acquisitionCost', '50000')
            ->set('buyingParty', 'Karim Traders')
            ->set('expectedSellingPrice', '61000')
            ->set('statusNote', 'Box slightly damaged')
            ->set('adapterName', '20W USB-C adapter')
            ->set('adapterPrice', '1200')
            ->set('cableName', 'USB-C cable')
            ->set('cablePrice', '300')
            ->call('save')
            ->assertHasNoErrors();

        $unit = SerializedUnit::query()->where('primary_imei', $imei)->firstOrFail();

        $this->assertSame('61000.00', $unit->expected_selling_price);
        $this->assertSame('Box slightly damaged', $unit->status_note);
        $this->assertSame('Karim Traders', $unit->activeCycle->buying_party_name);
        $this->assertCount(2, $unit->accessories);
        $this->assertSame('1200.00', (string) $unit->accessories->firstWhere('accessory_type', 'ADAPTER')->price);
        $this->assertSame('300.00', (string) $unit->accessories->firstWhere('accessory_type', 'CABLE')->price);
    }

    public function test_adapter_price_requires_an_adapter_name(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', $this->validImei(17))
            ->set('acquisitionCost', '50000')
            ->set('adapterPrice', '1200')
            ->call('save')
            ->assertHasErrors('adapter_name');

        $this->assertSame(0, SerializedUnit::query()->count());
    }

    public function test_product_mode_creates_an_accessory_and_adds_stock(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('mode', 'product')
            ->set('unitId', (string) $this->unitOne->id)
            ->set('productName', 'Tempered Glass 9H')
            ->set('productSku', 'ACC-GLASS-9H')
            ->set('productBuyingPrice', '50')
            ->set('productSellingPrice', '150')
            ->set('productStock', '20')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->where('name', 'Tempered Glass 9H')->firstOrFail();
        $this->assertSame(ProductType::Accessory, $product->product_type);

        $variant = $product->variants()->firstOrFail();
        $this->assertSame('ACC-GLASS-9H', $variant->sku);
        $this->assertSame('50.00', $variant->default_purchase_price);
        $this->assertSame('150.00', $variant->default_selling_price);

        $balance = AccessoryStockBalance::query()
            ->where('business_unit_id', $this->unitOne->id)
            ->where('product_variant_id', $variant->id)
            ->firstOrFail();
        $this->assertSame(20, $balance->qty_on_hand);

        $entry = JournalEntry::query()->latest('id')->firstOrFail();
        $this->assertSame(
            Money::of((string) $entry->totalDebit())->amount,
            Money::of((string) $entry->totalCredit())->amount,
        );
        $this->assertSame('1000.00', Money::of((string) $entry->totalDebit())->amount);
    }

    public function test_product_mode_adds_stock_to_an_existing_sku_without_duplicating_the_product(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $existing = $this->accessoryVariant();

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('mode', 'product')
            ->set('unitId', (string) $this->unitOne->id)
            ->set('productSku', (string) $existing->sku)
            ->set('productName', 'Ignored existing name')
            ->set('productBuyingPrice', '1200')
            ->set('productStock', '5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Product::query()->whereHas('variants', fn ($query) => $query->where('sku', $existing->sku))->count());

        $balance = AccessoryStockBalance::query()
            ->where('business_unit_id', $this->unitOne->id)
            ->where('product_variant_id', $existing->id)
            ->firstOrFail();
        $this->assertSame(5, $balance->qty_on_hand);
    }

    public function test_product_mode_rejects_a_phone_sku(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('mode', 'product')
            ->set('unitId', (string) $this->unitOne->id)
            ->set('productSku', (string) $phone->sku)
            ->set('productName', 'Trying to add a phone as accessory')
            ->set('productBuyingPrice', '100')
            ->set('productStock', '2')
            ->call('save')
            ->assertHasErrors('productSku');
    }

    public function test_product_mode_requires_positive_stock(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('mode', 'product')
            ->set('unitId', (string) $this->unitOne->id)
            ->set('productName', 'Cable')
            ->set('productSku', 'ACC-CABLE-X')
            ->set('productBuyingPrice', '100')
            ->set('productStock', '0')
            ->call('save')
            ->assertHasErrors('productStock');

        $this->assertSame(0, Product::query()->count());
    }

    public function test_duplicate_imei_is_rejected_across_both_slots(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $imei = $this->validImei(12);

        $this->addPhoneToStock($owner, $this->unitOne, $variant, $imei);

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', $imei)
            ->set('acquisitionCost', '1000')
            ->call('save')
            ->assertHasErrors('primary_imei');

        $this->assertSame(1, SerializedUnit::query()->where('primary_imei', $imei)->count());
    }

    public function test_secondary_slot_cannot_reuse_another_handsets_primary_imei(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $existing = $this->validImei(13);

        $this->addPhoneToStock($owner, $this->unitOne, $variant, $existing);

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', $this->validImei(14))
            ->set('secondaryImei', $existing)
            ->set('acquisitionCost', '1000')
            ->call('save')
            ->assertHasErrors('secondary_imei');
    }

    public function test_invalid_luhn_is_rejected(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', '123456789012345')
            ->set('acquisitionCost', '1000')
            ->call('save')
            ->assertHasErrors('primary_imei');

        $this->assertSame(0, SerializedUnit::count());
    }

    public function test_legacy_flagging_requires_permission_and_reason(): void
    {
        $staff = $this->staff('staff', [$this->unitOne->id]);
        $variant = $this->phoneVariant();

        Livewire::actingAs($staff)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', 'LEGACY-001')
            ->set('allowLegacy', true)
            ->set('flagReason', 'Imported from paper ledger')
            ->set('acquisitionCost', '1000')
            ->call('save')
            ->assertHasErrors('primary_imei');

        $this->assertSame(0, SerializedUnit::count());
    }

    public function test_owners_can_register_flagged_legacy_identities_with_a_reason(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', 'LEGACY-001')
            ->set('allowLegacy', true)
            ->set('flagReason', 'Imported from paper ledger, IMEI label faded')
            ->set('acquisitionCost', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $unit = SerializedUnit::query()->firstOrFail();
        $this->assertSame(ImeiStatus::LegacyUnverified, $unit->imei_status);
        $this->assertSame('Imported from paper ledger, IMEI label faded', $unit->imei_flag_reason);
    }

    public function test_internal_serial_is_generated_only_on_explicit_confirmation(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('variantId', (string) $variant->id)
            ->set('generateInternalSerial', true)
            ->set('flagReason', 'Device has no IMEI label')
            ->set('acquisitionCost', '9000')
            ->call('save')
            ->assertHasNoErrors();

        $unit = SerializedUnit::query()->firstOrFail();
        $this->assertSame(ImeiStatus::InternalSerial, $unit->imei_status);
        $this->assertStringStartsWith('SN-', (string) $unit->primary_imei);
    }

    public function test_user_cannot_receive_into_a_unit_they_do_not_belong_to(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();

        Livewire::actingAs($owner)
            ->test(PhoneIntake::class)
            ->set('unitId', (string) $this->unitTwo->id)
            ->set('variantId', (string) $variant->id)
            ->set('primaryImei', $this->validImei(15))
            ->set('acquisitionCost', '1000')
            ->call('save')
            ->assertHasErrors('unitId');

        $this->assertSame(0, SerializedUnit::count());
    }

    public function test_cashier_cannot_open_the_add_phone_screen(): void
    {
        $cashier = $this->staff('cashier', [$this->unitOne->id]);

        $this->actingAs($cashier)->get(route('inventory.create'))->assertForbidden();
    }
}
