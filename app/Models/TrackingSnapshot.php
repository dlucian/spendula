<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $bank_account_id
 * @property Carbon $as_of_date
 * @property int $native_balance_milliunits
 * @property int $base_balance_milliunits
 * @property string $exchange_rate
 * @property string $exchange_rate_source
 * @property string $ynab_transaction_id
 * @property Carbon $pushed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TrackingSnapshot extends Model
{
    use HasUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'native_balance_milliunits' => 'integer',
            'base_balance_milliunits' => 'integer',
            'pushed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
