<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_adds_decimal_money_without_float_error(): void
    {
        $this->assertSame('0.30', Money::of('0.10')->add('0.20')->amount);
        $this->assertSame('1.00', Money::of('0.70')->add('0.30')->amount);
    }

    public function test_it_rounds_half_away_from_zero_at_two_decimals(): void
    {
        $this->assertSame('10.01', Money::of('10.005')->amount);
        $this->assertSame('-10.01', Money::of('-10.005')->amount);
        $this->assertSame('1.00', Money::of('1.004')->amount);
    }

    public function test_it_multiplies_quantity_by_unit_price(): void
    {
        $this->assertSame('59.97', Money::of('19.99')->mul(3)->amount);
        $this->assertSame('0.00', Money::of('19.99')->mul(0)->amount);
    }

    public function test_it_subtracts_and_detects_sign(): void
    {
        $balance = Money::of('100.00')->sub('130.50');

        $this->assertSame('-30.50', $balance->amount);
        $this->assertTrue($balance->isNegative());
        $this->assertFalse($balance->isPositive());
        $this->assertSame('0.00', Money::zero()->amount);
    }

    public function test_it_converts_minor_units_both_ways(): void
    {
        $this->assertSame(123456, Money::of('1234.56')->toMinorUnits());
        $this->assertSame('1234.56', Money::fromMinorUnits(123456)->amount);
    }

    public function test_it_accepts_numeric_shapes_allowed_by_form_validation(): void
    {
        $this->assertSame('5.00', Money::of('5.')->amount);
        $this->assertSame('0.50', Money::of('.5')->amount);
        $this->assertSame('5.00', Money::of('+5')->amount);
        $this->assertSame('-0.50', Money::of('-.5')->amount);
        $this->assertSame('5.00', Money::of(' 5 ')->amount);
    }

    public function test_it_rejects_non_numeric_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('12,500');
    }
}
