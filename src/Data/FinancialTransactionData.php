<?php

namespace Nit\AgraniAccounts\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * An income, expense, receipt or payment to write.
 *
 * Income / expense: lines (account + amount); paid at once with cashBankAccountId, or on credit with
 * counterAccountId (a receivable / payable control account) and partyId.
 * Receipt / payment: amount, cashBankAccountId, counterAccountId and partyId; no lines.
 * sourceType / sourceId / sourceKey link the document of another module; the key prevents recording it twice.
 */
final readonly class FinancialTransactionData
{
    /**
     * @param  list<TransactionLineData>  $lines
     */
    public function __construct(
        public string $type,
        public \DateTimeInterface|string $date,
        public array $lines = [],
        public string|int|float|null $amount = null,
        public ?int $branchId = null,
        public ?int $partyId = null,
        public ?int $cashBankAccountId = null,
        public ?int $counterAccountId = null,
        public ?string $paymentMethod = null,
        public ?string $paymentReference = null,
        public \DateTimeInterface|string|null $dueDate = null,
        public ?string $reference = null,
        public ?string $narration = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?string $sourceKey = null,
    ) {}

    /** @param  array<string, mixed>  $input  validated request input */
    public static function fromArray(string $type, array $input): self
    {
        return new self(
            type: $type,
            date: $input['transaction_date'],
            lines: array_map(fn (array $line): TransactionLineData => new TransactionLineData((int) $line['account_id'], $line['amount'], $line['description'] ?? null), array_values($input['lines'] ?? [])),
            amount: $input['amount'] ?? null,
            branchId: isset($input['branch_id']) ? (int) $input['branch_id'] : null,
            partyId: isset($input['party_id']) ? (int) $input['party_id'] : null,
            cashBankAccountId: isset($input['cash_bank_account_id']) ? (int) $input['cash_bank_account_id'] : null,
            counterAccountId: isset($input['counter_account_id']) ? (int) $input['counter_account_id'] : null,
            paymentMethod: $input['payment_method'] ?? null,
            paymentReference: $input['payment_reference'] ?? null,
            dueDate: $input['due_date'] ?? null,
            reference: $input['reference'] ?? null,
            narration: $input['narration'] ?? null,
        );
    }

    /** The same transaction, linked to a document of another module. */
    public function from(Model $source, string $sourceKey): self
    {
        return new self($this->type, $this->date, $this->lines, $this->amount, $this->branchId, $this->partyId, $this->cashBankAccountId, $this->counterAccountId, $this->paymentMethod, $this->paymentReference, $this->dueDate, $this->reference, $this->narration, $source->getMorphClass(), (int) $source->getKey(), $sourceKey);
    }
}
