<?php

namespace Nit\AgraniAccounts\Data;

/**
 * One journal line: a debit or a credit (base currency, at most two decimals) on one account.
 */
final readonly class JournalLineData
{
    public function __construct(
        public int $accountId,
        public string|int|float $debit = 0,
        public string|int|float $credit = 0,
        public ?int $partyId = null,
        public ?string $currencyCode = null,
        public string|int|float $exchangeRate = 1,
        public string|int|float $fcDebit = 0,
        public string|int|float $fcCredit = 0,
        public \DateTimeInterface|string|null $dueDate = null,
        public ?string $description = null,
    ) {}

    /** @param  array<string, mixed>  $line  validated request input */
    public static function fromArray(array $line): self
    {
        return new self(
            accountId: (int) $line['account_id'],
            debit: $line['debit'] ?? 0,
            credit: $line['credit'] ?? 0,
            partyId: isset($line['party_id']) ? (int) $line['party_id'] : null,
            currencyCode: isset($line['currency_code']) ? strtoupper((string) $line['currency_code']) : null,
            exchangeRate: $line['exchange_rate'] ?? 1,
            fcDebit: $line['fc_debit'] ?? 0,
            fcCredit: $line['fc_credit'] ?? 0,
            dueDate: $line['due_date'] ?? null,
            description: $line['description'] ?? null,
        );
    }

    public static function debit(int $accountId, string|int|float $amount, ?int $partyId = null, ?string $description = null): self
    {
        return new self($accountId, debit: $amount, partyId: $partyId, description: $description);
    }

    public static function credit(int $accountId, string|int|float $amount, ?int $partyId = null, ?string $description = null): self
    {
        return new self($accountId, credit: $amount, partyId: $partyId, description: $description);
    }
}
