<?php

declare(strict_types=1);

namespace App\Exceptions;

class InvalidImeiException extends ErpException
{
    public static function format(string $field, string $value): self
    {
        return new self(
            "IMEI [{$value}] is not a valid 15-digit IMEI (Luhn check failed). Flag it as legacy only with an explicit reason.",
            422,
            [$field => 'Invalid IMEI: expected 15 digits passing the Luhn checksum, or a flagged legacy override.'],
        );
    }

    public static function sameSlot(): self
    {
        return new self(
            'IMEI 1 and IMEI 2 must be different values.',
            422,
            ['secondary_imei' => 'IMEI 2 must differ from IMEI 1.'],
        );
    }

    public static function missing(): self
    {
        return new self(
            'At least one identifier (IMEI 1, IMEI 2 or an internal serial) is required.',
            422,
            ['primary_imei' => 'Provide IMEI 1 or confirm an internally generated serial.'],
        );
    }

    public static function internalSerialNotAllowed(): self
    {
        return new self(
            'This device has no IMEI. Enable "generate internal serial" and give a reason to register it.',
            422,
            ['primary_imei' => 'An internal serial requires explicit confirmation and a reason.'],
        );
    }
}
