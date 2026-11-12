<?php

namespace Nit\AgraniAccounts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniAccounts\Models\Reconciliation;
use Nit\AgraniAccounts\Services\ReconciliationService;

/**
 * @mixin Reconciliation
 */
class ReconciliationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cash_bank_account_id' => $this->cash_bank_account_id,
            'statement_date' => $this->statement_date->toDateString(),
            'statement_balance' => $this->statement_balance,
            'status' => $this->status,
            'notes' => $this->notes,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'summary' => app(ReconciliationService::class)->summary($this->resource),
            'cleared_line_ids' => $this->whenLoaded('lines', fn () => $this->lines->pluck('journal_line_id')->all()),
        ];
    }
}
