<?php

declare(strict_types=1);

namespace App\Domain\Expenses;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Payroll\SalaryService;
use App\Enums\PaymentMethod;
use App\Models\BusinessUnit;
use App\Models\Expense;
use App\Models\StaffMember;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExpenseService
{
    public function record(array $data, User $actor): Expense
    {
        Gate::forUser($actor)->authorize('expense.create');
        $data['from_salary'] = $data['from_salary'] ?? false;
        if ($data['from_salary'] && ($data['recipient_type'] ?? '') !== 'STAFF') {
            throw ValidationException::withMessages(['from_salary' => 'From salary is available only for staff.']);
        }
        $data['recipient_name'] = trim((string) ($data['recipient_name'] ?? ''));
        $data = Validator::make($data, [
            'from_salary' => ['boolean'],
            'salary_month' => ['nullable', 'required_if:from_salary,true', 'date_format:Y-m'],
            'unit_id' => ['required', 'integer', Rule::in($actor->accessibleUnitIds())],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999'],
            'recipient_type' => ['required', Rule::in(['STAFF', 'SUPPLIER', 'OTHER'])],
            'recipient_id' => ['nullable', Rule::requiredIf(($data['recipient_type'] ?? '') !== 'OTHER' && $data['recipient_name'] === ''), 'integer'],
            'recipient_name' => ['nullable', Rule::requiredIf(($data['recipient_type'] ?? '') === 'OTHER' || empty($data['recipient_id'])), 'string', 'max:180'],
            'recipient_details' => ['nullable', 'required_if:recipient_type,OTHER', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($data, $actor) {
            $unit = BusinessUnit::active()->whereKey($data['unit_id'])->lockForUpdate()->firstOrFail();
            $existing = Expense::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->business_unit_id === $unit->id && $existing->created_by === $actor->id, 403);

                return $existing;
            }
            $recipient = null;
            $staffMember = null;
            $name = trim((string) ($data['recipient_name'] ?? ''));
            $normalized = mb_strtolower($name);
            if ($data['recipient_type'] === 'STAFF') {
                $users = User::where('is_active', true)->whereHas('businessUnits', fn ($q) => $q->where('business_units.id', $unit->id));
                $recipient = ! empty($data['recipient_id']) ? $users->find($data['recipient_id'])
                    : $users->whereRaw('LOWER(name) = ?', [$normalized])->first();
                if (! $recipient && ! empty($data['recipient_id'])) {
                    throw ValidationException::withMessages(['recipient_id' => 'Select an active staff member for this branch.']);
                }
                if (! $recipient) {
                    $staffMember = StaffMember::firstOrCreate(
                        ['business_unit_id' => $unit->id, 'normalized_name' => $normalized],
                        ['name' => $name, 'is_active' => true],
                    );
                    if (! $staffMember->is_active) {
                        throw ValidationException::withMessages(['recipient_name' => 'This staff member is inactive.']);
                    }
                    if ($staffMember->wasRecentlyCreated) {
                        AuditLogger::record('staff_member.created', $staffMember, [], $staffMember->toArray(), 'Created from POS expense', $unit->id, $actor);
                    }
                }
            } elseif ($data['recipient_type'] === 'SUPPLIER') {
                $recipient = ! empty($data['recipient_id']) ? Supplier::find($data['recipient_id'])
                    : Supplier::whereRaw('LOWER(name) = ?', [$normalized])->first();
                if (! $recipient && ! empty($data['recipient_id'])) {
                    throw ValidationException::withMessages(['recipient_id' => 'Select an active supplier.']);
                }
                if (! $recipient) {
                    $recipient = Supplier::create(['name' => $name, 'type' => Supplier::TYPE_BUSINESS, 'is_active' => true]);
                    AuditLogger::record('supplier.created', $recipient, [], $recipient->toArray(), 'Created from POS expense', $unit->id, $actor);
                }
                if (! $recipient->is_active) {
                    throw ValidationException::withMessages(['recipient_name' => 'This supplier is inactive.']);
                }
            }
            $salaryRecord = null;
            $salaryLines = null;
            if ($data['from_salary']) {
                $payroll = app(SalaryService::class);
                $salaryRecord = $payroll->month($unit, $recipient ?? $staffMember, $data['salary_month']);
                $salaryLines = $payroll->paymentLines($salaryRecord, Money::of($data['amount']));
            }
            $expense = Expense::create([
                'salary_month_id' => $salaryRecord?->id,
                'document_no' => app(DocumentNumberService::class)->next('expense'),
                'idempotency_key' => $data['idempotency_key'], 'business_unit_id' => $unit->id,
                'expense_date' => BusinessClock::today()->toDateString(),
                'amount' => $data['amount'], 'payment_method' => $data['payment_method'],
                'recipient_type' => $data['recipient_type'],
                'staff_id' => $data['recipient_type'] === 'STAFF' ? $recipient?->id : null,
                'staff_member_id' => $staffMember?->id,
                'supplier_id' => $data['recipient_type'] === 'SUPPLIER' ? $recipient->id : null,
                'recipient_name' => $recipient?->name ?? $staffMember?->name ?? $name,
                'recipient_details' => $data['recipient_type'] === 'OTHER' ? trim($data['recipient_details']) : null,
                'reason' => trim($data['reason']), 'created_by' => $actor->id,
            ]);
            $accounts = app(ChartOfAccounts::class);
            $journal = app(JournalPostingService::class)->post(
                lines: array_merge($salaryLines ?? [['account_id' => $accounts->account('6300')->id, 'debit' => $expense->amount, 'memo' => $expense->reason]], [
                    ['account_id' => $accounts->paymentAccount($unit, PaymentMethod::from($data['payment_method']))->id, 'credit' => $expense->amount, 'memo' => 'Paid to '.$expense->recipient_name],
                ]),
                memo: $expense->document_no.' · '.$expense->recipient_name.' · '.$expense->reason,
                entryDate: BusinessClock::today(), sourceKey: 'expense:'.$expense->id, source: $expense, actor: $actor,
            );
            $expense->update(['journal_entry_id' => $journal->id]);
            AuditLogger::record('expense.created', $expense, [], $expense->toArray(), $expense->reason, $unit->id, $actor);

            return $expense;
        });
    }
}
