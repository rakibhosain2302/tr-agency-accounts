<?php

namespace Nit\AgraniAccounts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniAccounts\Models\FiscalYear;

/**
 * @mixin FiscalYear
 */
class FiscalYearResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'status' => $this->status,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'periods' => PeriodResource::collection($this->whenLoaded('periods')),
        ];
    }
}
