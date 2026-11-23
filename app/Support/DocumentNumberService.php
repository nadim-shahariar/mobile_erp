<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BusinessUnit;
use App\Models\DocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transactional document numbering. Numbers are allocated from a locked
 * document_sequences row; the unique (key, unit, period) index plus retry makes
 * first allocation safe under concurrency.
 */
final class DocumentNumberService
{
    public function next(string $key, ?int $unitId = null, ?CarbonInterface $moment = null): string
    {
        $config = (array) config("erp.sequences.{$key}", [
            'prefix' => strtoupper(substr($key, 0, 3)),
            'padding' => 6,
            'period' => 'year',
        ]);

        $moment = CarbonImmutableAdapter::from($moment);
        $period = match ($config['period'] ?? 'year') {
            'year' => $moment->format('Y'),
            'month' => $moment->format('Ym'),
            default => 'GLOBAL',
        };

        $prefix = (string) $config['prefix'];
        $padding = (int) $config['padding'];

        $scopeKey = $unitId === null ? 'GLOBAL' : 'UNIT:'.$unitId;

        $number = DB::transaction(function () use ($key, $unitId, $scopeKey, $period, $prefix, $padding): int {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                /** @var DocumentSequence|null $sequence */
                $sequence = DocumentSequence::query()
                    ->where('key', $key)
                    ->where('scope_key', $scopeKey)
                    ->where('period', $period)
                    ->lockForUpdate()
                    ->first();

                if ($sequence !== null) {
                    $number = (int) $sequence->next_number;
                    $sequence->forceFill(['next_number' => $number + 1])->save();

                    return $number;
                }

                try {
                    DocumentSequence::create([
                        'key' => $key,
                        'scope_key' => $scopeKey,
                        'business_unit_id' => $unitId,
                        'period' => $period,
                        'prefix' => $prefix,
                        'padding' => $padding,
                        'next_number' => 2,
                    ]);

                    return 1;
                } catch (UniqueConstraintViolationException) {
                    // Another request created the sequence first; retry under lock.
                }
            }

            throw new RuntimeException("Unable to allocate a document number for [{$key}].");
        });

        $suffix = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
        $unitSegment = $unitId !== null
            ? '-'.(BusinessUnit::query()->whereKey($unitId)->value('code') ?? $unitId)
            : '';

        return $period === 'GLOBAL'
            ? "{$prefix}{$unitSegment}-{$suffix}"
            : "{$prefix}{$unitSegment}-{$period}-{$suffix}";
    }
}
