<?php

namespace Nit\AgraniAccounts\Support;

use Nit\AgraniAccounts\Contracts\TransactionNumberGenerator;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\Master\Models\Branch;

/**
 * Gap-free numbers per branch, transaction type and financial year; the financial year row is locked while
 * the next number is taken.
 */
final class DatabaseTransactionNumberGenerator implements TransactionNumberGenerator
{
    public function next(FinancialTransaction $transaction): array
    {
        $year = FiscalYear::query()->whereKey($transaction->fiscal_year_id)->lockForUpdate()->firstOrFail();

        $sequence = 1 + (int) FinancialTransaction::query()->withoutGlobalScopes()
            ->where('branch_id', $transaction->branch_id)
            ->where('transaction_type', $transaction->transaction_type)
            ->where('fiscal_year_id', $year->id)
            ->max('sequence_no');

        $branch = Branch::query()->find($transaction->branch_id);
        $format = (string) config('accounts.transactions.numbering.'.$transaction->transaction_type, strtoupper(substr($transaction->transaction_type, 0, 3)).'-{branch}-{year}-{number}');

        return ['sequence_no' => $sequence, 'transaction_no' => strtr($format, [
            '{branch}' => (string) ($branch?->code ?: $transaction->branch_id),
            '{year}' => $year->start_date->format('Y'),
            '{number}' => str_pad((string) $sequence, max(1, (int) config('accounts.transactions.padding', 5)), '0', STR_PAD_LEFT),
        ])];
    }
}
