<?php

namespace App\Services\Sync;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Counterparty\Resolver;
use App\Services\Money\Money;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The match-update-or-insert core. SPEC §6.3 (matching), §6.4 (update scope),
 * §6.5 (cutoff). Pure DB-facing; no network, no advisory locks. The caller
 * (SyncRunner) handles pagination, locking, and per-page persistence.
 *
 * Matching ladder:
 *   1. entry_reference (when present) — match by (bank_account_id, entry_reference).
 *   2. Fundamentals — match by (booking_date, amount_milliunits, currency, cdi,
 *      normalized raw counterparty). One match → update; two+ matches → insert
 *      with occurrence = max + 1.
 *   3. No match → insert with occurrence = 1.
 *
 * Immutable-after-insert fields (SPEC §6.4): status, amount_milliunits,
 * currency, credit_debit_indicator, booking_date, dedup_hash, occurrence.
 * Fields that ARE updated from later syncs: counterparty_name/level,
 * remittance_information, value_date, raw_payload, last_updated_from_bank_at.
 */
class MatchUpdateOrInsert
{
    public function __construct(private readonly Resolver $resolver) {}

    /**
     * @param  array<string, mixed>  $ebTransaction
     */
    public function apply(BankAccount $account, array $ebTransaction, ?Carbon $now = null): ApplyResult
    {
        $now = $now ?? Carbon::now();

        $parsed = $this->parseIncoming($account, $ebTransaction, $now);

        // Step 1 — match by entry_reference.
        $match = null;
        if ($parsed->entryReference !== null && $parsed->entryReference !== '') {
            $match = Transaction::query()
                ->where('bank_account_id', $account->id)
                ->where('entry_reference', $parsed->entryReference)
                ->first();
        }

        if (! $match instanceof Transaction) {
            // Step 2 — match by fundamentals.
            $candidates = Transaction::query()
                ->where('bank_account_id', $account->id)
                ->where('booking_date', $parsed->bookingDate)
                ->where('amount_milliunits', $parsed->amountMilliunits)
                ->where('currency', $parsed->currency)
                ->where('credit_debit_indicator', $parsed->creditDebitIndicator->value)
                ->get();

            $incomingNormalized = Resolver::normalize($parsed->rawCounterparty);
            $fundamentalMatches = $candidates->filter(function (Transaction $t) use ($incomingNormalized): bool {
                // A candidate that already carries a non-empty entry_reference is
                // a different transaction by identity — step 1 would have hit it
                // if we were the same row. Excluding those candidates here prevents
                // a later legitimate transaction (same fundamentals, different
                // entry_reference) from being merged into the previously-tagged row.
                if (is_string($t->entry_reference) && $t->entry_reference !== '') {
                    return false;
                }

                return Resolver::normalize($this->extractRawCounterpartyFromStored($t)) === $incomingNormalized;
            });

            if ($fundamentalMatches->count() === 1) {
                $match = $fundamentalMatches->first();
            } elseif ($fundamentalMatches->count() > 1) {
                /** @var int $maxOccurrence */
                $maxOccurrence = (int) $fundamentalMatches->max('occurrence');

                return $this->insert($parsed, occurrence: $maxOccurrence + 1);
            }
        }

        if ($match instanceof Transaction) {
            return $this->update($match, $parsed);
        }

        return $this->insert($parsed, occurrence: 1);
    }

    /**
     * @param  array<string, mixed>  $ebTransaction
     */
    private function parseIncoming(BankAccount $account, array $ebTransaction, Carbon $now): ParsedIncomingTransaction
    {
        $amountNode = $ebTransaction['transaction_amount'] ?? null;
        if (! is_array($amountNode) || ! isset($amountNode['amount'])) {
            throw new InvalidArgumentException('EB transaction missing transaction_amount.');
        }

        $cdiRaw = isset($ebTransaction['credit_debit_indicator']) && is_string($ebTransaction['credit_debit_indicator'])
            ? strtoupper($ebTransaction['credit_debit_indicator'])
            : '';
        $cdi = CreditDebitIndicator::tryFrom($cdiRaw);
        if ($cdi === null) {
            throw new InvalidArgumentException("Unexpected credit_debit_indicator: {$cdiRaw}");
        }

        $currency = isset($amountNode['currency']) && is_string($amountNode['currency'])
            ? strtoupper($amountNode['currency'])
            : strtoupper($account->currency);

        $milliunits = Money::toMilliunits((string) $amountNode['amount'], $cdi->value);

        $bookingDate = isset($ebTransaction['booking_date']) && is_string($ebTransaction['booking_date'])
            ? $ebTransaction['booking_date']
            : throw new InvalidArgumentException('EB transaction missing booking_date.');

        $valueDate = isset($ebTransaction['value_date']) && is_string($ebTransaction['value_date'])
            ? $ebTransaction['value_date']
            : null;

        $entryRef = isset($ebTransaction['entry_reference']) && is_string($ebTransaction['entry_reference'])
            ? $ebTransaction['entry_reference']
            : null;

        $transactionStatus = isset($ebTransaction['transaction_status']) && is_string($ebTransaction['transaction_status'])
            ? $ebTransaction['transaction_status']
            : 'BOOK';

        $rawCounterparty = $this->extractRawCounterparty($ebTransaction);
        $resolved = $this->resolver->resolve($ebTransaction);

        $remittance = null;
        if (isset($ebTransaction['remittance_information']) && is_array($ebTransaction['remittance_information'])) {
            $parts = array_filter($ebTransaction['remittance_information'], 'is_string');
            if ($parts !== []) {
                $remittance = implode(' · ', $parts);
            }
        }

        $dedupHash = DedupHasher::dedupHash(
            bankAccountId: $account->id,
            bookingDate: $bookingDate,
            amountMilliunits: $milliunits,
            currency: $currency,
            creditDebitIndicator: $cdi->value,
            rawCounterparty: $rawCounterparty,
            entryReference: $entryRef,
        );

        return new ParsedIncomingTransaction(
            account: $account,
            rawPayload: $ebTransaction,
            dedupHash: $dedupHash,
            entryReference: $entryRef,
            transactionStatus: $transactionStatus,
            bookingDate: $bookingDate,
            valueDate: $valueDate,
            amountMilliunits: $milliunits,
            currency: $currency,
            creditDebitIndicator: $cdi,
            rawCounterparty: $rawCounterparty,
            counterpartyName: $resolved->name,
            counterpartyResolutionLevel: $resolved->level,
            remittanceInformation: $remittance,
            now: $now,
        );
    }

    private function insert(ParsedIncomingTransaction $parsed, int $occurrence): ApplyResult
    {
        $beforeCutoff = $this->isBeforeCutoff($parsed);
        $status = $beforeCutoff ? TransactionStatus::Skipped : TransactionStatus::Fetched;

        $transaction = Transaction::query()->create([
            'bank_account_id' => $parsed->account->id,
            'dedup_hash' => $parsed->dedupHash,
            'entry_reference' => $parsed->entryReference,
            'status' => $status,
            'transaction_status' => $parsed->transactionStatus,
            'booking_date' => $parsed->bookingDate,
            'value_date' => $parsed->valueDate,
            'amount_milliunits' => $parsed->amountMilliunits,
            'currency' => $parsed->currency,
            'credit_debit_indicator' => $parsed->creditDebitIndicator,
            'counterparty_name' => $parsed->counterpartyName,
            'counterparty_resolution_level' => $parsed->counterpartyResolutionLevel,
            'remittance_information' => $parsed->remittanceInformation,
            'raw_payload' => $parsed->rawPayload,
            'occurrence' => $occurrence,
            'first_seen_at' => $parsed->now,
            'last_updated_from_bank_at' => $parsed->now,
            'skipped_at' => $beforeCutoff ? $parsed->now : null,
            'skip_reason' => $beforeCutoff ? 'before import cutoff' : null,
        ]);

        return new ApplyResult($transaction, ApplyOutcome::Inserted);
    }

    private function update(Transaction $existing, ParsedIncomingTransaction $parsed): ApplyResult
    {
        $changed = false;

        // Backfill entry_reference on fundamental-match updates: if the row was
        // first synced without one and EB is now returning one, persist it. Without
        // this, a later same-fundamentals transaction with a different
        // entry_reference would still miss step 1 and get merged into this row by
        // the fundamentals fallback, instead of being inserted with occurrence=2.
        // dedup_hash also has to be recomputed: it folds entry_reference into the
        // hash, so leaving it stale lets a later distinct same-fundamentals row
        // (no entry_reference) compute the same hash and collide with the existing
        // (bank_account_id, dedup_hash, occurrence=1) unique constraint.
        if (
            ($existing->entry_reference === null || $existing->entry_reference === '')
            && $parsed->entryReference !== null
            && $parsed->entryReference !== ''
        ) {
            $existing->entry_reference = $parsed->entryReference;
            $existing->dedup_hash = $parsed->dedupHash;
            $changed = true;
        }

        if ($existing->counterparty_name !== $parsed->counterpartyName) {
            $existing->counterparty_name = $parsed->counterpartyName;
            $changed = true;
        }
        if ($existing->counterparty_resolution_level !== $parsed->counterpartyResolutionLevel) {
            $existing->counterparty_resolution_level = $parsed->counterpartyResolutionLevel;
            $changed = true;
        }
        if ($existing->remittance_information !== $parsed->remittanceInformation) {
            $existing->remittance_information = $parsed->remittanceInformation;
            $changed = true;
        }

        $existingValueDateStr = $existing->value_date?->toDateString();
        if ($existingValueDateStr !== $parsed->valueDate) {
            $existing->value_date = $parsed->valueDate !== null ? Carbon::parse($parsed->valueDate) : null;
            $changed = true;
        }

        // raw_payload is always overwritten per SPEC §6.4 ("always overwrite with latest")
        // but that overwrite is an audit rewrite — it does NOT count as a user-relevant
        // change for the Updated/Deduped outcome. Otherwise every re-sync would look
        // like an update, because Postgres jsonb re-orders keys alphabetically on round-trip.
        $existing->raw_payload = $parsed->rawPayload;

        $existing->last_updated_from_bank_at = $parsed->now;
        $existing->save();

        return new ApplyResult($existing, $changed ? ApplyOutcome::Updated : ApplyOutcome::Deduped);
    }

    private function isBeforeCutoff(ParsedIncomingTransaction $parsed): bool
    {
        $cutoff = $parsed->account->import_cutoff_date;
        if ($cutoff === null) {
            return false;
        }

        return Carbon::parse($parsed->bookingDate)->lt($cutoff);
    }

    /** @param  array<string, mixed>  $ebTransaction */
    private function extractRawCounterparty(array $ebTransaction): string
    {
        $cdi = isset($ebTransaction['credit_debit_indicator']) && is_string($ebTransaction['credit_debit_indicator'])
            ? strtoupper($ebTransaction['credit_debit_indicator'])
            : '';

        $creditor = $this->nameOf($ebTransaction, 'creditor');
        $debtor = $this->nameOf($ebTransaction, 'debtor');

        $directCorrect = match ($cdi) {
            'CRDT' => $debtor,
            'DBIT' => $creditor,
            default => null,
        };
        if (is_string($directCorrect) && trim($directCorrect) !== '') {
            return $directCorrect;
        }

        $inverted = match ($cdi) {
            'CRDT' => $creditor,
            'DBIT' => $debtor,
            default => null,
        };
        if (is_string($inverted) && trim($inverted) !== '') {
            return $inverted;
        }

        return '';
    }

    /** @param  array<string, mixed>  $ebTransaction */
    private function nameOf(array $ebTransaction, string $party): ?string
    {
        $node = $ebTransaction[$party] ?? null;
        if (is_array($node) && isset($node['name']) && is_string($node['name'])) {
            return $node['name'];
        }

        return null;
    }

    private function extractRawCounterpartyFromStored(Transaction $transaction): string
    {
        return $this->extractRawCounterparty($transaction->raw_payload);
    }
}
