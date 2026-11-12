<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nit\AgraniAccounts\Exceptions\AccountingException;

/**
 * A journal entry detail: one debit or one credit on one account. Lines of a posted entry cannot be added,
 * changed or deleted.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $line_no
 * @property int $account_id
 * @property int|null $party_id
 * @property string $debit
 * @property string $credit
 * @property string|null $currency_code
 * @property string $exchange_rate
 * @property string $fc_debit
 * @property string $fc_credit
 */
class JournalLine extends Model
{
    protected $table = 'acc_journal_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'fc_debit' => 'decimal:2',
            'fc_credit' => 'decimal:2',
            'due_date' => 'date',
            'line_no' => 'integer',
            'account_id' => 'integer',
            'party_id' => 'integer',
            'journal_entry_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $line): void {
            $entry = JournalEntry::query()->withoutGlobalScopes()->find($line->journal_entry_id);
            if ($entry !== null && ! $entry->isDraft()) {
                throw AccountingException::immutable($entry);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
