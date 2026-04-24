<?php

namespace App\Models;

use App\Enums\BankConnectionStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $bank_slug
 * @property string $enable_banking_session_id
 * @property BankConnectionStatus $status
 * @property Carbon $authorized_at
 * @property Carbon $valid_until
 * @property string|null $superseded_by_id
 * @property array<string, mixed> $raw_session_response
 * @property Carbon|null $last_synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BankConnection extends Model
{
    use HasUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => BankConnectionStatus::class,
            'authorized_at' => 'datetime',
            'valid_until' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw_session_response' => 'array',
        ];
    }

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_slug', 'slug');
    }

    /** @return BelongsTo<BankConnection, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** @return HasMany<BankAccountSession, $this> */
    public function accountSessions(): HasMany
    {
        return $this->hasMany(BankAccountSession::class);
    }
}
