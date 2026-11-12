<?php

namespace Nit\AgraniAccounts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nit\AgraniAccounts\Models\Allocation;

/**
 * A receipt / payment settled (part of) an income / expense on credit; dispatched after the commit.
 */
class AllocationRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Allocation $allocation) {}
}
