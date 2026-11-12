<?php

namespace Nit\AgraniAccounts\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nit\AgraniAccounts\Models\Concerns\RecordsAuthors;

/**
 * Part of a receipt / payment settling an income / expense on credit. Only its reversal (reversed_at) may
 * change afterwards; it is never deleted, so the history of settlements stays.
 *
 * @property int $id
 * @property int $settlement_id
 * @property int $target_id
 * @property string $amount
 * @property Carbon $allocation_date
 * @property Carbon|null $reversed_at
 */
class Allocation extends Model
{
    use RecordsAuthors;

    protected $table = 'acc_allocations';

    protected $fillable = ['settlement_id', 'target_id', 'amount', 'allocation_date'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'allocation_date' => 'date', 'reversed_at' => 'datetime', 'settlement_id' => 'integer', 'target_id' => 'integer'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'settlement_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'target_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    public function isActive(): bool
    {
        return $this->reversed_at === null;
    }
}
