<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Domain\Exchanges\ExchangeService;
use App\Domain\Inventory\InventoryCycleService;
use App\Domain\Inventory\StockStateMachine;
use App\Domain\Sales\SaleService;
use App\Enums\AcquisitionSource;
use App\Enums\Custody;
use App\Enums\CycleCloseReason;
use App\Enums\InventoryEventType;
use App\Enums\Sellability;
use App\Exceptions\ErpException;
use App\Livewire\Pos\PosTerminal;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Exchange;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PosExchangeTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    private function incoming(string $price, int $seed = 301): array
    {
        return ['kind' => 'PHONE', 'primaryImei' => $this->validImei($seed), 'brandName' => 'Apple', 'modelName' => 'Incoming iPhone',
            'buyingPrice' => $price, 'askingPrice' => '35000', 'country' => 'Japan', 'colour' => 'Blue',
            'storage' => '256GB', 'ram' => '6GB', 'category' => 'PRE_OWNED', 'batteryHealth' => '85', 'inStock' => true];
    }

    private function saleData(User $owner, string $price = '50000'): array
    {
        $outgoing = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(300));
        $customer = Customer::create(['name' => 'Exchange Customer', 'type' => 'PERSON', 'is_active' => true]);

        return ['business_unit_id' => $this->unitOne->id, 'customer_id' => $customer->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $outgoing->id, 'unit_price' => $price]],
            'idempotency_key' => (string) Str::uuid()];
    }

    private function assertClearingBalanced(): void
    {
        $clearing = Account::where('code', '2100')->sole();
        $this->assertSame(Money::of($clearing->debitTotal())->amount, Money::of($clearing->creditTotal())->amount);
        foreach (JournalEntry::all() as $entry) {
            $this->assertSame(Money::of((string) $entry->totalDebit())->amount, Money::of((string) $entry->totalCredit())->amount);
        }
    }

    public function test_incoming_phone_can_use_only_the_secondary_imei(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner);
        $incoming = $this->incoming('30000', 301);
        $incoming['primaryImei'] = null;
        $incoming['secondaryImei'] = $this->validImei(305);

        $sale = app(ExchangeService::class)->post(
            $data,
            $incoming,
            ['direction' => 'CUSTOMER', 'payments' => [['method' => 'CASH', 'amount' => '20000']]],
            $owner,
        );

        $received = SerializedUnit::query()->where('secondary_imei', $this->validImei(305))->sole();
        $this->assertNull($received->primary_imei);
        $this->assertSame(Custody::InUnit, $received->current_custody);
        $this->assertSame('30000.00', $sale->trade_in_credit);
        $this->assertSame('0.00', $sale->due_total);
    }

    public function test_a_previously_sold_phone_can_be_traded_in_with_a_dashed_imei(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $soldImei = $this->validImei(306);
        $soldPhone = $this->addPhoneToStock($owner, $this->unitOne, $phone, $soldImei);

        app(SaleService::class)->post([
            'business_unit_id' => $this->unitOne->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $soldPhone->id, 'unit_price' => '50000']],
            'payments' => [['method' => 'CASH', 'amount' => '50000']],
        ], $owner);

        $this->assertSame(Custody::WithCustomer, $soldPhone->refresh()->current_custody);

        $outgoing = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(307));
        $dashed = implode(' ', str_split($soldImei, 4));

        $incoming = $this->incoming('30000', 308);
        $incoming['primaryImei'] = $dashed;
        $incoming['brandName'] = 'Apple';
        $incoming['modelName'] = 'iPhone 13';
        $incoming['storage'] = '128GB';
        $incoming['colour'] = 'Midnight';

        $sale = app(ExchangeService::class)->post([
            'business_unit_id' => $this->unitOne->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $outgoing->id, 'unit_price' => '50000']],
            'idempotency_key' => (string) Str::uuid(),
        ], $incoming, ['direction' => 'CUSTOMER', 'payments' => [['method' => 'CASH', 'amount' => '20000']]], $owner);

        $soldPhone->refresh();
        $this->assertSame(Custody::InUnit, $soldPhone->current_custody);
        $this->assertCount(2, $soldPhone->cycles);
        $this->assertSame('30000.00', $sale->trade_in_credit);
    }

    public function test_exchange_direction_recalculates_when_prices_and_discounts_change(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner);
        Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $data['items'][0]['serialized_unit_id'])
            ->set('lines.0.unit_price', '50000')->set('incoming', $this->incoming('30000'))->call('useExchange')
            ->assertSet('exchangeDirection', 'CUSTOMER')->assertSet('exchangePayments.0.amount', '20000.00')
            ->set('lines.0.unit_price', '20000')->assertSet('exchangeDirection', 'COUNTER')->assertSee('Shop pays from counter')
            ->assertSet('exchangePayments.0.amount', '10000.00')
            ->set('incoming.buyingPrice', '20000')->assertSet('exchangeDirection', 'EVEN')->assertSee('Even exchange')
            ->set('lines.0.unit_price', '25000')->assertSet('exchangeDirection', 'CUSTOMER')->assertSet('exchangePayments.0.amount', '5000.00')
            ->set('invoiceDiscount', '1000')->assertSet('exchangePayments.0.amount', '4000.00');
    }

    public function test_walk_in_exchange_retry_clears_errors_from_previous_failed_attempt(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner);
        Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $data['items'][0]['serialized_unit_id'])
            ->set('lines.0.unit_price', '50000')->set('incoming', $this->incoming('30000'))
            ->set('incoming.primaryImei', '123456789012345')->call('useExchange')
            ->call('complete')->assertHasErrors('sale')
            ->set('incoming.primaryImei', $this->validImei(304))->call('complete')->assertHasNoErrors()->assertRedirect();
        $this->assertNull(Sale::sole()->customer_id);
        $this->assertNull(Exchange::sole()->customer_id);
    }

    public function test_recent_transactions_are_collapsible_and_scoped_to_the_active_branch(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $data = $this->saleData($owner);
        $sale = app(ExchangeService::class)->post($data, $this->incoming('30000'), ['direction' => 'CUSTOMER', 'payments' => [['method' => 'CASH', 'amount' => '20000']]], $owner);
        $component = Livewire::actingAs($owner)->test(PosTerminal::class)->set('unitId', (string) $this->unitOne->id)
            ->assertSee('Recent transactions')->assertSee($sale->invoice_no)->assertSee('wire:ignore.self', false);
        $component->set('unitId', (string) $this->unitTwo->id)->assertDontSee($sale->invoice_no)->assertSee('No transactions in this branch today.');
    }

    public function test_walk_in_exchange_posts_without_creating_a_customer_account(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner);
        $customerCount = Customer::count();
        Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $data['items'][0]['serialized_unit_id'])
            ->set('lines.0.unit_price', '50000')->set('incoming', $this->incoming('30000'))->call('useExchange')
            ->assertSet('customerId', null)->call('complete')->assertHasNoErrors()->assertRedirect();
        $sale = Sale::sole();
        $this->assertNull($sale->customer_id);
        $this->assertNull(Exchange::sole()->customer_id);
        $this->assertSame('0.00', $sale->due_total);
        $this->assertSame($customerCount, Customer::count());
        $this->assertDatabaseHas('inventory_cycles', ['buying_party_name' => 'Walk-in customer']);
        $this->actingAs($owner)->get(route('invoices.show', $sale))->assertOk()->assertSee('Walk-in customer');
    }

    public function test_walk_in_even_exchange_needs_no_money_or_customer_record(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner, '25000');
        $data['customer_id'] = null;
        $sale = app(ExchangeService::class)->post($data, $this->incoming('25000'), ['direction' => 'EVEN', 'payments' => []], $owner);
        $this->assertNull($sale->customer_id);
        $this->assertSame('0.00', $sale->due_total);
        $this->assertSame('EVEN', Exchange::sole()->settlement_direction);
        $this->assertSame('0.00', Exchange::sole()->settlement_amount);
    }

    public function test_walk_in_customer_can_receive_counter_payout(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner, '25000');
        $data['customer_id'] = null;
        $sale = app(ExchangeService::class)->post($data, $this->incoming('40000'), ['direction' => 'COUNTER', 'payments' => [['method' => 'BKASH', 'amount' => '15000']]], $owner);
        $this->assertNull($sale->customer_id);
        $this->assertSame('15000.00', Exchange::sole()->settlement_amount);
        $this->assertClearingBalanced();
    }

    public function test_exchange_accepts_cash_bank_and_bkash_together_and_prints_each_payment(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $saleData = $this->saleData($owner);
        $payments = [
            ['method' => 'CASH', 'amount' => '10000', 'reference' => ''],
            ['method' => 'BANK_TRANSFER', 'amount' => '5000', 'reference' => 'BANK-123'],
            ['method' => 'BKASH', 'amount' => '5000', 'reference' => 'BKASH-456'],
        ];
        $sale = app(ExchangeService::class)->post($saleData, $this->incoming('30000'), ['direction' => 'CUSTOMER', 'payments' => $payments], $owner);
        $this->assertSame('0.00', $sale->due_total);
        $byMethod = $sale->payments->mapWithKeys(fn ($payment) => [$payment->method->value => $payment->amount]);
        $this->assertSame('10000.00', $byMethod['CASH']);
        $this->assertSame('5000.00', $byMethod['BANK_TRANSFER']);
        $this->assertSame('5000.00', $byMethod['BKASH']);
        $exchange = Exchange::sole();
        $this->assertSame('SPLIT', $exchange->settlement_method);
        $this->assertSame('20000.00', $exchange->settlement_amount);
        $this->assertSame('BKASH-456', $exchange->settlement_payments[2]['reference']);
        $this->assertClearingBalanced();
        $this->actingAs($owner)->get(route('invoices.show', $sale))->assertOk()->assertSee('Split payment')->assertSee('BKASH-456')->assertSee('BANK-123');
    }

    public function test_counter_payout_can_be_split_across_cash_bank_and_bkash(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $saleData = $this->saleData($owner, '25000');
        $payments = [['method' => 'CASH', 'amount' => '5000'], ['method' => 'BANK_TRANSFER', 'amount' => '6000'], ['method' => 'BKASH', 'amount' => '4000']];
        $sale = app(ExchangeService::class)->post($saleData, $this->incoming('40000'), ['direction' => 'COUNTER', 'payments' => $payments], $owner);
        $this->assertCount(0, $sale->payments);
        $entry = JournalEntry::where('source_key', 'exchange:'.Exchange::sole()->id.':payout')->sole();
        foreach (['CASH' => '5000.00', 'BANK' => '6000.00', 'MFS' => '4000.00'] as $accountType => $amount) {
            $account = Account::where('code', $accountType.'-'.$this->unitOne->code)->sole();
            $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry->id, 'account_id' => $account->id, 'credit' => $amount]);
        }
        $this->assertClearingBalanced();
    }

    public function test_pos_exchange_split_rows_add_remove_and_save_together(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner);
        Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $data['items'][0]['serialized_unit_id'])
            ->set('lines.0.unit_price', '50000')->set('customerId', $data['customer_id'])
            ->set('incoming', $this->incoming('30000'))->call('useExchange')
            ->set('exchangePayments.0.amount', '10000')->call('addExchangePayment')
            ->set('exchangePayments.1.method', 'BANK_TRANSFER')->set('exchangePayments.1.amount', '5000')
            ->call('addExchangePayment')->set('exchangePayments.2.method', 'BKASH')->set('exchangePayments.2.amount', '5000')
            ->call('addExchangePayment')->call('removeExchangePayment', 3)
            ->call('complete')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(3, Sale::sole()->payments()->count());
    }

    public function test_split_underpayment_rolls_back_whole_exchange_and_bad_amount_highlights_its_row(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner);
        $component = Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $data['items'][0]['serialized_unit_id'])
            ->set('lines.0.unit_price', '50000')->set('customerId', $data['customer_id'])
            ->set('incoming', $this->incoming('30000'))->call('useExchange')
            ->set('exchangePayments.0.amount', '-10')->call('complete')->assertHasErrors('exchangePayments.0.amount');
        $component->set('exchangePayments.0.amount', '10000')->call('complete')->assertHasErrors('exchange');
        $this->assertSame(0, Exchange::count());
        $this->assertSame(0, Sale::count());
        $this->assertDatabaseMissing('serialized_units', ['primary_imei' => $this->validImei(301)]);
    }

    public function test_customer_adds_money_and_trade_in_is_received_with_actual_cost_and_history(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner);
        $service = app(ExchangeService::class);
        $sale = $service->post($data, $this->incoming('30000'), ['direction' => 'CUSTOMER', 'amount' => '20000', 'method' => 'CASH'], $owner);
        $this->assertSame('30000.00', $sale->trade_in_credit);
        $this->assertSame('50000.00', $sale->paid_total);
        $this->assertSame('0.00', $sale->due_total);
        $this->assertSame('20000.00', $sale->payments->sole()->amount);
        $incoming = SerializedUnit::where('primary_imei', $this->validImei(301))->sole();
        $this->assertSame(Custody::InUnit, $incoming->current_custody);
        $this->assertSame('30000.00', $incoming->activeCycle->acquisition_value);
        $this->assertSame(AcquisitionSource::TradeIn, $incoming->activeCycle->source_type);
        $this->assertSame(85, $incoming->battery_health);
        $this->assertSame(Exchange::class, $incoming->activeCycle->source_ref_type);
        $this->assertClearingBalanced();
        $again = $service->post($data, $this->incoming('30000'), ['direction' => 'CUSTOMER', 'amount' => '20000', 'method' => 'CASH'], $owner);
        $this->assertSame($sale->id, $again->id);
        $this->assertSame(1, Exchange::count());
        $this->assertSame(1, Sale::count());
        $this->actingAs($owner)->get(route('invoices.show', $sale))->assertOk()->assertSee('Accepted buying value')->assertSee('Customer added');
    }

    public function test_counter_can_pay_difference_by_bank_without_a_fake_sale_receipt(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner, '25000');
        $sale = app(ExchangeService::class)->post($data, $this->incoming('40000'), ['direction' => 'COUNTER', 'amount' => '15000', 'method' => 'BANK_TRANSFER'], $owner);
        $this->assertSame('25000.00', $sale->trade_in_credit);
        $this->assertCount(0, $sale->payments);
        $exchange = Exchange::sole();
        $payout = JournalEntry::where('source_key', 'exchange:'.$exchange->id.':payout')->sole();
        $bank = Account::where('code', 'BANK-'.$this->unitOne->code)->sole();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $payout->id, 'account_id' => $bank->id, 'credit' => '15000.00']);
        $this->assertClearingBalanced();
    }

    public function test_even_exchange_and_accessory_trade_in(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner, '1000');
        $incoming = ['kind' => 'ACCESSORY', 'productSku' => 'EX-CHARGER', 'productName' => 'Exchange charger', 'buyingPrice' => '1000', 'askingPrice' => '1500', 'category' => 'PRE_OWNED', 'inStock' => true];
        $sale = app(ExchangeService::class)->post($data, $incoming, ['direction' => 'EVEN', 'amount' => '0', 'method' => 'CASH'], $owner);
        $exchange = Exchange::sole();
        $this->assertSame('0.00', $sale->due_total);
        $this->assertDatabaseHas('accessory_stock_balances', ['product_variant_id' => $exchange->product_variant_id, 'business_unit_id' => $this->unitOne->id, 'qty_on_hand' => 1]);
        $this->assertClearingBalanced();
    }

    public function test_incorrect_settlement_rolls_back_incoming_stock_and_outgoing_sale(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner, '20000');
        $journalsBefore = JournalEntry::count();
        try {
            app(ExchangeService::class)->post($data, $this->incoming('30000'), ['direction' => 'COUNTER', 'amount' => '9000', 'method' => 'CASH'], $owner);
            $this->fail('Wrong settlement was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('exchange', $exception->errors());
        }
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Exchange::count());
        $this->assertSame($journalsBefore, JournalEntry::count());
        $this->assertDatabaseMissing('serialized_units', ['primary_imei' => $this->validImei(301)]);
        $this->assertSame(Custody::InUnit, SerializedUnit::findOrFail($data['items'][0]['serialized_unit_id'])->current_custody);
    }

    public function test_previous_imei_auto_fills_and_reuses_identity_with_new_condition_and_cycle(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $previous = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant('Old iPhone'), $this->validImei(302));
        $previous->update(['battery_health' => 95]);
        app(StockStateMachine::class)->transition($previous, Custody::WithCustomer, null, InventoryEventType::Sold, $owner, Sellability::Sold);
        app(InventoryCycleService::class)->close($previous->activeCycle()->firstOrFail(), CycleCloseReason::Sold, $owner);
        $component = Livewire::actingAs($owner)->test(PosTerminal::class)->call('openExchange')
            ->set('incoming.primaryImei', $previous->primary_imei)
            ->assertSet('incoming.modelName', 'Old iPhone')->assertSet('incoming.batteryHealth', '95')->assertSet('incoming.inStock', true);
        $data = $this->saleData($owner, '30000');
        $incoming = array_merge($component->get('incoming'), ['batteryHealth' => '80', 'buyingPrice' => '30000']);
        app(ExchangeService::class)->post($data, $incoming, ['direction' => 'EVEN', 'amount' => '0', 'method' => 'CASH'], $owner);
        $this->assertSame(2, SerializedUnit::count());
        $this->assertSame(80, $previous->refresh()->battery_health);
        $this->assertSame(2, $previous->activeCycle->cycle_no);
        $this->assertSame(AcquisitionSource::TradeIn, $previous->activeCycle->source_type);
    }

    public function test_currently_owned_imei_cannot_be_received_as_another_trade_in(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner);
        $alreadyOwned = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant('Incoming iPhone'), $this->validImei(303));
        $incoming = $this->incoming('30000', 303);
        try {
            app(ExchangeService::class)->post($data, $incoming, ['direction' => 'CUSTOMER', 'amount' => '20000', 'method' => 'CASH'], $owner);
            $this->fail('Owned handset was received again.');
        } catch (ErpException $exception) {
            $this->assertArrayHasKey('primary_imei', $exception->errors());
        }
        $this->assertSame(0, Exchange::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(1, $alreadyOwned->cycles()->count());
    }

    public function test_pos_can_prepare_and_complete_exchange_from_cart(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $data = $this->saleData($owner);
        Livewire::actingAs($owner)->test(PosTerminal::class)
            ->call('addPhone', $data['items'][0]['serialized_unit_id'])->set('lines.0.unit_price', '50000')
            ->set('customerId', $data['customer_id'])->call('openExchange')->set('incoming', $this->incoming('30000'))
            ->call('useExchange')->assertHasNoErrors()->assertSet('exchangeEnabled', true)
            ->assertSet('exchangeDirection', 'CUSTOMER')->assertSet('exchangeAmount', '20000.00')
            ->call('complete')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(1, Exchange::count());
    }
}
