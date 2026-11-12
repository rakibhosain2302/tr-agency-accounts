<?php

namespace Nit\AgraniAccounts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniAccounts\Models\Account;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_group_id' => $this->account_group_id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->whenLoaded('group', fn () => $this->group->relationLoaded('type') ? $this->group->type->category : null),
            'is_control' => $this->is_control,
            'control_kind' => $this->control_kind,
            'currency_code' => $this->currency_code,
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
            'description' => $this->description,
        ];
    }
}
