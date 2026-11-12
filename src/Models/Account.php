<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * A postable account of the chart of accounts. Its type and category come from its group.
 * A control account needs a party on every journal line; control_kind says whether it holds what parties owe
 * (receivable: the customer side) or what is owed to them (payable: the supplier side).
 *
 * @property int $id
 * @property int $account_group_id
 * @property string $code
 * @property string $name
 * @property bool $is_control
 * @property string|null $control_kind receivable|payable
 * @property string|null $currency_code
 * @property bool $is_active
 * @property bool $is_system
 */
class Account extends Model
{
    use LogsActivity, RecordsAuthors;

    public const RECEIVABLE = 'receivable';

    public const PAYABLE = 'payable';

    protected $table = 'acc_accounts';

    protected $fillable = ['account_group_id', 'code', 'name', 'is_control', 'control_kind', 'currency_code', 'is_active', 'is_system', 'description'];

    protected function casts(): array
    {
        return ['is_control' => 'boolean', 'is_active' => 'boolean', 'is_system' => 'boolean', 'account_group_id' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function category(): string
    {
        return $this->group->type->category;
    }

    public function activityName(): string
    {
        return 'acc_account';
    }

    public function activityLabel(): string
    {
        return "{$this->code} {$this->name}";
    }
}
