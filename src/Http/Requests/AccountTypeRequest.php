<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Nit\AgraniAccounts\Models\AccountType;

class AccountTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->route('accountType');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_.-]+$/', Rule::unique('acc_account_types', 'code')->ignore($type)],
            'name' => ['required', 'string', 'max:100'],
            // The category of a type in use never changes (its groups and accounts depend on it).
            'category' => array_filter(['required', Rule::in(AccountType::CATEGORIES), $type?->groups()->exists() ? Rule::in([$type->category]) : null]),
        ];
    }
}
