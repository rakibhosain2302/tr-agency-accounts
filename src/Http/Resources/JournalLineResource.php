<?php

namespace Nit\AgraniAccounts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniAccounts\Models\JournalLine;

/**
 * @mixin JournalLine
 */
class JournalLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'line_no' => $this->line_no,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => ['code' => $this->account->code, 'name' => $this->account->name]),
            'party_id' => $this->party_id,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'fc_debit' => $this->fc_debit,
            'fc_credit' => $this->fc_credit,
            'due_date' => $this->due_date?->toDateString(),
            'description' => $this->description,
        ];
    }
}
