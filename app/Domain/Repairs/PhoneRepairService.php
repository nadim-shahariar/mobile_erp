<?php

namespace App\Domain\Repairs;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Inventory\StockStateMachine;
use App\Enums\Custody;
use App\Enums\InventoryEventType;
use App\Enums\PaymentMethod;
use App\Enums\Sellability;
use App\Exceptions\DomainRuleViolationException;
use App\Models\BusinessUnit;
use App\Models\InventoryCycle;
use App\Models\PhoneRepair;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PhoneRepairService
{
    public function send(int $phoneId, int $unitId, User $actor, string $key, ?string $vendor = null, ?string $notes = null): PhoneRepair
    {
        abort_unless($actor->can('repair.create') && $actor->hasUnitAccess($unitId), 403);
        Validator::make(['vendor' => $vendor, 'notes' => $notes, 'key' => $key], ['vendor' => 'nullable|string|max:180', 'notes' => 'nullable|string|max:2000', 'key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($phoneId, $unitId, $actor, $key, $vendor, $notes) {
            $phone = SerializedUnit::whereKey($phoneId)->lockForUpdate()->firstOrFail();
            if ($existing = PhoneRepair::where('idempotency_key', $key)->first()) {
                abort_unless($existing->serialized_unit_id === $phoneId && $existing->business_unit_id === $unitId, 403);

                return $existing;
            }
            if ((int) $phone->current_unit_id !== $unitId || ! $phone->isSellable() || ! BusinessUnit::active()->whereKey($unitId)->exists()) {
                throw DomainRuleViolationException::message('Only an available phone in the current unit can be sent to repair.');
            }
            $repair = PhoneRepair::create(['business_unit_id' => $unitId, 'serialized_unit_id' => $phoneId, 'inventory_cycle_id' => $phone->activeCycle->id, 'vendor_name' => $vendor, 'notes' => $notes, 'status' => 'OUT', 'sent_by' => $actor->id, 'sent_at' => BusinessClock::utcNow(), 'idempotency_key' => $key]);
            app(StockStateMachine::class)->transition($phone, Custody::AtRepairVendor, null, InventoryEventType::RepairSent, $actor, Sellability::NotForSale, $notes ?? 'Sent to repair', $repair, ['repair_id' => $repair->id, 'vendor' => $vendor, 'unit_id' => $unitId], 'repair:'.$repair->id.':send');
            AuditLogger::record('repair.sent', $repair, [], ['phone_id' => $phoneId, 'vendor' => $vendor], null, $unitId, $actor);

            return $repair;
        });
    }

    public function receive(int $repairId, string $cost, string $method, User $actor, ?string $notes = null): PhoneRepair
    {
        $ticket = PhoneRepair::findOrFail($repairId);
        abort_unless($actor->can('repair.receive') && $actor->hasUnitAccess($ticket->business_unit_id), 403);
        Validator::make(['cost' => $cost, 'method' => $method, 'notes' => $notes], ['cost' => 'required|numeric|decimal:0,2|min:0|max:999999999', 'method' => ['required', Rule::enum(PaymentMethod::class)], 'notes' => 'nullable|string|max:2000'])->validate();

        return DB::transaction(function () use ($ticket, $cost, $method, $notes, $actor) {
            $phone = SerializedUnit::whereKey($ticket->serialized_unit_id)->lockForUpdate()->firstOrFail();
            $repair = PhoneRepair::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($repair->status === 'RECEIVED') {
                return $repair;
            }
            $cycle = InventoryCycle::whereKey($repair->inventory_cycle_id)->lockForUpdate()->firstOrFail();
            if ($phone->current_custody !== Custody::AtRepairVendor || $cycle->closed_at || $phone->lastEvent?->reference_type !== $repair->getMorphClass() || (int) $phone->lastEvent?->reference_id !== $repair->id) {
                throw DomainRuleViolationException::message('The phone is no longer at repair for this ticket.');
            }
            $unit = BusinessUnit::active()->findOrFail($repair->business_unit_id);
            $value = Money::of($cost);
            if ($value->isPositive()) {
                $cycle->update(['allocated_direct_cost' => Money::of($cycle->allocated_direct_cost)->add($value)->amount, 'effective_cost_basis' => Money::of($cycle->effective_cost_basis)->add($value)->amount]);
                $accounts = app(ChartOfAccounts::class);
                app(JournalPostingService::class)->post([
                    ['account_id' => $accounts->handsetInventory()->id, 'debit' => $value->amount],
                    ['account_id' => $accounts->paymentAccount($unit, PaymentMethod::from($method))->id, 'credit' => $value->amount],
                ], 'Phone repair cost '.$phone->primaryIdentifier(), sourceKey: 'repair:'.$repair->id.':cost', source: $repair, actor: $actor);
            }
            app(StockStateMachine::class)->transition($phone, Custody::InUnit, $unit->id, InventoryEventType::RepairReceived, $actor, Sellability::Available, $notes ?? 'Returned from repair', $repair, ['repair_cost' => $value->amount, 'payment_method' => $method], 'repair:'.$repair->id.':receive');
            $repair->update(['status' => 'RECEIVED', 'cost' => $value->amount, 'payment_method' => $method, 'return_notes' => $notes, 'received_by' => $actor->id, 'received_at' => BusinessClock::utcNow()]);
            AuditLogger::record('repair.received',$repair,['status' => 'OUT'],['cost' => $value->amount, 'payment_method' => $method, 'status' => 'RECEIVED'],null,$unit->id,$actor);

            return $repair;
        });
    }
}
