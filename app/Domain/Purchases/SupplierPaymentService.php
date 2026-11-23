<?php

namespace App\Domain\Purchases;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\UnitContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPaymentService
{
    public function pay(int $supplierId, string $amount, string $method, string $key, User $actor, ?string $reference = null): SupplierPayment
    {
        abort_unless($actor->can('purchase.approve'), 403);
        $unit = app(UnitContext::class)->requireCurrent($actor);

        return DB::transaction(function () use ($supplierId, $amount, $method, $key, $actor, $reference, $unit) {
            Supplier::findOrFail($supplierId)->newQuery()->whereKey($supplierId)->lockForUpdate()->firstOrFail();
            if ($old = SupplierPayment::where('idempotency_key', $key)->first()) {
                abort_unless($old->supplier_id === $supplierId && $old->business_unit_id === $unit->id, 403);

                return $old;
            }
            $value = Money::of($amount);
            $paymentMethod = PaymentMethod::tryFrom($method);
            $purchases = Purchase::where('supplier_id', $supplierId)->where('business_unit_id', $unit->id)->where('status', PurchaseStatus::Received->value)->where('due_amount', '>', 0)->orderBy('purchase_date')->orderBy('id')->lockForUpdate()->get();
            $due = $purchases->reduce(fn ($sum, $p) => $sum->add($p->due_amount), Money::zero());
            if (! $value->isPositive() || $value->greaterThan($due) || ! $paymentMethod) {
                throw ValidationException::withMessages(['paymentAmount' => 'Enter a positive payment no greater than this branch supplier balance.']);
            }
            $remaining = $value;
            $allocations = [];
            foreach ($purchases as $purchase) {
                if ($remaining->isZero()) {
                    break;
                } $part = $remaining->min(Money::of($purchase->due_amount));
                $purchase->update(['paid_amount' => Money::of($purchase->paid_amount)->add($part)->amount, 'due_amount' => Money::of($purchase->due_amount)->sub($part)->amount]);
                $allocations[$purchase->id] = $part->amount;
                $remaining = $remaining->sub($part);
            }
            $payment = SupplierPayment::create(['supplier_id' => $supplierId, 'business_unit_id' => $unit->id, 'amount' => $value->amount, 'payment_method' => $method, 'reference' => $reference, 'allocations' => $allocations, 'idempotency_key' => $key, 'created_by' => $actor->id]);
            $accounts = app(ChartOfAccounts::class);
            app(JournalPostingService::class)->post([
                ['account_id' => $accounts->supplierPayable()->id, 'debit' => $value->amount],
                ['account_id' => $accounts->paymentAccount($unit, $paymentMethod)->id, 'credit' => $value->amount],
            ], 'Supplier payment', sourceKey: 'supplier-payment:'.$payment->id, source: $payment, actor: $actor);
            AuditLogger::record('supplier.payment', $payment, [], ['amount' => $value->amount, 'allocations' => $allocations], null, $unit->id, $actor);

            return $payment;
        });
    }
}
