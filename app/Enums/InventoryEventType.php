<?php

declare(strict_types=1);

namespace App\Enums;

/** Append-only event types on the IMEI lifetime ledger. */
enum InventoryEventType: string
{
    case OpeningBalance = 'OPENING_BALANCE';
    case PurchaseReceived = 'PURCHASE_RECEIVED';
    case TransferRequested = 'TRANSFER_REQUESTED';
    case TransferDispatched = 'TRANSFER_DISPATCHED';
    case TransferReceived = 'TRANSFER_RECEIVED';
    case TransferCancelled = 'TRANSFER_CANCELLED';
    case TransferRejected = 'TRANSFER_REJECTED';
    case RepairSent = 'REPAIR_SENT';
    case RepairReceived = 'REPAIR_RECEIVED';
    case InspectionPassed = 'INSPECTION_PASSED';
    case Quarantined = 'QUARANTINED';
    case Reserved = 'RESERVED';
    case ReservationReleased = 'RESERVATION_RELEASED';
    case Sold = 'SOLD';
    case TradeInAccepted = 'TRADE_IN_ACCEPTED';
    case ReturnReceived = 'RETURN_RECEIVED';
    case WrittenOff = 'WRITTEN_OFF';
    case Adjustment = 'ADJUSTMENT';
    case CostReallocated = 'COST_REALLOCATED';

    public function label(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->value)));
    }
}
