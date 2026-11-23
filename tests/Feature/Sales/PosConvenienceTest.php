<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Inventory\ProductIntakeService;
use App\Livewire\Pos\PosTerminal;
use App\Models\AccessoryStockMovement;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Supplier;
use App\Support\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PosConvenienceTest extends TestCase
{
    use BuildsErpScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_emi_percentage_is_added_once_on_financed_balance_and_scheduled(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(8011));
        $customer = Customer::create(['name' => 'EMI Buyer', 'phone' => '01700011111', 'type' => 'PERSON', 'is_active' => true]);
        Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $phone->id)->set('lines.0.unit_price', '10000')->set('customerId', $customer->id)
            ->set('saleTerms', 'INSTALLMENT')->set('payments.0.amount', '2000')->set('emiRate', '10')->set('installmentMonths', '3')
            ->set('firstInstallmentDate', BusinessClock::today()->addMonthNoOverflow()->toDateString())->call('complete')->assertHasNoErrors();
        $sale = Sale::sole();
        $this->assertSame('800.00', $sale->emi_charge);
        $this->assertSame('10800.00', $sale->grand_total);
        $this->assertSame('8800.00', $sale->due_total);
        $this->assertSame(['2933.33', '2933.33', '2933.34'], $sale->paymentSchedules()->orderBy('sequence')->pluck('amount')->all());
    }

    public function test_accessory_receipt_records_which_supplier_provided_stock(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        app(ProductIntakeService::class)->add(['name' => 'Cable', 'sku' => 'TEST-CABLE', 'quantity' => 5, 'buying_price' => '100', 'selling_price' => '200', 'supplier_name' => 'Cable Supplier'], $this->unitOne, $owner);
        $supplier = Supplier::where('name', 'Cable Supplier')->sole();
        $this->assertSame($supplier->id, AccessoryStockMovement::sole()->supplier_id);
    }

    public function test_customer_can_be_added_with_name_and_phone_and_existing_phone_is_reused(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $component = Livewire::actingAs($owner)->test(PosTerminal::class)->call('openCustomerModal')->set('customerName', 'Quick Customer')->set('customerPhone', '01722223333')->call('saveCustomer')->assertHasNoErrors()->assertSet('customerModalOpen', false);
        $id = Customer::sole()->id;
        $component->call('openCustomerModal')->set('customerName', 'Same Customer')->set('customerPhone', '01722223333')->call('saveCustomer')->assertHasNoErrors()->assertSet('customerId', $id);
        $this->assertSame(1, Customer::count());
    }
}
