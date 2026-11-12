<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Nit\AgraniAccounts\Data\FinancialTransactionData;
use Nit\AgraniAccounts\Data\TransactionLineData;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Services\FinancialTransactionService;
use Nit\AgraniAccounts\Tests\TestCase;
use Nit\Master\Http\Controllers\RoleController;
use Nit\Master\Models\Branch;
use PHPUnit\Framework\Attributes\DataProvider;

class ReportApiTest extends TestCase
{
    private const API = '/api/nit/accounts/reports/';

    private Branch $dhaka;

    private Branch $sylhet;

    /** @var array<string, Account> */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->sylhet = $this->branch('Sylhet');
        $this->accounts = $this->chart();
        $cash = $this->cashBank($this->accounts['cash']);
        $this->fiscalYear();

        foreach ([[$this->dhaka, '700'], [$this->sylhet, '300']] as [$branch, $amount]) {
            app(FinancialTransactionService::class)->record(new FinancialTransactionData('income', '2026-08-10', [new TransactionLineData($this->accounts['sales']->id, $amount)], branchId: $branch->id, cashBankAccountId: $cash->id, paymentMethod: 'cash'));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function reportsWithTheirGroup(): array
    {
        return [
            'general ledger' => ['general-ledger', 'FinancialReport::acc_report_ledger'],
            'trial balance' => ['trial-balance', 'FinancialReport::acc_report_ledger'],
            'profit and loss' => ['profit-and-loss', 'FinancialReport::acc_report_statements'],
            'balance sheet' => ['balance-sheet', 'FinancialReport::acc_report_statements'],
            'cash flow' => ['cash-flow', 'FinancialReport::acc_report_statements'],
            'income' => ['income', 'FinancialReport::acc_report_income_expense'],
            'expense' => ['expense', 'FinancialReport::acc_report_income_expense'],
            'customer ledger' => ['customer-ledger', 'FinancialReport::acc_report_parties'],
            'supplier ledger' => ['supplier-ledger', 'FinancialReport::acc_report_parties'],
            'receivables' => ['receivables', 'FinancialReport::acc_report_parties'],
            'payables' => ['payables', 'FinancialReport::acc_report_parties'],
            'cash and bank' => ['cash-bank', 'FinancialReport::acc_report_cash_bank'],
        ];
    }

    #[DataProvider('reportsWithTheirGroup')]
    public function test_each_report_needs_its_ability(string $path, string $ability): void
    {
        $this->assertContains($ability, array_keys(RoleController::abilities()));

        $this->getJson(self::API.$path, $this->bearer($this->userWith(['Profile::*'])))->assertUnauthorized();
        $this->getJson(self::API.$path.'?from=2026-07-01&to=2026-09-30', $this->bearer($this->userWith([$ability])))
            ->assertOk()->assertJsonPath('data.filters.from', '2026-07-01')->assertJsonStructure(['data' => ['report', 'title', 'filters', 'summary', 'sections']]);
    }

    public function test_csv_export_opens_in_excel(): void
    {
        $response = $this->get(self::API.'trial-balance?from=2026-07-01&to=2026-09-30&format=csv', $this->bearer($this->superAdmin()));

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('trial-balance-2026-07-01-2026-09-30.csv', (string) $response->headers->get('Content-Disposition'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Trial balance"', $csv);
        $this->assertStringContainsString('4010,Sales,income,0.00,0.00,0.00,1000.00,0.00,1000.00', $csv);
    }

    public function test_pdf_export_uses_nit_masters_print_page(): void
    {
        $this->get(self::API.'balance-sheet?from=2026-07-01&to=2026-09-30&format=pdf', $this->bearer($this->superAdmin()))
            ->assertOk()->assertSee('Balance sheet')->assertSee('Total assets')->assertSee('1000.00');
    }

    public function test_a_user_reports_only_on_their_branches_even_when_asking_for_others(): void
    {
        $dhakaStaff = $this->bearer($this->inBranches($this->userWith(['FinancialReport::*']), $this->dhaka));

        $this->getJson(self::API.'income?from=2026-07-01&to=2026-09-30', $dhakaStaff)->assertOk()->assertJsonPath('data.summary.total', '700.00');
        $this->getJson(self::API.'income?from=2026-07-01&to=2026-09-30&branch_ids[]='.$this->sylhet->id, $dhakaStaff)->assertOk()->assertJsonPath('data.summary.total', '0.00');
        $this->getJson(self::API.'income?from=2026-07-01&to=2026-09-30&branch_ids[]='.$this->sylhet->id, $this->bearer($this->superAdmin()))->assertOk()->assertJsonPath('data.summary.total', '300.00');
    }

    public function test_filters_are_validated(): void
    {
        $admin = $this->bearer($this->superAdmin());

        $this->getJson(self::API.'trial-balance?format=xlsx', $admin)->assertUnprocessable()->assertJsonValidationErrors('format');
        $this->getJson(self::API.'trial-balance?from=2026-09-30&to=2026-07-01', $admin)->assertUnprocessable()->assertJsonPath('reason', 'invalid_report');
        $this->getJson(self::API.'trial-balance?fiscal_year_id=999', $admin)->assertUnprocessable()->assertJsonValidationErrors('fiscal_year_id');
    }
}
