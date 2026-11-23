<?php

declare(strict_types=1);

namespace App\Enums;

enum ImeiStatus: string
{
    case Valid = 'VALID';
    case LegacyUnverified = 'LEGACY_UNVERIFIED';
    case InternalSerial = 'INTERNAL_SERIAL';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid IMEI',
            self::LegacyUnverified => 'Legacy / unverified',
            self::InternalSerial => 'Internal serial',
        };
    }

    /** True when the identity was positively validated (Luhn + length). */
    public function isVerified(): bool
    {
        return $this === self::Valid;
    }
}
