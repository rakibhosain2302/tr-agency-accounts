<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Nit\AgraniAccounts\Data\FinancialTransactionData;
use Nit\AgraniAccounts\Data\TransactionLineData;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Services\FinancialTransactionService;
use Nit\AgraniAccounts\Services\LedgerService;
use Nit\AgraniAccounts\Tests\TestCase;
use Nit\Master\Models\Branch;

class LedgerTest extends TestCase
{
    private const CUSTOMER = 501;

    private const SUPPLIER = 701;

    private Branch $dhaka;

    /** @var array<string, Account> */
    private array $accounts;

    private CashBankAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->accounts = $this->chart();
        $this->cash = $this->cashBank($this->accounts['cash']);
        $this->fiscalYear();
    }

    private function transactions(): FinancialTransactionService
    {
        return app(FinancialTransactionService::class);
    }

    private function ledgers(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function sale(string $amount, string $date, ?Branch $branch = null, string $due = '2026-09-30')
    {
        return $this->transactions()->record(new FinancialTransactionData('income', $date, [new TransactionLineData($this->accounts['sales']->id, $amount)], branchId: ($branch ?? $this->dhaka)->id, partyId: self::CUSTOMER, counterAccountId: $this->accounts['receivable']->id, dueDate: $due));
    }

    private function receipt(string $amount, string $date, ?Branch $branch = null)
    {
        return $this->transactions()->record(new FinancialTransactionData('receipt', $date, amount: $amount, branchId: ($branch ?? $this->dhaka)->id, partyId: self::CUSTOMER, cashBankAccountId: $this->cash->id, counterAccountId: $this->accounts['receivable']->id, paymentMethod: 'cash'));
    }

    public function test_customer_ledger_runs_its_balance_from_the_journal(): void
    {
        $this->sale('1000', '2026-08-01');
        $this->receipt('400', '2026-08-15');
        $this->sale('250', '2026-09-03');

        $ledger = $this->ledgers()->partyLedger(self::CUSTOMER, Account::RECEIVABLE);

        $this->assertSame(['0.00', '1250.00', '400.00', '850.00'], [$ledger['opening'], $ledger['debit_total'], $ledger['credit_total'], $ledger['closing']]);
        $this->assertSame(['1000.00', '600.00', '850.00'], array_column($ledger['entries'], 'balance'));
        $this->assertSame('INC-DHA-2026-00001', $ledger['entries'][0]['reference']);
        $this->assertSame('2026-09-30', $ledger['entries'][0]['due_date']);
    }

    public function test_ledger_period_has_an_opening_balance(): void
    {
        $this->sale('1000', '2026-08-01');
        $this->receipt('400', '2026-08-15');
        $this->sale('250', '2026-09-03');

        $september = $this->ledgers()->partyLedger(self::CUSTOMER, Account::RECEIVABLE, '2026-09-01', '2026-09-30');

        $this->assertSame(['600.00', '850.00'], [$september['opening'], $september['closing']]);
        $this->assertCount(1, $september['entries']);
    }

    public function test_supplier_ledger_counts_what_the_business_owes(): void
    {
        $this->transactions()->record(new FinancialTransactionData('expense', '2026-08-01', [new TransactionLineData($this->accounts['expense']->id, '900')], branchId: $this->dhaka->id, partyId: self::SUPPLIER, counterAccountId: $this->accounts['payable']->id));
        $this->transactions()->record(new FinancialTransactionData('payment', '2026-08-05', amount: '300', branchId: $this->dhaka->id, partyId: self::SUPPLIER, cashBankAccountId: $this->cash->id, counterAccountId: $this->accounts['payable']->id, paymentMethod: 'cash'));

        $ledger = $this->ledgers()->partyLedger(self::SUPPLIER, Account::PAYABLE);

        $this->assertSame(['600.00'], [$ledger['closing']]);
        $this->assertSame(['900.00', '600.00'], array_column($ledger['entries'], 'balance'));
        $this->assertSame('0.00', $this->ledgers()->partyLedger(self::SUPPLIER, Account::RECEIVABLE)['closing']);
        $this->assertSame('-600.00', $this->ledgers()->partyBalance(self::SUPPLIER));
    }

    public function test_a_reversed_transaction_nets_to_zero_in_the_ledger(): void
    {
        $sale = $this->sale('1000', '2026-08-01');
        $this->transactions()->reverse($sale, '2026-08-02');

        $ledger = $this->ledgers()->partyLedger(self::CUSTOMER, Account::RECEIVABLE);

        $this->assertSame('0.00', $ledger['closing']);
        $this->assertCount(2, $ledger['entries']);
        $this->assertSame([], $this->ledgers()->openItems(Account::RECEIVABLE));
    }

    public function test_ledger_shows_a_user_only_their_branches(): void
    {
        $sylhet = $this->branch('Sylhet');
        $this->sale('1000', '2026-08-01');
        $this->sale('300', '2026-08-02', $sylhet);

        $dhakaStaff = $this->inBranches($this->userWith(['PartyLedger::*']), $this->dhaka);

        $this->assertSame('1000.00', $this->ledgers()->partyLedger(self::CUSTOMER, Account::RECEIVABLE, user: $dhakaStaff)['closing']);
        $this->assertSame('1300.00', $this->ledgers()->partyLedger(self::CUSTOMER, Account::RECEIVABLE, user: $this->superAdmin())['closing']);
        $this->assertSame('0.00', $this->ledgers()->partyLedger(self::CUSTOMER, Account::RECEIVABLE, user: $this->userWith(['PartyLedger::*']))['closing'], 'Branchless staff see nothing.');
    }

    public function test_open_items_show_outstanding_amounts_and_days_overdue_as_of_a_date(): void
    {
        $first = $this->sale('1000', '2026-08-01', due: '2026-08-31');
        $second = $this->sale('500', '2026-08-05', due: '2026-09-15');
        $receipt = $this->receipt('1200', '2026-09-01');
        $this->transactions()->allocate($receipt, $first, '1000', '2026-09-01');
        $this->transactions()->allocate($receipt, $second, '200', '2026-09-10');

        $asOfSeptemberFifth = $this->ledgers()->openItems(Account::RECEIVABLE, asOf: '2026-09-05');
        $this->assertSame([[$second->transaction_no, '500.00', '0.00', '500.00', 0]], array_map(fn (array $item): array => [$item['transaction_no'], $item['total'], $item['allocated'], $item['outstanding'], $item['days_overdue']], $asOfSeptemberFifth));

        $asOfOctober = $this->ledgers()->openItems(Account::RECEIVABLE, self::CUSTOMER, '2026-10-01');
        $this->assertSame([['300.00', 16]], array_map(fn (array $item): array => [$item['outstanding'], $item['days_overdue']], $asOfOctober));
        $this->assertSame([], $this->ledgers()->openItems(Account::PAYABLE));
    }
}
