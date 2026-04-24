<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $bank_slug
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int $transactions_inserted
 * @property int $transactions_updated
 * @property int $transactions_deduped
 * @property int $error_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'transactions_inserted' => 'integer',
            'transactions_updated' => 'integer',
            'transactions_deduped' => 'integer',
            'error_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_slug', 'slug');
    }

    /** @return HasMany<SyncRunError, $this> */
    public function errors(): HasMany
    {
        return $this->hasMany(SyncRunError::class);
    }
}
