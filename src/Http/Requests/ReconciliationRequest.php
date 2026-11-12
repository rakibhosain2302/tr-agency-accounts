<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cash_bank_account_id' => ['required', 'integer', Rule::exists('acc_cash_bank_accounts', 'id')],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'decimal:0,2', 'min:-9999999999999999.99', 'max:9999999999999999.99'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
