<?php

namespace App\Services\Push;

use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Money\Money;
use App\Services\Sync\DedupHasher;
use RuntimeException;

/**
 * Builds the YNAB transaction payload (SPEC §7.3) from a local Transaction.
 * Pure function — no DB, no HTTP — so the import_id / memo / transfer-prefix
 * logic is unit-testable without fixtures.
 */
class PayloadBuilder
{
    private const int PAYEE_MAX = 50;

    private const int MEMO_MAX = 200;

    /**
     * YNAB rejects any payee_name that starts with one of these strings (reserved for internal YNAB use).
     * 'Transfer : ' is stripped and the remainder used; the other three fall back to a safe generic.
     *
     * @var list<string>
     */
    private const array RESERVED_PAYEE_PREFIXES = [
        'Transfer : ',
        'Starting Balance',
        'Manual Balance Adjustment',
        'Reconciliation Balance Adjustment',
    ];

    private const string SANITIZED_FALLBACK = 'Own account transfer';

    /** @return array<string, mixed> */
    public function build(Transaction $transaction, BankAccount $account): array
    {
        if ($account->ynab_account_id === null) {
            throw new RuntimeException('bank_account has no ynab_account_id — was accounts:seed-mock skipped?');
        }

        // Reuse the import_id we generated on a previous push attempt if one exists.
        // Without this, a later sync that improves raw_payload/counterparty would
        // change DedupHasher::importId() output between retries, defeating YNAB's
        // duplicate_import_ids dedup and risking a second YNAB transaction for the
        // same row. PushRunner persists this column before the HTTP call.
        if (is_string($transaction->ynab_import_id) && $transaction->ynab_import_id !== '') {
            $importId = $transaction->ynab_import_id;
        } else {
            $rawCounterparty = $this->extractRawCounterparty($transaction);
            $importId = DedupHasher::importId(
                bankAccountId: $transaction->bank_account_id,
                bookingDate: $transaction->booking_date->toDateString(),
                amountMilliunits: $transaction->amount_milliunits,
                rawCounterparty: $rawCounterparty,
                occurrence: $transaction->occurrence,
                entryReference: $transaction->entry_reference,
            );
        }

        $payeeName = $this->sanitizePayeeName($transaction->counterparty_name);
        if (is_string($payeeName) && mb_strlen($payeeName) > self::PAYEE_MAX) {
            $payeeName = mb_substr($payeeName, 0, self::PAYEE_MAX);
        }

        return [
            'account_id' => $account->ynab_account_id,
            'date' => $transaction->booking_date->toDateString(),
            'amount' => $transaction->amount_milliunits,
            'payee_name' => $payeeName,
            'memo' => $this->buildMemo($transaction),
            'cleared' => 'cleared',
            'approved' => false,
            'import_id' => $importId,
        ];
    }

    private function sanitizePayeeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        // Strip our own "Transfer : <dest>" prefix: the row is already tagged [TRANSFER]
        // in the memo, so the destination name alone is the useful YNAB payee.
        if (str_starts_with($name, 'Transfer : ')) {
            $stripped = mb_substr($name, mb_strlen('Transfer : '));

            return trim($stripped) !== '' ? $stripped : self::SANITIZED_FALLBACK;
        }

        // Other prefixes are reserved by YNAB itself — fall back to a safe generic.
        foreach (array_slice(self::RESERVED_PAYEE_PREFIXES, 1) as $reserved) {
            if (str_starts_with($name, $reserved)) {
                return self::SANITIZED_FALLBACK;
            }
        }

        return $name;
    }

    private function buildMemo(Transaction $transaction): string
    {
        $currency = strtoupper($transaction->currency);
        $baseCurrency = strtoupper((string) config('spendula.base_currency', 'EUR'));

        $formatted = Money::format($transaction->amount_milliunits, $currency);
        $sign = str_starts_with($formatted, '-') ? '-' : '';
        $abs = ltrim($formatted, '-');

        // SPEC §7.3 memo head: "€4.57" for base, "orig 120.00 RON" otherwise.
        $head = $currency === $baseCurrency
            ? $sign.Money::symbol($currency).$abs
            : 'orig '.$sign.$abs.' '.$currency;

        if ($transaction->status === TransactionStatus::Transfer) {
            $head = '[TRANSFER] '.$head;
        }

        $remittance = $transaction->remittance_information;
        if (is_string($remittance) && trim($remittance) !== '') {
            $head .= ' · '.trim($remittance);
        }

        return mb_substr($head, 0, self::MEMO_MAX);
    }

    /**
     * Mirrors the MatchUpdateOrInsert extraction so import_id and dedup_hash stay
     * consistent with what was computed at sync time.
     */
    private function extractRawCounterparty(Transaction $transaction): string
    {
        $raw = $transaction->raw_payload;
        $cdi = isset($raw['credit_debit_indicator']) && is_string($raw['credit_debit_indicator'])
            ? strtoupper($raw['credit_debit_indicator'])
            : '';

        $creditor = $this->nameOf($raw, 'creditor');
        $debtor = $this->nameOf($raw, 'debtor');

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

    /** @param  array<string, mixed>  $raw */
    private function nameOf(array $raw, string $party): ?string
    {
        $node = $raw[$party] ?? null;
        if (is_array($node) && isset($node['name']) && is_string($node['name'])) {
            return $node['name'];
        }

        return null;
    }
}
