<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nit\AgraniAccounts\Data\JournalEntryData;
use Nit\AgraniAccounts\Data\JournalLineData;
use Nit\AgraniAccounts\Events\JournalEntryPosted;
use Nit\AgraniAccounts\Events\JournalEntryReversed;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Models\JournalLine;
use Nit\AgraniAccounts\Models\Period;
use Nit\AgraniAccounts\Services\FiscalYearService;
use Nit\AgraniAccounts\Services\JournalService;
use Nit\AgraniAccounts\Tests\TestCase;
use Nit\Master\Models\ActivityLog;
use Nit\Master\Models\Branch;
use PHPUnit\Framework\Attributes\DataProvider;

class JournalPostingTest extends TestCase
{
    private Branch $branch;

    /** @var array{cash: Account, receivable: Account, sales: Account, expense: Account} */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = $this->branch('Dhaka');
        $this->accounts = $this->chart();
        $this->fiscalYear();
    }

    private function journals(): JournalService
    {
        return app(JournalService::class);
    }

    /** @param  list<JournalLineData>  $lines */
    private function entry(array $lines, string $date = '2026-08-15'): JournalEntryData
    {
        return new JournalEntryData($date, $lines, $this->branch->id);
    }

    public function test_a_draft_has_no_number_and_posting_gives_number_period_and_year(): void
    {
        $draft = $this->journals()->createDraft($this->saleData($this->branch, chart: $this->accounts));
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->entry_no);
        $this->assertNull($draft->period_id);

        $posted = $this->journals()->post($draft);

        $this->assertSame('posted', $posted->status);
        $this->assertSame('JV-DHA-2026-00001', $posted->entry_no);
        $this->assertSame('Aug 2026', $posted->period->name);
        $this->assertSame('FY 2026-27', $posted->fiscalYear->name);
        $this->assertNotNull($posted->posted_at);
        $this->assertSame(['1000.00', '0.00'], [$posted->lines[0]->debit, $posted->lines[0]->credit]);
    }

    public function test_numbers_follow_each_other_per_branch_and_financial_year(): void
    {
        $sylhet = $this->branch('Sylhet');
        app(FiscalYearService::class)->create('FY 2027-28', '2027-07-01', '2028-06-30');

        $numbers = [
            $this->journals()->record($this->saleData($this->branch, chart: $this->accounts))->entry_no,
            $this->journals()->record($this->saleData($this->branch, chart: $this->accounts))->entry_no,
            $this->journals()->record($this->saleData($sylhet, chart: $this->accounts))->entry_no,
            $this->journals()->record($this->saleData($this->branch, date: '2027-07-01', chart: $this->accounts))->entry_no,
        ];

        $this->assertSame(['JV-DHA-2026-00001', 'JV-DHA-2026-00002', 'JV-SYL-2026-00001', 'JV-DHA-2027-00001'], $numbers);
    }

    /**
     * @return array<string, array{list<array{0: string, 1: string, 2: string}>, string}>
     */
    public static function unpostableEntries(): array
    {
        return [
            'one line' => [[['cash', '100', '0']], 'too_few_lines'],
            'unbalanced' => [[['cash', '100', '0'], ['sales', '0', '99.99']], 'unbalanced'],
            'all zero' => [[['cash', '0', '0'], ['sales', '0', '0']], 'invalid_line'],
            'both sides on a line' => [[['cash', '100', '100'], ['sales', '0', '0']], 'invalid_line'],
            'negative amount' => [[['cash', '-100', '0'], ['sales', '0', '-100']], 'invalid_line'],
            'three decimals' => [[['cash', '10.005', '0'], ['sales', '0', '10.005']], 'invalid_line'],
            'control account without party' => [[['receivable', '100', '0'], ['sales', '0', '100']], 'invalid_line'],
        ];
    }

    /** @param  list<array{0: string, 1: string, 2: string}>  $lines */
    #[DataProvider('unpostableEntries')]
    public function test_unbalanced_or_invalid_journals_are_refused(array $lines, string $reason): void
    {
        try {
            $this->journals()->record($this->entry(array_map(fn (array $line): JournalLineData => new JournalLineData($this->accounts[$line[0]]->id, $line[1], $line[2]), $lines)));
            $this->fail('The journal was posted.');
        } catch (AccountingException $exception) {
            $this->assertSame($reason, $exception->reason);
        }

        $this->assertSame(0, JournalEntry::query()->count(), 'A refused journal leaves nothing behind.');
        $this->assertSame(0, JournalLine::query()->count());
    }

    public function test_inactive_accounts_cannot_be_used(): void
    {
        $this->accounts['expense']->update(['is_active' => false]);

        $this->expectException(AccountingException::class);
        $this->journals()->record($this->entry([JournalLineData::debit($this->accounts['expense']->id, '5'), JournalLineData::credit($this->accounts['cash']->id, '5')]));
    }

    public function test_control_account_lines_with_a_party_post(): void
    {
        $entry = $this->journals()->record($this->entry([
            JournalLineData::debit($this->accounts['receivable']->id, '700', partyId: 42),
            JournalLineData::credit($this->accounts['sales']->id, '700'),
        ]));

        $this->assertSame(42, $entry->lines[0]->party_id);
    }

    public function test_a_configured_parties_table_is_checked(): void
    {
        config(['accounts.parties.table' => 'branches']);

        $this->expectException(AccountingException::class);
        $this->journals()->record($this->entry([
            JournalLineData::debit($this->accounts['receivable']->id, '700', partyId: 999999),
            JournalLineData::credit($this->accounts['sales']->id, '700'),
        ]));
    }

    public function test_foreign_currency_lines_need_their_amount_on_the_same_side_and_accounts_can_be_restricted(): void
    {
        $usdLine = fn (string $fcDebit) => new JournalLineData($this->accounts['cash']->id, debit: '12225', currencyCode: 'usd', exchangeRate: '122.25', fcDebit: $fcDebit);

        $entry = $this->journals()->record($this->entry([$usdLine('100'), JournalLineData::credit($this->accounts['sales']->id, '12225')]));
        $this->assertSame(['USD', '122.25000000', '100.00'], [$entry->lines[0]->currency_code, $entry->lines[0]->exchange_rate, $entry->lines[0]->fc_debit]);

        try {
            $this->journals()->record($this->entry([$usdLine('0'), JournalLineData::credit($this->accounts['sales']->id, '12225')]));
            $this->fail('A USD line without its USD amount was accepted.');
        } catch (AccountingException) {
        }

        $this->accounts['expense']->update(['currency_code' => 'USD']);
        $this->expectException(AccountingException::class);
        $this->journals()->record($this->entry([JournalLineData::debit($this->accounts['expense']->id, '5'), JournalLineData::credit($this->accounts['cash']->id, '5')]));
    }

    public function test_journals_post_only_into_an_open_period_of_an_open_year(): void
    {
        $period = Period::query()->where('name', 'Aug 2026')->firstOrFail();
        app(FiscalYearService::class)->closePeriod($period);

        foreach (['2026-08-15', '2025-01-01'] as $date) {
            try {
                $this->journals()->record($this->saleData($this->branch, date: $date, chart: $this->accounts));
                $this->fail("Posted into {$date}.");
            } catch (AccountingException $exception) {
                $this->assertSame('period_closed', $exception->reason);
            }
        }

        $draft = $this->journals()->createDraft($this->saleData($this->branch, chart: $this->accounts));
        try {
            $this->journals()->post($draft);
        } catch (AccountingException) {
        }
        $this->assertSame('draft', $draft->fresh()->status, 'A failed posting leaves the draft as it was.');

        $this->assertSame('posted', $this->journals()->record($this->saleData($this->branch, date: '2026-09-01', chart: $this->accounts))->status);
    }

    public function test_a_posted_journal_and_its_lines_are_immutable(): void
    {
        $entry = $this->journals()->record($this->saleData($this->branch, chart: $this->accounts));
        $line = $entry->lines[0];

        $attempts = [
            'edit the entry' => fn () => $entry->update(['narration' => 'Changed']),
            'delete the entry' => fn () => $entry->delete(),
            'edit a line' => fn () => $line->update(['debit' => '1.00']),
            'delete a line' => fn () => $line->delete(),
            'add a line' => fn () => $entry->lines()->create(['line_no' => 9, 'account_id' => $this->accounts['cash']->id, 'debit' => '1']),
            'post again' => fn () => $this->journals()->post($entry),
            'edit as draft' => fn () => $this->journals()->updateDraft($entry, $this->saleData($this->branch, chart: $this->accounts)),
            'delete as draft' => fn () => $this->journals()->deleteDraft($entry),
            'reverse by hand' => fn () => $entry->forceFill(['status' => 'draft'])->save(),
        ];

        foreach ($attempts as $what => $attempt) {
            try {
                $attempt();
                $this->fail("Could {$what}.");
            } catch (AccountingException) {
            }
        }

        $fresh = $entry->fresh('lines');
        $this->assertSame(['posted', 'Cash sale', '1000.00', 2], [$fresh->status, $fresh->narration, $fresh->lines[0]->debit, $fresh->lines->count()]);
    }

    public function test_drafts_can_be_changed_and_deleted(): void
    {
        $draft = $this->journals()->createDraft($this->saleData($this->branch, chart: $this->accounts));

        $updated = $this->journals()->updateDraft($draft, $this->saleData($this->branch, amount: '250.00', chart: $this->accounts));
        $this->assertSame(['250.00', '250.00'], [$updated->lines[0]->debit, $updated->lines[1]->credit]);
        $this->assertCount(2, $updated->lines);

        $this->journals()->deleteDraft($updated);
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, JournalLine::query()->count());
    }

    public function test_recording_the_same_source_twice_keeps_one_journal(): void
    {
        $source = new JournalEntryData('2026-08-15', [JournalLineData::debit($this->accounts['cash']->id, '50'), JournalLineData::credit($this->accounts['sales']->id, '50')], $this->branch->id, 'system', sourceType: 'Invoice', sourceId: 7, sourceKey: 'invoice:7');

        $first = $this->journals()->record($source);
        $second = $this->journals()->record($source);

        $this->assertTrue($first->is($second));
        $this->assertSame(['Invoice', 7, 'invoice:7'], [$first->source_type, $first->source_id, $first->source_key]);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_reversal_posts_the_opposite_entry_and_marks_the_original(): void
    {
        $original = $this->journals()->record($this->entry([
            JournalLineData::debit($this->accounts['receivable']->id, '700', partyId: 5),
            JournalLineData::credit($this->accounts['sales']->id, '700'),
        ]));

        $reversal = $this->journals()->reverse($original, '2026-09-02', 'Invoice cancelled');

        $this->assertSame(['posted', 'reversal', $original->id, $original->entry_no, 'Invoice cancelled'], [$reversal->status, $reversal->entry_type, $reversal->reversal_of_id, $reversal->reference, $reversal->narration]);
        $this->assertSame([['0.00', '700.00', 5], ['700.00', '0.00', null]], $reversal->lines->map(fn ($line): array => [$line->debit, $line->credit, $line->party_id])->all());
        $this->assertSame('Sep 2026', $reversal->period->name);
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertTrue($original->fresh()->reversal->is($reversal));
        $this->assertNotNull($original->fresh()->reversed_at);
    }

    public function test_a_journal_is_reversed_once_and_a_reversal_is_never_reversed(): void
    {
        $original = $this->journals()->record($this->saleData($this->branch, chart: $this->accounts));
        $reversal = $this->journals()->reverse($original, '2026-08-20');

        foreach (['again' => $original, 'the reversal' => $reversal] as $what => $entry) {
            try {
                $this->journals()->reverse($entry, '2026-08-21');
                $this->fail("Could reverse {$what}.");
            } catch (AccountingException) {
            }
        }

        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_a_failed_reversal_changes_nothing(): void
    {
        $original = $this->journals()->record($this->saleData($this->branch, chart: $this->accounts));
        app(FiscalYearService::class)->closePeriod(Period::query()->where('name', 'Sep 2026')->firstOrFail());

        try {
            $this->journals()->reverse($original, '2026-09-10');
            $this->fail('Reversed into a closed period.');
        } catch (AccountingException) {
        }

        $this->assertSame('posted', $original->fresh()->status);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_a_journal_using_a_now_inactive_account_can_still_be_reversed(): void
    {
        $original = $this->journals()->record($this->saleData($this->branch, chart: $this->accounts));
        $this->accounts['sales']->update(['is_active' => false]);

        $this->assertSame('posted', $this->journals()->reverse($original, '2026-08-20')->status);
    }

    public function test_events_are_dispatched_after_commit_and_activity_is_logged_with_the_branch(): void
    {
        Event::fake([JournalEntryPosted::class, JournalEntryReversed::class]);

        DB::transaction(function (): void {
            $this->journals()->record($this->saleData($this->branch, chart: $this->accounts));
            Event::assertNotDispatched(JournalEntryPosted::class);
        });
        Event::assertDispatched(JournalEntryPosted::class);

        $entry = JournalEntry::query()->firstOrFail();
        $this->journals()->reverse($entry, '2026-08-20');
        Event::assertDispatched(JournalEntryReversed::class, fn (JournalEntryReversed $event): bool => $event->original->is($entry));

        $logged = ActivityLog::query()->where('event', 'acc_journal.posted')->firstOrFail();
        $this->assertSame($this->branch->id, $logged->properties['branch_id']);
        $this->assertSame('1000.00', $logged->properties['total']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'acc_journal.reversed', 'subject_id' => $entry->id]);
    }
}
