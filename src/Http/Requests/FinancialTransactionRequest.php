<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Nit\AgraniAccounts\Data\FinancialTransactionData;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\Master\Support\Branches;

/**
 * An income, expense, receipt or payment draft (the type comes from the route). The accounting rules are
 * checked by FinancialTransactionService when it is posted; this request checks the shape of the input.
 */
class FinancialTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function transactionType(): string
    {
        return (string) $this->route()->defaults['transaction_type'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $settlement = in_array($this->transactionType(), [FinancialTransaction::RECEIPT, FinancialTransaction::PAYMENT], true);

        return [
            'branch_id' => Branches::rule(required: true),
            'transaction_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:transaction_date'],
            'party_id' => ['nullable', 'integer'],
            'cash_bank_account_id' => ['nullable', 'integer', Rule::exists('acc_cash_bank_accounts', 'id')],
            'counter_account_id' => ['nullable', 'integer', Rule::exists('acc_accounts', 'id')],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:500'],
            'post' => ['nullable', 'boolean'],
            'amount' => $settlement ? ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999999.99'] : ['prohibited'],
            'lines' => $settlement ? ['prohibited'] : ['required', 'array', 'min:1', 'max:200'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('acc_accounts', 'id')],
            'lines.*.amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999999.99'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'allocations' => $settlement ? ['nullable', 'array', 'max:200'] : ['prohibited'],
            'allocations.*.target_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ];
    }

    public function transactionData(): FinancialTransactionData
    {
        return FinancialTransactionData::fromArray($this->transactionType(), $this->validated());
    }
}
