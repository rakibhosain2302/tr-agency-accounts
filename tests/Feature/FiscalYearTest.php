<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\AgraniAccounts\Models\Period;
use Nit\AgraniAccounts\Services\FiscalYearService;
use Nit\AgraniAccounts\Services\JournalService;
use Nit\AgraniAccounts\Tests\TestCase;

class FiscalYearTest extends TestCase
{
    private function years(): FiscalYearService
    {
        return app(FiscalYearService::class);
    }

    public function test_a_year_gets_twelve_monthly_periods(): void
    {
        $year = $this->fiscalYear('FY 2026-27', '2026-07-01', '2027-06-30');

        $this->assertCount(12, $year->periods);
        $this->assertSame(['2026-07-01', '2026-07-31'], [$year->periods[0]->start_date->toDateString(), $year->periods[0]->end_date->toDateString()]);
        $this->assertSame(['2027-06-01', '2027-06-30'], [$year->periods[11]->start_date->toDateString(), $year->periods[11]->end_date->toDateString()]);
        $this->assertSame('Feb 2027', $year->periods[7]->name);
        $this->assertSame(range(1, 12), $year->periods->pluck('period_no')->all());
    }

    public function test_partial_first_and_last_months_and_a_single_period(): void
    {
        $partial = $this->years()->create('Short', '2028-01-15', '2028-03-10');
        $this->assertSame([['2028-01-15', '2028-01-31'], ['2028-02-01', '2028-02-29'], ['2028-03-01', '2028-03-10']], $partial->periods->map(fn (Period $p): array => [$p->start_date->toDateString(), $p->end_date->toDateString()])->all());

        $single = $this->years()->create('Whole', '2029-01-01', '2029-12-31', monthlyPeriods: false);
        $this->assertCount(1, $single->periods);
    }

    public function test_years_cannot_overlap_or_end_before_they_start(): void
    {
        $this->fiscalYear('FY 2026-27', '2026-07-01', '2027-06-30');

        foreach ([['Overlap', '2027-06-30', '2028-06-29'], ['Backwards', '2030-01-01', '2029-12-31'], ['Same day', '2031-01-01', '2031-01-01']] as [$name, $start, $end]) {
            try {
                $this->years()->create($name, $start, $end);
                $this->fail("{$name} was created.");
            } catch (AccountingException $exception) {
                $this->assertSame('invalid_fiscal_year', $exception->reason);
            }
        }

        $this->assertSame(1, FiscalYear::query()->count());
    }

    public function test_closing_a_year_closes_its_periods_and_reopening_needs_an_open_year(): void
    {
        $year = $this->fiscalYear();

        $this->years()->close($year);
        $this->assertSame('closed', $year->fresh()->status);
        $this->assertSame(0, $year->periods()->where('status', 'open')->count());
        $this->assertNotNull($year->fresh()->closed_at);

        try {
            $this->years()->reopenPeriod($year->periods()->first());
            $this->fail('A period of a closed year was reopened.');
        } catch (AccountingException) {
        }

        $this->years()->reopen($year);
        $period = $this->years()->reopenPeriod($year->periods()->first());
        $this->assertSame('open', $period->status);
        $this->assertNull($period->closed_at);
    }

    public function test_a_year_is_changed_as_it_was_created_while_nothing_is_posted(): void
    {
        $year = $this->fiscalYear('FY 2026-27', '2026-07-01', '2027-06-30');
        $this->assertTrue($year->hasMonthlyPeriods());

        $changed = $this->years()->update($year, 'FY 2027', '2027-01-01', '2027-12-31', monthlyPeriods: true);
        $this->assertSame(['FY 2027', '2027-01-01', '2027-12-31'], [$changed->name, $changed->start_date->toDateString(), $changed->end_date->toDateString()]);
        $this->assertSame(['Jan 2027', 'Dec 2027'], [$changed->periods->first()->name, $changed->periods->last()->name]);
        $this->assertSame(12, Period::query()->count());

        $whole = $this->years()->update($changed, 'FY 2027', '2027-01-01', '2027-12-31', monthlyPeriods: false);
        $this->assertFalse($whole->hasMonthlyPeriods());
        $this->assertSame(['FY 2027'], $whole->periods->pluck('name')->all());

        // A rename of a one-period year renames its period too.
        $this->assertSame(['Calendar 2027'], $this->years()->update($whole, 'Calendar 2027')->periods->pluck('name')->all());
    }

    public function test_a_changed_year_still_cannot_overlap_or_end_before_it_starts(): void
    {
        $this->fiscalYear('FY 2025-26', '2025-07-01', '2026-06-30');
        $year = $this->fiscalYear('FY 2026-27', '2026-07-01', '2027-06-30');

        foreach ([['2026-06-01', '2027-05-31'], ['2027-06-30', '2026-07-01']] as [$start, $end]) {
            try {
                $this->years()->update($year, 'FY 2026-27', $start, $end);
                $this->fail("{$start} – {$end} was saved.");
            } catch (AccountingException $exception) {
                $this->assertSame('invalid_fiscal_year', $exception->reason);
            }
        }

        // Its own dates do not count as an overlap.
        $this->assertSame('2026-08-01', $this->years()->update($year, 'FY 2026-27', '2026-08-01', '2027-06-30')->start_date->toDateString());
    }

    public function test_a_year_with_postings_or_a_closed_year_keeps_its_dates(): void
    {
        $branch = $this->branch('Dhaka');
        $chart = $this->chart();
        $year = $this->fiscalYear();
        app(JournalService::class)->record($this->saleData($branch, chart: $chart));

        try {
            $this->years()->update($year, 'FY 2026-27', '2026-08-01', '2027-07-31');
            $this->fail('The dates of a year with postings changed.');
        } catch (AccountingException $exception) {
            $this->assertSame('invalid_fiscal_year', $exception->reason);
        }
        $this->assertSame('Renamed', $this->years()->update($year, 'Renamed', '2026-07-01', '2027-06-30')->name);
        $this->assertSame('2026-07-01', $year->fresh()->start_date->toDateString());

        $empty = $this->fiscalYear('FY 2028', '2028-01-01', '2028-12-31');
        $this->years()->close($empty);
        $this->expectException(AccountingException::class);
        $this->years()->update($empty, 'FY 2028', '2028-02-01', '2028-12-31');
    }

    public function test_periods_left_behind_by_a_deleted_year_are_not_posted_into(): void
    {
        $branch = $this->branch('Dhaka');
        $chart = $this->chart();
        // A closed year deleted outside the app (foreign keys off) leaves its periods behind.
        $gone = $this->fiscalYear('Old', '2026-07-01', '2027-06-30');
        $this->years()->close($gone);
        Schema::disableForeignKeyConstraints();
        DB::table('acc_fiscal_years')->where('id', $gone->id)->delete();
        Schema::enableForeignKeyConstraints();
        $this->fiscalYear('FY 2026-27', '2026-07-01', '2027-06-30');

        $entry = app(JournalService::class)->record($this->saleData($branch, chart: $chart));

        $this->assertSame('FY 2026-27', $entry->fiscalYear->name);
        $this->assertSame('Aug 2026', $entry->period->name);
    }
}
