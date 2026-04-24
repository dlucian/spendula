<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int $transactions_pushed
 * @property int $transactions_duplicate
 * @property int $error_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PushRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'transactions_pushed' => 'integer',
            'transactions_duplicate' => 'integer',
            'error_count' => 'integer',
        ];
    }

    /** @return HasMany<PushRunError, $this> */
    public function errors(): HasMany
    {
        return $this->hasMany(PushRunError::class);
    }
}
