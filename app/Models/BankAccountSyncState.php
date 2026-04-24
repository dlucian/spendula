<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $bank_account_id
 * @property Carbon|null $last_successful_sync_at
 * @property Carbon|null $last_fetched_through
 * @property string|null $last_continuation_key
 * @property Carbon|null $last_sync_error_at
 * @property int $consecutive_failure_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BankAccountSyncState extends Model
{
    protected $table = 'bank_account_sync_state';

    protected $primaryKey = 'bank_account_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_successful_sync_at' => 'datetime',
            'last_fetched_through' => 'date',
            'last_sync_error_at' => 'datetime',
            'consecutive_failure_count' => 'integer',
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
