<?php

namespace App\Models;

use App\Enums\PushErrorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $push_run_id
 * @property string|null $transaction_id
 * @property PushErrorType $error_type
 * @property string $error_detail
 * @property int|null $http_status
 * @property Carbon $created_at
 */
class PushRunError extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'error_type' => PushErrorType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PushRun, $this> */
    public function pushRun(): BelongsTo
    {
        return $this->belongsTo(PushRun::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
