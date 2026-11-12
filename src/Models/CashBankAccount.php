<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Branch;
use Nit\Master\Models\Concerns\LogsActivity;
use Nit\Master\Support\Branches;

/**
 * A cash, bank or mobile-wallet account: one asset account of the chart. branch_id NULL = every branch.
 *
 * @property int $id
 * @property int $account_id
 * @property string $code
 * @property string $name
 * @property string $kind cash|bank|mobile
 * @property int|null $branch_id
 * @property bool $is_active
 */
class CashBankAccount extends Model
{
    use LogsActivity, RecordsAuthors;

    public const KINDS = ['cash', 'bank', 'mobile'];

    protected $table = 'acc_cash_bank_accounts';

    protected $fillable = ['account_id', 'code', 'name', 'kind', 'branch_id', 'bank_name', 'bank_branch', 'account_title', 'account_number', 'routing_number', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'account_id' => 'integer', 'branch_id' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class, 'cash_bank_account_id');
    }

    /** Accounts of the branches the user sees, plus the shared ones. */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        $ids = Branches::visibleIds($user);

        return $ids === null ? $query : $query->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $ids ?: [0]));
    }

    /** Whether a transaction of the branch may use this account. */
    public function usableBy(int $branchId): bool
    {
        return $this->is_active && ($this->branch_id === null || $this->branch_id === $branchId);
    }

    public function activityName(): string
    {
        return 'acc_cash_bank_account';
    }
}
