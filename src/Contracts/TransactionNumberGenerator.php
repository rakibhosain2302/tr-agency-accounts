<?php

namespace Nit\AgraniAccounts\Contracts;

use Nit\AgraniAccounts\Models\FinancialTransaction;

/**
 * Gives an income, expense, receipt or payment its number when it is posted, inside the posting transaction,
 * with branch, type, date and financial year set. A host application may bind its own.
 */
interface TransactionNumberGenerator
{
    /**
     * @return array{sequence_no: int, transaction_no: string}
     */
    public function next(FinancialTransaction $transaction): array;
}
