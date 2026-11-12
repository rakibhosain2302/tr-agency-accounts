<?php

namespace Nit\AgraniAccounts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Models\JournalEntry;

/**
 * A posted transaction was reversed (its allocations undone, its journal reversed); dispatched after the commit.
 */
class FinancialTransactionReversed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public FinancialTransaction $transaction, public JournalEntry $reversalEntry) {}
}
