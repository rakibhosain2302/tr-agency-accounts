<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Nit\AgraniAccounts\Data\FinancialTransactionData;
use Nit\AgraniAccounts\Data\JournalEntryData;
use Nit\AgraniAccounts\Data\JournalLineData;
use Nit\AgraniAccounts\Data\TransactionLineData;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\AccountGroup;
use Nit\AgraniAccounts\Models\AccountType;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\AgraniAccounts\Reports\Report;
use Nit\AgraniAccounts\Reports\ReportFilters;
use Nit\AgraniAccounts\Reports\ReportSection;
use Nit\AgraniAccounts\Reports\ReportService;
use Nit\AgraniAccounts\Services\FinancialTransactionService;
use Nit\AgraniAccounts\Services\FiscalYearService;
use Nit\AgraniAccounts\Services\JournalService;
use Nit\AgraniAccounts\Tests\TestCase;
use Nit\Master\Models\Branch;

/**
 * The reports on a dataset whose figures are worked out by hand (two financial years, two branches):
 *
 * FY 2025-26: capital 10,000 into cash; cash sale 3,000; cash expense 1,000            (profit 2,000)
 * FY 2026-27: credit sale 5,000 to customer 501 (due 31 Aug); bank receipt 2,000 for it; credit expense
 *             1,500 from supplier 701; cash payment 500 for it; cash to bank 1,000; Sylhet cash sale 400.
 *
 * As of 30 Sep 2026: cash 10,900, bank 3,000, receivable 3,000 = assets 16,900;
 * payable 1,000; capital 10,000 + retained earnings 2,000 + profit of the year 3,900 = 16,900.
 */
class FinancialReportsTest extends TestCase
{
    private Branch $dhaka;

    private Branch $sylhet;

    /** @var array<string, Account> */
    private array $accounts;

    private FiscalYear $previousYear;

    private FiscalYear $currentYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->sylhet = $this->branch('Sylhet');
        $this->accounts = $this->chart();
        $equity = AccountGroup::query()->create(['account_type_id' => AccountType::query()->where('category', 'equity')->value('id'), 'code' => '3', 'name' => 'Equity']);
        $this->accounts['capital'] = Account::query()->create(['account_group_id' => $equity->id, 'code' => '3010', 'name' => 'Capital']);
        $cash = $this->cashBank($this->accounts['cash']);
        $bank = $this->cashBank($this->accounts['bank'], kind: 'bank');

        $this->previousYear = app(FiscalYearService::class)->create('FY 2025-26', '2025-07-01', '2026-06-30');
        $this->currentYear = app(FiscalYearService::class)->create('FY 2026-27', '2026-07-01', '2027-06-30');

        $journals = app(JournalService::class);
        $transactions = app(FinancialTransactionService::class);
        $line = fn (string $code, string $amount) => [new TransactionLineData($this->accounts[$code]->id, $amount)];

        $journals->record(new JournalEntryData('2025-07-05', [JournalLineData::debit($this->accounts['cash']->id, '10000'), JournalLineData::credit($this->accounts['capital']->id, '10000')], $this->dhaka->id, 'opening', narration: 'Capital'));
        $transactions->record(new FinancialTransactionData('income', '2025-08-01', $line('sales', '3000'), branchId: $this->dhaka->id, cashBankAccountId: $cash->id, paymentMethod: 'cash'));
        $transactions->record(new FinancialTransactionData('expense', '2025-09-01', $line('expense', '1000'), branchId: $this->dhaka->id, cashBankAccountId: $cash->id, paymentMethod: 'cash'));

        $sale = $transactions->record(new FinancialTransactionData('income', '2026-08-01', $line('sales', '5000'), branchId: $this->dhaka->id, partyId: 501, counterAccountId: $this->accounts['receivable']->id, dueDate: '2026-08-31'));
        $receipt = $transactions->record(new FinancialTransactionData('receipt', '2026-08-15', amount: '2000', branchId: $this->dhaka->id, partyId: 501, cashBankAccountId: $bank->id, counterAccountId: $this->accounts['receivable']->id, paymentMethod: 'bank_transfer', paymentReference: 'TT-1'));
        $transactions->allocate($receipt, $sale, '2000', '2026-08-15');
        $purchase = $transactions->record(new FinancialTransactionData('expense', '2026-08-20', $line('expense', '1500'), branchId: $this->dhaka->id, partyId: 701, counterAccountId: $this->accounts['payable']->id));
        $payment = $transactions->record(new FinancialTransactionData('payment', '2026-09-05', amount: '500', branchId: $this->dhaka->id, partyId: 701, cashBankAccountId: $cash->id, counterAccountId: $this->accounts['payable']->id, paymentMethod: 'cash'));
        $transactions->allocate($payment, $purchase, '500', '2026-09-05');
        $journals->record(new JournalEntryData('2026-09-10', [JournalLineData::debit($this->accounts['bank']->id, '1000'), JournalLineData::credit($this->accounts['cash']->id, '1000')], $this->dhaka->id, narration: 'Cash deposited'));
        $transactions->record(new FinancialTransactionData('income', '2026-08-10', $line('sales', '400'), branchId: $this->sylhet->id, cashBankAccountId: $cash->id, paymentMethod: 'cash'));
    }

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    /** @param  array<string, mixed>  $input */
    private function filters(array $input = []): ReportFilters
    {
        return ReportFilters::resolve($input + ['from' => '2026-07-01', 'to' => '2026-09-30']);
    }

    /** @return array<string, array<string, mixed>> rows of a section by a key column */
    private function rowsBy(Report $report, int $section, string $key): array
    {
        return collect($report->sections[$section]->rows)->keyBy($key)->all();
    }

    public function test_trial_balance_balances_at_opening_movement_and_closing(): void
    {
        $report = $this->reports()->trialBalance($this->filters());
        $rows = $this->rowsBy($report, 0, 'name');

        $this->assertTrue($report->summary['balanced']);
        $this->assertSame(['opening_debit' => '12000.00', 'opening_credit' => '12000.00', 'period_debit' => '10400.00', 'period_credit' => '10400.00', 'closing_debit' => '18400.00', 'closing_credit' => '18400.00'], $report->summary['totals']);

        $this->assertSame(['12000.00', '10900.00'], [$rows['Cash in hand']['opening_debit'], $rows['Cash in hand']['closing_debit']]);
        $this->assertSame(['0.00', '5400.00'], [$rows['Sales']['opening_credit'], $rows['Sales']['closing_credit']], 'Income starts again at zero in the new year.');
        $this->assertSame('2000.00', $rows['Retained earnings (earlier years)']['opening_credit']);
        $this->assertSame('10000.00', $rows['Capital']['closing_credit']);
    }

    public function test_balance_sheet_balances_with_retained_earnings_and_the_years_profit(): void
    {
        $report = $this->reports()->balanceSheet($this->filters());

        $this->assertTrue($report->summary['balanced']);
        $this->assertSame(
            ['16900.00', '1000.00', '15900.00', '16900.00', '2000.00', '3900.00', '0.00'],
            [$report->summary['total_assets'], $report->summary['total_liabilities'], $report->summary['total_equity'], $report->summary['total_liabilities_and_equity'], $report->summary['retained_earnings'], $report->summary['current_year_profit'], $report->summary['difference']],
        );
        $this->assertSame(['10900.00', '3000.00', '3000.00'], array_column($report->sections[0]->rows, 'amount'));
    }

    public function test_balance_sheet_at_the_end_of_the_earlier_year(): void
    {
        $report = $this->reports()->balanceSheet($this->filters(['from' => null, 'to' => null, 'fiscal_year_id' => $this->previousYear->id]));

        $this->assertTrue($report->summary['balanced']);
        $this->assertSame(['2026-06-30', '12000.00', '0.00', '2000.00'], [$report->summary['as_of'], $report->summary['total_assets'], $report->summary['retained_earnings'], $report->summary['current_year_profit']]);
    }

    public function test_profit_and_loss_of_each_year(): void
    {
        $current = $this->reports()->profitAndLoss($this->filters());
        $previous = $this->reports()->profitAndLoss($this->filters(['from' => null, 'to' => null, 'fiscal_year_id' => $this->previousYear->id]));

        $this->assertSame(['5400.00', '1500.00', '3900.00'], [$current->summary['total_income'], $current->summary['total_expense'], $current->summary['net_profit']]);
        $this->assertSame(['3000.00', '1000.00', '2000.00'], [$previous->summary['total_income'], $previous->summary['total_expense'], $previous->summary['net_profit']]);
    }

    public function test_cash_flow_explains_the_change_in_cash(): void
    {
        $report = $this->reports()->cashFlow($this->filters());
        $rows = $this->rowsBy($report, 0, 'name');

        $this->assertTrue($report->summary['consistent']);
        $this->assertSame(['12000.00', '2400.00', '500.00', '1900.00', '13900.00'], [$report->summary['opening_cash'], $report->summary['total_inflow'], $report->summary['total_outflow'], $report->summary['net_cash_flow'], $report->summary['closing_cash']]);
        $this->assertSame(['400.00', '2000.00', '500.00'], [$rows['Sales']['inflow'], $rows['Receivable']['inflow'], $rows['Payable']['outflow']]);
        $this->assertArrayNotHasKey('Bank', $rows, 'A move between cash and bank is not a cash flow.');
    }

    public function test_general_ledger_runs_each_accounts_balance(): void
    {
        $report = $this->reports()->generalLedger($this->filters(['account_ids' => [$this->accounts['cash']->id]]));

        $this->assertCount(1, $report->sections);
        $this->assertSame('1010 Cash in hand', $report->sections[0]->title);
        $this->assertSame(['12000.00', '12400.00', '11900.00', '10900.00'], array_column($report->sections[0]->rows, 'balance'));
        $this->assertSame(['400.00', '1500.00', '10900.00'], [$report->sections[0]->totals['debit'], $report->sections[0]->totals['credit'], $report->sections[0]->totals['balance']]);
    }

    public function test_general_ledger_of_all_accounts_has_equal_debits_and_credits(): void
    {
        $report = $this->reports()->generalLedger($this->filters());

        $this->assertSame('10400.00', $report->summary['total_debit']);
        $this->assertSame($report->summary['total_debit'], $report->summary['total_credit']);
    }

    public function test_branch_and_account_filters(): void
    {
        $dhaka = $this->reports()->trialBalance($this->filters(['branch_ids' => [$this->dhaka->id]]));
        $rows = $this->rowsBy($dhaka, 0, 'name');
        $this->assertTrue($dhaka->summary['balanced'], 'Each branch balances on its own.');
        $this->assertSame(['10500.00', '5000.00'], [$rows['Cash in hand']['closing_debit'], $rows['Sales']['closing_credit']]);

        $incomeGroup = AccountGroup::query()->where('code', '4')->firstOrFail();
        $onlySales = $this->reports()->trialBalance($this->filters(['account_group_id' => $incomeGroup->id]));
        $this->assertSame(['Sales'], array_column($onlySales->sections[0]->rows, 'name'));
    }

    public function test_income_and_expense_reports_by_account_party_and_month(): void
    {
        $income = $this->reports()->income($this->filters());
        $expense = $this->reports()->expense($this->filters());

        $this->assertSame(['total' => '5400.00', 'average_per_month' => '5400.00', 'entries' => 2], $income->summary);
        $this->assertSame([['code' => '4010', 'name' => 'Sales', 'amount' => '5400.00', 'share' => '100.0%']], $income->sections[0]->rows);
        // The party of a line is that of the income that wrote it; the biggest first, each with its share.
        $this->assertSame([
            ['party' => '#501', 'entries' => 1, 'amount' => '5000.00', 'share' => '92.6%'],
            ['party' => __('No party'), 'entries' => 1, 'amount' => '400.00', 'share' => '7.4%'],
        ], $income->sections[1]->rows);
        $this->assertSame(['party' => __('Total'), 'entries' => 2, 'amount' => '5400.00', 'share' => '100.0%'], $income->sections[1]->totals);
        $this->assertSame([['month' => 'Aug 2026', 'amount' => '5400.00', 'share' => '100.0%']], $income->sections[2]->rows);
        $this->assertSame('1500.00', $expense->summary['total']);
    }

    public function test_customer_and_supplier_ledgers(): void
    {
        $customer = $this->reports()->customerLedger($this->filters(['party_id' => 501]));
        $supplier = $this->reports()->supplierLedger($this->filters());

        $this->assertSame(['0.00', '5000.00', '2000.00', '3000.00'], [$customer->summary['opening'], $customer->summary['debit'], $customer->summary['credit'], $customer->summary['closing']]);
        $this->assertSame(['0.00', '5000.00', '3000.00'], array_column($customer->sections[1]->rows, 'balance'));
        $this->assertSame([701 => '1000.00'], collect($supplier->sections[0]->rows)->pluck('closing', 'party_id')->all());
    }

    public function test_receivables_and_payables_by_age(): void
    {
        $receivables = $this->reports()->receivables($this->filters());
        $payables = $this->reports()->payables($this->filters());

        $this->assertSame('3000.00', $receivables->summary['total_outstanding']);
        $this->assertSame('3000.00', $receivables->summary['buckets']['days_0_30']);
        $this->assertSame('1000.00', $payables->summary['total_outstanding']);
        $this->assertSame('1000.00', $payables->summary['buckets']['days_30_60'], 'Due on its date when there is no due date: 41 days.');
    }

    public function test_cash_and_bank_report(): void
    {
        $report = $this->reports()->cashAndBank($this->filters());
        $rows = $this->rowsBy($report, 0, 'name');

        $this->assertSame(['12000.00', '400.00', '1500.00', '10900.00'], [$rows['Cash in hand']['opening'], $rows['Cash in hand']['inflow'], $rows['Cash in hand']['outflow'], $rows['Cash in hand']['closing']]);
        $this->assertSame(['0.00', '3000.00', '0.00', '3000.00'], [$rows['Bank']['opening'], $rows['Bank']['inflow'], $rows['Bank']['outflow'], $rows['Bank']['closing']]);
        $this->assertSame('13900.00', $report->summary['closing']);
    }

    public function test_a_reversed_transaction_leaves_every_report_as_if_it_never_happened(): void
    {
        $before = $this->reports()->balanceSheet($this->filters())->summary;
        $extra = app(FinancialTransactionService::class)->record(new FinancialTransactionData('income', '2026-09-20', [new TransactionLineData($this->accounts['sales']->id, '777')], branchId: $this->dhaka->id, cashBankAccountId: CashBankAccount::query()->firstOrFail()->id, paymentMethod: 'cash'));
        app(FinancialTransactionService::class)->reverse($extra, '2026-09-21');

        $this->assertSame($before, $this->reports()->balanceSheet($this->filters())->summary);
        $this->assertTrue($this->reports()->trialBalance($this->filters())->summary['balanced']);
    }

    public function test_drafts_are_not_counted(): void
    {
        app(JournalService::class)->createDraft(new JournalEntryData('2026-09-25', [JournalLineData::debit($this->accounts['expense']->id, '999'), JournalLineData::credit($this->accounts['cash']->id, '999')], $this->dhaka->id));

        $this->assertSame('3900.00', $this->reports()->profitAndLoss($this->filters())->summary['net_profit']);
    }

    public function test_report_dates_must_make_sense(): void
    {
        $this->expectException(AccountingException::class);

        ReportFilters::resolve(['from' => '2026-09-30', 'to' => '2026-07-01']);
    }

    public function test_a_report_section_shape(): void
    {
        $section = new ReportSection('S', ['a' => 'A'], [['a' => '1']], ['a' => '1']);

        $this->assertSame(['title' => 'S', 'columns' => ['a' => 'A'], 'rows' => [['a' => '1']], 'totals' => ['a' => '1']], $section->toArray());
        $this->assertSame($this->currentYear->id, ReportFilters::resolve(['fiscal_year_id' => $this->currentYear->id])->fiscalYearId);
    }
}
