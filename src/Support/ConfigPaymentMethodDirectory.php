<?php

namespace Nit\AgraniAccounts\Support;

use Nit\AgraniAccounts\Contracts\PaymentMethodDirectory;

/**
 * Payment methods written in config('accounts.payment_methods').
 */
final class ConfigPaymentMethodDirectory implements PaymentMethodDirectory
{
    public function all(): array
    {
        $methods = [];
        foreach ((array) config('accounts.payment_methods', []) as $code => $method) {
            $methods[(string) $code] = ['name' => (string) ($method['name'] ?? $code), 'requires_reference' => (bool) ($method['requires_reference'] ?? false)];
        }

        return $methods;
    }

    public function exists(string $code): bool
    {
        return array_key_exists($code, $this->all());
    }

    public function requiresReference(string $code): bool
    {
        return $this->all()[$code]['requires_reference'] ?? false;
    }
}
