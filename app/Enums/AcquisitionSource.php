<?php

declare(strict_types=1);

namespace App\Enums;

/** How a new inventory cycle (ownership + cost basis) came into being. */
enum AcquisitionSource: string
{
    case Purchase = 'PURCHASE';
    case TradeIn = 'TRADE_IN';
    case OpeningBalance = 'OPENING_BALANCE';
    case LegacyImport = 'LEGACY_IMPORT';
    case Adjustment = 'ADJUSTMENT';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::TradeIn => 'Trade-in',
            self::OpeningBalance => 'Opening balance',
            self::LegacyImport => 'Legacy import',
            self::Adjustment => 'Adjustment',
        };
    }
}
