<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Nit\AgraniAccounts\Data\JournalEntryData;
use Nit\Master\Support\Branches;

/**
 * A manual journal (draft). The accounting rules (balance, open period, control accounts) are checked by
 * JournalService; this request checks the shape of the input and the user's branch.
 */
class JournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => Branches::rule(required: true),
            'entry_date' => ['required', 'date'],
            'entry_type' => ['nullable', Rule::in(['manual', 'opening', 'closing'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:500'],
            'post' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('acc_accounts', 'id')],
            'lines.*.debit' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'lines.*.credit' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'lines.*.party_id' => ['nullable', 'integer'],
            'lines.*.currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'lines.*.exchange_rate' => ['nullable', 'decimal:0,8', 'gt:0'],
            'lines.*.fc_debit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.fc_credit' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.due_date' => ['nullable', 'date'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function entryData(): JournalEntryData
    {
        return JournalEntryData::fromArray($this->validated());
    }
}
