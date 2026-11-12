<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nit\AgraniAccounts\Data\FinancialTransactionData;
use Nit\AgraniAccounts\Data\TransactionLineData;
use Nit\AgraniAccounts\Events\AllocationRecorded;
use Nit\AgraniAccounts\Events\AllocationReversed;
use Nit\AgraniAccounts\Events\FinancialTransactionPosted;
use Nit\AgraniAccounts\Events\FinancialTransactionReversed;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Models\Period;
use Nit\AgraniAccounts\Services\FinancialTransactionService;
use Nit\AgraniAccounts\Services\FiscalYearService;
use Nit\AgraniAccounts\Tests\TestCase;
use Nit\Master\Models\ActivityLog;
use Nit\Master\Models\Branch;
use PHPUnit\Framework\Attributes\DataProvider;

class FinancialTransactionTest extends TestCase
{
    private const CUSTOMER = 501;

    private const SUPPLIER = 701;

    private Branch $branch;

    /** @var array<string, Account> */
    private array $accounts;

    private CashBankAccount $cash;

    private CashBankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = $this->branch('Dhaka');
        $this->accounts = $this->chart();
        $this->cash = $this->cashBank($this->accounts['cash']);
        $this->bank = $this->cashBank($this->accounts['bank'], kind: 'bank');
        $this->fiscalYear();
    }

    private function service(): FinancialTransactionService
    {
        return app(FinancialTransactionService::class);
    }

    private function cashIncome(string $amount = '1000'): FinancialTransactionData
    {
        return new FinancialTransactionData('income', '2026-08-10', [new TransactionLineData($this->accounts['sales']->id, $amount, 'Service fee')], branchId: $this->branch->id, cashBankAccountId: $this->cash->id, paymentMethod: 'cash');
    }

    private function creditIncome(string $amount = '1000', int $party = self::CUSTOMER, string $date = '2026-08-10'): FinancialTransactionData
    {
        return new FinancialTransactionData('income', $date, [new TransactionLineData($this->accounts['sales']->id, $amount)], branchId: $this->branch->id, partyId: $party, counterAccountId: $this->accounts['receivable']->id, dueDate: '2026-09-10');
    }

    private function receipt(string $amount, int $party = self::CUSTOMER, string $date = '2026-08-20'): FinancialTransactionData
    {
        return new FinancialTransactionData('receipt', $date, amount: $amount, branchId: $this->branch->id, partyId: $party, cashBankAccountId: $this->bank->id, counterAccountId: $this->accounts['receivable']->id, paymentMethod: 'bank_transfer', paymentReference: 'TRX-'.$amount);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: ?int}> account code, debit, credit, party */
    private function journalOf(FinancialTransaction $transaction): array
    {
        return JournalEntry::query()->findOrFail($transaction->journal_entry_id)->lines()->with('account')->get()
            ->map(fn ($line): array => [$line->account->code, (string) $line->debit, (string) $line->credit, $line->party_id])->all();
    }

    public function test_cash_income_debits_cash_and_credits_income(): void
    {
        $income = $this->service()->record($this->cashIncome());

        $this->assertSame(['posted', 'INC-DHA-2026-00001', '1000.00'], [$income->status, $income->transaction_no, (string) $income->total_amount]);
        $this->assertSame([['1010', '1000.00', '0.00', null], ['4010', '0.00', '1000.00', null]], $this->journalOf($income));

        $journal = JournalEntry::query()->findOrFail($income->journal_entry_id);
        $this->assertSame([$income->getMorphClass(), $income->id, 'acc_transaction:'.$income->id, 'INC-DHA-2026-00001'], [$journal->source_type, $journal->source_id, $journal->source_key, $journal->reference]);
        $this->assertFalse($income->isOnCredit());
    }

    public function test_income_on_credit_debits_the_customers_receivable_with_its_due_date(): void
    {
        $income = $this->service()->record($this->creditIncome());

        $this->assertSame([['1200', '1000.00', '0.00', self::CUSTOMER], ['4010', '0.00', '1000.00', null]], $this->journalOf($income));
        $this->assertSame('2026-09-10', JournalEntry::query()->findOrFail($income->journal_entry_id)->lines[0]->due_date->toDateString());
        $this->assertSame('1000.00', $this->service()->outstanding($income));
    }

    public function test_expense_on_credit_and_payment_post_on_the_suppliers_payable(): void
    {
        $expense = $this->service()->record(new FinancialTransactionData('expense', '2026-08-05', [new TransactionLineData($this->accounts['expense']->id, '300'), new TransactionLineData($this->accounts['expense']->id, '200.50')], branchId: $this->branch->id, partyId: self::SUPPLIER, counterAccountId: $this->accounts['payable']->id));
        $payment = $this->service()->record(new FinancialTransactionData('payment', '2026-08-06', amount: '500.50', branchId: $this->branch->id, partyId: self::SUPPLIER, cashBankAccountId: $this->cash->id, counterAccountId: $this->accounts['payable']->id, paymentMethod: 'cash'));

        $this->assertSame([['5010', '300.00', '0.00', null], ['5010', '200.50', '0.00', null], ['2100', '0.00', '500.50', self::SUPPLIER]], $this->journalOf($expense));
        $this->assertSame([['2100', '500.50', '0.00', self::SUPPLIER], ['1010', '0.00', '500.50', null]], $this->journalOf($payment));
        $this->assertSame(['EXP-DHA-2026-00001', 'PAY-DHA-2026-00001'], [$expense->transaction_no, $payment->transaction_no]);

        $this->service()->allocate($payment, $expense, '500.50');
        $this->assertSame('0.00', $this->service()->outstanding($expense));
    }

    public function test_partial_receipts_leave_an_outstanding_balance_and_an_advance(): void
    {
        $income = $this->service()->record($this->creditIncome('1000'));
        $first = $this->service()->record($this->receipt('400'));
        $second = $this->service()->record($this->receipt('700', date: '2026-08-25'));

        $this->assertSame([['1020', '400.00', '0.00', null], ['1200', '0.00', '400.00', self::CUSTOMER]], $this->journalOf($first));

        $this->service()->allocate($first, $income, '400', '2026-08-20');
        $this->assertSame('600.00', $this->service()->outstanding($income));
        $this->assertSame('0.00', $this->service()->unallocated($first));

        $this->service()->allocate($second, $income, '600', '2026-08-25');
        $this->assertSame('0.00', $this->service()->outstanding($income));
        $this->assertSame('100.00', $this->service()->unallocated($second), 'What is not allocated stays as an advance.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wrongAllocations(): array
    {
        return [
            'more than outstanding' => ['too_much'],
            'more than the receipt' => ['more_than_receipt'],
            'other party' => ['other_party'],
            'cash income' => ['cash_income'],
            'receipt to receipt' => ['receipt_to_receipt'],
            'draft receipt' => ['draft'],
            'dated before the income' => ['too_early'],
            'zero' => ['zero'],
        ];
    }

    #[DataProvider('wrongAllocations')]
    public function test_wrong_allocations_are_refused(string $case): void
    {
        $income = $this->service()->record($this->creditIncome('500'));
        $receipt = $this->service()->record($this->receipt('800'));

        [$settlement, $target, $amount, $date] = match ($case) {
            'too_much' => [$receipt, $income, '500.01', null],
            'more_than_receipt' => [$this->service()->record($this->receipt('100', date: '2026-08-21')), $income, '200', null],
            'other_party' => [$this->service()->record($this->receipt('100', party: 999, date: '2026-08-21')), $income, '50', null],
            'cash_income' => [$receipt, $this->service()->record($this->cashIncome()), '50', null],
            'receipt_to_receipt' => [$receipt, $this->service()->record($this->receipt('100', date: '2026-08-21')), '50', null],
            'draft' => [$this->service()->createDraft($this->receipt('100')), $income, '50', null],
            'too_early' => [$receipt, $income, '50', '2026-08-15'],
            'zero' => [$receipt, $income, '0', null],
        };

        try {
            $this->service()->allocate($settlement, $target, $amount, $date);
            $this->fail('The allocation was accepted.');
        } catch (AccountingException) {
        }

        $this->assertSame('500.00', $this->service()->outstanding($income));
    }

    /**
     * @return array<string, array{\Closure(self): FinancialTransactionData}>
     */
    public static function unpostableTransactions(): array
    {
        $line = fn (self $t, string $code = 'sales') => [new TransactionLineData($t->accounts[$code]->id, '100')];

        return [
            'income without lines' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', [], branchId: $t->branch->id, cashBankAccountId: $t->cash->id, paymentMethod: 'cash')],
            'income on an expense account' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', $line($t, 'expense'), branchId: $t->branch->id, cashBankAccountId: $t->cash->id, paymentMethod: 'cash')],
            'paid and on credit' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', $line($t), branchId: $t->branch->id, partyId: self::CUSTOMER, cashBankAccountId: $t->cash->id, counterAccountId: $t->accounts['receivable']->id, paymentMethod: 'cash')],
            'neither paid nor on credit' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', $line($t), branchId: $t->branch->id)],
            'credit without party' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', $line($t), branchId: $t->branch->id, counterAccountId: $t->accounts['receivable']->id)],
            'income on the payable side' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', $line($t), branchId: $t->branch->id, partyId: self::CUSTOMER, counterAccountId: $t->accounts['payable']->id)],
            'unknown payment method' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', $line($t), branchId: $t->branch->id, cashBankAccountId: $t->cash->id, paymentMethod: 'gold')],
            'cheque without number' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', $line($t), branchId: $t->branch->id, cashBankAccountId: $t->cash->id, paymentMethod: 'cheque')],
            'due date on a cash income' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', $line($t), branchId: $t->branch->id, cashBankAccountId: $t->cash->id, paymentMethod: 'cash', dueDate: '2026-09-01')],
            'receipt without cash or bank' => [fn (self $t) => new FinancialTransactionData('receipt', '2026-08-10', amount: '100', branchId: $t->branch->id, partyId: self::CUSTOMER, counterAccountId: $t->accounts['receivable']->id, paymentMethod: 'cash')],
            'receipt without party account' => [fn (self $t) => new FinancialTransactionData('receipt', '2026-08-10', amount: '100', branchId: $t->branch->id, partyId: self::CUSTOMER, cashBankAccountId: $t->cash->id, paymentMethod: 'cash')],
            'cash account of another branch' => [fn (self $t) => new FinancialTransactionData('income', '2026-08-10', $line($t), branchId: $t->branch->id, cashBankAccountId: $t->cashBank(Account::query()->create(['account_group_id' => $t->accounts['cash']->account_group_id, 'code' => '1030', 'name' => 'Sylhet cash']), $t->branch('Sylhet'))->id, paymentMethod: 'cash')],
        ];
    }

    /** @param  \Closure(self): FinancialTransactionData  $make */
    #[DataProvider('unpostableTransactions')]
    public function test_transactions_that_break_a_rule_are_not_posted(\Closure $make): void
    {
        try {
            $this->service()->record($make($this));
            $this->fail('The transaction was posted.');
        } catch (AccountingException) {
        }

        $this->assertSame(0, FinancialTransaction::query()->where('status', 'posted')->count());
        $this->assertSame(0, JournalEntry::query()->count(), 'A refused transaction writes no journal.');
    }

    public function test_a_transaction_is_never_posted_twice(): void
    {
        $draft = $this->service()->createDraft($this->cashIncome());
        $this->service()->post($draft);

        try {
            $this->service()->post($draft);
            $this->fail('Posted twice.');
        } catch (AccountingException $exception) {
            $this->assertSame('not_draft', $exception->reason);
        }

        $keyed = $this->cashIncome()->from($draft, 'invoice:42');
        $first = $this->service()->record($keyed);
        $second = $this->service()->record($keyed);

        $this->assertTrue($first->is($second));
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_posted_transactions_and_their_lines_are_immutable(): void
    {
        $income = $this->service()->record($this->cashIncome());

        foreach ([
            fn () => $income->update(['narration' => 'Changed']),
            fn () => $income->delete(),
            fn () => $income->lines()->first()->update(['amount' => '1']),
            fn () => $income->lines()->create(['line_no' => 9, 'account_id' => $this->accounts['sales']->id, 'amount' => '1']),
            fn () => $this->service()->updateDraft($income, $this->cashIncome('5')),
            fn () => $this->service()->deleteDraft($income),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A posted transaction changed.');
            } catch (AccountingException) {
            }
        }

        $this->assertSame(['posted', '1000.00'], [$income->fresh()->status, (string) $income->fresh()->total_amount]);
    }

    public function test_drafts_change_and_keep_their_total_in_step_with_the_lines(): void
    {
        $draft = $this->service()->createDraft($this->cashIncome('100'));
        $updated = $this->service()->updateDraft($draft, new FinancialTransactionData('income', '2026-08-11', [new TransactionLineData($this->accounts['sales']->id, '40'), new TransactionLineData($this->accounts['sales']->id, '60.25')], branchId: $this->branch->id, cashBankAccountId: $this->cash->id, paymentMethod: 'cash'));

        $this->assertSame(['100.25', 2, '2026-08-11'], [(string) $updated->total_amount, $updated->lines->count(), $updated->transaction_date->toDateString()]);

        $this->service()->deleteDraft($updated);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_reversing_a_receipt_undoes_its_allocations_and_reverses_its_journal(): void
    {
        $income = $this->service()->record($this->creditIncome('1000'));
        $receipt = $this->service()->record($this->receipt('1000'));
        $allocation = $this->service()->allocate($receipt, $income, '1000');

        $reversed = $this->service()->reverse($receipt, '2026-08-28', 'Cheque bounced');

        $this->assertSame('reversed', $reversed->status);
        $this->assertNotNull($allocation->fresh()->reversed_at);
        $this->assertSame('1000.00', $this->service()->outstanding($income), 'What the receipt settled is outstanding again.');
        $this->assertSame('reversed', JournalEntry::query()->find($receipt->journal_entry_id)->status);
        $reversalJournal = JournalEntry::query()->find($reversed->reversal_journal_entry_id);
        $this->assertSame(['reversal', 'Cheque bounced', '2026-08-28'], [$reversalJournal->entry_type, $reversalJournal->narration, $reversalJournal->entry_date->toDateString()]);

        try {
            $this->service()->reverse($reversed);
            $this->fail('Reversed twice.');
        } catch (AccountingException) {
        }
    }

    public function test_a_reversal_into_a_closed_period_changes_nothing(): void
    {
        $income = $this->service()->record($this->creditIncome('1000'));
        $receipt = $this->service()->record($this->receipt('1000'));
        $allocation = $this->service()->allocate($receipt, $income, '1000');
        app(FiscalYearService::class)->closePeriod(Period::query()->where('name', 'Sep 2026')->firstOrFail());

        try {
            $this->service()->reverse($receipt, '2026-09-05');
            $this->fail('Reversed into a closed period.');
        } catch (AccountingException) {
        }

        $this->assertSame('posted', $receipt->fresh()->status);
        $this->assertNull($allocation->fresh()->reversed_at);
        $this->assertSame('0.00', $this->service()->outstanding($income));
    }

    public function test_a_transaction_in_a_closed_period_stays_a_draft_and_writes_no_journal(): void
    {
        app(FiscalYearService::class)->closePeriod(Period::query()->where('name', 'Aug 2026')->firstOrFail());
        $draft = $this->service()->createDraft($this->cashIncome());

        try {
            $this->service()->post($draft);
            $this->fail('Posted into a closed period.');
        } catch (AccountingException $exception) {
            $this->assertSame('period_closed', $exception->reason);
        }

        $this->assertSame(['draft', null, null], [$draft->fresh()->status, $draft->fresh()->transaction_no, $draft->fresh()->journal_entry_id]);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_events_after_commit_and_audit_history(): void
    {
        Event::fake([FinancialTransactionPosted::class, FinancialTransactionReversed::class, AllocationRecorded::class, AllocationReversed::class]);

        DB::transaction(function (): void {
            $this->service()->record($this->creditIncome('100'));
            Event::assertNotDispatched(FinancialTransactionPosted::class);
        });
        Event::assertDispatched(FinancialTransactionPosted::class);

        $income = FinancialTransaction::query()->firstOrFail();
        $receipt = $this->service()->record($this->receipt('100'));
        $allocation = $this->service()->allocate($receipt, $income, '100');
        $this->service()->unallocate($allocation);
        $this->service()->reverse($income, '2026-08-30');

        Event::assertDispatched(AllocationRecorded::class);
        Event::assertDispatched(AllocationReversed::class);
        Event::assertDispatched(FinancialTransactionReversed::class);

        $events = ActivityLog::query()->orderBy('id')->pluck('event')->filter(fn (string $event): bool => str_starts_with($event, 'acc_income') || str_starts_with($event, 'acc_receipt') || str_starts_with($event, 'acc_allocation'))->values()->all();
        $this->assertSame(['acc_income.posted', 'acc_receipt.posted', 'acc_allocation.recorded', 'acc_allocation.reversed', 'acc_income.reversed'], $events);
        $this->assertSame($this->branch->id, ActivityLog::query()->where('event', 'acc_income.posted')->first()->properties['branch_id']);
    }

    public function test_a_receipt_has_an_amount_and_no_lines(): void
    {
        $this->expectException(AccountingException::class);

        $this->service()->createDraft(new FinancialTransactionData('receipt', '2026-08-10', [new TransactionLineData($this->accounts['sales']->id, '1')], amount: '1', branchId: $this->branch->id));
    }
}
