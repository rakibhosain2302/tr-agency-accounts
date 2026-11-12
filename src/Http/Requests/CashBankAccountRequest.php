<?php

namespace Nit\AgraniAccounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\Master\Support\Branches;

class CashBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $record = $this->route('cashBankAccount');

        return [
            'account_id' => ['required', 'integer', Rule::exists('acc_accounts', 'id'), Rule::unique('acc_cash_bank_accounts', 'account_id')->ignore($record)],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('acc_cash_bank_accounts', 'code')->ignore($record)],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(CashBankAccount::KINDS)],
            // One of the user's branches; only a super admin may leave it empty (shared by every branch).
            'branch_id' => Branches::rule(required: ! $this->user()?->isSuperAdmin()),
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_branch' => ['nullable', 'string', 'max:100'],
            'account_title' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'routing_number' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** The ledger account must be an active asset account that is not a control account. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = Account::query()->with('group.type')->find($this->integer('account_id'));
            if ($account !== null && ($account->is_control || $account->category() !== 'asset')) {
                $validator->errors()->add('account_id', __('Choose an asset account that is not a control account.'));
            }
        }];
    }
}
