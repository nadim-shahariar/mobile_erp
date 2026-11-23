<?php

declare(strict_types=1);

namespace App\Domain\Deliveries;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Inventory\StockStateMachine;
use App\Domain\Sales\SaleService;
use App\Enums\Custody;
use App\Enums\InventoryEventType;
use App\Enums\PaymentMethod;
use App\Enums\Sellability;
use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\ExternalDelivery;
use App\Models\Sale;
use App\Models\SerializedUnit;
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

final class ExternalDeliveryService
{
    public function send(array $data, User $actor): ExternalDelivery
    {
        Gate::forUser($actor)->authorize('stock.transfer.create');
        $data = Validator::make($data, [
            'unit_id' => ['required', 'integer', Rule::in($actor->accessibleUnitIds())], 'imei' => ['required', 'string'],
            'recipient_type' => ['required', 'in:PERSON,SHOP'], 'name' => ['required', 'string', 'max:180'], 'phone' => ['nullable', 'string', 'max:32'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'deposit' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'lte:price'], 'method' => ['required', 'in:CASH,BANK_TRANSFER'],
            'notes' => ['nullable', 'string', 'max:1000'], 'key' => ['required', 'uuid'],
        ])->validate();

        if (Money::of($data['deposit'])->isPositive()) {
            Gate::forUser($actor)->authorize('payment.collect');
        }

        return DB::transaction(function () use ($data, $actor) {
            $branch = BusinessUnit::active()->whereKey($data['unit_id'])->lockForUpdate()->firstOrFail();
            $existing = ExternalDelivery::where('idempotency_key', $data['key'])->first();
            if ($existing) {
                abort_unless($existing->business_unit_id === $branch->id && $existing->created_by === $actor->id, 403);

                if ($existing->status !== 'OUT') {
                    throw ValidationException::withMessages(['key' => 'This request key already belongs to a closed delivery. Start a new handoff.']);
                }

                return $existing;
            }
            $match = app(SerialIdentityService::class)->findByImei($data['imei']);
            $phone = $match ? SerializedUnit::whereKey($match->id)->lockForUpdate()->first() : null;
            if (! $phone || $phone->current_unit_id !== $branch->id || ! $phone->isSellable()) {
                throw ValidationException::withMessages(['imei' => 'Only an available phone in this branch can be sent out.']);
            }
            $customer = filled($data['phone'] ?? null)
                ? Customer::active()->where('phone', $data['phone'])->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->first()
                : Customer::active()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->first();

            if ($customer === null) {
                Gate::forUser($actor)->authorize('customer.edit');
                $customer = Customer::create([
                    'name' => trim($data['name']),
                    'business_name' => $data['recipient_type'] === 'SHOP' ? trim($data['name']) : null,
                    'phone' => ($data['phone'] ?? null) ?: null,
                    'type' => $data['recipient_type'] === 'SHOP' ? 'SHOP' : 'PERSON',
                    'is_active' => true,
                ]);
            }

            $delivery = ExternalDelivery::create([
                'idempotency_key' => $data['key'],
                'reference_no' => app(DocumentNumberService::class)->next('external'),
                'business_unit_id' => $branch->id,
                'serialized_unit_id' => $phone->id,
                'customer_id' => $customer->id,
                'recipient_type' => $data['recipient_type'],
                'agreed_price' => $data['price'],
                'deposit_amount' => $data['deposit'],
                'payment_method' => $data['method'],
                'status' => 'OUT',
                'active_guard' => 1,
                'created_by' => $actor->id,
                'notes' => ($data['notes'] ?? null) ?: null,
            ]);
            app(StockStateMachine::class)->transition($phone, Custody::AtExternalParty, null, InventoryEventType::TransferDispatched, $actor, Sellability::NotForSale, 'Temporary delivery '.$delivery->reference_no.' to '.$customer->name, $delivery, ['recipient' => $customer->name, 'agreed_price' => $delivery->agreed_price]);
            $deposit = Money::of($data['deposit']);
            if ($deposit->isPositive()) {
                $accounts = app(ChartOfAccounts::class);
                app(JournalPostingService::class)->post([['account_id' => $accounts->paymentAccount($branch, PaymentMethod::from($data['method']))->id, 'debit' => $deposit->amount], ['account_id' => $accounts->account('2400')->id, 'credit' => $deposit->amount]], 'Delivery deposit '.$delivery->reference_no, BusinessClock::today(), 'delivery:'.$delivery->id.':deposit', $delivery, $actor);
            }
            AuditLogger::record('delivery.sent', $delivery, [], $delivery->toArray(), null, $branch->id, $actor);

            return $delivery;
        });
    }

    public function receiveReturn(int $id, string $refundMethod, User $actor): ExternalDelivery
    {
        Gate::forUser($actor)->authorize('stock.transfer.create');
        Validator::make(['method' => $refundMethod], ['method' => ['required', 'in:CASH,BANK_TRANSFER']])->validate();

        return DB::transaction(function () use ($id, $refundMethod, $actor) {
            $delivery = ExternalDelivery::findOrFail($id);
            abort_unless($actor->hasUnitAccess($delivery->business_unit_id), 403);
            $branch = BusinessUnit::whereKey($delivery->business_unit_id)->lockForUpdate()->firstOrFail();
            $delivery = ExternalDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($delivery->status === 'RETURNED') {
                return $delivery;
            }
            if ($delivery->status !== 'OUT') {
                throw ValidationException::withMessages(['delivery' => 'This delivery is already sold; use the sold-phone return/restock flow.']);
            }
            if (Money::of($delivery->deposit_amount)->isPositive()) {
                Gate::forUser($actor)->authorize('payment.collect');
            }

            $phone = SerializedUnit::whereKey($delivery->serialized_unit_id)->lockForUpdate()->firstOrFail();
            if ($phone->current_custody !== Custody::AtExternalParty || ! $phone->activeCycle) {
                throw ValidationException::withMessages(['delivery' => 'Phone custody changed. Return was not saved.']);
            }
            app(StockStateMachine::class)->transition($phone, Custody::InUnit, $branch->id, InventoryEventType::ReturnReceived, $actor, Sellability::Available, 'Temporary delivery returned '.$delivery->reference_no, $delivery);
            $deposit = Money::of($delivery->deposit_amount);
            if ($deposit->isPositive()) {
                $accounts = app(ChartOfAccounts::class);
                app(JournalPostingService::class)->post([['account_id' => $accounts->account('2400')->id, 'debit' => $deposit->amount], ['account_id' => $accounts->paymentAccount($branch, PaymentMethod::from($refundMethod))->id, 'credit' => $deposit->amount]], 'Delivery deposit refunded '.$delivery->reference_no, BusinessClock::today(), 'delivery:'.$delivery->id.':refund', $delivery, $actor);
            }
            $delivery->update(['status' => 'RETURNED', 'active_guard' => null]);
            AuditLogger::record('delivery.returned', $delivery, ['status' => 'OUT'], ['status' => 'RETURNED', 'refunded' => $deposit->amount, 'refund_method' => $refundMethod], null, $branch->id, $actor);

            return $delivery;
        });
    }

    public function confirmSale(int $id, string $price, string $paidNow, string $method, string $dueDate, User $actor): Sale
    {
        Gate::forUser($actor)->authorize('pos.sell');
        $data = Validator::make(compact('price', 'paidNow', 'method', 'dueDate'), [
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'paidNow' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'method' => ['required', 'in:CASH,BANK_TRANSFER'],
            'dueDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.BusinessClock::today()->toDateString()],
        ])->validate();

        return DB::transaction(function () use ($id, $price, $paidNow, $method, $dueDate, $actor) {
            $delivery = ExternalDelivery::findOrFail($id);
            abort_unless($actor->hasUnitAccess($delivery->business_unit_id), 403);
            BusinessUnit::whereKey($delivery->business_unit_id)->lockForUpdate()->firstOrFail();
            $delivery = ExternalDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($delivery->status === 'SOLD') {
                return $delivery->sale;
            }
            if ($delivery->status !== 'OUT') {
                throw ValidationException::withMessages(['delivery' => 'Delivery was already returned.']);
            }
            $phone = SerializedUnit::whereKey($delivery->serialized_unit_id)->lockForUpdate()->firstOrFail();
            if ($phone->current_custody !== Custody::AtExternalParty || ! $phone->activeCycle) {
                throw ValidationException::withMessages(['delivery' => 'Phone is no longer with this recipient.']);
            }
            app(StockStateMachine::class)->transition($phone, Custody::AtExternalParty, null, InventoryEventType::Adjustment, $actor, Sellability::Available, 'External sale confirmed '.$delivery->reference_no, $delivery);
            $sale = app(SaleService::class)->post(['business_unit_id' => $delivery->business_unit_id, 'customer_id' => $delivery->customer_id, 'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $phone->id, 'unit_price' => $price]], 'payments' => Money::of($paidNow)->isPositive() ? [['method' => $method, 'amount' => $paidNow]] : [], 'idempotency_key' => 'delivery-sale:'.$delivery->id, 'notes' => 'From temporary delivery '.$delivery->reference_no, 'repayment_plan' => ['type' => 'DUE', 'first_date' => $dueDate]], $actor, null, $delivery);
            $delivery->update(['status' => 'SOLD', 'active_guard' => null, 'sale_id' => $sale->id]);
            AuditLogger::record('delivery.sold', $delivery, ['status' => 'OUT'], ['sale_id' => $sale->id, 'status' => 'SOLD'], null, $delivery->business_unit_id, $actor);

            return $sale;
        });
    }
}
