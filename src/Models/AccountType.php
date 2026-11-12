<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * A kind of account in one of the five accounting categories.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $category asset|liability|equity|income|expense
 * @property bool $is_system
 */
class AccountType extends Model
{
    use LogsActivity, RecordsAuthors;

    public const CATEGORIES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Categories whose balance is normally on the debit side. */
    public const DEBIT_CATEGORIES = ['asset', 'expense'];

    protected $table = 'acc_account_types';

    protected $fillable = ['code', 'name', 'category', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(AccountGroup::class, 'account_type_id');
    }

    /** "debit" or "credit": derived from the category, never stored. */
    public function normalBalance(): string
    {
        return in_array($this->category, self::DEBIT_CATEGORIES, true) ? 'debit' : 'credit';
    }

    public function activityName(): string
    {
        return 'acc_account_type';
    }
}
