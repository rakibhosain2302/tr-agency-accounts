<?php

namespace Nit\AgraniAccounts\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Models\JournalEntry;
use RuntimeException;

/**
 * An accounting rule was broken: unbalanced journal, closed period, change of a posted entry … The reason is
 * a stable code for callers; the message is for people. Rendered as 422 (JSON) or as a form error.
 */
class AccountingException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function immutable(JournalEntry $entry): self
    {
        return new self(__('Journal :no is :status and cannot be changed; reverse it instead.', ['no' => $entry->entry_no ?? '#'.$entry->id, 'status' => $entry->getOriginal('status') ?? $entry->status]), 'immutable');
    }

    public static function notDraft(JournalEntry $entry): self
    {
        return new self(__('Only a draft journal can be changed or posted; :no is :status.', ['no' => $entry->entry_no ?? '#'.$entry->id, 'status' => $entry->status]), 'not_draft');
    }

    public static function notPosted(JournalEntry $entry): self
    {
        return new self(__('Only a posted journal can be reversed; :no is :status.', ['no' => $entry->entry_no ?? '#'.$entry->id, 'status' => $entry->status]), 'not_posted');
    }

    public static function reversalOfReversal(): self
    {
        return new self(__('A reversal cannot be reversed; post a new journal instead.'), 'reversal_of_reversal');
    }

    public static function tooFewLines(): self
    {
        return new self(__('A journal needs at least two lines.'), 'too_few_lines');
    }

    public static function unbalanced(string $debit, string $credit): self
    {
        return new self(__('The journal does not balance: debit :debit, credit :credit.', ['debit' => $debit, 'credit' => $credit]), 'unbalanced');
    }

    public static function invalidLine(int $lineNo, string $problem): self
    {
        return new self(__('Line :no: :problem', ['no' => $lineNo, 'problem' => $problem]), 'invalid_line');
    }

    public static function noOpenPeriod(string $date): self
    {
        return new self(__('There is no open accounting period for :date.', ['date' => $date]), 'period_closed');
    }

    public static function invalidFiscalYear(string $problem): self
    {
        return new self($problem, 'invalid_fiscal_year');
    }

    public static function invalidChart(string $problem): self
    {
        return new self($problem, 'invalid_chart');
    }

    public static function immutableTransaction(FinancialTransaction $transaction): self
    {
        return new self(__('Transaction :no is :status and cannot be changed; reverse it instead.', ['no' => $transaction->transaction_no ?? '#'.$transaction->id, 'status' => $transaction->getOriginal('status') ?? $transaction->status]), 'immutable');
    }

    public static function invalidTransaction(string $problem): self
    {
        return new self($problem, 'invalid_transaction');
    }

    public static function transactionNotDraft(FinancialTransaction $transaction): self
    {
        return new self(__('Only a draft transaction can be changed or posted; :no is :status.', ['no' => $transaction->transaction_no ?? '#'.$transaction->id, 'status' => $transaction->status]), 'not_draft');
    }

    public static function transactionNotPosted(FinancialTransaction $transaction): self
    {
        return new self(__('Only a posted transaction can be reversed or allocated; :no is :status.', ['no' => $transaction->transaction_no ?? '#'.$transaction->id, 'status' => $transaction->status]), 'not_posted');
    }

    public static function invalidAllocation(string $problem): self
    {
        return new self($problem, 'invalid_allocation');
    }

    public static function invalidReconciliation(string $problem): self
    {
        return new self($problem, 'invalid_reconciliation');
    }

    public static function invalidReport(string $problem): self
    {
        return new self($problem, 'invalid_report');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], 422);
        }

        return back()->withInput()->withErrors(['journal' => $this->getMessage()]);
    }
}
