<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Models\Sale;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PaymentScheduleService
{
    /** Monthly dates stay anchored: Jan 31, Feb 28, Mar 31. Final payment includes the remaining cents. */
    public function build(Money $due, array $plan): array
    {
        if (! $due->isPositive()) {
            return [];
        }
        $plan = Validator::make($plan, [
            'type' => ['required', 'in:DUE,INSTALLMENT'],
            'first_date' => ['required', 'date_format:Y-m-d'],
            'months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'monthly_amount' => ['nullable', Rule::requiredIf(($plan['type'] ?? '') === 'INSTALLMENT' && ! filled($plan['months'] ?? null)), 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999'],
        ])->validate();

        $firstDate = CarbonImmutable::createFromFormat('!Y-m-d', $plan['first_date'], BusinessClock::timezone());

        if ($firstDate->lessThan(BusinessClock::today())) {
            throw ValidationException::withMessages(['first_date' => 'The first payment date cannot be in the past.']);
        }
        $fixedMonths = $plan['type'] === 'INSTALLMENT' && filled($plan['months'] ?? null);
        if ($fixedMonths) {
            $count = (int) $plan['months'];
            if ($count > $due->toMinorUnits()) {
                throw ValidationException::withMessages(['months' => 'Choose fewer months so each installment is at least 0.01.']);
            }
            $monthly = Money::fromMinorUnits(intdiv($due->toMinorUnits(), $count));
        } else {
            $monthly = $plan['type'] === 'INSTALLMENT' ? Money::of($plan['monthly_amount']) : $due;
            $count = intdiv($due->toMinorUnits() + $monthly->toMinorUnits() - 1, $monthly->toMinorUnits());
        }
        if ($count > 120) {
            throw ValidationException::withMessages(['monthly_amount' => 'Increase the monthly payment: at most 120 installments are supported.']);
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $plan['first_date'], BusinessClock::timezone());
        $remaining = $due;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $amount = $fixedMonths && $i === $count - 1 ? $remaining : $remaining->min($monthly);
            $rows[] = ['type' => $plan['type'], 'sequence' => $i + 1, 'due_date' => $date->addMonthsNoOverflow($i)->toDateString(), 'amount' => $amount->amount, 'paid_amount' => '0.00'];
            $remaining = $remaining->sub($amount);
        }

        return $rows;
    }

    public function create(Sale $sale, array $plan, User $actor): void
    {
        $rows = $this->build(Money::of($sale->due_total), $plan);
        if ($rows !== []) {
            $sale->paymentSchedules()->createMany($rows);
            AuditLogger::record('sale.payment_schedule_created', $sale, [], ['schedule' => $rows], null, $sale->business_unit_id, $actor);
        }
    }

    /** Called inside the collection transaction, after the invoice row has been locked. */
    public function allocate(Sale $sale, Money $amount): void
    {
        $rows = $sale->paymentSchedules()->orderBy('due_date')->orderBy('sequence')->lockForUpdate()->get();
        foreach ($rows as $row) {
            if (! $amount->isPositive()) {
                break;
            }
            $allocation = $amount->min($row->remaining());
            if ($allocation->isPositive()) {
                $row->update(['paid_amount' => Money::of($row->paid_amount)->add($allocation)->amount]);
                $amount = $amount->sub($allocation);
            }
        }
    }
}
