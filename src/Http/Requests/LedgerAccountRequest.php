<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LedgerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'account_group_id' => ['required', 'integer', Rule::exists('acc_account_groups', 'id')],
            'code' => ['required', 'string', 'max:30', Rule::unique('acc_accounts', 'code')->ignore($account)],
            'name' => ['required', 'string', 'max:150'],
            'is_control' => ['nullable', 'boolean'],
            'control_kind' => ['nullable', Rule::in(['receivable', 'payable'])],
            'currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function accountData(): array
    {
        $data = $this->validated();
        $data['currency_code'] = isset($data['currency_code']) ? strtoupper($data['currency_code']) : null;
        foreach (['is_control', 'is_active'] as $flag) {
            if ($this->has($flag)) {
                $data[$flag] = $this->boolean($flag);
            }
        }
        // A receivable or payable account is always a control account.
        if (! empty($data['control_kind'])) {
            $data['is_control'] = true;
        } elseif (array_key_exists('is_control', $data) && ! $data['is_control']) {
            $data['control_kind'] = null;
        }

        return $data;
    }
}
