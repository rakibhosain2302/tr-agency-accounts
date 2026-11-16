<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a financial year (name, dates, monthly periods?) or change one the same way (its dates only while
 * nothing is posted into it; FiscalYearService::update checks that).
 * Abilities are checked by nit-master's "authorization" middleware before this runs.
 */
class FiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $year = $this->route('fiscalYear');

        return [
            'name' => ['required', 'string', 'max:30', Rule::unique('acc_fiscal_years', 'name')->ignore($year)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'monthly_periods' => ['nullable', 'boolean'],
        ];
    }
}
