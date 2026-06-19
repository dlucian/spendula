<?php

namespace App\Services\Sync;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Counterparty\OwnAccountClassifier;
use App\Services\Counterparty\Resolver;
use App\Services\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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
 *
 * Status assignment on insert (SPEC §5.3, §6.5): cutoff is checked first —
 * pre-cutoff transactions land as `skipped` regardless of account type, per
 * §6.5's "before import_cutoff_date → skipped, never reviewed". Post-cutoff,
 * the account's ynab_account_type drives the branch: `tracking` accounts
 * land as `tracking` (terminal — never reviewed, never pushed; consumed by
 * the snapshot path), everything else lands as `fetched`.
 */
class MatchUpdateOrInsert
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly OwnAccountClassifier $classifier,
    ) {}

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
            $incomingHasRef = $parsed->entryReference !== null && $parsed->entryReference !== '';

            // SPEC §6.3 includes normalized_counterparty in the fundamentals
            // tuple, so a counterparty mismatch means a different transaction
            // (silently merging would drop a real row). Bank-side enrichment
            // of creditor/debtor between overlap syncs is a known cause of
            // duplicate inserts; we accept that risk rather than risk
            // overwriting a distinct same-fundamentals row.
            $counterpartyMatches = $candidates->filter(
                fn (Transaction $t): bool => Resolver::normalize($this->extractRawCounterpartyFromStored($t)) === $incomingNormalized,
            );

            // Untagged candidates first — these are the "default" pool that the
            // pre-overlap matching ladder always considered. If the incoming row
            // has its own entry_reference, tagged candidates are by definition
            // different transactions (step 1 would have caught them otherwise),
            // so we only ever match against the untagged pool here.
            $untaggedMatches = $counterpartyMatches->filter(
                fn (Transaction $t): bool => ! is_string($t->entry_reference) || $t->entry_reference === '',
            );

            if ($untaggedMatches->count() === 1) {
                $match = $untaggedMatches->first();
            } elseif ($untaggedMatches->count() > 1) {
                /** @var int $maxOccurrence */
                $maxOccurrence = (int) $untaggedMatches->max('occurrence');

                return $this->insert($parsed, occurrence: $maxOccurrence + 1);
            } elseif (! $incomingHasRef) {
                // No untagged candidate, but incoming has no ref either. Fall
                // back to tagged candidates (the absent→present-then-absent
                // overlap case): a single tagged match is the same row with
                // its ref dropped on the later sync. Two or more tagged
                // matches are ambiguous — we cannot tell which the untagged
                // incoming corresponds to, so dedupe rather than insert a
                // duplicate occurrence.
                $taggedMatches = $counterpartyMatches->filter(
                    fn (Transaction $t): bool => is_string($t->entry_reference) && $t->entry_reference !== '',
                );

                if ($taggedMatches->count() === 1) {
                    $match = $taggedMatches->first();
                } elseif ($taggedMatches->count() > 1) {
                    /** @var Transaction $first */
                    $first = $taggedMatches->first();

                    return new ApplyResult($first, ApplyOutcome::Deduped);
                }
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

        $bookingDateRaw = isset($ebTransaction['booking_date']) && is_string($ebTransaction['booking_date'])
            ? $ebTransaction['booking_date']
            : throw new InvalidArgumentException('EB transaction missing booking_date.');
        $bookingDate = $this->parseDateOrFail('booking_date', $bookingDateRaw);

        $valueDate = null;
        if (isset($ebTransaction['value_date']) && is_string($ebTransaction['value_date'])) {
            // value_date is optional but must parse if present — otherwise the
            // later insert/update would throw InvalidFormatException out of
            // SyncRunner's parse-error catch and stall the whole page.
            $valueDate = $this->parseDateOrFail('value_date', $ebTransaction['value_date']);
        }

        $entryRef = isset($ebTransaction['entry_reference']) && is_string($ebTransaction['entry_reference'])
            ? $ebTransaction['entry_reference']
            : null;

        // EB emits this field as `status` in the payload; we persist it under
        // the legacy DB column name `transactions.transaction_status` (kept
        // to avoid a rippling migration; see GH #46). Default to BOOK when
        // missing or empty — must match SyncRunner's permissive treatment
        // (it lets empty-string statuses through), otherwise an empty value
        // would land here and violate the column's BOOK/PDNG/INFO CHECK
        // constraint, aborting the account sync via QueryException. Banks
        // that omit the field entirely on booked rows (and any that emit
        // `status: ""`) end up persisted as BOOK.
        $statusRaw = isset($ebTransaction['status']) && is_string($ebTransaction['status'])
            ? $ebTransaction['status']
            : '';
        $transactionStatus = $statusRaw !== '' ? $statusRaw : 'BOOK';

        $rawCounterparty = $this->extractRawCounterparty($ebTransaction);
        $resolved = $this->resolver->resolve($ebTransaction, $account->bank_slug);

        // Post-resolution own-account override. Runs after the Resolver so the
        // resolver's IBAN-independent output (L0–L4 level) is preserved in
        // counterparty_resolution_level; only counterparty_name is overridden here.
        $classification = $this->classifier->classify($ebTransaction, $account);
        $counterpartyName = $resolved->name;
        $ownAccountTransfer = false;

        if ($classification !== null) {
            // Both same-currency and cross-currency (FX) own-account moves resolve
            // to a transfer. The budget is single-currency EUR; foreign-currency
            // own accounts are held in EUR-equivalent, so the EUR debit/credit is
            // the correct budget event and should be booked as a transfer pair.
            // See DECISIONS.md — GH #14 FX-as-transfer reversal entry.
            $prefix = (string) config('spendula.own_account.transfer_prefix', 'Transfer');
            $counterpartyName = mb_substr("{$prefix} : {$classification->destinationLabel()}", 0, 64);
            $ownAccountTransfer = true;
        }

        $remittance = null;
        if (isset($ebTransaction['remittance_information']) && is_array($ebTransaction['remittance_information'])) {
            $parts = array_filter($ebTransaction['remittance_information'], 'is_string');
            if ($parts !== []) {
                $remittance = implode(' · ', $parts);
            }
        }

        // FX memo enrichment: when this is a cross-currency own-account transfer,
        // append original-currency detail to the remittance if the EB payload
        // carries a `currency_exchange` object (populated by some banks on
        // cross-currency transactions per SPEC §5.6). The field is optional —
        // if absent (e.g. ING-RO free-text transfers, which encode everything in
        // remittance_information), the remittance is left as-is; no fabrication.
        if ($classification !== null && ! $classification->sameCurrency) {
            $fxSuffix = $this->buildFxSuffix($ebTransaction);
            if ($fxSuffix !== null) {
                $remittance = $remittance !== null
                    ? $remittance.' · '.$fxSuffix
                    : $fxSuffix;
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
            counterpartyName: $counterpartyName,
            counterpartyResolutionLevel: $resolved->level,
            remittanceInformation: $remittance,
            now: $now,
            ownAccountTransfer: $ownAccountTransfer,
        );
    }

    private function insert(ParsedIncomingTransaction $parsed, int $occurrence): ApplyResult
    {
        $beforeCutoff = $this->isBeforeCutoff($parsed);
        $status = match (true) {
            $beforeCutoff => TransactionStatus::Skipped,
            $parsed->account->ynab_account_type === YnabAccountType::Tracking => TransactionStatus::Tracking,
            $parsed->ownAccountTransfer => TransactionStatus::Transfer,
            default => TransactionStatus::Fetched,
        };

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
        // SPEC §6.4 marks amount/currency/cdi/booking_date as immutable after insert.
        // If a later sync of the same matched row (most often via entry_reference)
        // presents different fundamentals, that's bank-side drift. Behavior depends
        // on whether the operator has already acted on the row:
        //
        //   * status=fetched (still under operator review) — throw so SyncRunner
        //     records a parse_error and the bad state is investigated before it
        //     can reach YNAB.
        //   * any other status (approved/pushed/skipped/transfer/tracking) — log
        //     and return Deduped. Failing here would make spendula:sync exit
        //     non-zero on every overlap and the operator cannot act on the local
        //     row anyway: it must be reconciled manually in YNAB. SPEC §6.4 only
        //     calls for a hard fail while the row is still fetched.
        $hasDrift = $existing->amount_milliunits !== $parsed->amountMilliunits
            || strtoupper($existing->currency) !== strtoupper($parsed->currency)
            || $existing->credit_debit_indicator !== $parsed->creditDebitIndicator
            || $existing->booking_date->toDateString() !== $parsed->bookingDate;

        if ($hasDrift) {
            $message = sprintf(
                'Drift detected on transaction %s — immutable fields changed since insert. '
                .'Stored: amount=%d currency=%s cdi=%s booking_date=%s. '
                .'Incoming: amount=%d currency=%s cdi=%s booking_date=%s.',
                $existing->id,
                $existing->amount_milliunits,
                $existing->currency,
                $existing->credit_debit_indicator->value,
                $existing->booking_date->toDateString(),
                $parsed->amountMilliunits,
                $parsed->currency,
                $parsed->creditDebitIndicator->value,
                $parsed->bookingDate,
            );

            if ($existing->status === TransactionStatus::Fetched) {
                throw new InvalidArgumentException($message);
            }

            Log::warning('Bank drift on transaction past fetched state — leaving local row unchanged.', [
                'event' => 'sync.drift_after_review',
                'transaction_id' => $existing->id,
                'transaction_status' => $existing->status->value,
                'reason' => $message,
            ]);

            return new ApplyResult($existing, ApplyOutcome::Deduped);
        }

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

    private function parseDateOrFail(string $field, string $value): string
    {
        $parsed = Carbon::createFromFormat('!Y-m-d', $value);
        if (! $parsed instanceof Carbon || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("EB transaction has invalid {$field}='{$value}' — expected YYYY-MM-DD.");
        }

        return $value;
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

    /**
     * Build a compact FX detail suffix from the EB transaction's `currency_exchange`
     * object (SPEC §5.6). Returns null when the field is absent or incomplete.
     *
     * EB shape (when populated by the bank):
     *   currency_exchange.instructed_amount.amount   — original amount in source currency
     *   currency_exchange.instructed_amount.currency — source currency code
     *   currency_exchange.exchange_rate              — rate applied
     *
     * Format emitted: `[FX] <amount> <CCY> @ <rate>` (e.g. `[FX] 1050.00 RON @ 0.20120000`).
     * Both fields are required; if either is missing, null is returned to avoid
     * partial or misleading information in the memo. Rate is rendered as-is from
     * the payload (a string); amount likewise. No rounding applied here — the bank's
     * reported values are authoritative.
     *
     * @param  array<string, mixed>  $ebTransaction
     */
    private function buildFxSuffix(array $ebTransaction): ?string
    {
        $cx = $ebTransaction['currency_exchange'] ?? null;
        if (! is_array($cx)) {
            return null;
        }

        $instructed = $cx['instructed_amount'] ?? null;
        if (! is_array($instructed)) {
            return null;
        }

        $origAmount = $instructed['amount'] ?? null;
        $origCurrency = $instructed['currency'] ?? null;

        if (! is_string($origAmount) || trim($origAmount) === ''
            || ! is_string($origCurrency) || trim($origCurrency) === '') {
            return null;
        }

        $rate = $cx['exchange_rate'] ?? null;
        if (! is_string($rate) && ! is_numeric($rate)) {
            return null;
        }

        return sprintf('[FX] %s %s @ %s', trim($origAmount), strtoupper(trim($origCurrency)), (string) $rate);
    }
}
