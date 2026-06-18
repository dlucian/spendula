<?php

declare(strict_types=1);

namespace App\Services\Counterparty;

use App\Models\BankAccount;

/**
 * Result of a successful own-account classification.
 *
 * @immutable
 */
final class OwnAccountClassification
{
    public function __construct(
        /** The matched destination account. */
        public readonly BankAccount $destination,
        /** Normalized IBAN used for the match (stripped whitespace, upper-cased). */
        public readonly string $destinationIban,
        /**
         * True when the transaction currency matches the destination account's
         * currency. Same-currency → status=transfer; different-currency → FX
         * labelling with status unchanged.
         */
        public readonly bool $sameCurrency,
    ) {}
}
