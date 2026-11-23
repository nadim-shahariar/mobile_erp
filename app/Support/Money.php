<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Immutable BDT money value object backed by bcmath decimal strings.
 *
 * Financial code must never use IEEE floats. Every amount that reaches the
 * database is a string produced here (or by a validated form request) and is
 * stored in a DECIMAL(18,2) column.
 */
final class Money implements \Stringable
{
    public const SCALE = 2;

    private function __construct(public readonly string $amount) {}

    public static function zero(): self
    {
        return new self('0.00');
    }

    public static function of(string|int|float|self|null $amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }

        if ($amount === null || $amount === '') {
            return self::zero();
        }

        if (is_float($amount)) {
            // Floats are accepted only at the edge (e.g. legacy CSV import),
            // and are converted with a fixed precision before any arithmetic.
            $amount = number_format($amount, 6, '.', '');
        }

        $string = trim((string) $amount);

        // Accept the numeric shapes Laravel's `numeric` / `decimal:0,2` rules
        // allow (`5.`, `.5`, `+5`) but normalise them before bcmath. `+` and a
        // trailing dot are common while typing a POS amount.
        if (! preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $string)) {
            throw new InvalidArgumentException("Invalid money amount [{$string}].");
        }

        $string = ltrim($string, '+');

        if (str_starts_with($string, '-.')) {
            $string = '-0'.substr($string, 1);
        } elseif (str_starts_with($string, '.')) {
            $string = '0'.$string;
        }

        if (str_ends_with($string, '.')) {
            $string .= '0';
        }

        return new self(self::roundToScale($string));
    }

    /**
     * Round an arbitrary decimal string half-away-from-zero to the currency
     * scale using bcmath only (no float conversion).
     */
    public static function roundToScale(string $value, int $scale = self::SCALE): string
    {
        $shifted = bcmul($value, bcpow('10', (string) $scale), 8);
        $sign = str_starts_with($shifted, '-') ? -1 : 1;
        $absolute = ltrim($shifted, '-');
        $rounded = bcadd($absolute, '0.5', 0);

        if ($sign < 0) {
            $rounded = '-'.$rounded;
        }

        return bcdiv($rounded, bcpow('10', (string) $scale), $scale);
    }

    public static function fromMinorUnits(int $minorUnits): self
    {
        return new self(bcdiv((string) $minorUnits, '100', self::SCALE));
    }

    public function toMinorUnits(): int
    {
        return (int) bcmul($this->amount, '100', 0);
    }

    public function add(self|string|int $other): self
    {
        return new self(bcadd($this->amount, self::of($other)->amount, self::SCALE));
    }

    public function sub(self|string|int $other): self
    {
        return new self(bcsub($this->amount, self::of($other)->amount, self::SCALE));
    }

    public function mul(string|int $factor): self
    {
        return new self(self::roundToScale(bcmul($this->amount, (string) $factor, 8)));
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) > 0;
    }

    public function greaterThan(self|string|int $other): bool
    {
        return bccomp($this->amount, self::of($other)->amount, self::SCALE) > 0;
    }

    public function lessThan(self|string|int $other): bool
    {
        return bccomp($this->amount, self::of($other)->amount, self::SCALE) < 0;
    }

    public function equals(self|string|int $other): bool
    {
        return bccomp($this->amount, self::of($other)->amount, self::SCALE) === 0;
    }

    public function min(self $other): self
    {
        return $this->lessThan($other) ? $this : $other;
    }

    public function formatted(): string
    {
        return number_format((float) $this->amount, 2);
    }

    public function __toString(): string
    {
        return $this->amount;
    }
}
