<?php

namespace App\Models;

use App\Enums\SyncErrorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sync_run_id
 * @property string|null $bank_account_id
 * @property SyncErrorType $error_type
 * @property string $error_detail
 * @property int|null $http_status
 * @property Carbon $created_at
 */
class SyncRunError extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'error_type' => SyncErrorType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SyncRun, $this> */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
