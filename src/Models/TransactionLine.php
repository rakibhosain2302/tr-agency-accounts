<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nit\AgraniAccounts\Exceptions\AccountingException;

/**
 * One line of an income or expense: an income / expense account and its amount. Lines of a posted
 * transaction cannot be added, changed or deleted.
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $line_no
 * @property int $account_id
 * @property string $amount
 * @property string|null $description
 */
class TransactionLine extends Model
{
    protected $table = 'acc_transaction_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'line_no' => 'integer', 'account_id' => 'integer', 'transaction_id' => 'integer'];
    }

    protected static function booted(): void
    {
        $guard = function (self $line): void {
            $transaction = FinancialTransaction::query()->withoutGlobalScopes()->find($line->transaction_id);
            if ($transaction !== null && ! $transaction->isDraft()) {
                throw AccountingException::immutableTransaction($transaction);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
