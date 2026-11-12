<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Nit\AgraniAccounts\Data\FinancialTransactionData;
use Nit\AgraniAccounts\Data\TransactionLineData;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Models\JournalLine;
use Nit\AgraniAccounts\Services\FinancialTransactionService;
use Nit\AgraniAccounts\Services\ReconciliationService;
use Nit\AgraniAccounts\Tests\TestCase;

class ReconciliationTest extends TestCase
{
    private CashBankAccount $bank;

    private CashBankAccount $cash;

    /** @var list<int> journal line ids on the bank account: +1000, -300, +50 */
    private array $bankLines;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = $this->branch('Dhaka');
        $accounts = $this->chart();
        $this->bank = $this->cashBank($accounts['bank'], kind: 'bank');
        $this->cash = $this->cashBank($accounts['cash']);
        $this->fiscalYear();

        $service = app(FinancialTransactionService::class);
        $in = fn (string $amount, string $date) => $service->record(new FinancialTransactionData('income', $date, [new TransactionLineData($accounts['sales']->id, $amount)], branchId: $branch->id, cashBankAccountId: $this->bank->id, paymentMethod: 'bank_transfer', paymentReference: 'T'.$amount));
        $out = fn (string $amount, string $date) => $service->record(new FinancialTransactionData('expense', $date, [new TransactionLineData($accounts['expense']->id, $amount)], branchId: $branch->id, cashBankAccountId: $this->bank->id, paymentMethod: 'cheque', paymentReference: 'C'.$amount));

        $transactions = [$in('1000', '2026-08-02'), $out('300', '2026-08-10'), $in('50', '2026-08-28')];
        $this->bankLines = array_map(fn ($transaction): int => JournalLine::query()->where('journal_entry_id', $transaction->journal_entry_id)->where('account_id', $accounts['bank']->id)->value('id'), $transactions);
    }

    private function service(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    public function test_a_reconciliation_completes_when_the_cleared_balance_matches_the_statement(): void
    {
        $reconciliation = $this->service()->start($this->bank, '2026-08-31', '700.00');

        $summary = $this->service()->summary($reconciliation);
        $this->assertSame(['700.00', '750.00', '0.00', '700.00', 0, 3], [$summary['statement_balance'], $summary['book_balance'], $summary['cleared_balance'], $summary['difference'], $summary['cleared_lines'], $summary['uncleared_lines']]);

        $this->service()->clear($reconciliation, [$this->bankLines[0], $this->bankLines[1]]);
        $summary = $this->service()->summary($reconciliation->refresh());
        $this->assertSame(['700.00', '0.00', 1], [$summary['cleared_balance'], $summary['difference'], $summary['uncleared_lines']]);

        $completed = $this->service()->complete($reconciliation);
        $this->assertSame('completed', $completed->status);
        $this->assertSame([$this->bankLines[2]], $this->service()->unclearedLines($this->bank)->pluck('l.id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_it_does_not_complete_while_there_is_a_difference(): void
    {
        $reconciliation = $this->service()->start($this->bank, '2026-08-31', '700.00');
        $this->service()->clear($reconciliation, [$this->bankLines[0]]);

        try {
            $this->service()->complete($reconciliation);
            $this->fail('Completed with a difference.');
        } catch (AccountingException $exception) {
            $this->assertSame('invalid_reconciliation', $exception->reason);
        }

        $this->service()->unclear($reconciliation, $this->bankLines[0]);
        $this->assertSame(0, $reconciliation->lines()->count());
    }

    public function test_the_next_reconciliation_carries_the_lines_cleared_before(): void
    {
        $first = $this->service()->start($this->bank, '2026-08-15', '700.00');
        $this->service()->clear($first, [$this->bankLines[0], $this->bankLines[1]]);
        $this->service()->complete($first);

        $second = $this->service()->start($this->bank, '2026-08-31', '750.00');
        $this->service()->clear($second, [$this->bankLines[2]]);

        $this->assertSame('completed', $this->service()->complete($second)->status);
    }

    public function test_only_open_lines_of_the_account_up_to_the_statement_date_can_be_cleared(): void
    {
        $cashLine = JournalLine::query()->where('account_id', $this->cash->account_id)->value('id');
        $reconciliation = $this->service()->start($this->bank, '2026-08-20', '700.00');

        foreach ([[$this->bankLines[2]], array_filter([$cashLine, 999999])] as $ids) {
            try {
                $this->service()->clear($reconciliation, $ids);
                $this->fail('A line that is not open up to the statement date was cleared.');
            } catch (AccountingException) {
            }
        }

        $this->service()->clear($reconciliation, [$this->bankLines[0]]);
        $this->expectException(AccountingException::class);
        $this->service()->clear($reconciliation, [$this->bankLines[0]]);
    }

    public function test_one_open_reconciliation_per_account_after_the_last_completed_date_and_completed_ones_stay(): void
    {
        $first = $this->service()->start($this->bank, '2026-08-15', '700.00');

        try {
            $this->service()->start($this->bank, '2026-08-20', '0');
            $this->fail('Two open reconciliations.');
        } catch (AccountingException) {
        }

        $this->service()->clear($first, [$this->bankLines[0], $this->bankLines[1]]);
        $this->service()->complete($first);

        foreach ([fn () => $this->service()->start($this->bank, '2026-08-15', '0'), fn () => $this->service()->unclear($first, $this->bankLines[0]), fn () => $this->service()->discard($first)] as $attempt) {
            try {
                $attempt();
                $this->fail('Changed a completed reconciliation or went back in time.');
            } catch (AccountingException) {
            }
        }

        $this->assertSame(2, $first->lines()->count());
    }
}
