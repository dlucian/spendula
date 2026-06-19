<?php

namespace App\Models;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $bank_account_id
 * @property string $dedup_hash
 * @property string|null $entry_reference
 * @property TransactionStatus $status
 * @property string $transaction_status
 * @property Carbon $booking_date
 * @property Carbon|null $value_date
 * @property int $amount_milliunits
 * @property string $currency
 * @property CreditDebitIndicator $credit_debit_indicator
 * @property string|null $counterparty_name
 * @property int $counterparty_resolution_level
 * @property string|null $remittance_information
 * @property array<string, mixed> $raw_payload
 * @property int $occurrence
 * @property string|null $ynab_transaction_id
 * @property string|null $ynab_import_id
 * @property int $push_attempt_count
 * @property Carbon|null $last_push_attempt_at
 * @property string|null $last_push_error
 * @property Carbon|null $pushed_at
 * @property Carbon|null $skipped_at
 * @property string|null $skip_reason
 * @property string|null $linked_transfer_id GH #16 — nullable self-FK linking the two legs of a cross-source own-account top-up.
 * @property Carbon $first_seen_at
 * @property Carbon $last_updated_from_bank_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Transaction extends Model
{
    use HasUuidV7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'credit_debit_indicator' => CreditDebitIndicator::class,
            'booking_date' => 'date',
            'value_date' => 'date',
            'amount_milliunits' => 'integer',
            'counterparty_resolution_level' => 'integer',
            'occurrence' => 'integer',
            'push_attempt_count' => 'integer',
            'raw_payload' => 'array',
            'last_push_attempt_at' => 'datetime',
            'pushed_at' => 'datetime',
            'skipped_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_updated_from_bank_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * GH #16 — the other leg of a cross-source own-account top-up.
     *
     * For the funding leg (status=transfer): returns the destination leg (status=transfer_dropped).
     * For the destination leg (status=transfer_dropped): returns the funding leg (status=transfer).
     * For all other transactions: linked_transfer_id is null and this relation returns null.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function linkedTransfer(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'linked_transfer_id');
    }
}
