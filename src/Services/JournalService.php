<?php

namespace Nit\AgraniAccounts\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniAccounts\Contracts\EntryNumberGenerator;
use Nit\AgraniAccounts\Data\JournalEntryData;
use Nit\AgraniAccounts\Data\JournalLineData;
use Nit\AgraniAccounts\Events\JournalEntryPosted;
use Nit\AgraniAccounts\Events\JournalEntryReversed;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Models\JournalLine;
use Nit\AgraniAccounts\Models\Period;
use Nit\AgraniAccounts\Support\Decimal;
use Nit\Master\Support\Activity;

/**
 * Journal entries: drafts, posting and reversal, each in one database transaction with the entry row locked.
 *
 * Posting checks that the entry is a draft with at least two lines, every line is one positive debit or
 * credit on an active account (control accounts need a party), the total debit equals the total credit,
 * and the date falls in an open period of an open financial year. It then gives the entry its number.
 */
final class JournalService
{
    public function __construct(private EntryNumberGenerator $numbers) {}

    public function createDraft(JournalEntryData $data): JournalEntry
    {
        return DB::transaction(function () use ($data): JournalEntry {
            $entry = JournalEntry::query()->create([
                'branch_id' => $data->branchId,
                'entry_date' => Carbon::parse($data->entryDate)->toDateString(),
                'entry_type' => $data->entryType,
                'status' => JournalEntry::DRAFT,
                'reference' => $data->reference,
                'narration' => $data->narration,
                'source_type' => $data->sourceType,
                'source_id' => $data->sourceId,
                'source_key' => $data->sourceKey,
            ]);
            $this->writeLines($entry, $data->lines, $data->entryType === 'reversal');

            return $entry->load('lines');
        });
    }

    public function updateDraft(JournalEntry $entry, JournalEntryData $data): JournalEntry
    {
        return DB::transaction(function () use ($entry, $data): JournalEntry {
            $entry = $this->lockedDraft($entry);
            $entry->update([
                'branch_id' => $data->branchId ?? $entry->branch_id,
                'entry_date' => Carbon::parse($data->entryDate)->toDateString(),
                'reference' => $data->reference,
                'narration' => $data->narration,
            ]);
            $entry->lines()->get()->each->delete();
            $this->writeLines($entry, $data->lines);

            return $entry->load('lines');
        });
    }

    public function deleteDraft(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $entry = $this->lockedDraft($entry);
            $entry->lines()->get()->each->delete();
            $entry->delete();
        });
    }

    public function post(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($entry): JournalEntry {
            $entry = $this->lockedDraft($entry);
            $lines = $entry->lines()->get();

            // A reversal may use an account that was made inactive after the original was posted.
            $this->ensureBalanced($lines->all(), allowInactive: $entry->entry_type === 'reversal');
            $period = $this->openPeriodFor($entry->entry_date);

            $entry->forceFill(['period_id' => $period->id, 'fiscal_year_id' => $period->fiscal_year_id]);
            $number = $this->numbers->next($entry);

            $entry->forceFill([
                'status' => JournalEntry::POSTED,
                'sequence_no' => $number['sequence_no'],
                'entry_no' => $number['entry_no'],
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ])->save();

            $this->log('acc_journal.posted', "Posted journal {$entry->entry_no}", $entry, ['total' => Decimal::sum($lines->pluck('debit'))]);
            JournalEntryPosted::dispatch($entry);

            return $entry->load('lines');
        });
    }

    /**
     * Create and post in one step, e.g. for a document of another module. With a source key the same
     * document is recorded once: the entry already recorded for the key is returned.
     */
    public function record(JournalEntryData $data): JournalEntry
    {
        if ($data->sourceKey !== null && ($existing = JournalEntry::query()->withoutGlobalScopes()->where('source_key', $data->sourceKey)->first())) {
            return $existing->load('lines');
        }

        return DB::transaction(fn (): JournalEntry => $this->post($this->createDraft($data)));
    }

    /**
     * Reverse a posted entry with a new posted entry of the opposite sides, dated $date (default: today),
     * and mark the original "reversed". Both happen or neither.
     */
    public function reverse(JournalEntry $entry, \DateTimeInterface|string|null $date = null, ?string $narration = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $date, $narration): JournalEntry {
            $original = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($original->reversal_of_id !== null) {
                throw AccountingException::reversalOfReversal();
            }
            if (! $original->isPosted()) {
                throw AccountingException::notPosted($original);
            }

            $lines = $original->lines()->get()->map(fn (JournalLine $line): JournalLineData => new JournalLineData(
                accountId: $line->account_id,
                debit: $line->credit,
                credit: $line->debit,
                partyId: $line->party_id,
                currencyCode: $line->currency_code,
                exchangeRate: $line->exchange_rate,
                fcDebit: $line->fc_credit,
                fcCredit: $line->fc_debit,
                dueDate: $line->due_date,
                description: $line->description,
            ))->all();

            $reversal = $this->createDraft(new JournalEntryData(
                entryDate: $date ?? now(),
                lines: $lines,
                branchId: $original->branch_id,
                entryType: 'reversal',
                reference: $original->entry_no,
                narration: $narration ?? __('Reversal of :no', ['no' => $original->entry_no]),
                sourceType: $original->source_type,
                sourceId: $original->source_id,
            ));
            $reversal->forceFill(['reversal_of_id' => $original->id])->save();
            $reversal = $this->post($reversal);

            $original->forceFill(['status' => JournalEntry::REVERSED, 'reversed_at' => now(), 'reversed_by' => auth()->id()])->save();

            $this->log('acc_journal.reversed', "Reversed journal {$original->entry_no} with {$reversal->entry_no}", $original, ['reversal_id' => $reversal->id]);
            JournalEntryReversed::dispatch($original, $reversal);

            return $reversal;
        });
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function writeLines(JournalEntry $entry, array $lines, bool $allowInactive = false): void
    {
        foreach (array_values($lines) as $index => $line) {
            $entry->lines()->create($this->lineAttributes($line, $index + 1, $allowInactive));
        }
    }

    /** @return array<string, mixed> */
    private function lineAttributes(JournalLineData $line, int $lineNo, bool $allowInactive = false): array
    {
        $debit = Decimal::exact($line->debit);
        $credit = Decimal::exact($line->credit);
        $fcDebit = Decimal::exact($line->fcDebit);
        $fcCredit = Decimal::exact($line->fcCredit);
        $rate = Decimal::exact($line->exchangeRate, 8);

        foreach (['debit' => $debit, 'credit' => $credit, 'foreign-currency debit' => $fcDebit, 'foreign-currency credit' => $fcCredit] as $what => $value) {
            if ($value === null || Decimal::isNegative($value)) {
                throw AccountingException::invalidLine($lineNo, __('the :what must be a positive amount with at most two decimals.', ['what' => $what]));
            }
        }
        if ($rate === null || ! Decimal::isPositive($rate)) {
            throw AccountingException::invalidLine($lineNo, __('the exchange rate must be more than zero.'));
        }
        if (Decimal::isPositive($debit) === Decimal::isPositive($credit)) {
            throw AccountingException::invalidLine($lineNo, __('give either a debit or a credit, not both and not neither.'));
        }

        $account = Account::query()->with('group')->find($line->accountId);
        if ($account === null || (! $account->is_active && ! $allowInactive)) {
            throw AccountingException::invalidLine($lineNo, __('the account does not exist or is inactive.'));
        }
        if ($account->is_control && $line->partyId === null) {
            throw AccountingException::invalidLine($lineNo, __('account :code is a control account and needs a party.', ['code' => $account->code]));
        }
        if ($line->partyId !== null && ($table = config('accounts.parties.table')) && ! DB::table($table)->where('id', $line->partyId)->exists()) {
            throw AccountingException::invalidLine($lineNo, __('the party does not exist.'));
        }

        $base = strtoupper((string) config('accounts.base_currency', 'BDT'));
        $currency = $line->currencyCode !== null ? strtoupper($line->currencyCode) : null;
        if ($account->currency_code !== null && ($currency ?? $base) !== $account->currency_code) {
            throw AccountingException::invalidLine($lineNo, __('account :code takes :currency only.', ['code' => $account->code, 'currency' => $account->currency_code]));
        }
        if ($currency !== null && $currency !== $base && Decimal::isPositive($debit) !== Decimal::isPositive($fcDebit)) {
            throw AccountingException::invalidLine($lineNo, __('a :currency line needs its :currency amount on the same side.', ['currency' => $currency]));
        }

        return [
            'line_no' => $lineNo,
            'account_id' => $account->id,
            'party_id' => $line->partyId,
            'debit' => $debit,
            'credit' => $credit,
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'fc_debit' => $fcDebit,
            'fc_credit' => $fcCredit,
            'due_date' => $line->dueDate !== null ? Carbon::parse($line->dueDate)->toDateString() : null,
            'description' => $line->description,
        ];
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function ensureBalanced(array $lines, bool $allowInactive = false): void
    {
        if (count($lines) < 2) {
            throw AccountingException::tooFewLines();
        }

        foreach ($lines as $line) {
            $account = Account::query()->find($line->account_id);
            if ($account === null || (! $account->is_active && ! $allowInactive)) {
                throw AccountingException::invalidLine($line->line_no, __('the account does not exist or is inactive.'));
            }
        }

        $debit = Decimal::sum(array_map(fn (JournalLine $line): string => (string) $line->debit, $lines));
        $credit = Decimal::sum(array_map(fn (JournalLine $line): string => (string) $line->credit, $lines));

        if ($debit !== $credit || ! Decimal::isPositive($debit)) {
            throw AccountingException::unbalanced($debit, $credit);
        }
    }

    private function openPeriodFor(\DateTimeInterface $date): Period
    {
        $period = Period::query()->with('fiscalYear')->containing($date)->lockForUpdate()->first();

        if ($period === null || ! $period->isOpen() || ! $period->fiscalYear->isOpen()) {
            throw AccountingException::noOpenPeriod($date->format('Y-m-d'));
        }

        return $period;
    }

    private function lockedDraft(JournalEntry $entry): JournalEntry
    {
        $locked = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isDraft()) {
            throw AccountingException::notDraft($locked);
        }

        return $locked;
    }

    /** @param  array<string, mixed>  $properties */
    private function log(string $event, string $description, JournalEntry $entry, array $properties): void
    {
        if (config('accounts.log_activity', true)) {
            Activity::log($event, $description, $entry, ['branch_id' => $entry->branch_id] + $properties);
        }
    }
}
