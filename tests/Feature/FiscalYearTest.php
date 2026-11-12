<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\AgraniAccounts\Models\Period;
use Nit\AgraniAccounts\Services\FiscalYearService;
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
}
