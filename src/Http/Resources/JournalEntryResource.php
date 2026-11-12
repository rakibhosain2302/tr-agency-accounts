<?php

namespace Nit\AgraniAccounts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Support\Decimal;

/**
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'branch_id' => $this->branch_id,
            'entry_no' => $this->entry_no,
            'entry_date' => $this->entry_date->toDateString(),
            'entry_type' => $this->entry_type,
            'status' => $this->status,
            'fiscal_year_id' => $this->fiscal_year_id,
            'period_id' => $this->period_id,
            'reference' => $this->reference,
            'narration' => $this->narration,
            'source' => $this->source_type ? ['type' => $this->source_type, 'id' => $this->source_id, 'key' => $this->source_key] : null,
            'reversal_of_id' => $this->reversal_of_id,
            'reversed_by_entry_id' => $this->whenLoaded('reversal', fn () => $this->reversal?->id),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'total_debit' => $this->whenLoaded('lines', fn () => Decimal::sum($this->lines->pluck('debit'))),
            'total_credit' => $this->whenLoaded('lines', fn () => Decimal::sum($this->lines->pluck('credit'))),
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
