<?php

namespace Nit\AgraniAccounts\Reports;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Services\LedgerService;

/**
 * Financial reports, computed from posted journal lines only (entries that are posted or reversed: a reversal
 * is itself a posted entry, so the two net to zero). Nothing is read from any business module.
 *
 * Opening balances: asset, liability and equity accounts carry everything before the report's start; income and
 * expense accounts start again at zero each financial year, and the profit of earlier years shows as
 * "Retained earnings (earlier years)", so the trial balance and the balance sheet always balance.
 *
 * Each report runs a few grouped queries (one row per account / party / month), never one query per row.
 */
final class ReportService
{
    private const PROFIT_CATEGORIES = ['income', 'expense'];

    public function __construct(private LedgerService $ledgers) {}

    public function trialBalance(ReportFilters $filters): Report
    {
        $report = new Report('trial-balance', __('Trial balance'), $filters->toArray());
        $rows = [];
        $totals = array_fill_keys(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'], BigDecimal::zero());

        $accounts = $this->accountTotals($filters);
        $earlierProfit = $accounts->reduce(fn (BigDecimal $sum, object $row): BigDecimal => $sum->plus((string) $row->earlier_profit), BigDecimal::zero());

        foreach ($accounts as $row) {
            $rows[] = $this->trialRow($row->code, $row->name, $row->category, BigDecimal::of((string) $row->opening), (string) $row->period_debit, (string) $row->period_credit, $totals);
        }
        // Without an account filter: the profit of earlier financial years, carried as equity.
        if ($filters->accountIds === [] && ! $earlierProfit->isZero()) {
            $rows[] = $this->trialRow('', __('Retained earnings (earlier years)'), 'equity', $earlierProfit, '0', '0', $totals);
        }

        $rows = array_values(array_filter($rows, fn (array $row): bool => array_filter(array_slice($row, 3), fn ($v) => $v !== '0.00') !== []));
        $totals = array_map(fn (BigDecimal $v): string => $this->money($v), $totals);

        $report->section(new ReportSection(__('Accounts'), [
            'code' => __('Code'), 'name' => __('Account'), 'category' => __('Category'),
            'opening_debit' => __('Opening debit'), 'opening_credit' => __('Opening credit'),
            'period_debit' => __('Debit'), 'period_credit' => __('Credit'),
            'closing_debit' => __('Closing debit'), 'closing_credit' => __('Closing credit'),
        ], $rows, ['code' => '', 'name' => __('Total'), 'category' => ''] + $totals));

        return $report->summarize([
            'totals' => $totals,
            // Debit equals credit at every stage (meaningful for the whole chart, i.e. without an account filter).
            'balanced' => $totals['opening_debit'] === $totals['opening_credit'] && $totals['period_debit'] === $totals['period_credit'] && $totals['closing_debit'] === $totals['closing_credit'],
        ]);
    }

    public function generalLedger(ReportFilters $filters): Report
    {
        $report = new Report('general-ledger', __('General ledger'), $filters->toArray());
        $accounts = $this->accountTotals($filters)->keyBy('account_id');

        $lines = $this->lines($filters)
            ->whereBetween('e.entry_date', [$filters->from, $filters->to])
            ->when($filters->accountIds !== [], fn (Builder $q) => $q->whereIn('l.account_id', $filters->accountIds))
            ->orderBy('a.code')->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.line_no')
            ->get(['l.account_id', 'e.entry_date', 'e.entry_no', 'e.reference', 'e.narration', 'e.branch_id', 'l.party_id', 'l.description', 'l.debit', 'l.credit'])
            ->groupBy('account_id');

        $grandDebit = BigDecimal::zero();
        $grandCredit = BigDecimal::zero();
        foreach ($accounts as $accountId => $account) {
            $balance = BigDecimal::of((string) $account->opening);
            $rows = [['date' => $filters->from, 'entry_no' => '', 'narration' => __('Opening balance'), 'debit' => '', 'credit' => '', 'balance' => $this->money($balance)]];
            $debit = BigDecimal::zero();
            $credit = BigDecimal::zero();

            foreach ($lines->get($accountId, collect()) as $line) {
                $debit = $debit->plus((string) $line->debit);
                $credit = $credit->plus((string) $line->credit);
                $balance = $balance->plus((string) $line->debit)->minus((string) $line->credit);
                $rows[] = [
                    'date' => Carbon::parse($line->entry_date)->toDateString(),
                    'entry_no' => $line->entry_no,
                    'narration' => $line->description ?: ($line->narration ?: $line->reference),
                    'debit' => $this->money($line->debit),
                    'credit' => $this->money($line->credit),
                    'balance' => $this->money($balance),
                ];
            }

            if (count($rows) === 1 && BigDecimal::of((string) $account->opening)->isZero()) {
                continue;
            }

            $grandDebit = $grandDebit->plus($debit);
            $grandCredit = $grandCredit->plus($credit);
            $report->section(new ReportSection("{$account->code} {$account->name}", $this->ledgerColumns(), $rows, ['date' => '', 'entry_no' => '', 'narration' => __('Closing balance'), 'debit' => $this->money($debit), 'credit' => $this->money($credit), 'balance' => $this->money($balance)]));
        }

        return $report->summarize(['total_debit' => $this->money($grandDebit), 'total_credit' => $this->money($grandCredit)]);
    }

    public function profitAndLoss(ReportFilters $filters): Report
    {
        $report = new Report('profit-and-loss', __('Profit and loss'), $filters->toArray());
        $rows = $this->lines($filters)
            ->whereBetween('e.entry_date', [$filters->from, $filters->to])
            ->whereIn('t.category', self::PROFIT_CATEGORIES)
            ->groupBy('a.id', 'a.code', 'a.name', 't.category', 'g.code', 'g.name')
            ->orderBy('g.code')->orderBy('a.code')
            ->selectRaw('a.code, a.name, t.category, g.code AS group_code, g.name AS group_name, SUM(l.debit) AS debit, SUM(l.credit) AS credit')
            ->get();

        $income = $this->categoryRows($rows->where('category', 'income'), credit: true);
        $expense = $this->categoryRows($rows->where('category', 'expense'), credit: false);
        $net = BigDecimal::of($income['total'])->minus($expense['total']);

        $columns = ['group' => __('Group'), 'code' => __('Code'), 'name' => __('Account'), 'amount' => __('Amount')];
        $report->section(new ReportSection(__('Income'), $columns, $income['rows'], ['group' => '', 'code' => '', 'name' => __('Total income'), 'amount' => $income['total']]));
        $report->section(new ReportSection(__('Expenses'), $columns, $expense['rows'], ['group' => '', 'code' => '', 'name' => __('Total expenses'), 'amount' => $expense['total']]));

        return $report->summarize(['total_income' => $income['total'], 'total_expense' => $expense['total'], 'net_profit' => $this->money($net)]);
    }

    /** As of the report's end date ("to"). */
    public function balanceSheet(ReportFilters $filters): Report
    {
        $report = new Report('balance-sheet', __('Balance sheet'), $filters->toArray());
        $yearStart = FiscalYear::query()->whereDate('start_date', '<=', $filters->to)->whereDate('end_date', '>=', $filters->to)->value('start_date');
        $yearStart = $yearStart !== null ? Carbon::parse($yearStart)->toDateString() : Carbon::parse($filters->to)->startOfYear()->toDateString();

        $rows = $this->lines($filters)
            ->where('e.entry_date', '<=', $filters->to)
            ->groupBy('a.id', 'a.code', 'a.name', 't.category', 'g.code', 'g.name')
            ->orderBy('g.code')->orderBy('a.code')
            ->selectRaw("a.code, a.name, t.category, g.code AS group_code, g.name AS group_name, SUM(l.debit) AS debit, SUM(l.credit) AS credit,
                SUM(CASE WHEN t.category IN ('income','expense') AND e.entry_date < ? THEN l.credit - l.debit ELSE 0 END) AS earlier_profit,
                SUM(CASE WHEN t.category IN ('income','expense') AND e.entry_date >= ? THEN l.credit - l.debit ELSE 0 END) AS current_profit", [$yearStart, $yearStart])
            ->get();

        $assets = $this->categoryRows($rows->where('category', 'asset'), credit: false);
        $liabilities = $this->categoryRows($rows->where('category', 'liability'), credit: true);
        $equity = $this->categoryRows($rows->where('category', 'equity'), credit: true);
        $earlier = $rows->reduce(fn (BigDecimal $sum, object $row): BigDecimal => $sum->plus((string) $row->earlier_profit), BigDecimal::zero());
        $current = $rows->reduce(fn (BigDecimal $sum, object $row): BigDecimal => $sum->plus((string) $row->current_profit), BigDecimal::zero());

        $equityRows = $equity['rows'];
        $equityRows[] = ['group' => '', 'code' => '', 'name' => __('Retained earnings (earlier years)'), 'amount' => $this->money($earlier)];
        $equityRows[] = ['group' => '', 'code' => '', 'name' => __('Profit of the current year'), 'amount' => $this->money($current)];
        $equityTotal = BigDecimal::of($equity['total'])->plus($earlier)->plus($current);
        $liabilitiesAndEquity = BigDecimal::of($liabilities['total'])->plus($equityTotal);

        $columns = ['group' => __('Group'), 'code' => __('Code'), 'name' => __('Account'), 'amount' => __('Amount')];
        $report->section(new ReportSection(__('Assets'), $columns, $assets['rows'], ['group' => '', 'code' => '', 'name' => __('Total assets'), 'amount' => $assets['total']]));
        $report->section(new ReportSection(__('Liabilities'), $columns, $liabilities['rows'], ['group' => '', 'code' => '', 'name' => __('Total liabilities'), 'amount' => $liabilities['total']]));
        $report->section(new ReportSection(__('Equity'), $columns, $equityRows, ['group' => '', 'code' => '', 'name' => __('Total equity'), 'amount' => $this->money($equityTotal)]));

        $difference = BigDecimal::of($assets['total'])->minus($liabilitiesAndEquity);

        return $report->summarize([
            'as_of' => $filters->to,
            'total_assets' => $assets['total'],
            'total_liabilities' => $liabilities['total'],
            'total_equity' => $this->money($equityTotal),
            'total_liabilities_and_equity' => $this->money($liabilitiesAndEquity),
            'retained_earnings' => $this->money($earlier),
            'current_year_profit' => $this->money($current),
            'difference' => $this->money($difference),
            'balanced' => $difference->isZero(),
        ]);
    }

    /**
     * Direct-method cash flow of the cash / bank accounts: every posted entry that moves cash, split by the
     * accounts on its other side (money in from income, receivables …; money out to expenses, payables …).
     * Moves between two cash / bank accounts change nothing in total. opening + net flow = closing.
     */
    public function cashFlow(ReportFilters $filters): Report
    {
        $report = new Report('cash-flow', __('Cash flow'), $filters->toArray());
        $cashAccounts = $this->cashAccountIds($filters);

        $opening = $this->sumOf($this->lines($filters)->whereIn('l.account_id', $cashAccounts ?: [0])->where('e.entry_date', '<', $filters->from));
        $closing = $this->sumOf($this->lines($filters)->whereIn('l.account_id', $cashAccounts ?: [0])->where('e.entry_date', '<=', $filters->to));

        $cashEntries = DB::table('acc_journal_lines as cl')->join('acc_journal_entries as ce', 'ce.id', '=', 'cl.journal_entry_id')
            ->whereIn('cl.account_id', $cashAccounts ?: [0])
            ->whereIn('ce.status', [JournalEntry::POSTED, JournalEntry::REVERSED])
            ->whereBetween('ce.entry_date', [$filters->from, $filters->to])
            ->when($filters->branchIds !== null, fn (Builder $q) => $q->whereIn('ce.branch_id', $filters->branchIds))
            ->select('cl.journal_entry_id');

        $flows = $this->lines($filters)
            ->whereIn('l.journal_entry_id', $cashEntries)
            ->whereNotIn('l.account_id', $cashAccounts ?: [0])
            ->groupBy('a.id', 'a.code', 'a.name', 't.category')
            ->orderBy('t.category')->orderBy('a.code')
            ->selectRaw('a.code, a.name, t.category, SUM(l.credit - l.debit) AS flow')
            ->get();

        $in = BigDecimal::zero();
        $out = BigDecimal::zero();
        $rows = [];
        foreach ($flows as $flow) {
            $amount = BigDecimal::of((string) $flow->flow);
            if ($amount->isZero()) {
                continue;
            }
            if ($amount->isPositive()) {
                $in = $in->plus($amount);
            } else {
                $out = $out->plus($amount->negated());
            }
            $rows[] = ['category' => $flow->category, 'code' => $flow->code, 'name' => $flow->name, 'inflow' => $amount->isPositive() ? $this->money($amount) : '0.00', 'outflow' => $amount->isNegative() ? $this->money($amount->negated()) : '0.00'];
        }

        $net = $in->minus($out);
        $report->section(new ReportSection(__('Cash movements by account'), ['category' => __('Category'), 'code' => __('Code'), 'name' => __('Account'), 'inflow' => __('Money in'), 'outflow' => __('Money out')], $rows, ['category' => '', 'code' => '', 'name' => __('Total'), 'inflow' => $this->money($in), 'outflow' => $this->money($out)]));

        return $report->summarize([
            'opening_cash' => $this->money($opening),
            'total_inflow' => $this->money($in),
            'total_outflow' => $this->money($out),
            'net_cash_flow' => $this->money($net),
            'closing_cash' => $this->money($closing),
            'consistent' => $opening->plus($net)->isEqualTo($closing),
        ]);
    }

    public function income(ReportFilters $filters): Report
    {
        return $this->profitCategoryReport($filters, 'income', 'income-report', __('Income report'));
    }

    public function expense(ReportFilters $filters): Report
    {
        return $this->profitCategoryReport($filters, 'expense', 'expense-report', __('Expense report'));
    }

    /** Customers: receivable side. With a party filter, that party's ledger; otherwise a line per party. */
    public function customerLedger(ReportFilters $filters): Report
    {
        return $this->partyReport($filters, Account::RECEIVABLE, 'customer-ledger', __('Customer ledger'));
    }

    /** Suppliers: payable side. */
    public function supplierLedger(ReportFilters $filters): Report
    {
        return $this->partyReport($filters, Account::PAYABLE, 'supplier-ledger', __('Supplier ledger'));
    }

    /** Open income on credit as of the report's end date, by age. */
    public function receivables(ReportFilters $filters): Report
    {
        return $this->agingReport($filters, Account::RECEIVABLE, 'receivables', __('Receivables'));
    }

    /** Open expense on credit as of the report's end date, by age. */
    public function payables(ReportFilters $filters): Report
    {
        return $this->agingReport($filters, Account::PAYABLE, 'payables', __('Payables'));
    }

    /** Every cash / bank account: opening, money in, money out and closing for the period. */
    public function cashAndBank(ReportFilters $filters): Report
    {
        $report = new Report('cash-bank', __('Cash and bank'), $filters->toArray());
        $cashBanks = CashBankAccount::query()->with('account')
            ->when($filters->cashBankAccountIds !== [], fn ($q) => $q->whereIn('id', $filters->cashBankAccountIds))
            ->orderBy('code')->get();

        $totals = $this->lines($filters)
            ->whereIn('l.account_id', $cashBanks->pluck('account_id')->all() ?: [0])
            ->where('e.entry_date', '<=', $filters->to)
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id,
                SUM(CASE WHEN e.entry_date < ? THEN l.debit - l.credit ELSE 0 END) AS opening,
                SUM(CASE WHEN e.entry_date >= ? THEN l.debit ELSE 0 END) AS inflow,
                SUM(CASE WHEN e.entry_date >= ? THEN l.credit ELSE 0 END) AS outflow', [$filters->from, $filters->from, $filters->from])
            ->get()->keyBy('account_id');

        $sum = array_fill_keys(['opening', 'inflow', 'outflow', 'closing'], BigDecimal::zero());
        $rows = [];
        foreach ($cashBanks as $cashBank) {
            $row = $totals->get($cashBank->account_id);
            $opening = BigDecimal::of((string) ($row->opening ?? 0));
            $inflow = BigDecimal::of((string) ($row->inflow ?? 0));
            $outflow = BigDecimal::of((string) ($row->outflow ?? 0));
            $closing = $opening->plus($inflow)->minus($outflow);
            foreach (['opening' => $opening, 'inflow' => $inflow, 'outflow' => $outflow, 'closing' => $closing] as $key => $value) {
                $sum[$key] = $sum[$key]->plus($value);
            }
            $rows[] = ['code' => $cashBank->code, 'name' => $cashBank->name, 'kind' => $cashBank->kind, 'opening' => $this->money($opening), 'inflow' => $this->money($inflow), 'outflow' => $this->money($outflow), 'closing' => $this->money($closing)];
        }
        $sum = array_map(fn (BigDecimal $v): string => $this->money($v), $sum);

        $report->section(new ReportSection(__('Cash and bank accounts'), ['code' => __('Code'), 'name' => __('Account'), 'kind' => __('Kind'), 'opening' => __('Opening'), 'inflow' => __('Money in'), 'outflow' => __('Money out'), 'closing' => __('Closing')], $rows, ['code' => '', 'name' => __('Total'), 'kind' => ''] + $sum));

        return $report->summarize($sum);
    }

    /** Posted lines with their entry, account, group and type, limited to the report's branches. */
    private function lines(ReportFilters $filters): Builder
    {
        return DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->join('acc_account_groups as g', 'g.id', '=', 'a.account_group_id')
            ->join('acc_account_types as t', 't.id', '=', 'g.account_type_id')
            ->whereIn('e.status', [JournalEntry::POSTED, JournalEntry::REVERSED])
            ->when($filters->branchIds !== null, fn (Builder $q) => $q->whereIn('e.branch_id', $filters->branchIds));
    }

    /**
     * One row per account: opening (debit minus credit), period debit and credit, and the profit of earlier
     * financial years carried by income / expense accounts (credit minus debit).
     */
    private function accountTotals(ReportFilters $filters): Collection
    {
        $yearStart = $filters->profitYearStart();

        return $this->lines($filters)
            ->where('e.entry_date', '<=', $filters->to)
            ->when($filters->accountIds !== [], fn (Builder $q) => $q->whereIn('l.account_id', $filters->accountIds))
            ->groupBy('a.id', 'a.code', 'a.name', 't.category')
            ->orderBy('a.code')
            ->selectRaw("a.id AS account_id, a.code, a.name, t.category,
                SUM(CASE WHEN e.entry_date < ? AND (t.category NOT IN ('income','expense') OR e.entry_date >= ?) THEN l.debit - l.credit ELSE 0 END) AS opening,
                SUM(CASE WHEN e.entry_date >= ? THEN l.debit ELSE 0 END) AS period_debit,
                SUM(CASE WHEN e.entry_date >= ? THEN l.credit ELSE 0 END) AS period_credit,
                SUM(CASE WHEN t.category IN ('income','expense') AND e.entry_date < ? THEN l.debit - l.credit ELSE 0 END) AS earlier_profit",
                [$filters->from, $yearStart, $filters->from, $filters->from, $yearStart])
            ->get();
    }

    /**
     * @param  array<string, BigDecimal>  $totals
     * @return array<string, string>
     */
    private function trialRow(string $code, string $name, string $category, BigDecimal $opening, string $debit, string $credit, array &$totals): array
    {
        $closing = $opening->plus($debit)->minus($credit);
        $values = [
            'opening_debit' => $opening->isPositive() ? $opening : BigDecimal::zero(),
            'opening_credit' => $opening->isNegative() ? $opening->negated() : BigDecimal::zero(),
            'period_debit' => BigDecimal::of($debit),
            'period_credit' => BigDecimal::of($credit),
            'closing_debit' => $closing->isPositive() ? $closing : BigDecimal::zero(),
            'closing_credit' => $closing->isNegative() ? $closing->negated() : BigDecimal::zero(),
        ];
        foreach ($values as $key => $value) {
            $totals[$key] = $totals[$key]->plus($value);
        }

        return ['code' => $code, 'name' => $name, 'category' => $category] + array_map(fn (BigDecimal $v): string => $this->money($v), $values);
    }

    /**
     * Rows of one category with the amount on its normal side (credit minus debit for income, liabilities, equity).
     *
     * @return array{rows: list<array<string, string>>, total: string}
     */
    private function categoryRows(Collection $rows, bool $credit): array
    {
        $total = BigDecimal::zero();
        $out = [];
        foreach ($rows as $row) {
            $amount = $credit ? BigDecimal::of((string) $row->credit)->minus((string) $row->debit) : BigDecimal::of((string) $row->debit)->minus((string) $row->credit);
            if ($amount->isZero()) {
                continue;
            }
            $total = $total->plus($amount);
            $out[] = ['group' => "{$row->group_code} {$row->group_name}", 'code' => $row->code, 'name' => $row->name, 'amount' => $this->money($amount)];
        }

        return ['rows' => $out, 'total' => $this->money($total)];
    }

    /**
     * Income or expenses of the period: by account, by party and by month, each row with its share of the
     * total. A line's party is its own, else the party of the income / expense (or receipt / payment) that
     * wrote its journal, so cash sales to a candidate count for that candidate.
     */
    private function profitCategoryReport(ReportFilters $filters, string $category, string $key, string $title): Report
    {
        $report = new Report($key, $title, $filters->toArray());
        $sign = $category === 'income' ? 'l.credit - l.debit' : 'l.debit - l.credit';
        $party = 'COALESCE(l.party_id, (SELECT ft.party_id FROM acc_transactions ft WHERE ft.journal_entry_id = e.id OR ft.reversal_journal_entry_id = e.id ORDER BY ft.id LIMIT 1))';
        $base = fn () => $this->lines($filters)
            ->whereBetween('e.entry_date', [$filters->from, $filters->to])
            ->where('t.category', $category)
            ->when($filters->accountIds !== [], fn (Builder $q) => $q->whereIn('l.account_id', $filters->accountIds));

        $byAccount = $base()->groupBy('a.id', 'a.code', 'a.name')->orderBy('a.code')->selectRaw("a.code, a.name, SUM({$sign}) AS amount")->get();
        $byParty = $base()->groupByRaw($party)->selectRaw("{$party} AS party_id, SUM({$sign}) AS amount, COUNT(DISTINCT e.id) AS entries")->get()
            ->sortByDesc(fn (object $row): float => (float) $row->amount)->values();
        $byMonth = $base()->groupByRaw('SUBSTR(e.entry_date, 1, 7)')->orderByRaw('SUBSTR(e.entry_date, 1, 7)')->selectRaw("SUBSTR(e.entry_date, 1, 7) AS month, SUM({$sign}) AS amount")->get();
        $entries = (int) $base()->distinct()->count('e.id');

        $total = $byAccount->reduce(fn (BigDecimal $sum, object $row): BigDecimal => $sum->plus((string) $row->amount), BigDecimal::zero());
        $share = fn ($amount): string => $total->isZero() ? '—' : (string) BigDecimal::of((string) $amount)->multipliedBy(100)->dividedBy($total, 1, self::halfUp()).'%';
        $names = $this->partyNames($byParty->pluck('party_id')->filter()->all());

        $report->section(new ReportSection(__('By account'), ['code' => __('Code'), 'name' => __('Account'), 'amount' => __('Amount'), 'share' => __('Share')],
            $byAccount->map(fn (object $row): array => ['code' => $row->code, 'name' => $row->name, 'amount' => $this->money($row->amount), 'share' => $share($row->amount)])->all(),
            ['code' => '', 'name' => __('Total'), 'amount' => $this->money($total), 'share' => $total->isZero() ? '' : '100.0%']));
        $report->section(new ReportSection(__('By party'), ['party' => __('Party'), 'entries' => __('Entries'), 'amount' => __('Amount'), 'share' => __('Share')],
            $byParty->map(fn (object $row): array => [
                'party' => $row->party_id === null ? __('No party') : ($names[(int) $row->party_id] ?? '#'.$row->party_id),
                'entries' => (int) $row->entries,
                'amount' => $this->money($row->amount),
                'share' => $share($row->amount),
            ])->all(),
            ['party' => __('Total'), 'entries' => $entries, 'amount' => $this->money($total), 'share' => $total->isZero() ? '' : '100.0%']));
        $report->section(new ReportSection(__('By month'), ['month' => __('Month'), 'amount' => __('Amount'), 'share' => __('Share')],
            $byMonth->map(fn (object $row): array => ['month' => Carbon::createFromFormat('!Y-m', $row->month)->format('M Y'), 'amount' => $this->money($row->amount), 'share' => $share($row->amount)])->all(),
            ['month' => __('Total'), 'amount' => $this->money($total), 'share' => $total->isZero() ? '' : '100.0%']));

        $months = max(1, $byMonth->count());

        return $report->summarize([
            'total' => $this->money($total),
            'average_per_month' => (string) $total->dividedBy($months, 2, self::halfUp()),
            'entries' => $entries,
        ]);
    }

    private function partyReport(ReportFilters $filters, string $kind, string $key, string $title): Report
    {
        $report = new Report($key, $title, $filters->toArray());
        $sign = $kind === Account::PAYABLE ? -1 : 1;
        $base = fn () => $this->lines($filters)->where('a.control_kind', $kind)->whereNotNull('l.party_id')
            ->when($filters->partyId !== null, fn (Builder $q) => $q->where('l.party_id', $filters->partyId));

        $parties = $base()->where('e.entry_date', '<=', $filters->to)->groupBy('l.party_id')->orderBy('l.party_id')
            ->selectRaw('l.party_id,
                SUM(CASE WHEN e.entry_date < ? THEN l.debit - l.credit ELSE 0 END) AS opening,
                SUM(CASE WHEN e.entry_date >= ? THEN l.debit ELSE 0 END) AS debit,
                SUM(CASE WHEN e.entry_date >= ? THEN l.credit ELSE 0 END) AS credit', [$filters->from, $filters->from, $filters->from])
            ->get();
        $names = $this->partyNames($parties->pluck('party_id')->all());

        $sum = array_fill_keys(['opening', 'debit', 'credit', 'closing'], BigDecimal::zero());
        $rows = [];
        foreach ($parties as $party) {
            $opening = BigDecimal::of((string) $party->opening)->multipliedBy($sign);
            $closing = $opening->plus(BigDecimal::of((string) $party->debit)->minus((string) $party->credit)->multipliedBy($sign));
            foreach (['opening' => $opening, 'debit' => BigDecimal::of((string) $party->debit), 'credit' => BigDecimal::of((string) $party->credit), 'closing' => $closing] as $k => $v) {
                $sum[$k] = $sum[$k]->plus($v);
            }
            $rows[] = ['party_id' => (int) $party->party_id, 'party' => $names[$party->party_id] ?? '#'.$party->party_id, 'opening' => $this->money($opening), 'debit' => $this->money($party->debit), 'credit' => $this->money($party->credit), 'closing' => $this->money($closing)];
        }
        $sum = array_map(fn (BigDecimal $v): string => $this->money($v), $sum);

        $report->section(new ReportSection(__('Parties'), ['party_id' => __('Party no.'), 'party' => __('Party'), 'opening' => __('Opening'), 'debit' => __('Debit'), 'credit' => __('Credit'), 'closing' => __('Closing')], $rows, ['party_id' => '', 'party' => __('Total')] + $sum));

        if ($filters->partyId !== null) {
            $balance = BigDecimal::of($sum['opening']);
            $detail = [['date' => $filters->from, 'entry_no' => '', 'narration' => __('Opening balance'), 'debit' => '', 'credit' => '', 'balance' => $this->money($balance)]];
            foreach ($base()->whereBetween('e.entry_date', [$filters->from, $filters->to])->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.line_no')->get(['e.entry_date', 'e.entry_no', 'e.reference', 'e.narration', 'l.debit', 'l.credit']) as $line) {
                $balance = $balance->plus(BigDecimal::of((string) $line->debit)->minus((string) $line->credit)->multipliedBy($sign));
                $detail[] = ['date' => Carbon::parse($line->entry_date)->toDateString(), 'entry_no' => $line->entry_no, 'narration' => $line->narration ?: $line->reference, 'debit' => $this->money($line->debit), 'credit' => $this->money($line->credit), 'balance' => $this->money($balance)];
            }
            $report->section(new ReportSection(__('Ledger of :party', ['party' => $names[$filters->partyId] ?? '#'.$filters->partyId]), $this->ledgerColumns(), $detail, ['date' => '', 'entry_no' => '', 'narration' => __('Closing balance'), 'debit' => $sum['debit'], 'credit' => $sum['credit'], 'balance' => $sum['closing']]));
        }

        return $report->summarize($sum);
    }

    private function agingReport(ReportFilters $filters, string $kind, string $key, string $title): Report
    {
        $report = new Report($key, $title, $filters->toArray() + ['as_of' => $filters->to]);
        $limits = array_values(array_map('intval', (array) config('accounts.reports.aging_days', [30, 60, 90])));
        $buckets = ['current' => __('Not due')];
        $previous = 0;
        foreach ($limits as $limit) {
            $buckets["days_{$previous}_{$limit}"] = __(':from–:to days', ['from' => $previous + 1, 'to' => $limit]);
            $previous = $limit;
        }
        $buckets["over_{$previous}"] = __('Over :days days', ['days' => $previous]);

        $items = array_values(array_filter(
            $this->ledgers->openItems($kind, $filters->partyId, $filters->to),
            fn (array $item): bool => $filters->branchIds === null || in_array($item['branch_id'], $filters->branchIds, true),
        ));
        $names = $this->partyNames(array_column($items, 'party_id'));

        $byParty = [];
        $grand = array_fill_keys(array_keys($buckets), BigDecimal::zero()) + ['total' => BigDecimal::zero()];
        $detail = [];
        foreach ($items as $item) {
            $bucket = $this->bucketFor((int) $item['days_overdue'], $limits);
            $amount = BigDecimal::of($item['outstanding']);
            $party = $item['party_id'];
            $byParty[$party] ??= array_fill_keys(array_keys($buckets), BigDecimal::zero()) + ['total' => BigDecimal::zero()];
            $byParty[$party][$bucket] = $byParty[$party][$bucket]->plus($amount);
            $byParty[$party]['total'] = $byParty[$party]['total']->plus($amount);
            $grand[$bucket] = $grand[$bucket]->plus($amount);
            $grand['total'] = $grand['total']->plus($amount);
            $detail[] = ['transaction_no' => $item['transaction_no'], 'party' => $names[$party] ?? '#'.$party, 'date' => $item['transaction_date'], 'due_date' => $item['due_date'], 'total' => $item['total'], 'allocated' => $item['allocated'], 'outstanding' => $item['outstanding'], 'days_overdue' => $item['days_overdue']];
        }

        $rows = [];
        foreach ($byParty as $party => $values) {
            $rows[] = ['party_id' => $party, 'party' => $names[$party] ?? '#'.$party] + array_map(fn (BigDecimal $v): string => $this->money($v), $values);
        }
        $grand = array_map(fn (BigDecimal $v): string => $this->money($v), $grand);

        $report->section(new ReportSection(__('By party and age'), ['party_id' => __('Party no.'), 'party' => __('Party')] + $buckets + ['total' => __('Outstanding')], $rows, ['party_id' => '', 'party' => __('Total')] + $grand));
        $report->section(new ReportSection(__('Open items'), ['transaction_no' => __('No.'), 'party' => __('Party'), 'date' => __('Date'), 'due_date' => __('Due'), 'total' => __('Amount'), 'allocated' => __('Settled'), 'outstanding' => __('Outstanding'), 'days_overdue' => __('Days overdue')], $detail));

        return $report->summarize(['as_of' => $filters->to, 'total_outstanding' => $grand['total'], 'buckets' => array_intersect_key($grand, $buckets)]);
    }

    /** @param  list<int>  $limits */
    private function bucketFor(int $daysOverdue, array $limits): string
    {
        if ($daysOverdue <= 0) {
            return 'current';
        }
        $previous = 0;
        foreach ($limits as $limit) {
            if ($daysOverdue <= $limit) {
                return "days_{$previous}_{$limit}";
            }
            $previous = $limit;
        }

        return "over_{$previous}";
    }

    /** @return list<int> chart accounts of the cash / bank accounts in the report */
    private function cashAccountIds(ReportFilters $filters): array
    {
        return CashBankAccount::query()
            ->when($filters->cashBankAccountIds !== [], fn ($q) => $q->whereIn('id', $filters->cashBankAccountIds))
            ->pluck('account_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function sumOf(Builder $query): BigDecimal
    {
        return BigDecimal::of((string) ($query->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) AS total')->value('total') ?? 0));
    }

    /**
     * Names of parties from the host application's party table, when one is configured.
     *
     * @param  list<int|string>  $ids
     * @return array<int, string>
     */
    private function partyNames(array $ids): array
    {
        $table = config('accounts.parties.table');
        $column = config('accounts.parties.name_column', 'name');
        if (! $table || ! $column || $ids === []) {
            return [];
        }

        return DB::table($table)->whereIn('id', array_unique($ids))->pluck($column, 'id')->map(fn ($name): string => (string) $name)->all();
    }

    /** @return array<string, string> */
    private function ledgerColumns(): array
    {
        return ['date' => __('Date'), 'entry_no' => __('Journal'), 'narration' => __('Narration'), 'debit' => __('Debit'), 'credit' => __('Credit'), 'balance' => __('Balance')];
    }

    /** Rounding half up, named HalfUp in brick/math 0.13+ and HALF_UP before (as Support\\Decimal does). */
    private static function halfUp(): RoundingMode
    {
        return constant(RoundingMode::class.'::'.(defined(RoundingMode::class.'::HalfUp') ? 'HalfUp' : 'HALF_UP'));
    }

    private function money(BigDecimal|string|int|float|null $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? 0))->toScale(2);
    }
}
