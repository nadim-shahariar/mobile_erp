<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Inventory\ImeiNormalizer;
use App\Enums\ImeiStatus;
use App\Exceptions\InvalidImeiException;
use Tests\TestCase;

class ImeiNormalizerTest extends TestCase
{
    private ImeiNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ImeiNormalizer;
    }

    public function test_it_normalises_whitespace_dashes_and_case(): void
    {
        $this->assertSame('356789011000001', $this->normalizer->normalize(" 356 789-011 000001 \n"));
        $this->assertNull($this->normalizer->normalize('   '));
    }

    public function test_it_preserves_leading_zeroes_as_a_string(): void
    {
        $value = $this->normalizer->normalize('012345678901234');

        $this->assertSame('012345678901234', $value);
        $this->assertIsString($value);
    }

    public function test_it_validates_luhn_checksum(): void
    {
        $this->assertTrue($this->normalizer->passesLuhn('490154203237518'));
        $this->assertFalse($this->normalizer->passesLuhn('490154203237519'));
        $this->assertFalse($this->normalizer->isWellFormed('123456789012345'));
        $this->assertFalse($this->normalizer->isWellFormed('12345678901234'));
        $this->assertTrue($this->normalizer->isWellFormed('490154203237518'));
    }

    public function test_it_accepts_a_flagged_legacy_imei_only_when_allowed(): void
    {
        $result = $this->normalizer->validatePair('LEGACY-001', null, true, false);
        $this->assertSame(ImeiStatus::LegacyUnverified, $result['status']);

        $this->expectException(InvalidImeiException::class);
        $this->normalizer->validatePair('LEGACY-001', null, false, false);
    }

    public function test_it_requires_explicit_internal_serial_confirmation(): void
    {
        $this->expectException(InvalidImeiException::class);
        $this->normalizer->validatePair(null, null, false, false);
    }

    public function test_it_rejects_the_same_value_in_both_slots(): void
    {
        $this->expectException(InvalidImeiException::class);
        $this->normalizer->validatePair('490154203237518', '490154203237518', false, false);
    }
}
