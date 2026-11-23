<?php

declare(strict_types=1);

namespace App\Exceptions;

class DuplicateImeiException extends ErpException
{
    public static function forValue(string $normalizedValue, string $field = 'primary_imei'): self
    {
        return new self(
            "IMEI [{$normalizedValue}] is already registered to another handset.",
            422,
            [$field => 'This IMEI already exists on another handset identity.'],
        );
    }
}
