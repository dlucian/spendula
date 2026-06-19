<?php

declare(strict_types=1);

namespace App\Services\Sync;

/**
 * Value object representing a single own-account card top-up mapping loaded
 * from the operator's config/counterparty-rules-enabled/own-account-topups.json.
 *
 * A TopupLink captures the three identifiers that name the same physical card /
 * top-up relationship:
 *   - funding_card_last4: the card's last-4 as it appears in the bank's COMPRA
 *     descriptor (e.g. "COMPRA 5962 Revolut 2180 Dublin IE").
 *   - funding_marker: the string that tags the payee as Revolut on the bank side
 *     (e.g. "Revolut"). Matched case-insensitively as a substring of the COMPRA
 *     descriptor after the card last-4.
 *   - apple_pay_tokens: one or more Apple Pay DPAN last-4 digits that represent
 *     the same physical card on the Revolut side ("Apple Pay Top-Up by *2798").
 *
 * The resolved bank_account_id for the destination is populated by TopupLinkLoader
 * after it looks up destination_account_ref against the bank_accounts table. A
 * null resolvedDestinationId means the ref did not match any active account; the
 * linker skips that link.
 *
 * @immutable
 */
final class TopupLink
{
    /**
     * @param  list<string>  $applePayTokens
     */
    public function __construct(
        /** The bank slug of the funding account (e.g. "bcp"). */
        public readonly string $fundingBankSlug,
        /** Last-4 of the card as seen in the bank's COMPRA descriptor. */
        public readonly string $fundingCardLast4,
        /**
         * Substring that marks the payee as the destination provider on the funding side
         * (e.g. "Revolut"). Matched case-insensitively.
         */
        public readonly string $fundingMarker,
        /**
         * Human-readable reference for the destination account — matched against
         * bank_accounts.display_name or bank_accounts.iban. Resolved to a
         * bank_account_id by TopupLinkLoader::resolve().
         */
        public readonly string $destinationAccountRef,
        /**
         * Apple Pay DPAN token last-4 digits that identify this card on the
         * destination (Revolut) side. "Apple Pay Top-Up by *XXXX" entries whose
         * XXXX appears in this list are treated as the destination leg of this link.
         */
        public readonly array $applePayTokens,
        /**
         * How many calendar days the funding and destination legs may differ in
         * booking_date. Typically 1–3 for cross-bank settlement lag.
         */
        public readonly int $amountToleranceDays,
        /**
         * Resolved bank_account_id for the destination account. Null when
         * TopupLinkLoader could not match destination_account_ref to a row.
         */
        public readonly ?string $resolvedDestinationId = null,
    ) {}

    /**
     * Return a copy with the resolved destination bank_account_id populated.
     */
    public function withResolvedDestination(string $bankAccountId): self
    {
        return new self(
            fundingBankSlug: $this->fundingBankSlug,
            fundingCardLast4: $this->fundingCardLast4,
            fundingMarker: $this->fundingMarker,
            destinationAccountRef: $this->destinationAccountRef,
            applePayTokens: $this->applePayTokens,
            amountToleranceDays: $this->amountToleranceDays,
            resolvedDestinationId: $bankAccountId,
        );
    }

    /**
     * Returns true when a funding-side COMPRA descriptor matches this link.
     *
     * Checks:
     *   1. The card last-4 appears in the descriptor (case-insensitive).
     *   2. The funding marker appears in the descriptor (case-insensitive).
     *
     * Example: "COMPRA 5962 Revolut 2180 Dublin IE" matches a link with
     * fundingCardLast4="5962" and fundingMarker="Revolut".
     */
    public function matchesFundingDescriptor(string $descriptor): bool
    {
        $lower = mb_strtolower($descriptor);

        return str_contains($lower, mb_strtolower($this->fundingCardLast4))
            && str_contains($lower, mb_strtolower($this->fundingMarker));
    }

    /**
     * Returns true when a destination-side remittance string matches any of
     * this link's Apple Pay tokens.
     *
     * Checks for the pattern "Top-Up by *XXXX" (case-insensitive) where XXXX
     * is one of this link's apple_pay_tokens. Handles both forms that Revolut
     * emits: "Apple Pay Top-Up by *2798" and provider-prefix variants.
     */
    public function matchesDestinationRemittance(string $remittance): bool
    {
        $lower = mb_strtolower($remittance);
        foreach ($this->applePayTokens as $token) {
            if (str_contains($lower, 'top-up by *'.mb_strtolower($token))) {
                return true;
            }
        }

        return false;
    }
}
