<?php

namespace Nit\AgraniAccounts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniAccounts\Models\CashBankAccount;

/**
 * @mixin CashBankAccount
 */
class CashBankAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => ['code' => $this->account->code, 'name' => $this->account->name]),
            'branch_id' => $this->branch_id,
            'shared' => $this->branch_id === null,
            'bank_name' => $this->bank_name,
            'bank_branch' => $this->bank_branch,
            'account_title' => $this->account_title,
            'account_number' => $this->account_number,
            'routing_number' => $this->routing_number,
            'is_active' => $this->is_active,
        ];
    }
}
