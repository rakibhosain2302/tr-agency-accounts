<?php

namespace Nit\AgraniAccounts\Support;

use Nit\AgraniAccounts\Contracts\EntryNumberGenerator;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\Master\Models\Branch;

/**
 * Gap-free numbers per branch and financial year: the financial year row is locked while the next number
 * is taken, so two postings at once never get the same or a skipped number.
 */
final class DatabaseEntryNumberGenerator implements EntryNumberGenerator
{
    public function next(JournalEntry $entry): array
    {
        $year = FiscalYear::query()->whereKey($entry->fiscal_year_id)->lockForUpdate()->firstOrFail();

        $sequence = 1 + (int) JournalEntry::query()->withoutGlobalScopes()
            ->where('branch_id', $entry->branch_id)
            ->where('fiscal_year_id', $year->id)
            ->max('sequence_no');

        $branch = Branch::query()->find($entry->branch_id);

        $number = strtr((string) config('accounts.numbering.format', 'JV-{branch}-{year}-{number}'), [
            '{branch}' => (string) ($branch?->code ?: $entry->branch_id),
            '{year}' => $year->start_date->format('Y'),
            '{number}' => str_pad((string) $sequence, max(1, (int) config('accounts.numbering.padding', 5)), '0', STR_PAD_LEFT),
        ]);

        return ['sequence_no' => $sequence, 'entry_no' => $number];
    }
}
