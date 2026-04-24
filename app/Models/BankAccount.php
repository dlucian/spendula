<?php

namespace App\Models;

use App\Enums\YnabAccountType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $bank_slug
 * @property string|null $display_name
 * @property string|null $iban
 * @property string $currency
 * @property bool $is_base_currency
 * @property string|null $ynab_account_id
 * @property YnabAccountType|null $ynab_account_type
 * @property Carbon|null $import_cutoff_date
 * @property bool $active
 * @property Carbon $first_linked_at
 * @property Carbon $last_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BankAccount extends Model
{
    use HasUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ynab_account_type' => YnabAccountType::class,
            'import_cutoff_date' => 'date',
            'first_linked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_base_currency' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_slug', 'slug');
    }

    /** @return HasMany<BankAccountIdentifier, $this> */
    public function identifiers(): HasMany
    {
        return $this->hasMany(BankAccountIdentifier::class);
    }

    /** @return HasMany<BankAccountSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(BankAccountSession::class);
    }

    /** @return HasOne<BankAccountSyncState, $this> */
    public function syncState(): HasOne
    {
        return $this->hasOne(BankAccountSyncState::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
