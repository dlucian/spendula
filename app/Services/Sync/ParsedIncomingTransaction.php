<?php

namespace App\Services\Sync;

use App\Enums\CreditDebitIndicator;
use App\Models\BankAccount;
use Illuminate\Support\Carbon;

/**
 * Value object for an Enable Banking transaction normalized into Spendula's
 * internal shape. Produced by MatchUpdateOrInsert's parser, consumed by its
 * insert/update branches. Pulled out of the class so the branches have a
 * concrete type to work with instead of a tuple.
 *
 * @immutable
 */
final class ParsedIncomingTransaction
{
    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  bool  $ownAccountTransfer  True when OwnAccountClassifier confirmed a
     *                                    same-currency own-account transfer. Drives
     *                                    status=transfer on insert (after cutoff/tracking
     *                                    guards). False for all other transactions,
     *                                    including cross-currency FX own-account moves.
     */
    public function __construct(
        public readonly BankAccount $account,
        public readonly array $rawPayload,
        public readonly string $dedupHash,
        public readonly ?string $entryReference,
        public readonly string $transactionStatus,
        public readonly string $bookingDate,
        public readonly ?string $valueDate,
        public readonly int $amountMilliunits,
        public readonly string $currency,
        public readonly CreditDebitIndicator $creditDebitIndicator,
        public readonly string $rawCounterparty,
        public readonly string $counterpartyName,
        public readonly int $counterpartyResolutionLevel,
        public readonly ?string $remittanceInformation,
        public readonly Carbon $now,
        public readonly bool $ownAccountTransfer = false,
    ) {}
}
