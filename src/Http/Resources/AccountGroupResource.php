<?php

namespace Nit\AgraniAccounts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniAccounts\Models\AccountGroup;

/**
 * @mixin AccountGroup
 */
class AccountGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => new AccountTypeResource($this->whenLoaded('type')),
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'children' => self::collection($this->whenLoaded('children')),
            'accounts' => AccountResource::collection($this->whenLoaded('accounts')),
        ];
    }
}
