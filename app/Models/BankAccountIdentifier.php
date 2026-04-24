<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $bank_account_id
 * @property string $hash
 * @property bool $is_primary
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BankAccountIdentifier extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
