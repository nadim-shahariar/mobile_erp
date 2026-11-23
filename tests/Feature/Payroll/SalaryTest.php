<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Domain\Expenses\ExpenseService;
use App\Domain\Payroll\SalaryService;
use App\Livewire\Payroll\SalaryIndex;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\SalaryMonth;
use App\Models\StaffMember;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class SalaryTest extends TestCase
{
    use BuildsErpScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
        $this->travelTo(Carbon::parse('2026-11-20 12:00', 'Asia/Dhaka'));
    }

    public function test_advances_reduce_salary_and_month_finalization_does_not_double_count_expenses(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $payload = ['unit_id' => $this->unitOne->id, 'recipient_type' => 'STAFF', 'recipient_name' => 'Karim', 'recipient_id' => null, 'reason' => 'Advance salary', 'amount' => '2000', 'payment_method' => 'CASH', 'idempotency_key' => (string) Str::uuid(), 'from_salary' => true, 'salary_month' => '2026-11'];
        $expenses = app(ExpenseService::class);
        $expenses->record($payload, $owner);
        $expenses->record($payload, $owner);
        $member = StaffMember::sole();
        $service = app(SalaryService::class);
        $record = $service->configure($this->unitOne->id, 'member:'.$member->id, '2026-11', '10000', $owner);
        $expenses->record(array_merge($payload, ['idempotency_key' => (string) Str::uuid(), 'amount' => '1000']), $owner);
        $expenses->record(array_merge($payload, ['idempotency_key' => (string) Str::uuid(), 'amount' => '500', 'from_salary' => false]), $owner);
        $this->assertSame('3000.00', Money::of((string) $record->expenses()->sum('amount'))->amount);
        Livewire::actingAs($owner)->test(SalaryIndex::class)->assertSee('Karim')->assertSee('7,000.00');
        try {
            $service->finalize($record->id, $owner);
            $this->fail('Finalized before month end.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('salary', $e->errors());
        }
        $this->travelTo(Carbon::parse('2026-11-30 12:00', 'Asia/Dhaka'));
        $service->finalize($record->id, $owner);
        $count = JournalEntry::count();
        $service->finalize($record->id, $owner);
        $this->assertSame($count, JournalEntry::count());
        $expenses->record(array_merge($payload, ['idempotency_key' => (string) Str::uuid(), 'amount' => '7000']), $owner);
        $expenses->record(array_merge($payload, ['idempotency_key' => (string) Str::uuid(), 'amount' => '500']), $owner);
        $advance = Account::where('code', '1300')->sole();
        $this->assertSame('500.00', Money::of($advance->debitTotal())->sub($advance->creditTotal())->amount);
        $payable = Account::where('code', '2300')->sole();
        $this->assertSame('0.00', Money::of($payable->creditTotal())->sub($payable->debitTotal())->amount);
        $salaryExpense = Account::where('code', '6400')->sole();
        $this->assertSame('10000.00', Money::of($salaryExpense->debitTotal())->amount);
        Livewire::actingAs($owner)->test(SalaryIndex::class)->assertSee('Extra advance')->assertSee('500.00');
    }

    public function test_backdated_rate_change_updates_unfinalized_later_months(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $staff = $this->staff('staff', [$this->unitOne->id]);
        $service = app(SalaryService::class);

        $service->configure($this->unitOne->id, 'user:'.$staff->id, '2026-11', '10000', $owner);
        $december = $service->month($this->unitOne, $staff, '2026-12');
        $this->assertSame('10000.00', $december->salary_amount);

        $service->configure($this->unitOne->id, 'user:'.$staff->id, '2026-11', '12000', $owner);

        $this->assertSame('12000.00', $service->month($this->unitOne, $staff, '2026-12')->salary_amount);
    }

    public function test_salary_month_can_be_finalized_without_a_pre_existing_record(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $staff = $this->staff('staff', [$this->unitOne->id]);
        $service = app(SalaryService::class);

        $service->configure($this->unitOne->id, 'user:'.$staff->id, '2026-11', '10000', $owner);
        $this->travelTo(Carbon::parse('2026-12-31 12:00', 'Asia/Dhaka'));

        Livewire::actingAs($owner)->test(SalaryIndex::class)
            ->set('month', '2026-12')
            ->assertSee('Finalize month')
            ->call('finalizeKey', 'user:'.$staff->id)
            ->assertHasNoErrors();

        $record = SalaryMonth::where('business_unit_id', $this->unitOne->id)
            ->where('recipient_key', 'user:'.$staff->id)
            ->where('salary_month', '2026-12')
            ->sole();

        $this->assertNotNull($record->finalized_at);
        $this->assertSame('10000.00', $record->salary_amount);
    }

    public function test_salary_rates_are_month_specific_and_suppliers_cannot_receive_salary(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $staff = $this->staff('staff', [$this->unitOne->id]);
        $service = app(SalaryService::class);
        $nov = $service->configure($this->unitOne->id, 'user:'.$staff->id, '2026-11', '10000', $owner);
        $service->configure($this->unitOne->id, 'user:'.$staff->id, '2026-12', '12000', $owner);
        $this->assertSame('10000.00', $nov->refresh()->salary_amount);
        $this->assertSame('12000.00', $service->rate($this->unitOne->id, 'user:'.$staff->id, '2027-01'));
        $this->expectException(ValidationException::class);
        app(ExpenseService::class)->record(['from_salary' => true, 'recipient_type' => 'SUPPLIER'], $owner);
    }
}
