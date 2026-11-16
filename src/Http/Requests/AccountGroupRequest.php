<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $group = $this->route('accountGroup');

        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('acc_account_groups', 'id'), Rule::notIn(array_filter([$group?->id]))],
            'account_type_id' => ['required', 'integer', Rule::exists('acc_account_types', 'id')],
            // Left out on a new group: ChartOfAccountsService::nextGroupCode gives it one.
            'code' => [...($group !== null ? ['sometimes', 'required'] : ['nullable']), 'string', 'max:30', Rule::unique('acc_account_groups', 'code')->ignore($group)],
            'name' => ['required', 'string', 'max:150'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
