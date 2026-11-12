<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A journal line of the cash / bank account marked as cleared (seen on the statement). A line is cleared once.
 *
 * @property int $id
 * @property int $reconciliation_id
 * @property int $journal_line_id
 */
class ReconciliationLine extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'acc_reconciliation_lines';

    protected $fillable = ['reconciliation_id', 'journal_line_id', 'created_by'];

    protected function casts(): array
    {
        return ['reconciliation_id' => 'integer', 'journal_line_id' => 'integer'];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class, 'reconciliation_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'journal_line_id');
    }
}
