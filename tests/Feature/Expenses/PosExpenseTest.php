<?php

declare(strict_types=1);

namespace Tests\Feature\Expenses;

use App\Domain\Expenses\ExpenseService;
use App\Livewire\Pos\ExpenseEntry;
use App\Livewire\Pos\PosTerminal;
use App\Models\Account;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\StaffMember;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PosExpenseTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_saving_clears_form_closes_modal_and_collapses_history_but_keeps_record(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $component = Livewire::actingAs($owner)->test(ExpenseEntry::class, ['unitId' => (string) $this->unitOne->id])
            ->call('open')->set('historyOpen', true)->set('recipientType', 'OTHER')
            ->set('recipientName', 'Courier Rider')->set('recipientDetails', 'Parcel pickup')
            ->set('amount', '150')->set('reason', 'Courier delivery')->set('paymentMethod', 'BKASH')
            ->call('save')->assertHasNoErrors()->assertSet('modalOpen', false)->assertSet('historyOpen', false)
            ->assertSet('amount', '')->assertSet('recipientName', '')->assertSet('recipientDetails', '')
            ->assertSet('reason', '')->assertSet('paymentMethod', 'CASH')->assertSet('fromSalary', false)
            ->assertDontSee('Courier delivery')->assertSee('View recent expenses');
        $this->assertDatabaseHas('expenses', ['recipient_name' => 'Courier Rider', 'amount' => '150.00', 'reason' => 'Courier delivery']);
        $component->call('save')->assertHasNoErrors();
        $this->assertSame(1, Expense::count());
        $component->call('open')->assertSet('amount', '')->assertSet('recipientName', '')->assertSet('reason', '');
    }

    public function test_expense_document_numbers_do_not_collide_across_branches(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $service = app(ExpenseService::class);

        $first = $service->record([
            'unit_id' => $this->unitOne->id,
            'amount' => '50',
            'recipient_type' => 'OTHER',
            'recipient_name' => 'Cleaner',
            'recipient_details' => 'No staff record',
            'reason' => 'Office cleaning',
            'payment_method' => 'CASH',
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        $second = $service->record([
            'unit_id' => $this->unitTwo->id,
            'amount' => '60',
            'recipient_type' => 'OTHER',
            'recipient_name' => 'Cleaner',
            'recipient_details' => 'No staff record',
            'reason' => 'Office cleaning',
            'payment_method' => 'CASH',
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        $this->assertNotSame($first->document_no, $second->document_no);
        $this->assertStringStartsWith('EXP-', $first->document_no);
        $this->assertSame(2, Expense::query()->count());
    }

    public function test_typed_staff_name_creates_and_reuses_a_staff_recipient_without_a_login(): void
    {
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        $userCount = User::count();
        $component = Livewire::actingAs($cashier)->test(ExpenseEntry::class, ['unitId' => (string) $this->unitOne->id]);
        foreach (['New Staff', 'new staff'] as $name) {
            $component->call('open')->set('recipientName', $name)->set('amount', '100')->set('reason', 'Lunch allowance')
                ->call('save')->assertHasNoErrors();
        }
        $this->assertSame(1, StaffMember::count());
        $member = StaffMember::sole();
        $this->assertSame($this->unitOne->id, $member->business_unit_id);
        $this->assertSame('New Staff', $member->name);
        $this->assertSame(2, Expense::where('staff_member_id', $member->id)->count());
        $this->assertSame($userCount, User::count());
        $component->call('open')->assertSee('New Staff');
    }

    public function test_typed_supplier_name_creates_and_reuses_supplier(): void
    {
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        $component = Livewire::actingAs($cashier)->test(ExpenseEntry::class, ['unitId' => (string) $this->unitOne->id]);
        foreach (['Parts Supplier', 'parts supplier'] as $name) {
            $component->call('open')->set('recipientType', 'SUPPLIER')->set('recipientName', $name)
                ->set('amount', '250')->set('reason', 'Cleaning supplies')->call('save')->assertHasNoErrors();
        }
        $supplier = Supplier::where('name', 'Parts Supplier')->sole();
        $this->assertSame(1, Supplier::count());
        $this->assertSame(2, Expense::where('supplier_id', $supplier->id)->count());
    }

    public function test_typing_existing_staff_name_reuses_the_user_and_invalid_expense_creates_nobody(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $recipient = $this->staff('staff', [$this->unitOne->id]);
        $component = Livewire::actingAs($owner)->test(ExpenseEntry::class, ['unitId' => (string) $this->unitOne->id])
            ->call('open')->set('recipientName', $recipient->name)->set('amount', '25')->set('reason', 'Tea')
            ->call('save')->assertHasNoErrors();
        $this->assertSame($recipient->id, Expense::sole()->staff_id);
        $this->assertSame(0, StaffMember::count());
        $component->call('open')->set('recipientType', 'SUPPLIER')->set('recipientName', 'Unsaved Supplier')
            ->set('amount', '-5')->set('reason', 'Invalid')->call('save')->assertHasErrors('amount');
        $this->assertDatabaseMissing('suppliers', ['name' => 'Unsaved Supplier']);
    }

    public function test_staff_expense_posts_balanced_cash_payment_and_retry_does_not_duplicate_it(): void
    {
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        $recipient = $this->staff('staff', [$this->unitOne->id]);
        $component = Livewire::actingAs($cashier)->test(ExpenseEntry::class, ['unitId' => (string) $this->unitOne->id])
            ->call('open')->assertSet('modalOpen', true)
            ->set('amount', '350.50')->set('recipientId', (string) $recipient->id)
            ->set('reason', 'Transport allowance')->call('save')->assertHasNoErrors()->assertSet('modalOpen', false)
            ->assertDontSee('Transport allowance')->set('historyOpen', true)->assertSee('Transport allowance')->call('save')->assertHasNoErrors();
        $this->assertSame(1, Expense::count());
        $expense = Expense::sole();
        $this->assertSame('350.50', $expense->amount);
        $this->assertSame($recipient->id, $expense->staff_id);
        $entry = $expense->journalEntry;
        $this->assertSame('350.50', Money::of((string) $entry->totalDebit())->amount);
        $this->assertSame('350.50', Money::of((string) $entry->totalCredit())->amount);
        $cash = Account::where('code', 'CASH-'.$this->unitOne->code)->sole();
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $entry->id, 'account_id' => $cash->id, 'credit' => '350.50']);
        $this->assertSame(0, Sale::count());
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_supplier_and_other_recipients_are_saved_with_details(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $supplier = Supplier::create(['name' => 'Repair shop', 'type' => 'BUSINESS', 'is_active' => true]);
        $component = Livewire::actingAs($owner)->test(ExpenseEntry::class, ['unitId' => (string) $this->unitOne->id])
            ->call('open')->set('recipientType', 'SUPPLIER')->set('recipientId', (string) $supplier->id)
            ->set('amount', '100')->set('reason', 'Shop cleaning supplies')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('expenses', ['supplier_id' => $supplier->id, 'recipient_type' => 'SUPPLIER', 'recipient_name' => 'Repair shop']);
        $component->call('open')->set('recipientType', 'OTHER')->assertSee('Recipient details')
            ->set('amount', '150')->set('recipientName', 'Courier rider')->set('reason', 'Parcel delivery')
            ->call('save')->assertHasErrors('recipientDetails')
            ->set('recipientDetails', 'Local delivery to the repair shop')->set('paymentMethod', 'BKASH')
            ->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('expenses', ['recipient_type' => 'OTHER', 'recipient_name' => 'Courier rider', 'recipient_details' => 'Local delivery to the repair shop', 'payment_method' => 'BKASH']);
    }

    public function test_invalid_expenses_do_not_create_records_or_journals(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $outsideStaff = $this->staff('staff', [$this->unitTwo->id]);
        Livewire::actingAs($owner)->test(ExpenseEntry::class, ['unitId' => (string) $this->unitOne->id])
            ->call('open')->set('amount', '-50')->call('save')->assertHasErrors(['amount', 'reason', 'recipientId'])
            ->set('amount', '50')->set('recipientId', (string) $outsideStaff->id)->set('reason', 'Invalid recipient')
            ->call('save')->assertHasErrors('recipientId');
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_expense_permission_and_branch_access_are_enforced(): void
    {
        $staff = $this->staff('staff', [$this->unitOne->id]);
        Livewire::actingAs($staff)->test(ExpenseEntry::class, ['unitId' => (string) $this->unitOne->id])->assertForbidden();

        $owner = $this->staff('owner', [$this->unitOne->id]);
        Livewire::actingAs($owner)->test(ExpenseEntry::class, ['unitId' => (string) $this->unitTwo->id])->assertForbidden();
    }

    public function test_exact_payment_replaces_partial_payment_with_full_total_and_is_repeatable(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $unit = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(211));
        Livewire::actingAs($owner)->test(PosTerminal::class)->call('addPhone', $unit->id)
            ->set('lines.0.unit_price', '30000')->set('lines.0.discount', '500')->set('invoiceDiscount', '500')
            ->set('payments.0.amount', '10000')->call('setExactPayment', 'CASH')
            ->assertSet('payments.0.amount', '29000.00')->call('setExactPayment', 'BKASH')
            ->assertSet('payments.0.amount', '29000.00')->assertSet('payments.0.method', 'BKASH');
    }
}
