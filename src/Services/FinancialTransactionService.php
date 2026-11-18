<?php

namespace Nit\AgraniAccounts\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniAccounts\Contracts\PaymentMethodDirectory;
use Nit\AgraniAccounts\Contracts\TransactionNumberGenerator;
use Nit\AgraniAccounts\Data\FinancialTransactionData;
use Nit\AgraniAccounts\Data\JournalEntryData;
use Nit\AgraniAccounts\Data\JournalLineData;
use Nit\AgraniAccounts\Events\AllocationRecorded;
use Nit\AgraniAccounts\Events\AllocationReversed;
use Nit\AgraniAccounts\Events\FinancialTransactionPosted;
use Nit\AgraniAccounts\Events\FinancialTransactionReversed;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\Allocation;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Models\Period;
use Nit\AgraniAccounts\Models\TransactionLine;
use Nit\AgraniAccounts\Support\Decimal;
use Nit\Master\Support\Activity;

/**
 * Income, expense, receipt and payment: drafts, posting, allocation (settling what is outstanding, also in
 * part) and reversal. Each step is one database transaction with the rows it changes locked.
 *
 * Posting writes one balanced journal entry through JournalService, with the transaction as its source and
 * "acc_transaction:<id>" as its source key, so a transaction is never posted twice.
 */
final class FinancialTransactionService
{
    public function __construct(
        private JournalService $journals,
        private TransactionNumberGenerator $numbers,
        private PaymentMethodDirectory $paymentMethods,
    ) {}

    public function createDraft(FinancialTransactionData $data): FinancialTransaction
    {
        if (! in_array($data->type, FinancialTransaction::TYPES, true)) {
            throw AccountingException::invalidTransaction(__('Unknown transaction type :type.', ['type' => $data->type]));
        }

        if ($data->sourceKey !== null && ($existing = FinancialTransaction::query()->withoutGlobalScopes()->where('source_key', $data->sourceKey)->first())) {
            return $existing->load('lines');
        }

        return DB::transaction(function () use ($data): FinancialTransaction {
            $transaction = FinancialTransaction::query()->create(['transaction_type' => $data->type, 'status' => FinancialTransaction::DRAFT] + $this->attributes($data));
            $this->writeLines($transaction, $data);

            return $transaction->load('lines');
        });
    }

    public function updateDraft(FinancialTransaction $transaction, FinancialTransactionData $data): FinancialTransaction
    {
        return DB::transaction(function () use ($transaction, $data): FinancialTransaction {
            $transaction = $this->lockedDraft($transaction);
            if ($data->type !== $transaction->transaction_type) {
                throw AccountingException::invalidTransaction(__('The type of a transaction cannot change.'));
            }

            $transaction->update($this->attributes($data, $transaction->branch_id));
            $transaction->lines()->get()->each->delete();
            $this->writeLines($transaction, $data);

            return $transaction->load('lines');
        });
    }

    public function deleteDraft(FinancialTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $transaction = $this->lockedDraft($transaction);
            $transaction->lines()->get()->each->delete();
            $transaction->delete();
        });
    }

    /** Create and post in one step (e.g. for another module's document); a known source key returns its transaction. */
    public function record(FinancialTransactionData $data): FinancialTransaction
    {
        return DB::transaction(function () use ($data): FinancialTransaction {
            $transaction = $this->createDraft($data);

            return $transaction->isDraft() ? $this->post($transaction) : $transaction;
        });
    }

    public function post(FinancialTransaction $transaction): FinancialTransaction
    {
        return DB::transaction(function () use ($transaction): FinancialTransaction {
            $transaction = $this->lockedDraft($transaction)->load(['lines.account.group.type', 'cashBankAccount.account', 'counterAccount']);
            $this->validateForPosting($transaction);

            $period = Period::query()->with('fiscalYear')->whereHas('fiscalYear')->containing($transaction->transaction_date)->first();
            if ($period === null || ! $period->isOpen() || ! $period->fiscalYear->isOpen()) {
                throw AccountingException::noOpenPeriod($transaction->transaction_date->toDateString());
            }
            $transaction->forceFill(['fiscal_year_id' => $period->fiscal_year_id]);
            $number = $this->numbers->next($transaction);

            $journal = $this->journals->record(new JournalEntryData(
                entryDate: $transaction->transaction_date,
                lines: $this->journalLines($transaction),
                branchId: $transaction->branch_id,
                entryType: 'system',
                reference: $number['transaction_no'],
                narration: $transaction->narration ?? ucfirst($transaction->transaction_type).' '.$number['transaction_no'],
                sourceType: $transaction->getMorphClass(),
                sourceId: $transaction->id,
                sourceKey: 'acc_transaction:'.$transaction->id,
            ));

            $transaction->forceFill([
                'status' => FinancialTransaction::POSTED,
                'sequence_no' => $number['sequence_no'],
                'transaction_no' => $number['transaction_no'],
                'journal_entry_id' => $journal->id,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ])->save();

            $this->log("acc_{$transaction->transaction_type}.posted", 'Posted '.$transaction->transaction_type." {$transaction->transaction_no} ({$transaction->total_amount})", $transaction, ['journal_entry_id' => $journal->id, 'amount' => (string) $transaction->total_amount]);
            FinancialTransactionPosted::dispatch($transaction);

            return $transaction->load('lines');
        });
    }

    /**
     * Reverse a posted transaction: its allocations are undone (what they settled becomes outstanding again),
     * its journal entry is reversed on $date (default today), and it is marked reversed. All or nothing.
     */
    public function reverse(FinancialTransaction $transaction, \DateTimeInterface|string|null $date = null, ?string $narration = null): FinancialTransaction
    {
        return DB::transaction(function () use ($transaction, $date, $narration): FinancialTransaction {
            $transaction = FinancialTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if (! $transaction->isPosted()) {
                throw AccountingException::transactionNotPosted($transaction);
            }

            $active = Allocation::query()->active()->where(fn ($q) => $q->where('settlement_id', $transaction->id)->orWhere('target_id', $transaction->id))->lockForUpdate()->get();
            foreach ($active as $allocation) {
                $this->undoAllocation($allocation, __('Reversal of :no', ['no' => $transaction->transaction_no]));
            }

            $reversal = $this->journals->reverse($transaction->journalEntry()->firstOrFail(), $date, $narration ?? __('Reversal of :no', ['no' => $transaction->transaction_no]));

            $transaction->forceFill(['status' => FinancialTransaction::REVERSED, 'reversal_journal_entry_id' => $reversal->id, 'reversed_at' => now(), 'reversed_by' => auth()->id()])->save();

            $this->log("acc_{$transaction->transaction_type}.reversed", 'Reversed '.$transaction->transaction_type." {$transaction->transaction_no} with journal {$reversal->entry_no}", $transaction, ['reversal_journal_entry_id' => $reversal->id, 'allocations_undone' => $active->count()]);
            FinancialTransactionReversed::dispatch($transaction, $reversal);

            return $transaction;
        });
    }

    /**
     * Settle (part of) an income / expense on credit with a receipt / payment of the same party, branch and
     * receivable / payable account. The amount may not be more than what is still outstanding on the one,
     * nor more than what is still unallocated on the other.
     */
    public function allocate(FinancialTransaction $settlement, FinancialTransaction $target, string|int|float $amount, \DateTimeInterface|string|null $date = null): Allocation
    {
        return DB::transaction(function () use ($settlement, $target, $amount, $date): Allocation {
            // Locked in id order, so two allocations at once never wait on each other in a circle.
            $locked = FinancialTransaction::query()->whereKey([$settlement->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $settlement = $locked[$settlement->id];
            $target = $locked[$target->id];

            $amount = Decimal::exact($amount);
            if ($amount === null || ! Decimal::isPositive($amount)) {
                throw AccountingException::invalidAllocation(__('The amount must be more than zero with at most two decimals.'));
            }
            foreach ([$settlement, $target] as $transaction) {
                if (! $transaction->isPosted()) {
                    throw AccountingException::transactionNotPosted($transaction);
                }
            }
            if (! $settlement->isSettlement()) {
                throw AccountingException::invalidAllocation(__('Only a receipt or a payment settles other transactions.'));
            }
            $expectedTarget = $settlement->transaction_type === FinancialTransaction::RECEIPT ? FinancialTransaction::INCOME : FinancialTransaction::EXPENSE;
            if ($target->transaction_type !== $expectedTarget || ! $target->isOnCredit()) {
                throw AccountingException::invalidAllocation(__('A :settlement settles :target on credit only.', ['settlement' => $settlement->transaction_type, 'target' => $expectedTarget]));
            }
            if ($settlement->party_id !== $target->party_id || $settlement->branch_id !== $target->branch_id || $settlement->counter_account_id !== $target->counter_account_id) {
                throw AccountingException::invalidAllocation(__('Both transactions must be of the same party, branch and receivable / payable account.'));
            }
            if (BigDecimal::of($amount)->isGreaterThan($this->outstanding($target))) {
                throw AccountingException::invalidAllocation(__('Only :amount is outstanding on :no.', ['amount' => $this->outstanding($target), 'no' => $target->transaction_no]));
            }
            if (BigDecimal::of($amount)->isGreaterThan($this->unallocated($settlement))) {
                throw AccountingException::invalidAllocation(__('Only :amount of :no is not allocated yet.', ['amount' => $this->unallocated($settlement), 'no' => $settlement->transaction_no]));
            }

            $day = Carbon::parse($date ?? now())->toDateString();
            $latest = max($settlement->transaction_date->toDateString(), $target->transaction_date->toDateString());
            if ($day < $latest) {
                throw AccountingException::invalidAllocation(__('An allocation cannot be dated before :date.', ['date' => $latest]));
            }

            $allocation = Allocation::query()->create(['settlement_id' => $settlement->id, 'target_id' => $target->id, 'amount' => $amount, 'allocation_date' => $day]);

            $this->log('acc_allocation.recorded', "Allocated {$amount} of {$settlement->transaction_no} to {$target->transaction_no}", $settlement, ['allocation_id' => $allocation->id, 'target_id' => $target->id, 'amount' => $amount]);
            AllocationRecorded::dispatch($allocation);

            return $allocation;
        });
    }

    /** Undo an allocation: what it settled is outstanding again. The allocation row stays, marked reversed. */
    public function unallocate(Allocation $allocation): Allocation
    {
        return DB::transaction(function () use ($allocation): Allocation {
            $allocation = Allocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if (! $allocation->isActive()) {
                throw AccountingException::invalidAllocation(__('This allocation was already undone.'));
            }

            return $this->undoAllocation($allocation, null);
        });
    }

    /** What is still to be received / paid on an income / expense on credit. */
    public function outstanding(FinancialTransaction $target): string
    {
        $allocated = Allocation::query()->active()->where('target_id', $target->id)->sum('amount');

        return (string) BigDecimal::of((string) $target->total_amount)->minus((string) $allocated)->toScale(2);
    }

    /** What part of a receipt / payment is not allocated yet (an advance). */
    public function unallocated(FinancialTransaction $settlement): string
    {
        $allocated = Allocation::query()->active()->where('settlement_id', $settlement->id)->sum('amount');

        return (string) BigDecimal::of((string) $settlement->total_amount)->minus((string) $allocated)->toScale(2);
    }

    private function undoAllocation(Allocation $allocation, ?string $why): Allocation
    {
        $allocation->forceFill(['reversed_at' => now(), 'reversed_by' => auth()->id()])->save();

        $settlement = FinancialTransaction::query()->withoutGlobalScopes()->find($allocation->settlement_id);
        $this->log('acc_allocation.reversed', "Undid allocation of {$allocation->amount} from {$settlement?->transaction_no}".($why ? " ({$why})" : ''), $settlement, ['allocation_id' => $allocation->id, 'target_id' => $allocation->target_id, 'amount' => (string) $allocation->amount]);
        AllocationReversed::dispatch($allocation);

        return $allocation;
    }

    /** @return array<string, mixed> */
    private function attributes(FinancialTransactionData $data, ?int $currentBranchId = null): array
    {
        return [
            'branch_id' => $data->branchId ?? $currentBranchId,
            'transaction_date' => Carbon::parse($data->date)->toDateString(),
            'due_date' => $data->dueDate !== null ? Carbon::parse($data->dueDate)->toDateString() : null,
            'party_id' => $data->partyId,
            'cash_bank_account_id' => $data->cashBankAccountId,
            'counter_account_id' => $data->counterAccountId,
            'payment_method' => $data->paymentMethod,
            'payment_reference' => $data->paymentReference,
            'reference' => $data->reference,
            'narration' => $data->narration,
            'source_type' => $data->sourceType,
            'source_id' => $data->sourceId,
            'source_key' => $data->sourceKey,
        ];
    }

    private function writeLines(FinancialTransaction $transaction, FinancialTransactionData $data): void
    {
        if ($transaction->isSettlement()) {
            if ($data->lines !== []) {
                throw AccountingException::invalidTransaction(__('A :type has an amount, not lines.', ['type' => $transaction->transaction_type]));
            }
            $total = Decimal::exact($data->amount);
            if ($total === null || Decimal::isNegative($total)) {
                throw AccountingException::invalidTransaction(__('The amount must be a positive number with at most two decimals.'));
            }
            $transaction->forceFill(['total_amount' => $total])->save();

            return;
        }

        $amounts = [];
        foreach (array_values($data->lines) as $index => $line) {
            $amount = Decimal::exact($line->amount);
            if ($amount === null || ! Decimal::isPositive($amount)) {
                throw AccountingException::invalidTransaction(__('Line :no: the amount must be more than zero with at most two decimals.', ['no' => $index + 1]));
            }
            $transaction->lines()->create(['line_no' => $index + 1, 'account_id' => $line->accountId, 'amount' => $amount, 'description' => $line->description]);
            $amounts[] = $amount;
        }

        $transaction->forceFill(['total_amount' => Decimal::sum($amounts)])->save();
    }

    private function validateForPosting(FinancialTransaction $transaction): void
    {
        if (! Decimal::isPositive((string) $transaction->total_amount)) {
            throw AccountingException::invalidTransaction(__('The amount must be more than zero.'));
        }

        if (! $transaction->isSettlement()) {
            if ($transaction->lines->isEmpty()) {
                throw AccountingException::invalidTransaction(__('An :type needs at least one line.', ['type' => $transaction->transaction_type]));
            }
            $category = $transaction->transaction_type === FinancialTransaction::INCOME ? 'income' : 'expense';
            foreach ($transaction->lines as $line) {
                /** @var TransactionLine $line */
                if (! $line->account?->is_active || $line->account->is_control || $line->account->group->type->category !== $category) {
                    throw AccountingException::invalidTransaction(__('Line :no: use an active :category account.', ['no' => $line->line_no, 'category' => $category]));
                }
            }
        }

        $paidNow = $transaction->cash_bank_account_id !== null;
        $onCredit = $transaction->counter_account_id !== null;

        if ($transaction->isSettlement() && (! $paidNow || ! $onCredit)) {
            throw AccountingException::invalidTransaction(__('A :type needs a cash / bank account and the party\'s :kind account.', ['type' => $transaction->transaction_type, 'kind' => $transaction->controlKind()]));
        }
        if (! $transaction->isSettlement() && $paidNow === $onCredit) {
            throw AccountingException::invalidTransaction(__('Give either a cash / bank account (paid now) or a :kind account (on credit).', ['kind' => $transaction->controlKind()]));
        }

        if ($paidNow) {
            $cashBank = $transaction->cashBankAccount;
            if ($cashBank === null || ! $cashBank->usableBy($transaction->branch_id) || ! $cashBank->account->is_active) {
                throw AccountingException::invalidTransaction(__('The cash / bank account is inactive or belongs to another branch.'));
            }
            $method = (string) $transaction->payment_method;
            if (! $this->paymentMethods->exists($method)) {
                throw AccountingException::invalidTransaction(__('Choose a known payment method.'));
            }
            if ($this->paymentMethods->requiresReference($method) && blank($transaction->payment_reference)) {
                throw AccountingException::invalidTransaction(__('Payment method :method needs a reference number.', ['method' => $method]));
            }
        }

        if ($onCredit) {
            $counter = $transaction->counterAccount;
            if ($counter === null || ! $counter->is_active || ! $counter->is_control || $counter->control_kind !== $transaction->controlKind()) {
                throw AccountingException::invalidTransaction(__('Use an active :kind control account.', ['kind' => $transaction->controlKind()]));
            }
            if ($transaction->party_id === null) {
                throw AccountingException::invalidTransaction(__('A :kind needs a party.', ['kind' => $transaction->controlKind()]));
            }
        }

        if ($transaction->due_date !== null && ! $transaction->isOnCredit()) {
            throw AccountingException::invalidTransaction(__('Only income or expense on credit has a due date.'));
        }
    }

    /** @return list<JournalLineData> */
    private function journalLines(FinancialTransaction $transaction): array
    {
        $total = (string) $transaction->total_amount;
        $cashAccountId = $transaction->cashBankAccount?->account_id;
        $partyLine = fn (bool $debit): JournalLineData => new JournalLineData(
            accountId: $transaction->counter_account_id,
            debit: $debit ? $total : 0,
            credit: $debit ? 0 : $total,
            partyId: $transaction->party_id,
            dueDate: $transaction->due_date,
        );
        $cashLine = fn (bool $debit): JournalLineData => $debit ? JournalLineData::debit($cashAccountId, $total) : JournalLineData::credit($cashAccountId, $total);
        $counterpart = fn (bool $debit): JournalLineData => $cashAccountId !== null ? $cashLine($debit) : $partyLine($debit);
        $itemLines = fn (bool $debit): array => $transaction->lines->map(fn (TransactionLine $line): JournalLineData => $debit
            ? JournalLineData::debit($line->account_id, (string) $line->amount, description: $line->description)
            : JournalLineData::credit($line->account_id, (string) $line->amount, description: $line->description))->all();

        return match ($transaction->transaction_type) {
            FinancialTransaction::INCOME => [$counterpart(true), ...$itemLines(false)],
            FinancialTransaction::EXPENSE => [...$itemLines(true), $counterpart(false)],
            FinancialTransaction::RECEIPT => [$cashLine(true), $partyLine(false)],
            FinancialTransaction::PAYMENT => [$partyLine(true), $cashLine(false)],
        };
    }

    private function lockedDraft(FinancialTransaction $transaction): FinancialTransaction
    {
        $locked = FinancialTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isDraft()) {
            throw AccountingException::transactionNotDraft($locked);
        }

        return $locked;
    }

    /** @param  array<string, mixed>  $properties */
    private function log(string $event, string $description, ?FinancialTransaction $transaction, array $properties): void
    {
        if (config('accounts.log_activity', true)) {
            Activity::log($event, $description, $transaction, array_filter(['branch_id' => $transaction?->branch_id], fn ($v) => $v !== null) + $properties);
        }
    }
}
