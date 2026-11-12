<?php

namespace Nit\AgraniAccounts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniAccounts\Models\JournalEntry;

/**
 * A journal entry was posted (also a reversal entry); dispatched after the transaction commits.
 */
class JournalEntryPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public JournalEntry $entry) {}
}
