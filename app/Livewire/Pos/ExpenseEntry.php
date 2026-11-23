<?php

declare(strict_types=1);

namespace App\Livewire\Pos;

use App\Domain\Expenses\ExpenseService;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\StaffMember;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ExpenseEntry extends Component
{
    #[Locked]
    public string $unitId = '';

    public bool $modalOpen = false;

    public bool $historyOpen = false;

    public bool $fromSalary = false;

    public string $salaryMonth = '';

    public string $amount = '';

    public string $recipientType = 'STAFF';

    public string $recipientId = '';

    public string $recipientName = '';

    public string $recipientDetails = '';

    public string $reason = '';

    public string $paymentMethod = 'CASH';

    #[Locked]
    public string $requestKey = '';

    public function mount(string $unitId): void
    {
        $this->unitId = $unitId;
    }

    public function open(): void
    {
        $this->authorize('expense.create');
        abort_unless(auth()->user()->hasUnitAccess((int) $this->unitId), 403);
        $this->clearForm();
        $this->historyOpen = false;
        session()->forget('expenseSuccess');
        $this->requestKey = (string) Str::uuid();
        $this->modalOpen = true;
    }

    private function clearForm(): void
    {
        $this->reset('amount', 'recipientType', 'recipientId', 'recipientName', 'recipientDetails', 'reason', 'paymentMethod', 'fromSalary');
        $this->salaryMonth = BusinessClock::today()->format('Y-m');
        $this->resetErrorBag();
    }

    public function updatedRecipientType(): void
    {
        $this->fromSalary = false;
        $this->reset('recipientId', 'recipientName', 'recipientDetails');
        $this->resetErrorBag();
    }

    public function updatedRecipientName(): void
    {
        $this->recipientId = '';
    }

    public function save(ExpenseService $expenses): void
    {
        $this->authorize('expense.create');
        if (! $this->modalOpen) {
            return;
        }
        $this->resetErrorBag();
        try {
            $expense = $expenses->record([
                'from_salary' => $this->fromSalary, 'salary_month' => $this->salaryMonth ?: null,
                'unit_id' => $this->unitId, 'amount' => $this->amount,
                'recipient_type' => $this->recipientType, 'recipient_id' => $this->recipientId ?: null,
                'recipient_name' => $this->recipientName, 'recipient_details' => $this->recipientDetails,
                'reason' => $this->reason, 'payment_method' => $this->paymentMethod, 'idempotency_key' => $this->requestKey,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $map = ['from_salary' => 'fromSalary', 'salary_month' => 'salaryMonth', 'recipient_type' => 'recipientType', 'recipient_id' => 'recipientId', 'recipient_name' => 'recipientName', 'recipient_details' => 'recipientDetails', 'payment_method' => 'paymentMethod'];
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [$map[$key] ?? $key => $messages])->all());
        }
        $this->modalOpen = false;
        $this->historyOpen = false;
        $this->clearForm();
        session()->flash('expenseSuccess', $expense->document_no.' saved · '.config('erp.currency.symbol').$expense->amount.' paid to '.$expense->recipient_name.'.');
    }

    public function render()
    {
        $this->authorize('expense.create');
        abort_unless(auth()->user()->hasUnitAccess((int) $this->unitId), 403);

        return view('livewire.pos.expense-entry', [
            'staff' => User::where('is_active', true)->whereHas('businessUnits', fn ($q) => $q->where('business_units.id', (int) $this->unitId))->orderBy('name')->get(['users.id', 'users.name']),
            'staffMembers' => StaffMember::where('business_unit_id', (int) $this->unitId)->where('is_active', true)->orderBy('name')->get(),
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'methods' => PaymentMethod::cases(),
            'recentExpenses' => Expense::where('business_unit_id', $this->unitId)->whereDate('expense_date', BusinessClock::today()->toDateString())->latest('id')->limit(5)->get(),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
