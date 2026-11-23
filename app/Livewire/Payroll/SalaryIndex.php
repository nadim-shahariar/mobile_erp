<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Payroll\SalaryService;
use App\Models\BusinessUnit;
use App\Models\SalaryMonth;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\Money;
use App\Support\UnitContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SalaryIndex extends Component
{
    public string $unitId = '';

    public string $month = '';

    public array $salaryInputs = [];

    public function mount(UnitContext $units): void
    {
        $this->authorize('payroll.run');
        $this->unitId = (string) $units->requireCurrent(auth()->user())->id;
        $this->month = BusinessClock::today()->format('Y-m');
    }

    public function updatedMonth(): void
    {
        $this->salaryInputs = [];
    }

    public function updatedUnitId(): void
    {
        $this->salaryInputs = [];
    }

    public function setSalary(string $key, SalaryService $salary): void
    {
        $this->authorize('payroll.run');
        $salary->configure((int) $this->unitId, $key, $this->month, $this->salaryInputs[$key] ?? '', auth()->user());
        session()->flash('success', 'Monthly salary saved. This rate applies from the selected month.');
    }

    public function finalize(int $id, SalaryService $salary): void
    {
        $this->authorize('payroll.run');
        $record = SalaryMonth::findOrFail($id);
        abort_unless((string) $record->business_unit_id === $this->unitId && $record->salary_month === $this->month, 403);
        $salary->finalize($id, auth()->user());
        session()->flash('success', 'Salary month finalized. Advances were applied to salary payable.');
    }

    public function finalizeKey(string $key, SalaryService $salary): void
    {
        $this->authorize('payroll.run');
        $unit = BusinessUnit::findOrFail((int) $this->unitId);
        abort_unless(auth()->user()->hasUnitAccess($unit->id), 403);

        [$type, $id] = explode(':', $key);
        $person = $type === 'user' ? User::findOrFail($id) : StaffMember::findOrFail($id);

        $record = $salary->month($unit, $person, $this->month);
        $salary->finalize($record->id, auth()->user());
        session()->flash('success', 'Salary month finalized. Advances were applied to salary payable.');
    }

    public function render(SalaryService $salary)
    {
        $this->authorize('payroll.run');
        $this->validate(['month' => ['required', 'date_format:Y-m']]);
        abort_unless(auth()->user()->hasUnitAccess((int) $this->unitId), 403);
        $users = User::with('staffProfile')->where('is_active', true)->whereHas('businessUnits', fn ($q) => $q->where('business_units.id', $this->unitId))->get();
        $members = StaffMember::where('business_unit_id', $this->unitId)->where('is_active', true)->get();
        $records = SalaryMonth::with('expenses')->where('business_unit_id', $this->unitId)->where('salary_month', $this->month)->get()->keyBy('recipient_key');
        $rows = [];
        foreach ($users->concat($members)->sortBy('name') as $person) {
            $key = ($person instanceof User ? 'user:' : 'member:').$person->id;
            $record = $records->get($key);
            $effective = $salary->rate((int) $this->unitId, $key, $this->month, $person instanceof User ? $person : null);
            $base = $record?->finalized_at ? $record->salary_amount : ($effective ?? $record?->salary_amount);
            $paid = $record ? $record->expenses->reduce(fn (Money $sum, $expense) => $sum->add($expense->amount), Money::zero()) : Money::zero();
            $this->salaryInputs[$key] ??= $base ?? '';
            $rows[] = ['key' => $key, 'name' => $person->name, 'salary' => $base, 'paid' => $paid, 'balance' => $base === null ? null : Money::of($base)->sub($paid), 'record' => $record];
        }

        return view('livewire.payroll.salaries', ['rows' => $rows, 'units' => BusinessUnit::whereIn('id', auth()->user()->accessibleUnitIds())->ordered()->get(), 'currency' => config('erp.currency.symbol')]);
    }
}
