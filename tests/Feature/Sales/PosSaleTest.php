<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Inventory\AccessoryStockService;
use App\Domain\Sales\SaleService;
use App\Enums\AccessoryMovementReason;
use App\Enums\Custody;
use App\Enums\CustomerType;
use App\Enums\InventoryEventType;
use App\Enums\Sellability;
use App\Exceptions\ErpException;
use App\Livewire\Pos\PosTerminal;
use App\Models\Account;
use App\Models\Customer;
use App\Models\InventoryCycle;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PosSaleTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_a_sale_posts_invoice_stock_events_tenders_and_a_balanced_journal(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $accessory = $this->accessoryVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(801), '50000.00');

        app(AccessoryStockService::class)->receive(
            $this->unitOne->id,
            $accessory->id,
            10,
            '1000.00',
            AccessoryMovementReason::Opening,
            $owner,
        );

        $customer = Customer::create(['type' => CustomerType::Person, 'name' => 'Rahim', 'is_active' => true]);

        $sale = app(SaleService::class)->post([
            'business_unit_id' => $this->unitOne->id,
            'customer_id' => $customer->id,
            'items' => [
                ['line_type' => 'PHONE', 'serialized_unit_id' => $serialized->id, 'unit_price' => '62000', 'discount' => '2000'],
                ['line_type' => 'ACCESSORY', 'product_variant_id' => $accessory->id, 'quantity' => 2, 'unit_price' => '2200', 'discount' => '0'],
            ],
            'payments' => [
                ['method' => 'CASH', 'amount' => '30000'],
                ['method' => 'BANK_TRANSFER', 'amount' => '20000', 'reference' => 'TRX-1'],
            ],
            'idempotency_key' => 'sale-test-1',
        ], $owner);

        $this->assertSame('64400.00', $sale->subtotal);
        $this->assertSame('2000.00', $sale->line_discount_total);
        $this->assertSame('64400.00', $sale->grand_total);
        $this->assertSame('50000.00', $sale->paid_total);
        $this->assertSame('14400.00', $sale->due_total);
        $this->assertCount(2, $sale->items);
        $this->assertCount(2, $sale->payments);

        $serialized->refresh();
        $this->assertSame(Custody::WithCustomer, $serialized->current_custody);
        $this->assertSame(Sellability::Sold, $serialized->sellability_status);
        $this->assertNull($serialized->current_unit_id);
        $this->assertSame(InventoryEventType::Sold, $serialized->lastEvent->event_type);
        $cycle = InventoryCycle::query()->where('serialized_unit_id', $serialized->id)->firstOrFail();
        $this->assertNotNull($cycle->closed_at);
        $this->assertNull($cycle->active_cycle_guard);

        $entry = JournalEntry::query()->where('source_key', 'sale:'.$sale->id)->firstOrFail();
        $this->assertSame(
            Money::of((string) $entry->totalDebit())->amount,
            Money::of((string) $entry->totalCredit())->amount,
        );

        $revenue = $entry->lines()->where('account_id', Account::where('code', '4000')->value('id'))->sum('credit');
        $receivable = $entry->lines()->where('account_id', Account::where('code', '1100')->value('id'))->sum('debit');
        $cogs = $entry->lines()->whereIn('account_id', Account::whereIn('code', ['5000', '5010'])->pluck('id'))->sum('debit');

        $this->assertSame('64400.00', number_format((float) $revenue, 2, '.', ''));
        $this->assertSame('14400.00', number_format((float) $receivable, 2, '.', ''));
        $this->assertSame('52000.00', number_format((float) $cogs, 2, '.', ''));

        $this->assertSame(8, (int) app(AccessoryStockService::class)->balanceFor($this->unitOne->id, $accessory->id)->qty_on_hand);
    }

    public function test_a_due_sale_requires_a_registered_customer(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(802));

        $this->expectException(ErpException::class);

        app(SaleService::class)->post([
            'business_unit_id' => $this->unitOne->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $serialized->id, 'unit_price' => '55000']],
            'payments' => [['method' => 'CASH', 'amount' => '10000']],
        ], $owner);
    }

    public function test_payments_cannot_exceed_the_invoice_total(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(803));

        $this->expectException(ErpException::class);

        app(SaleService::class)->post([
            'business_unit_id' => $this->unitOne->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $serialized->id, 'unit_price' => '55000']],
            'payments' => [['method' => 'CASH', 'amount' => '60000']],
        ], $owner);
    }

    public function test_the_same_imei_cannot_be_sold_twice(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(804));

        $service = app(SaleService::class);

        $service->post([
            'business_unit_id' => $this->unitOne->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $serialized->id, 'unit_price' => '55000']],
            'payments' => [['method' => 'CASH', 'amount' => '55000']],
        ], $owner);

        try {
            $service->post([
                'business_unit_id' => $this->unitOne->id,
                'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $serialized->id, 'unit_price' => '55000']],
                'payments' => [['method' => 'CASH', 'amount' => '55000']],
            ], $owner);
            $this->fail('Selling the same IMEI twice must be rejected.');
        } catch (ErpException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame(1, Sale::query()->count());
    }

    public function test_wholesale_credit_limit_is_enforced(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(805));

        $shop = Customer::create([
            'type' => CustomerType::Shop,
            'name' => 'Shop B',
            'business_name' => 'Shop B Mobile',
            'credit_limit' => '10000',
            'is_active' => true,
        ]);

        $this->expectException(ErpException::class);

        app(SaleService::class)->post([
            'business_unit_id' => $this->unitOne->id,
            'customer_id' => $shop->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $serialized->id, 'unit_price' => '55000']],
            'payments' => [['method' => 'CASH', 'amount' => '10000']],
        ], $owner);
    }

    public function test_invoice_is_visible_to_unit_staff_and_hidden_from_another_unit(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        $otherCashier = $this->staff('cashier', [$this->unitTwo->id]);
        $phone = $this->phoneVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(806));

        $sale = app(SaleService::class)->post([
            'business_unit_id' => $this->unitOne->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $serialized->id, 'unit_price' => '55000']],
            'payments' => [['method' => 'CASH', 'amount' => '55000']],
        ], $owner);

        $this->actingAs($cashier)->get(route('invoices.show', $sale))->assertOk()->assertSee($sale->invoice_no);
        $this->actingAs($otherCashier)->get(route('invoices.show', $sale))->assertForbidden();
    }

    public function test_pos_tolerates_numeric_shapes_allowed_by_validation(): void
    {
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(808));

        Livewire::actingAs($cashier)
            ->test(PosTerminal::class)
            ->call('addPhone', $serialized->id)
            ->set('lines.0.unit_price', '5.')
            ->set('lines.0.discount', '.5')
            ->set('invoiceDiscount', '+1')
            ->set('payments.0.amount', '.5')
            ->call('setExactPayment', 'CASH')
            ->assertHasNoErrors()
            ->assertSee('3.50');
    }

    public function test_pos_terminal_can_add_a_handset_and_post_a_cash_sale(): void
    {
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(807));

        $component = Livewire::actingAs($cashier)
            ->test(PosTerminal::class)
            ->call('addPhone', $serialized->id)
            ->assertSet('unitId', (string) $this->unitOne->id);

        $this->assertCount(1, $component->get('lines'));

        $component
            ->set('payments.0.amount', '60000')
            ->call('complete')
            ->assertHasNoErrors();

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame('60000.00', Sale::query()->firstOrFail()->grand_total);
    }
}
