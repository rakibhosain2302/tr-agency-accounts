<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;
use Nit\Master\Models\Concerns\LogsActivity;

/**
 * An accounting period of a financial year. Journals post only into an open period of an open year.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property int $period_no
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $status open|closed
 */
class Period extends Model
{
    use LogsActivity, RecordsAuthors;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'acc_periods';

    protected $fillable = ['fiscal_year_id', 'period_no', 'name', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime', 'period_no' => 'integer', 'fiscal_year_id' => 'integer'];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    /** The period whose dates contain the day. */
    public function scopeContaining(Builder $query, \DateTimeInterface|string $date): Builder
    {
        $day = Carbon::parse($date)->toDateString();

        return $query->whereDate('start_date', '<=', $day)->whereDate('end_date', '>=', $day);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function activityName(): string
    {
        return 'acc_period';
    }
}
