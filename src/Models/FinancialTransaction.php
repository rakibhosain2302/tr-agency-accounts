<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Concerns\BranchScoped;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;

/**
 * An income, expense, receipt or payment. draft → posted (its journal entry is written) → reversed (the journal
 * is reversed). Only drafts change; a posted transaction stays as it is. Write through FinancialTransactionService.
 *
 * - income: credit the income lines; debit cash / bank (paid at once) or the receivable account of the party.
 * - expense: debit the expense lines; credit cash / bank (paid at once) or the payable account of the party.
 * - receipt: money in from a party: debit cash / bank, credit the party's receivable account.
 * - payment: money out to a party: debit the party's payable account, credit cash / bank.
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property string $transaction_type
 * @property string $status
 * @property string|null $transaction_no
 * @property Carbon $transaction_date
 * @property Carbon|null $due_date
 * @property int|null $party_id
 * @property int|null $cash_bank_account_id
 * @property int|null $counter_account_id
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property string $total_amount
 * @property int|null $journal_entry_id
 * @property int|null $reversal_journal_entry_id
 */
class FinancialTransaction extends Model
{
    use BranchScoped, RecordsAuthors;

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const RECEIPT = 'receipt';

    public const PAYMENT = 'payment';

    public const TYPES = [self::INCOME, self::EXPENSE, self::RECEIPT, self::PAYMENT];

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    /** What may still change on a posted transaction: reversing it. */
    private const REVERSAL_COLUMNS = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at', 'updated_by'];

    protected $table = 'acc_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
            'branch_id' => 'integer',
            'party_id' => 'integer',
            'cash_bank_account_id' => 'integer',
            'counter_account_id' => 'integer',
            'journal_entry_id' => 'integer',
            'reversal_journal_entry_id' => 'integer',
            'source_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $transaction): void {
            $original = $transaction->getOriginal('status');
            if ($original === self::DRAFT) {
                return;
            }

            $onlyReversal = $original === self::POSTED && $transaction->status === self::REVERSED
                && array_diff(array_keys($transaction->getDirty()), self::REVERSAL_COLUMNS) === [];

            if (! $onlyReversal) {
                throw AccountingException::immutableTransaction($transaction);
            }
        });

        static::deleting(function (self $transaction): void {
            if ($transaction->getOriginal('status') !== self::DRAFT) {
                throw AccountingException::immutableTransaction($transaction);
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransactionLine::class, 'transaction_id')->orderBy('line_no');
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    /** Allocations made by this receipt / payment. */
    public function allocationsMade(): HasMany
    {
        return $this->hasMany(Allocation::class, 'settlement_id');
    }

    /** Allocations that settle this income / expense. */
    public function allocationsReceived(): HasMany
    {
        return $this->hasMany(Allocation::class, 'target_id');
    }

    /** The document behind the transaction in another module (polymorphic, no foreign key). */
    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('transaction_type', $type);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    /** Receipts and payments settle open items; income and expense on credit are open items. */
    public function isSettlement(): bool
    {
        return in_array($this->transaction_type, [self::RECEIPT, self::PAYMENT], true);
    }

    /** Income or expense on credit (a party owes / is owed the amount until it is settled). */
    public function isOnCredit(): bool
    {
        return ! $this->isSettlement() && $this->cash_bank_account_id === null && $this->counter_account_id !== null;
    }

    /** receivable (income, receipt) or payable (expense, payment): the side of the party the transaction is on. */
    public function controlKind(): string
    {
        return in_array($this->transaction_type, [self::INCOME, self::RECEIPT], true) ? Account::RECEIVABLE : Account::PAYABLE;
    }

    public function activityLabel(): string
    {
        return $this->transaction_no ?? $this->transaction_type.' draft #'.$this->id;
    }
}
