<?php

declare(strict_types=1);

namespace App\Enums;

/** Why an inventory cycle stopped being the active owned cost basis. */
enum CycleCloseReason: string
{
    case Sold = 'SOLD';
    case WrittenOff = 'WRITTEN_OFF';
    case Adjustment = 'ADJUSTMENT';

    public function label(): string
    {
        return match ($this) {
            self::Sold => 'Sold',
            self::WrittenOff => 'Written off',
            self::Adjustment => 'Adjustment',
        };
    }
}
