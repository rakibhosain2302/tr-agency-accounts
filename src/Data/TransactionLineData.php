<?php

namespace Nit\AgraniAccounts\Data;

/**
 * One line of an income or expense: an income / expense account and a positive amount (two decimals).
 */
final readonly class TransactionLineData
{
    public function __construct(
        public int $accountId,
        public string|int|float $amount,
        public ?string $description = null,
    ) {}
}
