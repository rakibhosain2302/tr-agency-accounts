<?php

namespace Nit\AgraniAccounts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Services\FinancialTransactionService;

/**
 * @mixin FinancialTransaction
 */
class FinancialTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $service = app(FinancialTransactionService::class);
        $posted = $this->isPosted();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->transaction_type,
            'status' => $this->status,
            'transaction_no' => $this->transaction_no,
            'branch_id' => $this->branch_id,
            'transaction_date' => $this->transaction_date->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'party_id' => $this->party_id,
            'cash_bank_account_id' => $this->cash_bank_account_id,
            'counter_account_id' => $this->counter_account_id,
            'on_credit' => $this->isOnCredit(),
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'total_amount' => $this->total_amount,
            // What is still owed on income / expense on credit, and what is not allocated yet on a receipt / payment.
            'outstanding' => $this->when($posted && $this->isOnCredit(), fn () => $service->outstanding($this->resource)),
            'unallocated' => $this->when($posted && $this->isSettlement(), fn () => $service->unallocated($this->resource)),
            'reference' => $this->reference,
            'narration' => $this->narration,
            'source' => $this->source_type ? ['type' => $this->source_type, 'id' => $this->source_id, 'key' => $this->source_key] : null,
            'journal_entry_id' => $this->journal_entry_id,
            'reversal_journal_entry_id' => $this->reversal_journal_entry_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'line_no' => $line->line_no,
                'account_id' => $line->account_id,
                'amount' => $line->amount,
                'description' => $line->description,
            ])->all()),
            'allocations' => AllocationResource::collection($this->whenLoaded($this->isSettlement() ? 'allocationsMade' : 'allocationsReceived')),
        ];
    }
}
