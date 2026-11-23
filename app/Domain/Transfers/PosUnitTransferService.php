<?php

namespace App\Domain\Transfers;

use App\Enums\InventoryEventType;
use App\Enums\StockTransferStatus;
use App\Exceptions\DomainRuleViolationException;
use App\Models\BusinessUnit;
use App\Models\SerializedUnit;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PosUnitTransferService
{
    public function previousTransfer(SerializedUnit $phone, int $currentUnit): ?StockTransfer
    {
        if (! $phone->isSellable() || ! $phone->current_unit_id || (int) $phone->current_unit_id === $currentUnit) {
            return null;
        }
        $event = $phone->events()->where('inventory_cycle_id', $phone->activeCycle?->id)->where('event_type', InventoryEventType::TransferReceived->value)->latest('id')->first();
        if (! $event || $event->reference_type !== (new StockTransfer)->getMorphClass()) {
            return null;
        }

        return StockTransfer::whereKey($event->reference_id)->where('status', StockTransferStatus::Received->value)->where('from_unit_id', $currentUnit)->where('to_unit_id', $phone->current_unit_id)->first();
    }

    public function move(int $fromId, int $toId, array $phoneIds, User $actor, string $key, ?string $notes = null, ?int $returnOf = null): StockTransfer
    {
        abort_unless($actor->can('stock.transfer.create') && $actor->can('stock.transfer.approve') && $actor->hasUnitAccess($fromId) && $actor->hasUnitAccess($toId), 403);

        return DB::transaction(function () use ($fromId, $toId, $phoneIds, $actor, $key, $notes, $returnOf) {
            $phones = SerializedUnit::whereIn('id', $phoneIds)->orderBy('id')->lockForUpdate()->get();
            if ($existing = StockTransfer::where('idempotency_key', $key)->first()) {
                $expectedIds = array_values(array_unique(array_map('intval', $phoneIds)));
                sort($expectedIds);
                $existingIds = $existing->lines()->whereNotNull('serialized_unit_id')->orderBy('serialized_unit_id')->pluck('serialized_unit_id')->map(fn ($id) => (int) $id)->all();
                abort_unless($existingIds === $expectedIds && (int) $existing->return_of_transfer_id === (int) $returnOf && $existing->status === StockTransferStatus::Received, 409);
                abort_unless((int) $existing->from_unit_id === $fromId && (int) $existing->to_unit_id === $toId && (int) $existing->requested_by === $actor->id, 403);

                return $existing;
            }
            if ($returnOf !== null) {
                $phone = $phones->first();
                if (count($phoneIds) !== 1 || ! $phone) {
                    throw DomainRuleViolationException::message('Scan one previously transferred phone to return.');
                }
                $previous = $this->previousTransfer($phone, $toId);
                if (! $previous || $previous->id !== $returnOf || (int) $previous->to_unit_id !== $fromId) {
                    throw DomainRuleViolationException::message('This phone is no longer available for return from that transfer.');
                }
            }
            $service = app(StockTransferService::class);
            $transfer = $service->request(BusinessUnit::findOrFail($fromId), BusinessUnit::findOrFail($toId), $phoneIds, [], $actor, $notes, $key);
            $transfer->update(['return_of_transfer_id' => $returnOf]);
            $service->dispatch($transfer, $actor);

            return $service->receive($transfer, $actor);
        });
    }
}
