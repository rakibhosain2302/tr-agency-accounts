<?php

namespace Nit\AgraniAccounts\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Models\Reconciliation;
use Nit\AgraniAccounts\Models\ReconciliationLine;
use Nit\AgraniAccounts\Support\Decimal;
use Nit\Master\Support\Activity;

/**
 * Bank / cash reconciliation: start one for a statement date and balance, mark the account's posted journal
 * lines that appear on the statement as cleared, and complete it once the cleared balance (every line cleared
 * so far, in this and earlier reconciliations) equals the statement balance. Completed reconciliations stay.
 */
final class ReconciliationService
{
    public function start(CashBankAccount $cashBank, \DateTimeInterface|string $statementDate, string|int|float $statementBalance, ?string $notes = null): Reconciliation
    {
        return DB::transaction(function () use ($cashBank, $statementDate, $statementBalance, $notes): Reconciliation {
            CashBankAccount::query()->whereKey($cashBank->id)->lockForUpdate()->first();
            $date = Carbon::parse($statementDate)->toDateString();

            if (Reconciliation::query()->where('cash_bank_account_id', $cashBank->id)->where('status', Reconciliation::DRAFT)->exists()) {
                throw AccountingException::invalidReconciliation(__('Finish the open reconciliation of :code first.', ['code' => $cashBank->code]));
            }
            $last = Reconciliation::query()->where('cash_bank_account_id', $cashBank->id)->where('status', Reconciliation::COMPLETED)->max('statement_date');
            if ($last !== null && $date <= Carbon::parse($last)->toDateString()) {
                throw AccountingException::invalidReconciliation(__('The statement date must be after the last reconciled date :date.', ['date' => Carbon::parse($last)->toDateString()]));
            }

            $balance = Decimal::exact($statementBalance);
            if ($balance === null) {
                throw AccountingException::invalidReconciliation(__('The statement balance is a number with at most two decimals.'));
            }

            return Reconciliation::query()->create(['cash_bank_account_id' => $cashBank->id, 'statement_date' => $date, 'statement_balance' => $balance, 'notes' => $notes]);
        });
    }

    /**
     * Mark journal lines as cleared. Each must be a posted line of this account, dated on or before the
     * statement date, and not cleared before.
     *
     * @param  list<int>  $journalLineIds
     */
    public function clear(Reconciliation $reconciliation, array $journalLineIds): Reconciliation
    {
        return DB::transaction(function () use ($reconciliation, $journalLineIds): Reconciliation {
            $reconciliation = $this->lockedDraft($reconciliation);
            $ids = array_values(array_unique(array_map('intval', $journalLineIds)));

            $eligible = $this->unclearedLines($reconciliation->cashBankAccount, $reconciliation->statement_date)
                ->whereIn('l.id', $ids)->pluck('l.id')->map(fn ($id): int => (int) $id)->all();
            $refused = array_diff($ids, $eligible);
            if ($refused !== []) {
                throw AccountingException::invalidReconciliation(__('Lines :ids are not open lines of this account up to the statement date.', ['ids' => implode(', ', $refused)]));
            }

            foreach ($eligible as $id) {
                ReconciliationLine::query()->create(['reconciliation_id' => $reconciliation->id, 'journal_line_id' => $id, 'created_by' => auth()->id()]);
            }

            return $reconciliation->refresh();
        });
    }

    public function unclear(Reconciliation $reconciliation, int $journalLineId): Reconciliation
    {
        return DB::transaction(function () use ($reconciliation, $journalLineId): Reconciliation {
            $reconciliation = $this->lockedDraft($reconciliation);
            $reconciliation->lines()->where('journal_line_id', $journalLineId)->delete();

            return $reconciliation->refresh();
        });
    }

    public function complete(Reconciliation $reconciliation): Reconciliation
    {
        return DB::transaction(function () use ($reconciliation): Reconciliation {
            $reconciliation = $this->lockedDraft($reconciliation);
            $summary = $this->summary($reconciliation);

            if ($summary['difference'] !== '0.00') {
                throw AccountingException::invalidReconciliation(__('The cleared balance :cleared differs from the statement balance :statement by :difference.', ['cleared' => $summary['cleared_balance'], 'statement' => $summary['statement_balance'], 'difference' => $summary['difference']]));
            }

            $reconciliation->forceFill(['status' => Reconciliation::COMPLETED, 'completed_at' => now(), 'completed_by' => auth()->id()])->save();
            Activity::log('acc_reconciliation.completed', "Reconciled {$reconciliation->cashBankAccount->code} up to {$reconciliation->statement_date->toDateString()}", $reconciliation, array_filter(['branch_id' => $reconciliation->cashBankAccount->branch_id]) + $summary);

            return $reconciliation;
        });
    }

    /** A draft reconciliation can be thrown away; its cleared marks go with it. */
    public function discard(Reconciliation $reconciliation): void
    {
        DB::transaction(function () use ($reconciliation): void {
            $reconciliation = $this->lockedDraft($reconciliation);
            $reconciliation->lines()->delete();
            $reconciliation->delete();
        });
    }

    /**
     * @return array{statement_balance: string, book_balance: string, cleared_balance: string, difference: string, cleared_lines: int, uncleared_lines: int}
     */
    public function summary(Reconciliation $reconciliation): array
    {
        $cashBank = $reconciliation->cashBankAccount;
        $date = $reconciliation->statement_date->toDateString();

        $book = $this->postedLines($cashBank)->where('e.entry_date', '<=', $date)->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) AS balance')->value('balance');
        $cleared = DB::table('acc_reconciliation_lines as r')
            ->join('acc_reconciliations as rc', 'rc.id', '=', 'r.reconciliation_id')
            ->join('acc_journal_lines as l', 'l.id', '=', 'r.journal_line_id')
            ->where('rc.cash_bank_account_id', $cashBank->id)
            ->where(fn (Builder $q) => $q->where('rc.status', Reconciliation::COMPLETED)->orWhere('rc.id', $reconciliation->id))
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) AS balance')->value('balance');

        $clearedBalance = BigDecimal::of((string) $cleared)->toScale(2);
        $statement = BigDecimal::of((string) $reconciliation->statement_balance)->toScale(2);

        return [
            'statement_balance' => (string) $statement,
            'book_balance' => (string) BigDecimal::of((string) $book)->toScale(2),
            'cleared_balance' => (string) $clearedBalance,
            'difference' => (string) $statement->minus($clearedBalance)->toScale(2),
            'cleared_lines' => $reconciliation->lines()->count(),
            'uncleared_lines' => $this->unclearedLines($cashBank, $reconciliation->statement_date)->count(),
        ];
    }

    /** Posted lines of the account not cleared in any reconciliation, up to the date. */
    public function unclearedLines(CashBankAccount $cashBank, \DateTimeInterface|string|null $upTo = null): Builder
    {
        return $this->postedLines($cashBank)
            ->when($upTo, fn (Builder $q) => $q->where('e.entry_date', '<=', Carbon::parse($upTo)->toDateString()))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('acc_reconciliation_lines as r')->whereColumn('r.journal_line_id', 'l.id'));
    }

    private function postedLines(CashBankAccount $cashBank): Builder
    {
        return DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $cashBank->account_id)
            ->whereIn('e.status', [JournalEntry::POSTED, JournalEntry::REVERSED]);
    }

    private function lockedDraft(Reconciliation $reconciliation): Reconciliation
    {
        $locked = Reconciliation::query()->with('cashBankAccount')->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isDraft()) {
            throw AccountingException::invalidReconciliation(__('A completed reconciliation cannot change.'));
        }

        return $locked;
    }
}
