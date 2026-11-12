<?php

namespace Nit\AgraniAccounts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniAccounts\Models\Allocation;

/**
 * An allocation was undone; what it settled is outstanding again. Dispatched after the commit.
 */
class AllocationReversed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Allocation $allocation) {}
}
