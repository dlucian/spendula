<?php

namespace App\Services\Status;

use Illuminate\Support\Carbon;

/**
 * One row in the spendula:status push-stuck warnings section.
 *
 * The query is three-way: `push_attempt_count >= PUSH_STUCK_ATTEMPTS AND
 * status IN ('approved', 'transfer') AND ynab_transaction_id IS NULL`. The
 * status/ynab_transaction_id filters matter because PushRunner increments
 * `push_attempt_count` on the success path too — without them, a row that
 * retried five times then succeeded would linger in this list forever.
 *
 * @immutable
 */
final class StuckTransactionRow
{
    public function __construct(
        public readonly string $bankDisplayName,
        public readonly string $bankAccountDisplayName,
        public readonly string $transactionId,
        public readonly Carbon $bookingDate,
        public readonly int $amountMilliunits,
        public readonly string $currency,
        public readonly ?string $counterpartyName,
        public readonly int $pushAttemptCount,
        public readonly ?Carbon $lastPushAttemptAt,
        public readonly ?string $lastPushError,
    ) {}
}
