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

    /**
     * Human-readable label for the destination account.
     *
     * Returns the trimmed display_name when non-blank; falls back to the
     * normalized IBAN. Prevents "Transfer : " (with an empty suffix) when
     * display_name is null, empty, or whitespace-only.
     */
    public function destinationLabel(): string
    {
        $name = trim((string) $this->destination->display_name);

        return $name !== '' ? $name : $this->destinationIban;
    }
}
