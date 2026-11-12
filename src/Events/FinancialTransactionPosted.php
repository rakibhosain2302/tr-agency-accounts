<?php

namespace Nit\AgraniAccounts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniAccounts\Models\FinancialTransaction;

/**
 * An income, expense, receipt or payment was posted with its journal entry; dispatched after the commit.
 */
class FinancialTransactionPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public FinancialTransaction $transaction) {}
}
