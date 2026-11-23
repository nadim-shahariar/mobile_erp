<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Sales\CustomerPaymentService;
use App\Domain\Sales\PaymentScheduleService;
use App\Domain\Sales\SaleService;
use App\Enums\PaymentMethod;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\PaymentReminders;
use App\Livewire\Pos\PosTerminal;
use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePaymentSchedule;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PaymentScheduleTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    private int $imeiSeed = 501;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00', 'Asia/Dhaka'));
    }

    private function scheduledSale(User $owner, array $plan, string $total = '1000', string $paid = '0', ?Customer $customer = null, ?BusinessUnit $branch = null): Sale
    {
        $branch ??= $this->unitOne;
        $customer ??= Customer::create(['name' => 'Credit Customer', 'phone' => '01712345678', 'type' => 'PERSON', 'is_active' => true]);
        $phone = $this->addPhoneToStock($owner, $branch, $this->phoneVariant(), $this->validImei($this->imeiSeed++));

        return app(SaleService::class)->post([
            'business_unit_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $phone->id, 'unit_price' => $total]],
            'payments' => Money::of($paid)->isPositive() ? [['method' => 'CASH', 'amount' => $paid]] : [],
            'repayment_plan' => $plan,
        ], $owner);
    }

    public function test_credit_due_reminder_starts_after_local_deadline_and_clears_with_payments(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $sale = $this->scheduledSale($owner, ['type' => 'DUE', 'first_date' => '2026-01-02'], '1000', '100');
        $this->assertSame('900.00', $sale->paymentSchedules->sole()->amount);
        $this->travelTo(Carbon::parse('2026-01-02 23:59:00', 'Asia/Dhaka'));
        Livewire::actingAs($owner)->test(PaymentReminders::class)->assertSee('0 overdue')->assertDontSee('01712345678');
        $this->travelTo(Carbon::parse('2026-01-03 00:01:00', 'Asia/Dhaka'));
        Livewire::actingAs($owner)->test(PaymentReminders::class)->assertSee('1 overdue')->assertSee('01712345678')->assertSee('900.00');
        $service = app(CustomerPaymentService::class);
        $service->collect($sale->customer, $this->unitOne, PaymentMethod::Cash, '300', null, null, $owner, 'partial-repayment');
        $this->assertSame('300.00', $sale->paymentSchedules()->sole()->paid_amount);
        Livewire::actingAs($owner)->test(PaymentReminders::class)->assertSee('600.00');
        $service->collect($sale->customer, $this->unitOne, PaymentMethod::Cash, '600', null, null, $owner, 'full-repayment');
        Livewire::actingAs($owner)->test(PaymentReminders::class)->assertSee('0 overdue')->assertDontSee('01712345678');
        $this->assertSame('0.00', $sale->refresh()->due_total);
    }

    public function test_fixed_month_count_divides_exact_balance_and_adjusts_last_installment(): void
    {
        $rows = app(PaymentScheduleService::class)->build(Money::of('1000.01'), ['type' => 'INSTALLMENT', 'first_date' => '2026-01-31', 'months' => '3']);
        $this->assertCount(3, $rows);
        $this->assertSame(['333.33', '333.33', '333.35'], array_column($rows, 'amount'));
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($rows, 'due_date'));
        $total = array_reduce($rows, fn (Money $sum, array $row) => $sum->add($row['amount']), Money::zero());
        $this->assertSame('1000.01', $total->amount);
    }

    public function test_monthly_amount_updates_when_months_percentage_and_deposit_change(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(522));
        Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $phone->id)
            ->set('lines.0.unit_price', '10000')->set('saleTerms', 'INSTALLMENT')->set('payments.0.amount', '1000')
            ->set('emiRate', '10')->set('installmentMonths', '6')->set('firstInstallmentDate', '2026-01-31')
            ->assertSet('monthlyInstallment', '1650.00')
            ->set('installmentMonths', '12')->assertSet('monthlyInstallment', '825.00')
            ->set('emiRate', '20')->assertSet('monthlyInstallment', '900.00')
            ->set('payments.0.amount', '2000')->assertSet('monthlyInstallment', '800.00')
            ->set('invoiceDiscount', '1000')->assertSet('monthlyInstallment', '700.00');
    }

    public function test_invalid_month_count_cannot_post_an_installment_sale(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $customer = Customer::create(['name' => 'EMI Buyer', 'type' => 'PERSON', 'is_active' => true]);
        $phone = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(523));
        $component = Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $phone->id)
            ->set('customerId', $customer->id)->set('saleTerms', 'INSTALLMENT')
            ->set('installmentMonths', '0')->call('complete')->assertHasErrors('installmentMonths');
        $component->set('installmentMonths', '121')->call('complete')->assertHasErrors('installmentMonths');
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePaymentSchedule::count());
    }

    public function test_monthly_dates_stay_anchored_and_last_installment_keeps_exact_cents(): void
    {
        $rows = app(PaymentScheduleService::class)->build(Money::of('1000.01'), ['type' => 'INSTALLMENT', 'first_date' => '2026-01-31', 'monthly_amount' => '400']);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($rows, 'due_date'));
        $this->assertSame(['400.00', '400.00', '200.01'], array_column($rows, 'amount'));
    }

    public function test_partial_installment_payment_allocates_earliest_first_and_reminder_excludes_future_balance(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $sale = $this->scheduledSale($owner, ['type' => 'INSTALLMENT', 'first_date' => '2026-01-31', 'monthly_amount' => '400'], '1000.01');
        $this->travelTo(Carbon::parse('2026-03-01 10:00:00', 'Asia/Dhaka'));
        $service = app(CustomerPaymentService::class);
        $service->collect($sale->customer, $this->unitOne, PaymentMethod::BankTransfer, '450', null, null, $owner, 'installment-1', $sale->id);
        $service->collect($sale->customer, $this->unitOne, PaymentMethod::BankTransfer, '450', null, null, $owner, 'installment-1', $sale->id);
        $rows = $sale->paymentSchedules()->orderBy('sequence')->get();
        $this->assertSame(['400.00', '50.00', '0.00'], $rows->pluck('paid_amount')->all());
        $this->assertSame('550.01', $sale->refresh()->due_total);
        Livewire::actingAs($owner)->test(PaymentReminders::class)->assertSee('1 overdue')->assertSee('350.00')->assertSee('Monthly installment #2')->assertSee('01712345678');
    }

    public function test_collection_pays_the_oldest_overdue_installment_across_invoices(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $customer = Customer::create(['name' => 'Cross Invoice', 'phone' => '01800000001', 'type' => 'PERSON', 'is_active' => true]);

        $older = $this->scheduledSale($owner, ['type' => 'DUE', 'first_date' => '2026-01-31'], '1000', '0', $customer);
        $newer = $this->scheduledSale($owner, ['type' => 'INSTALLMENT', 'first_date' => '2026-01-02', 'monthly_amount' => '250'], '1000', '0', $customer);

        $this->travelTo(Carbon::parse('2026-01-03 10:00:00', 'Asia/Dhaka'));

        app(CustomerPaymentService::class)->collect(
            $customer,
            $this->unitOne,
            PaymentMethod::Cash,
            '300',
            null,
            null,
            $owner,
            'cross-invoice-collection',
        );

        $this->assertSame('250.00', $newer->paymentSchedules()->orderBy('sequence')->first()->paid_amount);
        $this->assertSame('50.00', $older->paymentSchedules()->sole()->paid_amount);
        $this->assertSame('750.00', $newer->refresh()->due_total);
        $this->assertSame('950.00', $older->refresh()->due_total);
    }

    public function test_reminders_are_branch_scoped(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $this->scheduledSale($owner, ['type' => 'DUE', 'first_date' => '2026-01-02'], '1000', '0', null, $this->unitTwo);
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        $this->travelTo(Carbon::parse('2026-01-03 10:00:00', 'Asia/Dhaka'));
        Livewire::actingAs($cashier)->test(PaymentReminders::class)->assertSee('0 overdue')->assertDontSee('01712345678');
        Livewire::actingAs($cashier)->test(PaymentReminders::class, ['unitId' => (string) $this->unitTwo->id])->assertForbidden();
    }

    public function test_invalid_due_date_rolls_back_invoice_and_stock_sale(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        try {
            $this->scheduledSale($owner, ['type' => 'DUE', 'first_date' => '2025-12-31']);
            $this->fail('Past deadline accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('first_date', $exception->errors());
        }
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SalePaymentSchedule::count());
        $this->assertTrue(SerializedUnit::sole()->isSellable());
    }

    public function test_pos_credit_and_installment_modes_persist_schedules_and_show_on_invoice(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $customer = Customer::create(['name' => 'Monthly Customer', 'phone' => '01911112222', 'type' => 'PERSON', 'is_active' => true]);
        $phone = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(520));
        Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $phone->id)
            ->set('lines.0.unit_price', '1000')->set('customerId', $customer->id)
            ->set('saleTerms', 'CREDIT')->assertSet('payments', [])
            ->set('saleTerms', 'INSTALLMENT')->set('installmentMonths', '3')->set('firstInstallmentDate', '2026-01-31')
            ->call('complete')->assertHasNoErrors()->assertRedirect();
        $sale = Sale::sole();
        $this->assertSame(3, $sale->paymentSchedules()->count());
        $this->actingAs($owner)->get(route('invoices.show', $sale))->assertOk()->assertSee('Monthly installment schedule')->assertSee('31 Jan 2026');
    }

    public function test_collection_can_target_an_installment_invoice_instead_of_older_invoice(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $older = $this->scheduledSale($owner, ['type' => 'DUE', 'first_date' => '2026-01-03']);
        $monthly = $this->scheduledSale($owner, ['type' => 'INSTALLMENT', 'first_date' => '2026-01-31', 'monthly_amount' => '250'], '1000', '0', $older->customer);
        Livewire::actingAs($owner)->test(CustomerIndex::class)->call('openCollect', $older->customer_id)
            ->set('collectSaleId', (string) $monthly->id)->assertSet('collectAmount', '250.00')
            ->call('saveCollection')->assertHasNoErrors();
        $this->assertSame('1000.00', $older->refresh()->due_total);
        $this->assertSame('750.00', $monthly->refresh()->due_total);
        $this->assertSame('250.00', $monthly->paymentSchedules()->orderBy('sequence')->first()->paid_amount);
    }
}
