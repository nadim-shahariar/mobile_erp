<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Exceptions\DomainRuleViolationException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Purchase;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only place journal entries are written.
 *
 * A posted entry must balance exactly; each line carries a debit or a credit,
 * never both. Source keys make posting idempotent, which is what prevents a
 * retried request from doubling revenue or inventory.
 */
final class JournalPostingService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  list<array{account_id: int, debit?: string|int|float|null, credit?: string|int|float|null, memo?: ?string}>  $lines
     */
    public function post(
        array $lines,
        string $memo,
        CarbonInterface|string|null $entryDate = null,
        ?string $sourceKey = null,
        ?Model $source = null,
        ?User $actor = null,
    ): JournalEntry {
        if ($sourceKey !== null) {
            $existing = JournalEntry::query()->where('source_key', $sourceKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $prepared = [];
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        foreach ($lines as $index => $line) {
            $debit = Money::of($line['debit'] ?? '0');
            $credit = Money::of($line['credit'] ?? '0');

            if ($debit->isNegative() || $credit->isNegative()) {
                throw DomainRuleViolationException::message(
                    'Journal line amounts cannot be negative.',
                    ["lines.{$index}" => 'Negative debit/credit is not allowed.'],
                );
            }

            if ($debit->isZero() && $credit->isZero()) {
                continue;
            }

            if ($debit->isPositive() && $credit->isPositive()) {
                throw DomainRuleViolationException::message(
                    'A journal line cannot carry both a debit and a credit.',
                    ["lines.{$index}" => 'Use one side per line.'],
                );
            }

            $prepared[] = [
                'account_id' => (int) $line['account_id'],
                'debit' => $debit->amount,
                'credit' => $credit->amount,
                'memo' => $line['memo'] ?? null,
            ];

            $totalDebit = $totalDebit->add($debit);
            $totalCredit = $totalCredit->add($credit);
        }

        if (count($prepared) < 2) {
            throw DomainRuleViolationException::message(
                'A journal entry needs at least two lines.',
                ['journal' => 'Insufficient lines.'],
            );
        }

        if (! $totalDebit->equals($totalCredit)) {
            throw DomainRuleViolationException::message(
                "Journal is not balanced: debit {$totalDebit->amount}, credit {$totalCredit->amount}.",
                ['journal' => 'Debits must equal credits.'],
            );
        }

        return DB::transaction(function () use ($prepared, $memo, $entryDate, $sourceKey, $source, $actor, $totalDebit): JournalEntry {
            $entry = JournalEntry::create([
                'entry_no' => $this->numbers->next('journal'),
                'entry_date' => BusinessClock::toUtc($entryDate)->setTimezone(BusinessClock::timezone())->toDateString(),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'source_key' => $sourceKey,
                'memo' => $memo,
                'posted_at' => BusinessClock::utcNow(),
                'created_by' => $actor?->getKey(),
            ]);

            foreach ($prepared as $line) {
                $entry->lines()->create($line);
            }

            AuditLogger::record(
                'accounting.journal_posted',
                $entry,
                [],
                ['entry_no' => $entry->entry_no, 'total' => $totalDebit->amount, 'source_key' => $sourceKey],
                $memo,
                $source instanceof Purchase ? $source->business_unit_id : null,
                $actor,
            );

            return $entry->load('lines');
        });
    }

    public function reverse(JournalEntry $entry, string $memo, ?User $actor = null): JournalEntry
    {
        $lines = $entry->lines->map(fn (JournalLine $line) => [
            'account_id' => $line->account_id,
            'debit' => (string) $line->credit,
            'credit' => (string) $line->debit,
            'memo' => $line->memo,
        ])->all();

        return $this->post(
            lines: $lines,
            memo: $memo,
            entryDate: BusinessClock::today(),
            sourceKey: 'reversal:'.$entry->getKey(),
            source: $entry,
            actor: $actor,
        );
    }
}
