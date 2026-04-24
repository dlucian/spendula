<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $bank_connection_id
 * @property string $bank_account_id
 * @property string $enable_banking_uid
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BankAccountSession extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<BankConnection, $this> */
    public function bankConnection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
