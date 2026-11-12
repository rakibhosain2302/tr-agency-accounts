<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * A heading of the chart of accounts. Groups form a tree; every group of a branch of the tree has the same
 * account category as its parent.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property int $account_type_id
 * @property string $code
 * @property string $name
 */
class AccountGroup extends Model
{
    use LogsActivity, RecordsAuthors;

    protected $table = 'acc_account_groups';

    protected $fillable = ['parent_id', 'account_type_id', 'code', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'parent_id' => 'integer', 'account_type_id' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_group_id')->orderBy('code');
    }

    /**
     * Ids of this group and every group below it.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];

        while ($level !== []) {
            $level = self::query()->whereIn('parent_id', $level)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    public function activityName(): string
    {
        return 'acc_account_group';
    }
}
