<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Models\BusinessUnit;
use App\Models\SalaryMonth;
use App\Models\StaffMember;
use App\Models\StaffSalaryRate;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalaryService
{
    public function rate(int $unitId, string $key, string $month, ?User $staff = null): ?string
    {
        return StaffSalaryRate::where('business_unit_id', $unitId)->where('recipient_key', $key)
            ->where('effective_month', '<=', $month)->orderByDesc('effective_month')->value('monthly_salary')
            ?? $staff?->staffProfile?->monthly_salary;
    }

    /** Caller must hold the branch lock. Payroll is separate from ERP login accounts. */
    public function month(BusinessUnit $unit, User|StaffMember $staff, string $month): SalaryMonth
    {
        Validator::make(['month' => $month], ['month' => ['required', 'date_format:Y-m']])->validate();
        $isUser = $staff instanceof User;
        abort_unless($isUser ? $staff->hasUnitAccess($unit->id) : $staff->business_unit_id === $unit->id, 403);
        $key = ($isUser ? 'user:' : 'member:').$staff->id;

        $record = SalaryMonth::firstOrCreate([
            'business_unit_id' => $unit->id, 'recipient_key' => $key, 'salary_month' => $month,
        ], [
            'staff_id' => $isUser ? $staff->id : null, 'staff_member_id' => $isUser ? null : $staff->id,
            'staff_name' => $staff->name, 'salary_amount' => $this->rate($unit->id, $key, $month, $isUser ? $staff : null),
        ]);

        $this->refreshAmount($record);

        return $record->refresh();
    }

    /**
     * Keep an unfinalized month aligned with the effective salary rate. A
     * backdated rate change must not leave a stale snapshot behind.
     */
    private function refreshAmount(SalaryMonth $record): void
    {
        if ($record->finalized_at) {
            return;
        }

        $staff = $record->staff_id !== null
            ? User::query()->find($record->staff_id)
            : ($record->staff_member_id !== null ? StaffMember::query()->find($record->staff_member_id) : null);

        $rate = $this->rate(
            $record->business_unit_id,
            $record->recipient_key,
            $record->salary_month,
            $staff instanceof User ? $staff : null,
        );

        if ($rate !== null && ! Money::of($rate)->equals($record->salary_amount ?? '0')) {
            $record->forceFill(['salary_amount' => $rate])->save();
        }
    }

    public function configure(int $unitId, string $key, string $month, string $amount, User $actor): SalaryMonth
    {
        Gate::forUser($actor)->authorize('payroll.run');
        abort_unless($actor->hasUnitAccess($unitId), 403);
        Validator::make(compact('month', 'amount', 'key'), [
            'month' => ['required', 'date_format:Y-m'], 'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'key' => ['required', 'regex:/^(user|member):[1-9][0-9]*$/'],
        ])->validate();

        return DB::transaction(function () use ($unitId, $key, $month, $amount, $actor) {
            $unit = BusinessUnit::active()->whereKey($unitId)->lockForUpdate()->firstOrFail();
            [$type, $id] = explode(':', $key);
            $staff = $type === 'user' ? User::findOrFail($id) : StaffMember::findOrFail($id);
            $record = $this->month($unit, $staff, $month);
            if ($record->finalized_at) {
                throw ValidationException::withMessages(['salary' => 'This month is finalized. Its salary cannot be changed.']);
            }
            $before = $record->salary_amount;
            $record->update(['salary_amount' => Money::of($amount)->amount]);
            StaffSalaryRate::updateOrCreate(['business_unit_id' => $unitId, 'recipient_key' => $key, 'effective_month' => $month], ['monthly_salary' => Money::of($amount)->amount]);
            AuditLogger::record('payroll.salary_configured', $record, ['salary_amount' => $before], ['salary_amount' => $record->salary_amount], 'Monthly salary set', $unitId, $actor);

            return $record;
        });
    }

    public function finalize(int $salaryMonthId, User $actor): SalaryMonth
    {
        Gate::forUser($actor)->authorize('payroll.run');

        return DB::transaction(function () use ($salaryMonthId, $actor) {
            $record = SalaryMonth::findOrFail($salaryMonthId);
            abort_unless($actor->hasUnitAccess($record->business_unit_id), 403);
            BusinessUnit::whereKey($record->business_unit_id)->lockForUpdate()->firstOrFail();
            $record = SalaryMonth::whereKey($salaryMonthId)->lockForUpdate()->firstOrFail();
            if ($record->finalized_at) {
                return $record;
            }
            $this->refreshAmount($record);
            $record->refresh();
            $monthEnd = CarbonImmutable::createFromFormat('!Y-m', $record->salary_month, BusinessClock::timezone())->endOfMonth()->startOfDay();
            if (BusinessClock::today()->lessThan($monthEnd)) {
                throw ValidationException::withMessages(['salary' => 'Finalize salary on or after the last day of this month. Advances can be recorded now.']);
            }
            if ($record->salary_amount === null) {
                throw ValidationException::withMessages(['salary' => 'Set this staff member’s monthly salary first.']);
            }
            $salary = Money::of($record->salary_amount);
            $paid = Money::of((string) $record->expenses()->sum('amount'));
            $accounts = app(ChartOfAccounts::class);
            if ($salary->isPositive()) {
                $entry = app(JournalPostingService::class)->post([
                    ['account_id' => $accounts->account('6400')->id, 'debit' => $salary->amount],
                    ['account_id' => $accounts->account('2300')->id, 'credit' => $salary->amount],
                ], 'Salary '.$record->salary_month.' · '.$record->staff_name, $monthEnd, 'salary:'.$record->id.':accrual', $record, $actor);
                $record->journal_entry_id = $entry->id;
            }
            $settled = $salary->min($paid);
            if ($settled->isPositive()) {
                app(JournalPostingService::class)->post([
                    ['account_id' => $accounts->account('2300')->id, 'debit' => $settled->amount],
                    ['account_id' => $accounts->account('1300')->id, 'credit' => $settled->amount],
                ], 'Salary advances applied · '.$record->staff_name, $monthEnd, 'salary:'.$record->id.':advances', $record, $actor);
            }
            $record->finalized_at = BusinessClock::utcNow();
            $record->finalized_by = $actor->id;
            $record->save();
            AuditLogger::record('payroll.month_finalized', $record, [], ['salary_amount' => $salary->amount, 'paid' => $paid->amount], null, $record->business_unit_id, $actor);

            return $record;
        });
    }

    /** Split a payout between an accrued salary balance and any further advance. */
    public function paymentLines(SalaryMonth $record, Money $amount): array
    {
        $accounts = app(ChartOfAccounts::class);
        $payable = Money::zero();
        if ($record->finalized_at) {
            $balance = Money::of($record->salary_amount)->sub((string) $record->expenses()->sum('amount'));
            if ($balance->isPositive()) {
                $payable = $amount->min($balance);
            }
        }
        $advance = $amount->sub($payable);
        $lines = [];
        if ($payable->isPositive()) {
            $lines[] = ['account_id' => $accounts->account('2300')->id, 'debit' => $payable->amount, 'memo' => 'Salary payment '.$record->salary_month];
        }
        if ($advance->isPositive()) {
            $lines[] = ['account_id' => $accounts->account('1300')->id, 'debit' => $advance->amount, 'memo' => 'Salary advance '.$record->salary_month];
        }

        return $lines;
    }
}
