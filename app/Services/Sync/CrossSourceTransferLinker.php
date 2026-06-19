<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cross-source own-account top-up transfer linker (GH #16).
 *
 * When a newly inserted (or updated) transaction is recognised as one leg of an
 * own-account card top-up, this service looks for the counterpart leg on the
 * other account and — when found — suppresses the Revolut-side copy so that
 * YNAB sees only the funding bank's debit, avoiding phantom duplicate entries.
 *
 * ## Terminology
 *
 *   Funding leg  — the DBIT on the bank account (e.g. "COMPRA 5962 Revolut 2180
 *                  Dublin IE" on Millennium BCP). This is the leg the operator
 *                  sees and reconciles against the bank statement. It is KEPT
 *                  (promoted to status=transfer) and pushed to YNAB.
 *
 *   Destination leg — the CRDT on the Revolut account (e.g. "Apple Pay Top-Up
 *                     by *2798"). This is suppressed (status=transfer_dropped)
 *                     so YNAB never sees it standalone.
 *
 * ## Detection
 *
 * TopupLinkLoader maps (funding_bank_slug, card_last4, funding_marker) to a
 * destination bank account (display_name or IBAN). The linker checks both legs:
 *
 *   Funding side (DBIT): the creditor name or remittance contains the card last-4
 *     AND the funding_marker ("Revolut") for this link.
 *
 *   Destination side (CRDT): remittance_information[0] matches "Top-Up by *XXXX"
 *     where XXXX is one of the link's apple_pay_tokens.
 *
 * ## Match key
 *
 *   (funding_bank_account_id, destination_bank_account_id, |amount_milliunits|,
 *    currency, ±amountToleranceDays)
 *
 * ## Order-independence and idempotency
 *
 *   The method fires after each insert/update. Whichever side arrives first
 *   parks as fetched (no counterpart found yet). When the second side arrives,
 *   the linker finds the first and collapses the pair. Re-running the same
 *   transaction is a no-op: the link + status guard short-circuits.
 *
 * ## Already-pushed guard
 *
 *   If the destination leg was already pushed (status=pushed), we do NOT retro-
 *   edit it. We promote the funding leg to transfer and log a cross_source.late_pair
 *   warning for manual convergence. The operator is responsible for reversing or
 *   reconciling the YNAB duplicate manually in that case.
 *
 * ## Not applicable
 *
 *   - Rows already linked (linked_transfer_id set).
 *   - Rows in terminal statuses (skipped, tracking, pushed, transfer_dropped).
 *   - Transactions with no matching TopupLink for this account/descriptor.
 */
final class CrossSourceTransferLinker
{
    public function __construct(
        private readonly TopupLinkLoader $loader,
    ) {}

    /**
     * Attempt to link the given transaction to its cross-source counterpart.
     *
     * Safe to call on every insert/update — returns immediately when no matching
     * link configuration applies or the transaction is already handled.
     *
     * @param  BankAccount  $account  The account the transaction belongs to.
     * @param  Transaction  $transaction  The freshly inserted/updated transaction.
     * @param  string  $rawCounterparty  The raw (pre-normalization) counterparty string
     *                                   extracted at parse time — used for funding-side pattern matching.
     * @param  string|null  $remittanceInfo  The joined remittance information string — used for
     *                                       destination-side Apple Pay token matching.
     */
    public function link(
        BankAccount $account,
        Transaction $transaction,
        string $rawCounterparty,
        ?string $remittanceInfo,
    ): void {
        // Already linked or in a terminal / non-actionable status.
        if ($transaction->linked_transfer_id !== null) {
            return;
        }
        $skipStatuses = [
            TransactionStatus::Skipped,
            TransactionStatus::Tracking,
            TransactionStatus::Pushed,
            TransactionStatus::TransferDropped,
            TransactionStatus::Transfer,
        ];
        if (in_array($transaction->status, $skipStatuses, true)) {
            return;
        }

        $links = $this->loader->links();
        if ($links === []) {
            return;
        }

        // Attempt to classify this transaction as a funding leg or destination leg.
        foreach ($links as $link) {
            if ($link->resolvedDestinationId === null) {
                continue;
            }

            $isFundingLeg = $this->isFundingLeg($account, $transaction, $rawCounterparty, $link);
            $isDestinationLeg = $this->isDestinationLeg($account, $transaction, $remittanceInfo, $link);

            if ($isFundingLeg) {
                $this->handleFundingLeg($account, $transaction, $link);

                return;
            }

            if ($isDestinationLeg) {
                $this->handleDestinationLeg($account, $transaction, $link);

                return;
            }
        }
    }

    /**
     * Returns true when the transaction looks like the funding (bank DBIT) leg.
     *
     * Conditions:
     *   - Account bank_slug matches the link's funding_bank_slug.
     *   - Transaction is a DBIT (debit — money leaving the funding account).
     *   - The raw counterparty contains both the card last-4 AND the funding marker
     *     (e.g. "COMPRA 5962 Revolut 2180 Dublin IE").
     */
    private function isFundingLeg(
        BankAccount $account,
        Transaction $transaction,
        string $rawCounterparty,
        TopupLink $link,
    ): bool {
        return $account->bank_slug === $link->fundingBankSlug
            && $transaction->credit_debit_indicator === CreditDebitIndicator::Debit
            && $link->matchesFundingDescriptor($rawCounterparty);
    }

    /**
     * Returns true when the transaction looks like the destination (Revolut CRDT) leg.
     *
     * Conditions:
     *   - Account id matches the link's resolved destination account.
     *   - Transaction is a CRDT (credit — money arriving in the Revolut account).
     *   - The remittance information contains a "Top-Up by *XXXX" token that
     *     matches one of the link's apple_pay_tokens.
     */
    private function isDestinationLeg(
        BankAccount $account,
        Transaction $transaction,
        ?string $remittanceInfo,
        TopupLink $link,
    ): bool {
        if ($account->id !== $link->resolvedDestinationId) {
            return false;
        }
        if ($transaction->credit_debit_indicator !== CreditDebitIndicator::Credit) {
            return false;
        }
        if ($remittanceInfo === null || $remittanceInfo === '') {
            return false;
        }

        return $link->matchesDestinationRemittance($remittanceInfo);
    }

    /**
     * Handle the funding-leg side of the link.
     *
     * Look for an unlinked destination-leg counterpart within the settlement
     * window. On a hit: promote this funding leg to transfer and drop the
     * destination leg. On a miss: leave this leg as-is (the destination hasn't
     * arrived yet; the linker will fire when it does).
     */
    private function handleFundingLeg(
        BankAccount $account,
        Transaction $transaction,
        TopupLink $link,
    ): void {
        $counterpart = $this->findDestinationCounterpart($transaction, $link);
        if ($counterpart === null) {
            // Destination leg not yet synced. The linker fires again when it arrives.
            return;
        }

        $this->applyLink($transaction, $counterpart, $link);
    }

    /**
     * Handle the destination-leg side of the link.
     *
     * Look for an unlinked funding-leg counterpart within the settlement window.
     * On a hit: promote the funding leg to transfer, drop this destination leg.
     * On a miss: leave as-is (funding leg hasn't arrived yet).
     */
    private function handleDestinationLeg(
        BankAccount $account,
        Transaction $transaction,
        TopupLink $link,
    ): void {
        $counterpart = $this->findFundingCounterpart($transaction, $link);
        if ($counterpart === null) {
            // Funding leg not yet synced. The linker fires again when it arrives.
            return;
        }

        $this->applyLink($counterpart, $transaction, $link);
    }

    /**
     * Search the destination account for a CRDT transaction within the settlement
     * window that matches this link's apple_pay_tokens.
     *
     * Match key: (destination_account_id, abs(amount_milliunits), currency, ±window days,
     * CRDT, not-yet-linked, not terminal).
     */
    private function findDestinationCounterpart(Transaction $funding, TopupLink $link): ?Transaction
    {
        $absAmount = abs($funding->amount_milliunits);
        $windowDays = $link->amountToleranceDays;
        $date = $funding->booking_date;

        $candidates = Transaction::query()
            ->where('bank_account_id', $link->resolvedDestinationId)
            ->where('credit_debit_indicator', CreditDebitIndicator::Credit->value)
            ->where('amount_milliunits', $absAmount)
            ->where('currency', $funding->currency)
            ->whereNull('linked_transfer_id')
            ->whereNotIn('status', [
                TransactionStatus::Skipped->value,
                TransactionStatus::Tracking->value,
                TransactionStatus::TransferDropped->value,
                TransactionStatus::Transfer->value,
            ])
            ->whereBetween('booking_date', [
                $date->copy()->subDays($windowDays)->toDateString(),
                $date->copy()->addDays($windowDays)->toDateString(),
            ])
            ->get();

        foreach ($candidates as $candidate) {
            $remittance = $candidate->remittance_information;
            if (! is_string($remittance) || $remittance === '') {
                // Also check raw_payload remittance_information
                $rawRemittance = $this->extractRawRemittance($candidate->raw_payload);
                if ($rawRemittance === null) {
                    continue;
                }
                $remittance = $rawRemittance;
            }
            if ($link->matchesDestinationRemittance($remittance)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Search the funding account for a DBIT transaction within the settlement
     * window that matches this link's card/marker pattern.
     *
     * Match key: (funding_account_id per bank_slug, abs(amount_milliunits), currency,
     * ±window days, DBIT, not-yet-linked, not terminal).
     */
    private function findFundingCounterpart(Transaction $destination, TopupLink $link): ?Transaction
    {
        $absAmount = abs($destination->amount_milliunits);
        $windowDays = $link->amountToleranceDays;
        $date = $destination->booking_date;

        // Find all DBIT accounts for this bank slug.
        $fundingAccountIds = BankAccount::query()
            ->where('bank_slug', $link->fundingBankSlug)
            ->where('active', true)
            ->pluck('id')
            ->all();

        if ($fundingAccountIds === []) {
            return null;
        }

        $candidates = Transaction::query()
            ->whereIn('bank_account_id', $fundingAccountIds)
            ->where('credit_debit_indicator', CreditDebitIndicator::Debit->value)
            ->where('amount_milliunits', -$absAmount)   // DBIT milliunits are negative
            ->where('currency', $destination->currency)
            ->whereNull('linked_transfer_id')
            ->whereNotIn('status', [
                TransactionStatus::Skipped->value,
                TransactionStatus::Tracking->value,
                TransactionStatus::TransferDropped->value,
                TransactionStatus::Transfer->value,
            ])
            ->whereBetween('booking_date', [
                $date->copy()->subDays($windowDays)->toDateString(),
                $date->copy()->addDays($windowDays)->toDateString(),
            ])
            ->get();

        foreach ($candidates as $candidate) {
            $rawCounterparty = $this->extractRawCounterpartyFromPayload($candidate->raw_payload);
            if ($link->matchesFundingDescriptor($rawCounterparty)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Apply the link: promote the funding leg to transfer and drop the destination leg.
     *
     * Checks the already-pushed guard before modifying the destination leg.
     * If the destination was already pushed: promote the funding leg but leave the
     * destination alone, log a cross_source.late_pair warning.
     */
    private function applyLink(
        Transaction $funding,
        Transaction $destination,
        TopupLink $link,
    ): void {
        $prefix = (string) config('spendula.own_account.transfer_prefix', 'Transfer');

        // Look up the destination account's display_name for the memo.
        $destAccount = BankAccount::query()->find($link->resolvedDestinationId);
        $destLabel = $destAccount instanceof BankAccount && is_string($destAccount->display_name)
            ? $destAccount->display_name
            : $link->destinationAccountRef;

        $transferName = mb_substr("{$prefix} : {$destLabel}", 0, 64);

        // Promote the funding leg to transfer.
        $funding->status = TransactionStatus::Transfer;
        $funding->counterparty_name = $transferName;
        $funding->linked_transfer_id = $destination->id;
        $funding->save();

        // Guard: if destination was already pushed, do NOT retro-edit it.
        if ($destination->status === TransactionStatus::Pushed) {
            Log::warning('CrossSourceTransferLinker: destination leg already pushed — funding promoted but destination left as-is for manual convergence.', [
                'event' => 'cross_source.late_pair',
                'funding_transaction_id' => $funding->id,
                'destination_transaction_id' => $destination->id,
                'funding_amount_milliunits' => $funding->amount_milliunits,
                'currency' => $funding->currency,
            ]);

            return;
        }

        // Drop the destination leg.
        $destination->status = TransactionStatus::TransferDropped;
        $destination->linked_transfer_id = $funding->id;
        $destination->save();

        Log::info('CrossSourceTransferLinker: cross-source top-up pair linked.', [
            'event' => 'cross_source.linked',
            'funding_transaction_id' => $funding->id,
            'destination_transaction_id' => $destination->id,
            'funding_amount_milliunits' => $funding->amount_milliunits,
            'currency' => $funding->currency,
            'window_days' => $link->amountToleranceDays,
        ]);
    }

    /**
     * Extract joined remittance information from a raw EB payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractRawRemittance(array $payload): ?string
    {
        $remittances = $payload['remittance_information'] ?? null;
        if (! is_array($remittances)) {
            return null;
        }
        $parts = array_filter($remittances, 'is_string');
        if ($parts === []) {
            return null;
        }

        return implode(' · ', $parts);
    }

    /**
     * Extract the raw counterparty string from a stored transaction's raw_payload,
     * mirroring MatchUpdateOrInsert::extractRawCounterparty logic.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractRawCounterpartyFromPayload(array $payload): string
    {
        $cdi = isset($payload['credit_debit_indicator']) && is_string($payload['credit_debit_indicator'])
            ? strtoupper($payload['credit_debit_indicator'])
            : '';

        $creditorName = null;
        if (is_array($payload['creditor'] ?? null) && is_string($payload['creditor']['name'] ?? null)) {
            $creditorName = $payload['creditor']['name'];
        }
        $debtorName = null;
        if (is_array($payload['debtor'] ?? null) && is_string($payload['debtor']['name'] ?? null)) {
            $debtorName = $payload['debtor']['name'];
        }

        $directCorrect = match ($cdi) {
            'CRDT' => $debtorName,
            'DBIT' => $creditorName,
            default => null,
        };
        if (is_string($directCorrect) && trim($directCorrect) !== '') {
            return $directCorrect;
        }

        $inverted = match ($cdi) {
            'CRDT' => $creditorName,
            'DBIT' => $debtorName,
            default => null,
        };
        if (is_string($inverted) && trim($inverted) !== '') {
            return $inverted;
        }

        // Fall back to remittance_information[0] for pattern matching.
        $remittances = $payload['remittance_information'] ?? null;
        if (is_array($remittances)) {
            foreach ($remittances as $line) {
                if (is_string($line) && trim($line) !== '') {
                    return $line;
                }
            }
        }

        return '';
    }
}
