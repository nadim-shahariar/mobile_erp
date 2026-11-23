<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\ImeiStatus;
use App\Exceptions\InvalidImeiException;

/**
 * IMEI canonicalisation and validation.
 *
 * IMEIs are strings, never integers: a leading zero is significant. The raw
 * value is preserved for display; the normalised value is what uniqueness is
 * enforced on.
 */
final class ImeiNormalizer
{
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = strtoupper(trim($raw));

        foreach ((array) config('erp.imei.normalize_strip', []) as $character) {
            $value = str_replace((string) $character, '', $value);
        }

        return $value === '' ? null : $value;
    }

    public function isWellFormed(?string $normalized): bool
    {
        if ($normalized === null || ! preg_match('/^\d{15}$/', $normalized)) {
            return false;
        }

        if (! config('erp.imei.require_luhn', true)) {
            return true;
        }

        return $this->passesLuhn($normalized);
    }

    public function passesLuhn(string $digits): bool
    {
        if ($digits === '' || ! ctype_digit($digits)) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Validate a pair of identifiers and return the enforced IMEI status.
     *
     * @return array{primary: ?string, secondary: ?string, status: ImeiStatus}
     *
     * @throws InvalidImeiException
     */
    public function validatePair(
        ?string $primary,
        ?string $secondary,
        bool $allowLegacy,
        bool $generateInternalSerial,
    ): array {
        $status = ImeiStatus::Valid;

        if ($primary === null && $secondary === null) {
            if (! $generateInternalSerial) {
                throw InvalidImeiException::internalSerialNotAllowed();
            }

            return [
                'primary' => null,
                'secondary' => null,
                'status' => ImeiStatus::InternalSerial,
            ];
        }

        if ($primary !== null && $primary === $secondary) {
            throw InvalidImeiException::sameSlot();
        }

        foreach (['primary_imei' => $primary, 'secondary_imei' => $secondary] as $field => $value) {
            if ($value === null) {
                continue;
            }

            if ($this->isWellFormed($value)) {
                continue;
            }

            if (! $allowLegacy) {
                throw InvalidImeiException::format($field, $value);
            }

            $status = ImeiStatus::LegacyUnverified;
        }

        return ['primary' => $primary, 'secondary' => $secondary, 'status' => $status];
    }
}
