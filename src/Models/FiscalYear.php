<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * A financial year (any start month; the dates are chosen when it is created).
 *
 * @property int $id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $status open|closed
 */
class FiscalYear extends Model
{
    use LogsActivity, RecordsAuthors;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'acc_fiscal_years';

    protected $fillable = ['name', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime'];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(Period::class, 'fiscal_year_id')->orderBy('period_no');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function activityName(): string
    {
        return 'acc_fiscal_year';
    }
}
