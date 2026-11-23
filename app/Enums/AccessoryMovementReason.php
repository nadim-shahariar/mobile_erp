<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessoryMovementReason: string
{
    case Opening = 'OPENING';
    case PurchaseReceipt = 'PURCHASE_RECEIPT';
    case Sale = 'SALE';
    case SaleReturn = 'SALE_RETURN';
    case AdjustmentIn = 'ADJUSTMENT_IN';
    case AdjustmentOut = 'ADJUSTMENT_OUT';
    case TransferIn = 'TRANSFER_IN';
    case TransferOut = 'TRANSFER_OUT';
    case WriteOff = 'WRITE_OFF';

    public function label(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->value)));
    }

    public function isInflow(): bool
    {
        return in_array($this, [
            self::Opening,
            self::PurchaseReceipt,
            self::SaleReturn,
            self::AdjustmentIn,
            self::TransferIn,
        ], true);
    }
}
