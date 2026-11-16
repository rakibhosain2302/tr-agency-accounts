<?php

namespace Nit\AgraniAccounts\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Models\Period;

/**
 * Financial years and their periods: creating a year (with monthly periods, or one period), closing and
 * reopening years and periods. Years never overlap.
 */
final class FiscalYearService
{
    public function create(string $name, \DateTimeInterface|string $startDate, \DateTimeInterface|string $endDate, ?bool $monthlyPeriods = null): FiscalYear
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        $monthlyPeriods ??= (bool) config('accounts.fiscal_years.monthly_periods', true);

        if ($end->lte($start)) {
            throw AccountingException::invalidFiscalYear(__('A financial year ends after it starts.'));
        }

        return DB::transaction(function () use ($name, $start, $end, $monthlyPeriods): FiscalYear {
            $overlapping = FiscalYear::query()->lockForUpdate()
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->first();
            if ($overlapping !== null) {
                throw AccountingException::invalidFiscalYear(__('The dates overlap financial year :name.', ['name' => $overlapping->name]));
            }

            $year = FiscalYear::query()->create(['name' => $name, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]);
            $this->createPeriods($year, $start, $end, $monthlyPeriods);

            return $year->load('periods');
        });
    }

    /**
     * Changes a year as it was created: its name, dates and monthly periods. The dates and periods change only
     * while the year is open and nothing is posted into it (its periods are made again); a year with postings
     * can only be renamed.
     */
    public function update(FiscalYear $year, string $name, \DateTimeInterface|string|null $startDate = null, \DateTimeInterface|string|null $endDate = null, ?bool $monthlyPeriods = null): FiscalYear
    {
        $start = $startDate !== null ? Carbon::parse($startDate)->startOfDay() : $year->start_date->copy()->startOfDay();
        $end = $endDate !== null ? Carbon::parse($endDate)->startOfDay() : $year->end_date->copy()->startOfDay();
        $wasMonthly = $year->hasMonthlyPeriods();
        $monthlyPeriods ??= $wasMonthly;

        if ($start->isSameDay($year->start_date) && $end->isSameDay($year->end_date) && $monthlyPeriods === $wasMonthly) {
            return DB::transaction(function () use ($year, $name, $wasMonthly): FiscalYear {
                if (! $wasMonthly) {
                    $year->periods()->update(['name' => $name]);
                }
                $year->update(['name' => $name]);

                return $year->load('periods');
            });
        }

        if ($end->lte($start)) {
            throw AccountingException::invalidFiscalYear(__('A financial year ends after it starts.'));
        }

        return DB::transaction(function () use ($year, $name, $start, $end, $monthlyPeriods): FiscalYear {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);
            if (! $year->isOpen()) {
                throw AccountingException::invalidFiscalYear(__('Reopen financial year :name before changing its dates.', ['name' => $year->name]));
            }
            if ($this->hasPostings($year)) {
                throw AccountingException::invalidFiscalYear(__('Financial year :name already has postings: only its name can change.', ['name' => $year->name]));
            }
            $overlapping = FiscalYear::query()->lockForUpdate()->whereKeyNot($year->id)
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->first();
            if ($overlapping !== null) {
                throw AccountingException::invalidFiscalYear(__('The dates overlap financial year :name.', ['name' => $overlapping->name]));
            }

            $year->update(['name' => $name, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]);
            $year->periods()->delete();
            $this->createPeriods($year, $start, $end, $monthlyPeriods);

            return $year->load('periods');
        });
    }

    /** Whether a journal or a transaction is posted into the year. */
    public function hasPostings(FiscalYear $year): bool
    {
        $periodIds = $year->periods()->pluck('id');

        return JournalEntry::query()->withoutGlobalScopes()->where(fn ($query) => $query->where('fiscal_year_id', $year->id)->orWhereIn('period_id', $periodIds))->exists()
            || FinancialTransaction::query()->withoutGlobalScopes()->where('fiscal_year_id', $year->id)->exists();
    }

    /** Closes the year and every period of it: nothing can be posted into it any more. */
    public function close(FiscalYear $year): FiscalYear
    {
        return DB::transaction(function () use ($year): FiscalYear {
            foreach ($year->periods()->where('status', Period::OPEN)->get() as $period) {
                $this->closePeriod($period);
            }
            $this->setStatus($year, FiscalYear::CLOSED);

            return $year->refresh();
        });
    }

    /** Opens the year again; its periods stay as they are (reopen them one by one). */
    public function reopen(FiscalYear $year): FiscalYear
    {
        $this->setStatus($year, FiscalYear::OPEN);

        return $year->refresh();
    }

    public function closePeriod(Period $period): Period
    {
        $this->setStatus($period, Period::CLOSED);

        return $period->refresh();
    }

    public function reopenPeriod(Period $period): Period
    {
        if (! $period->fiscalYear->isOpen()) {
            throw AccountingException::invalidFiscalYear(__('Reopen financial year :name first.', ['name' => $period->fiscalYear->name]));
        }

        $this->setStatus($period, Period::OPEN);

        return $period->refresh();
    }

    private function createPeriods(FiscalYear $year, Carbon $start, Carbon $end, bool $monthlyPeriods): void
    {
        foreach ($this->periodRanges($start, $end, $monthlyPeriods) as $index => [$from, $to]) {
            Period::query()->create([
                'fiscal_year_id' => $year->id,
                'period_no' => $index + 1,
                'name' => $monthlyPeriods ? $from->format('M Y') : $year->name,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
            ]);
        }
    }

    private function setStatus(FiscalYear|Period $record, string $status): void
    {
        $closing = $status === 'closed';
        $record->forceFill(['status' => $status, 'closed_at' => $closing ? now() : null, 'closed_by' => $closing ? auth()->id() : null])->save();
    }

    /**
     * Calendar months inside the year (the first and last may be partial), or the whole year as one period.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function periodRanges(Carbon $start, Carbon $end, bool $monthly): array
    {
        if (! $monthly) {
            return [[$start, $end]];
        }

        $ranges = [];
        $from = $start->copy();
        while ($from->lte($end)) {
            $to = $from->copy()->endOfMonth()->startOfDay();
            if ($to->gt($end)) {
                $to = $end->copy();
            }
            $ranges[] = [$from->copy(), $to];
            $from = $to->copy()->addDay();
        }

        return $ranges;
    }
}
