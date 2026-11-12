<?php

namespace Nit\AgraniAccounts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniAccounts\Models\JournalEntry;

/**
 * A posted journal entry was reversed by a new posted entry; dispatched after the transaction commits.
 */
class JournalEntryReversed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public JournalEntry $original, public JournalEntry $reversal) {}
}
