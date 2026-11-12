<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * Reconciliation of a cash / bank account with its statement up to statement_date. Draft until completed;
 * a completed reconciliation does not change.
 *
 * @property int $id
 * @property int $cash_bank_account_id
 * @property Carbon $statement_date
 * @property string $statement_balance
 * @property string $status draft|completed
 */
class Reconciliation extends Model
{
    use LogsActivity, RecordsAuthors;

    public const DRAFT = 'draft';

    public const COMPLETED = 'completed';

    protected $table = 'acc_reconciliations';

    protected $fillable = ['cash_bank_account_id', 'statement_date', 'statement_balance', 'notes'];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'statement_balance' => 'decimal:2', 'completed_at' => 'datetime', 'cash_bank_account_id' => 'integer'];
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReconciliationLine::class, 'reconciliation_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function activityName(): string
    {
        return 'acc_reconciliation';
    }
}
