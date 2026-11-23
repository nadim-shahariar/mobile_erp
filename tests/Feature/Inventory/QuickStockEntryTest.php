<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Livewire\Inventory\QuickStockEntry;
use App\Livewire\Pos\PosTerminal;
use App\Models\AccessoryStockBalance;
use App\Models\ProductVariant;
use App\Models\SerializedUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class QuickStockEntryTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_quick_entry_opens_inside_pos_and_keeps_cart_and_payments_when_closed(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(951));
        $component = Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $phone->id)
            ->set('payments.0.amount', '1234')->set('notes', 'Keep this sale draft')
            ->call('openStockEntry', 'phone')->assertSet('stockEntryOpen', true)->assertSee('Quick stock entry')
            ->assertSeeLivewire(QuickStockEntry::class)
            ->dispatch('stock-entry-saved')->assertSet('payments.0.amount', '1234')
            ->assertSet('lines.0.reference_id', $phone->id)->call('closeStockEntry')->assertSet('stockEntryOpen', false)
            ->assertSet('notes', 'Keep this sale draft')->assertSet('lines.0.reference_id', $phone->id)
            ->call('openStockEntry', 'product')->assertSet('stockEntryMode', 'product')->assertSee('SKU / barcode');
    }

    public function test_accessory_can_be_saved_from_quick_entry_in_the_pos_branch_without_redirect(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $component = Livewire::actingAs($owner)->test(QuickStockEntry::class, ['entryMode' => 'product', 'receivingUnit' => (string) $this->unitOne->id])
            ->assertSet('mode', 'product')->assertSet('unitId', (string) $this->unitOne->id)->assertDontSee('Back to inventory');
        $key = array_key_first($component->get('productRows'));
        $component->assertSet("productRows.{$key}.unitId", (string) $this->unitOne->id)
            ->set("productRows.{$key}.productName", 'Quick cable')->set("productRows.{$key}.productSku", 'QUICK-CABLE')
            ->set("productRows.{$key}.productBuyingPrice", '100')->set("productRows.{$key}.productSellingPrice", '200')
            ->set("productRows.{$key}.productStock", '3')->call('saveRows')->assertHasNoErrors()
            ->assertDispatched('stock-entry-saved')->assertNoRedirect();
        $variant = ProductVariant::where('sku', 'QUICK-CABLE')->sole();
        $this->assertDatabaseHas('accessory_stock_balances', ['business_unit_id' => $this->unitOne->id, 'product_variant_id' => $variant->id, 'qty_on_hand' => 3]);
        $this->assertSame(1, AccessoryStockBalance::count());
    }

    public function test_phone_quick_entry_saves_and_validation_failure_keeps_the_row_open(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $component = Livewire::actingAs($owner)->test(QuickStockEntry::class, ['entryMode' => 'phone', 'receivingUnit' => (string) $this->unitOne->id]);
        $key = array_key_first($component->get('phoneRows'));
        $component->set("phoneRows.{$key}.brandName", $variant->product->brand->name)->set("phoneRows.{$key}.modelName", $variant->product->name)
            ->set("phoneRows.{$key}.primaryImei", '123456789012345')->set("phoneRows.{$key}.acquisitionCost", '10000')
            ->call('saveRows')->assertHasErrors("phoneRows.{$key}.primaryImei")->assertNotDispatched('stock-entry-saved')
            ->assertSet("phoneRows.{$key}.acquisitionCost", '10000')
            ->set("phoneRows.{$key}.primaryImei", $this->validImei(952))
            ->call('saveRows')->assertHasNoErrors()->assertDispatched('stock-entry-saved')->assertNoRedirect();
        $this->assertSame(1, SerializedUnit::count());
    }

    public function test_quick_stock_entry_enforces_permissions_and_branch_access(): void
    {
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        Livewire::actingAs($cashier)->test(PosTerminal::class)->call('openStockEntry', 'phone')->assertForbidden();
        $owner = $this->staff('owner', [$this->unitOne->id]);
        Livewire::actingAs($owner)->test(QuickStockEntry::class, ['entryMode' => 'phone', 'receivingUnit' => (string) $this->unitTwo->id])->assertForbidden();
    }
}
