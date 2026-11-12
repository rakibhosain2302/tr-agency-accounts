<?php

namespace Nit\AgraniAccounts\Contracts;

/**
 * The payment methods money can move by (cash, bank transfer, cheque …), by code. The default reads
 * config('accounts.payment_methods'); a host application may bind its own list.
 */
interface PaymentMethodDirectory
{
    /**
     * @return array<string, array{name: string, requires_reference: bool}>
     */
    public function all(): array;

    public function exists(string $code): bool;

    /** Whether a cheque / transaction number must be given with this method. */
    public function requiresReference(string $code): bool;
}
