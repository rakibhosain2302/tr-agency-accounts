<?php

namespace Nit\AgraniAccounts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniAccounts\Models\Allocation;

/**
 * @mixin Allocation
 */
class AllocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settlement_id' => $this->settlement_id,
            'target_id' => $this->target_id,
            'amount' => $this->amount,
            'allocation_date' => $this->allocation_date->toDateString(),
            'active' => $this->isActive(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
        ];
    }
}
