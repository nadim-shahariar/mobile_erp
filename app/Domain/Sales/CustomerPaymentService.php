<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\DomainRuleViolationException;
use App\Exceptions\UnitAccessDeniedException;
use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\SalePaymentSchedule;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Collects money against a customer's outstanding invoices. Collections
 * recover an existing receivable; they are never new revenue.
 */
final class CustomerPaymentService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ChartOfAccounts $accounts,
        private readonly JournalPostingService $journal,
    ) {}

    public function collect(
        Customer $customer,
        BusinessUnit $unit,
        PaymentMethod $method,
        string|Money $amount,
        ?string $reference,
        ?string $notes,
        User $actor,
        ?string $idempotencyKey = null,
        ?int $saleId = null,
    ): CustomerPayment {
        if (! $actor->can('payment.collect')) {
            throw DomainRuleViolationException::message(
                'You do not have permission to collect customer dues.',
                ['collection' => 'Missing permission: payment.collect'],
            );
        }

        if (! $actor->hasUnitAccess($unit->getKey())) {
            throw UnitAccessDeniedException::forUnit($unit->getKey());
        }

        $amount = Money::of($amount);

        if (! $amount->isPositive()) {
            throw DomainRuleViolationException::message(
                'Collection amount must be greater than zero.',
                ['amount' => 'Enter an amount above zero.'],
            );
        }

        return DB::transaction(function () use ($customer, $unit, $method, $amount, $reference, $notes, $actor, $idempotencyKey, $saleId): CustomerPayment {
            // Serialize collections for this branch so retries cannot allocate the same money twice.
            BusinessUnit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
            if ($idempotencyKey !== null) {
                $existing = CustomerPayment::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    abort_unless($existing->business_unit_id === $unit->id && $existing->customer_id === $customer->id && $existing->received_by === $actor->id, 403);

                    return $existing->load('allocations');
                }
            }
            $sales = Sale::query()
                ->where('customer_id', $customer->getKey())
                ->where('business_unit_id', $unit->getKey())
                ->where('status', SaleStatus::Posted->value)
                ->where('due_total', '>', 0)
                ->when($saleId !== null, fn ($query) => $query->whereKey($saleId))
                ->orderBy('posted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $outstanding = $sales->reduce(fn (Money $carry, Sale $sale) => $carry->add((string) $sale->due_total), Money::zero());

            if ($amount->greaterThan($outstanding)) {
                throw DomainRuleViolationException::message(
                    'Collection exceeds the outstanding balance of '.config('erp.currency.symbol').$outstanding->formatted().'.',
                    ['amount' => 'Amount is greater than the customer outstanding.'],
                );
            }

            $payment = CustomerPayment::create([
                'receipt_no' => $this->numbers->next('collection'),
                'customer_id' => $customer->getKey(),
                'business_unit_id' => $unit->getKey(),
                'account_id' => $this->accounts->paymentAccount($unit, $method)->getKey(),
                'amount' => $amount->amount,
                'reference' => $reference,
                'notes' => $notes,
                'received_by' => $actor->getKey(),
                'received_at' => BusinessClock::utcNow(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $remaining = $amount;
            $allocatedBySale = [];

            // The specification requires the oldest overdue installment first,
            // even when it belongs to a newer invoice than another credit due.
            // Allocate schedule lines across every in-scope invoice before
            // falling back to invoices without an installment schedule.
            $scheduleQuery = SalePaymentSchedule::query()
                ->whereIn('sale_id', $sales->pluck('id'))
                ->whereColumn('paid_amount', '<', 'amount')
                ->orderBy('due_date')
                ->orderBy('sequence')
                ->orderBy('id')
                ->lockForUpdate();

            if ($saleId !== null) {
                $scheduleQuery->where('sale_id', $saleId);
            }

            foreach ($scheduleQuery->get() as $schedule) {
                if (! $remaining->isPositive()) {
                    break;
                }

                $scheduleRemaining = $schedule->remaining();

                if (! $scheduleRemaining->isPositive()) {
                    continue;
                }

                $allocation = $remaining->min($scheduleRemaining);

                $schedule->forceFill([
                    'paid_amount' => Money::of((string) $schedule->paid_amount)->add($allocation)->amount,
                ])->save();

                $allocatedBySale[$schedule->sale_id] = ($allocatedBySale[$schedule->sale_id] ?? Money::zero())->add($allocation);
                $remaining = $remaining->sub($allocation);
            }

            foreach ($sales as $sale) {
                if (! $remaining->isPositive()) {
                    break;
                }

                $saleDue = Money::of((string) $sale->due_total);
                $already = $allocatedBySale[$sale->getKey()] ?? Money::zero();
                $available = $saleDue->sub($already);

                if (! $available->isPositive()) {
                    continue;
                }

                $allocation = $remaining->min($available);

                $allocatedBySale[$sale->getKey()] = $already->add($allocation);
                $remaining = $remaining->sub($allocation);
            }

            if ($remaining->isPositive()) {
                throw DomainRuleViolationException::message(
                    'The collection could not be fully allocated to outstanding invoices.',
                    ['amount' => 'Try a smaller amount and review the invoice schedule.'],
                );
            }

            foreach ($allocatedBySale as $saleKey => $allocated) {
                if (! $allocated->isPositive()) {
                    continue;
                }

                /** @var Sale $sale */
                $sale = $sales->firstWhere('id', $saleKey);

                $payment->allocations()->create([
                    'sale_id' => $sale->getKey(),
                    'amount' => $allocated->amount,
                ]);

                $sale->forceFill([
                    'paid_total' => Money::of((string) $sale->paid_total)->add($allocated)->amount,
                    'due_total' => Money::of((string) $sale->due_total)->sub($allocated)->amount,
                ])->save();
            }

            $entry = $this->journal->post(
                lines: [
                    [
                        'account_id' => $this->accounts->paymentAccount($unit, $method)->getKey(),
                        'debit' => $amount->amount,
                        'memo' => 'Collection '.$payment->receipt_no,
                    ],
                    [
                        'account_id' => $this->accounts->receivable()->getKey(),
                        'credit' => $amount->amount,
                        'memo' => 'Receivable recovered '.$payment->receipt_no,
                    ],
                ],
                memo: 'Customer collection '.$payment->receipt_no,
                entryDate: BusinessClock::today(),
                sourceKey: 'collection:'.$payment->getKey(),
                source: $payment,
                actor: $actor,
            );

            $payment->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            AuditLogger::record(
                'sale.collection_received',
                $payment,
                [],
                ['receipt_no' => $payment->receipt_no, 'amount' => $amount->amount],
                null,
                $unit->getKey(),
                $actor,
            );

            return $payment->load('allocations');
        });
    }
}
