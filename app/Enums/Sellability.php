<?php

declare(strict_types=1);

namespace App\Enums;

/** Whether the physical unit may be sold, reserved, quarantined or is gone. */
enum Sellability: string
{
    case Available = 'AVAILABLE';
    case Reserved = 'RESERVED';
    case Quarantined = 'QUARANTINED';
    case NotForSale = 'NOT_FOR_SALE';
    case Sold = 'SOLD';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::Quarantined => 'Quarantined',
            self::NotForSale => 'Not for sale',
            self::Sold => 'Sold',
        };
    }

    public function isSellable(): bool
    {
        return $this === self::Available;
    }
}
