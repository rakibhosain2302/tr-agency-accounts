<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Concerns\BranchScoped;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;

/**
 * A journal entry: draft → posted → reversed. Only drafts can be changed or deleted; a posted entry stays
 * as it is, except that reversing it marks it "reversed" (the reversal itself is a new posted entry).
 * Write through Nit\AgraniAccounts\Services\JournalService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property int|null $fiscal_year_id
 * @property int|null $period_id
 * @property int|null $sequence_no
 * @property string|null $entry_no
 * @property Carbon $entry_date
 * @property string $entry_type
 * @property string $status
 * @property string|null $reference
 * @property string|null $narration
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $source_key
 * @property int|null $reversal_of_id
 */
class JournalEntry extends Model
{
    use BranchScoped, RecordsAuthors;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    public const TYPES = ['manual', 'system', 'opening', 'closing', 'reversal'];

    /** What may still change on a posted entry: reversing it. */
    private const REVERSAL_COLUMNS = ['status', 'reversed_at', 'reversed_by', 'updated_at', 'updated_by'];

    protected $table = 'acc_journal_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
            'branch_id' => 'integer',
            'fiscal_year_id' => 'integer',
            'period_id' => 'integer',
            'sequence_no' => 'integer',
            'source_id' => 'integer',
            'reversal_of_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $entry): void {
            $original = $entry->getOriginal('status');
            if ($original === self::DRAFT) {
                return;
            }

            $onlyReversal = $original === self::POSTED && $entry->status === self::REVERSED
                && array_diff(array_keys($entry->getDirty()), self::REVERSAL_COLUMNS) === [];

            if (! $onlyReversal) {
                throw AccountingException::immutable($entry);
            }
        });

        static::deleting(function (self $entry): void {
            if ($entry->getOriginal('status') !== self::DRAFT) {
                throw AccountingException::immutable($entry);
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_no');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    /** The document this entry comes from (polymorphic, no foreign key). */
    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }

    /** The entry this one reverses. */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** The entry that reverses this one. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    public function activityLabel(): string
    {
        return $this->entry_no ?? 'draft #'.$this->id;
    }
}
