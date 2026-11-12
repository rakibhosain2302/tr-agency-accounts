<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Nit\AgraniAccounts\Reports\ReportExporter;
use Nit\AgraniAccounts\Reports\ReportFilters;

/**
 * Filters shared by every report: dates or a financial year, branches, accounts / an account group, a party,
 * cash / bank accounts, and the format (json, csv, pdf).
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'fiscal_year_id' => ['nullable', 'integer', Rule::exists('acc_fiscal_years', 'id')],
            'branch_ids' => ['nullable', 'array', 'max:200'],
            'branch_ids.*' => ['integer'],
            'account_ids' => ['nullable', 'array', 'max:500'],
            'account_ids.*' => ['integer'],
            'account_group_id' => ['nullable', 'integer', Rule::exists('acc_account_groups', 'id')],
            'party_id' => ['nullable', 'integer'],
            'cash_bank_account_ids' => ['nullable', 'array', 'max:200'],
            'cash_bank_account_ids.*' => ['integer'],
            'format' => ['nullable', Rule::in(ReportExporter::FORMATS)],
        ];
    }

    public function filters(): ReportFilters
    {
        return ReportFilters::resolve($this->safe()->except('format'), $this->user());
    }

    public function reportFormat(): string
    {
        return (string) ($this->validated('format') ?? 'json');
    }
}
