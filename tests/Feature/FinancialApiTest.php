<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Tests\TestCase;
use Nit\Master\Http\Controllers\RoleController;
use Nit\Master\Models\Branch;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Phase 06 JSON API: income, expense, receipt, payment, allocations, ledgers, receivables, payables,
 * cash / bank accounts and reconciliation.
 */
class FinancialApiTest extends TestCase
{
    private const API = '/api/nit/accounts';

    private Branch $branch;

    /** @var array<string, Account> */
    private array $accounts;

    private CashBankAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = $this->branch('Dhaka');
        $this->accounts = $this->chart();
        $this->cash = $this->cashBank($this->accounts['cash'], $this->branch);
        $this->fiscalYear();
    }

    /** @return array<string, string> */
    private function accountant(): array
    {
        return $this->bearer($this->inBranches($this->userWith([
            'Income::*', 'Expense::*', 'Receipt::*', 'Payment::*', 'FinancialTransaction::*', 'PartyLedger::*',
            'AccountsReceivable::*', 'AccountsPayable::*', 'CashBankAccount::*', 'Reconciliation::*',
        ]), $this->branch));
    }

    /** @return array<string, mixed> */
    private function creditSale(string $amount = '1000'): array
    {
        return ['branch_id' => $this->branch->id, 'transaction_date' => '2026-08-01', 'due_date' => '2026-08-31', 'party_id' => 501, 'counter_account_id' => $this->accounts['receivable']->id, 'lines' => [['account_id' => $this->accounts['sales']->id, 'amount' => $amount]]];
    }

    public function test_every_new_ability_shows_on_the_role_form(): void
    {
        $keys = array_keys(RoleController::abilities());

        foreach ([
            'Income::acc_income_view', 'Income::acc_income_create', 'Income::acc_income_post', 'Income::acc_income_reverse',
            'Expense::acc_expense_post', 'Receipt::acc_receipt_allocate', 'Payment::acc_payment_allocate',
            'FinancialTransaction::acc_transaction_view', 'PartyLedger::acc_customer_ledger_view', 'PartyLedger::acc_supplier_ledger_view',
            'AccountsReceivable::acc_receivable_view', 'AccountsPayable::acc_payable_view',
            'CashBankAccount::acc_cash_bank_view', 'CashBankAccount::acc_cash_bank_manage',
            'Reconciliation::acc_reconciliation_view', 'Reconciliation::acc_reconciliation_manage',
        ] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listEndpoints(): array
    {
        return array_combine(
            ['incomes', 'expenses', 'receipts', 'payments', 'transactions', 'payment-methods', 'receivables', 'payables', 'cash-bank-accounts', 'reconciliations', 'customer ledger', 'supplier ledger'],
            array_map(fn (string $path): array => [$path], ['/incomes', '/expenses', '/receipts', '/payments', '/transactions', '/payment-methods', '/receivables', '/payables', '/cash-bank-accounts', '/reconciliations', '/customer-ledger/501', '/supplier-ledger/501']),
        );
    }

    #[DataProvider('listEndpoints')]
    public function test_every_endpoint_needs_its_ability(string $path): void
    {
        $this->getJson(self::API.$path, $this->bearer($this->userWith(['Profile::*'])))->assertUnauthorized();
        $this->getJson(self::API.$path, $this->accountant())->assertOk();
    }

    public function test_income_on_credit_then_a_receipt_with_allocations_in_one_request(): void
    {
        $headers = $this->accountant();

        $sale = $this->postJson(self::API.'/incomes', ['post' => true] + $this->creditSale('1000'), $headers)
            ->assertCreated()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.on_credit', true)->assertJsonPath('data.outstanding', '1000.00')->json('data');

        $receipt = $this->postJson(self::API.'/receipts', [
            'branch_id' => $this->branch->id, 'transaction_date' => '2026-08-10', 'amount' => '600', 'party_id' => 501,
            'cash_bank_account_id' => $this->cash->id, 'counter_account_id' => $this->accounts['receivable']->id, 'payment_method' => 'cash',
            'post' => true, 'allocations' => [['target_id' => $sale['id'], 'amount' => '600']],
        ], $headers)->assertCreated()->assertJsonPath('data.unallocated', '0.00')->assertJsonCount(1, 'data.allocations')->json('data');

        $this->getJson(self::API.'/incomes/'.$sale['id'], $headers)->assertOk()->assertJsonPath('data.outstanding', '400.00');
        $this->getJson(self::API.'/receivables', $headers)->assertOk()->assertJsonPath('total_outstanding', '400.00')->assertJsonPath('data.0.outstanding', '400.00');
        $this->getJson(self::API.'/customer-ledger/501', $headers)->assertOk()->assertJsonPath('data.closing', '400.00')->assertJsonCount(2, 'data.entries');

        $allocationId = $receipt['allocations'][0]['id'];
        $this->deleteJson(self::API.'/receipts/'.$receipt['id'].'/allocations/'.$allocationId, [], $headers)->assertOk()->assertJsonPath('data.active', false);
        $this->getJson(self::API.'/incomes/'.$sale['id'], $headers)->assertJsonPath('data.outstanding', '1000.00');
        $this->postJson(self::API.'/receipts/'.$receipt['id'].'/allocations', ['target_id' => $sale['id'], 'amount' => '1000'], $headers)
            ->assertUnprocessable()->assertJsonPath('reason', 'invalid_allocation');
    }

    public function test_a_failed_allocation_rolls_back_the_whole_receipt(): void
    {
        $headers = $this->accountant();
        $sale = $this->postJson(self::API.'/incomes', ['post' => true] + $this->creditSale('100'), $headers)->json('data');

        $this->postJson(self::API.'/receipts', [
            'branch_id' => $this->branch->id, 'transaction_date' => '2026-08-10', 'amount' => '600', 'party_id' => 501,
            'cash_bank_account_id' => $this->cash->id, 'counter_account_id' => $this->accounts['receivable']->id, 'payment_method' => 'cash',
            'post' => true, 'allocations' => [['target_id' => $sale['id'], 'amount' => '600']],
        ], $headers)->assertUnprocessable()->assertJsonPath('reason', 'invalid_allocation');

        $this->assertSame(0, FinancialTransaction::query()->withoutGlobalScopes()->ofType('receipt')->count());
        $this->assertSame(1, JournalEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_draft_post_and_reverse_an_expense(): void
    {
        $headers = $this->accountant();
        $payload = ['branch_id' => $this->branch->id, 'transaction_date' => '2026-08-03', 'cash_bank_account_id' => $this->cash->id, 'payment_method' => 'cash', 'lines' => [['account_id' => $this->accounts['expense']->id, 'amount' => '75.50']]];

        $draft = $this->postJson(self::API.'/expenses', $payload, $headers)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.transaction_no', null)->json('data');
        $this->putJson(self::API.'/expenses/'.$draft['id'], ['lines' => [['account_id' => $this->accounts['expense']->id, 'amount' => '80']]] + $payload, $headers)->assertOk()->assertJsonPath('data.total_amount', '80.00');
        $posted = $this->postJson(self::API.'/expenses/'.$draft['id'].'/post', [], $headers)->assertOk()->assertJsonPath('data.transaction_no', 'EXP-DHA-2026-00001')->json('data');
        $this->postJson(self::API.'/expenses/'.$draft['id'].'/post', [], $headers)->assertUnprocessable()->assertJsonPath('reason', 'not_draft');
        $this->putJson(self::API.'/expenses/'.$draft['id'], $payload, $headers)->assertUnprocessable();

        $this->postJson(self::API.'/expenses/'.$draft['id'].'/reverse', ['reversal_date' => '2026-08-04'], $headers)->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame('reversed', JournalEntry::query()->withoutGlobalScopes()->find($posted['journal_entry_id'])->status);
        $this->getJson(self::API.'/transactions?type=expense&status=reversed', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/incomes/'.$draft['id'], $headers)->assertNotFound();
    }

    public function test_posting_and_allocating_at_once_need_their_abilities(): void
    {
        $writer = $this->bearer($this->inBranches($this->userWith(['Income::acc_income_view', 'Income::acc_income_create', 'Receipt::acc_receipt_view', 'Receipt::acc_receipt_create', 'Receipt::acc_receipt_post']), $this->branch));

        $this->postJson(self::API.'/incomes', ['post' => true] + $this->creditSale(), $writer)->assertForbidden();
        $this->postJson(self::API.'/receipts', [
            'branch_id' => $this->branch->id, 'transaction_date' => '2026-08-10', 'amount' => '10', 'party_id' => 501,
            'cash_bank_account_id' => $this->cash->id, 'counter_account_id' => $this->accounts['receivable']->id, 'payment_method' => 'cash',
            'post' => true, 'allocations' => [['target_id' => 1, 'amount' => '10']],
        ], $writer)->assertForbidden();
        $this->assertSame(0, FinancialTransaction::query()->withoutGlobalScopes()->count());
    }

    public function test_input_is_validated_per_type(): void
    {
        $headers = $this->accountant();

        $this->postJson(self::API.'/receipts', ['branch_id' => $this->branch->id, 'transaction_date' => '2026-08-10', 'lines' => [['account_id' => $this->accounts['sales']->id, 'amount' => '1']]], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'lines']);
        $this->postJson(self::API.'/incomes', ['branch_id' => $this->branch->id, 'transaction_date' => '2026-08-10', 'amount' => '5', 'lines' => [['account_id' => $this->accounts['sales']->id, 'amount' => '1.005']]], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'lines.0.amount']);
        $this->postJson(self::API.'/incomes', array_merge($this->creditSale(), ['due_date' => '2026-07-01']), $headers)->assertUnprocessable()->assertJsonValidationErrors('due_date');
    }

    public function test_transactions_of_another_branch_are_not_found(): void
    {
        $sylhet = $this->branch('Sylhet');
        $foreign = $this->postJson(self::API.'/incomes', ['post' => true, 'branch_id' => $sylhet->id] + $this->creditSale(), $this->bearer($this->superAdmin()))->assertCreated()->json('data');

        $headers = $this->accountant();
        $this->getJson(self::API.'/incomes/'.$foreign['id'], $headers)->assertNotFound();
        $this->postJson(self::API.'/incomes/'.$foreign['id'].'/reverse', [], $headers)->assertNotFound();
        $this->postJson(self::API.'/incomes', ['branch_id' => $sylhet->id] + $this->creditSale(), $headers)->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->getJson(self::API.'/receivables', $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cash_and_bank_accounts_and_their_rules(): void
    {
        $headers = $this->accountant();

        $this->postJson(self::API.'/cash-bank-accounts', ['account_id' => $this->accounts['sales']->id, 'code' => 'X', 'name' => 'Not an asset', 'kind' => 'cash', 'branch_id' => $this->branch->id], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson(self::API.'/cash-bank-accounts', ['account_id' => $this->accounts['bank']->id, 'code' => 'BANK-1', 'name' => 'City Bank', 'kind' => 'bank'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $bank = $this->postJson(self::API.'/cash-bank-accounts', ['account_id' => $this->accounts['bank']->id, 'code' => 'BANK-1', 'name' => 'City Bank', 'kind' => 'bank', 'branch_id' => $this->branch->id, 'account_number' => '0011223344'], $headers)
            ->assertCreated()->assertJsonPath('data.kind', 'bank')->json('data');

        $this->getJson(self::API.'/cash-bank-accounts', $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->postJson(self::API.'/incomes', ['post' => true, 'branch_id' => $this->branch->id, 'transaction_date' => '2026-08-01', 'cash_bank_account_id' => $bank['id'], 'payment_method' => 'bank_transfer', 'payment_reference' => 'TT-1', 'lines' => [['account_id' => $this->accounts['sales']->id, 'amount' => '10']]], $headers)->assertCreated();
        $this->deleteJson(self::API.'/cash-bank-accounts/'.$bank['id'], [], $headers)->assertUnprocessable();
        $this->getJson(self::API.'/payment-methods', $headers)->assertOk()->assertJsonPath('data.0.code', 'cash');
    }

    public function test_reconciliation_over_the_api(): void
    {
        $headers = $this->accountant();
        $income = $this->postJson(self::API.'/incomes', ['post' => true, 'branch_id' => $this->branch->id, 'transaction_date' => '2026-08-01', 'cash_bank_account_id' => $this->cash->id, 'payment_method' => 'cash', 'lines' => [['account_id' => $this->accounts['sales']->id, 'amount' => '500']]], $headers)->json('data');

        $uncleared = $this->getJson(self::API.'/cash-bank-accounts/'.$this->cash->id.'/uncleared-lines', $headers)->assertOk()->assertJsonCount(1, 'data')->json('data');
        $reconciliation = $this->postJson(self::API.'/reconciliations', ['cash_bank_account_id' => $this->cash->id, 'statement_date' => '2026-08-31', 'statement_balance' => '500'], $headers)
            ->assertCreated()->assertJsonPath('data.summary.difference', '500.00')->json('data');

        $this->postJson(self::API.'/reconciliations/'.$reconciliation['id'].'/lines', ['journal_line_ids' => [$uncleared[0]['journal_line_id']]], $headers)->assertOk()->assertJsonPath('data.summary.difference', '0.00');
        $this->postJson(self::API.'/reconciliations/'.$reconciliation['id'].'/complete', [], $headers)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertNotNull($income['journal_entry_id']);
    }
}
