<?php

namespace Nit\AgraniAccounts\Services;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\Master\Support\Branches;

/**
 * Party ledgers (customer side: receivable control accounts; supplier side: payable control accounts) and
 * open items (accounts receivable / payable), from posted journal lines and allocations only. Nothing here
 * is stored: every figure is computed from the journal, so the ledger and the transactions never disagree.
 */
final class LedgerService
{
    public function __construct(private FinancialTransactionService $transactions) {}

    /**
     * The party's ledger on the receivable or payable side: opening balance before $from, every posted line
     * between $from and $to with a running balance, and the closing balance. Positive balance = the party owes
     * the business (receivable) / the business owes the party (payable).
     *
     * @return array{party_id: int, kind: string, from: ?string, to: ?string, opening: string, debit_total: string, credit_total: string, closing: string, entries: list<array<string, mixed>>}
     */
    public function partyLedger(int $partyId, string $kind, \DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, ?Authenticatable $user = null): array
    {
        $from = $from !== null ? Carbon::parse($from)->toDateString() : null;
        $to = $to !== null ? Carbon::parse($to)->toDateString() : null;
        $sign = $kind === Account::PAYABLE ? -1 : 1;

        $opening = $from === null ? BigDecimal::zero() : BigDecimal::of((string) (clone $this->lines($partyId, $kind, $user))
            ->where('e.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) AS balance')->value('balance'))->multipliedBy($sign);

        $rows = $this->lines($partyId, $kind, $user)
            ->when($from, fn (Builder $q) => $q->where('e.entry_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('e.entry_date', '<=', $to))
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.line_no')
            ->get(['e.id as journal_entry_id', 'e.entry_no', 'e.entry_date', 'e.entry_type', 'e.reference', 'e.narration', 'e.source_type', 'e.source_id', 'e.branch_id', 'l.id as line_id', 'a.code as account_code', 'l.debit', 'l.credit', 'l.due_date']);

        $balance = $opening;
        $debitTotal = BigDecimal::zero();
        $creditTotal = BigDecimal::zero();
        $entries = [];
        foreach ($rows as $row) {
            $debitTotal = $debitTotal->plus((string) $row->debit);
            $creditTotal = $creditTotal->plus((string) $row->credit);
            $balance = $balance->plus(BigDecimal::of((string) $row->debit)->minus((string) $row->credit)->multipliedBy($sign));
            $entries[] = [
                'date' => Carbon::parse($row->entry_date)->toDateString(),
                'journal_entry_id' => (int) $row->journal_entry_id,
                'entry_no' => $row->entry_no,
                'entry_type' => $row->entry_type,
                'reference' => $row->reference,
                'narration' => $row->narration,
                'source' => $row->source_type ? ['type' => $row->source_type, 'id' => (int) $row->source_id] : null,
                'branch_id' => (int) $row->branch_id,
                'account_code' => $row->account_code,
                'debit' => (string) BigDecimal::of((string) $row->debit)->toScale(2),
                'credit' => (string) BigDecimal::of((string) $row->credit)->toScale(2),
                'due_date' => $row->due_date ? Carbon::parse($row->due_date)->toDateString() : null,
                'balance' => (string) $balance->toScale(2),
            ];
        }

        return [
            'party_id' => $partyId,
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'opening' => (string) $opening->toScale(2),
            'debit_total' => (string) $debitTotal->toScale(2),
            'credit_total' => (string) $creditTotal->toScale(2),
            'closing' => (string) $balance->toScale(2),
            'entries' => $entries,
        ];
    }

    /** The party's balance on one side, or on both: receivable minus payable (positive = the party owes us). */
    public function partyBalance(int $partyId, ?string $kind = null, ?Authenticatable $user = null): string
    {
        if ($kind !== null) {
            return $this->partyLedger($partyId, $kind, user: $user)['closing'];
        }

        return (string) BigDecimal::of($this->partyLedger($partyId, Account::RECEIVABLE, user: $user)['closing'])
            ->minus($this->partyLedger($partyId, Account::PAYABLE, user: $user)['closing'])->toScale(2);
    }

    /**
     * Open items: income (receivable) or expense (payable) on credit that is not fully settled, as of a date,
     * with what was allocated, what is outstanding and how many days it is overdue.
     *
     * @return list<array<string, mixed>>
     */
    public function openItems(string $kind, ?int $partyId = null, \DateTimeInterface|string|null $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? now())->startOfDay();
        $type = $kind === Account::PAYABLE ? FinancialTransaction::EXPENSE : FinancialTransaction::INCOME;

        return FinancialTransaction::query()
            ->ofType($type)->where('status', FinancialTransaction::POSTED)
            ->whereNull('cash_bank_account_id')->whereNotNull('counter_account_id')
            ->whereDate('transaction_date', '<=', $asOf->toDateString())
            ->when($partyId, fn ($q) => $q->where('party_id', $partyId))
            ->withSum(['allocationsReceived as allocated' => fn ($q) => $q->whereNull('reversed_at')->whereDate('allocation_date', '<=', $asOf->toDateString())], 'amount')
            ->orderBy('due_date')->orderBy('transaction_date')->orderBy('id')
            ->get()
            ->map(function (FinancialTransaction $item) use ($asOf): array {
                $allocated = BigDecimal::of((string) ($item->allocated ?? 0))->toScale(2);
                $outstanding = BigDecimal::of((string) $item->total_amount)->minus($allocated)->toScale(2);
                $due = $item->due_date ?? $item->transaction_date;

                return [
                    'transaction_id' => $item->id,
                    'transaction_no' => $item->transaction_no,
                    'party_id' => $item->party_id,
                    'branch_id' => $item->branch_id,
                    'transaction_date' => $item->transaction_date->toDateString(),
                    'due_date' => $item->due_date?->toDateString(),
                    'total' => (string) $item->total_amount,
                    'allocated' => (string) $allocated,
                    'outstanding' => (string) $outstanding,
                    'days_overdue' => max(0, (int) $due->diffInDays($asOf, false)),
                ];
            })
            ->filter(fn (array $item): bool => BigDecimal::of($item['outstanding'])->isPositive())
            ->values()
            ->all();
    }

    /** Posted lines of the party on control accounts of the kind, limited to the user's branches. */
    private function lines(int $partyId, string $kind, ?Authenticatable $user): Builder
    {
        $query = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.party_id', $partyId)
            ->where('a.control_kind', $kind)
            ->whereIn('e.status', [JournalEntry::POSTED, JournalEntry::REVERSED]);

        if ($user !== null && Branches::enabled()) {
            $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
            if (! $isSuperAdmin && Branches::assignedIds($user) === []) {
                return $query->whereRaw('1 = 0');
            }
            $ids = Branches::visibleIds($user);
            if ($ids !== null) {
                $query->whereIn('e.branch_id', $ids ?: [0]);
            }
        }

        return $query;
    }
}
