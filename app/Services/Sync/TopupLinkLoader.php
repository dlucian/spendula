<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\BankAccount;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Loads and resolves own-account card top-up link mappings from
 * config/counterparty-rules-enabled/own-account-topups.json.
 *
 * Parallel to RuleLoader (bank counterparty rules) but purpose-built for the
 * cross-source topup mapping shape. Key responsibilities:
 *
 *   1. Parse the JSON file (optional — missing file → empty link list).
 *   2. Validate each link entry (required fields, types).
 *   3. Resolve destination_account_ref → bank_account_id via one DB query
 *      (matches display_name or iban, active accounts only).
 *   4. Cache both the raw links and the resolved links for the lifetime of
 *      the instance — no repeated DB queries.
 *
 * The resolution strategy (display_name OR iban) intentionally mirrors how an
 * operator thinks of accounts: they name them by display_name in the config
 * (e.g. "Revolut EUR") or by IBAN. If neither is found, the link is retained
 * in the resolved list with resolvedDestinationId=null and a warning is logged;
 * CrossSourceTransferLinker skips those links.
 *
 * Concurrency: one instance per process/command run. A concurrent account
 * de/activation during a long sync run is accepted; the next run gets a fresh
 * instance.
 */
final class TopupLinkLoader
{
    private const string CONFIG_FILENAME = 'own-account-topups.json';

    /** @var list<TopupLink>|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly string $configDir,
    ) {}

    /**
     * Return all fully-resolved TopupLink instances.
     *
     * Links whose destination_account_ref could not be resolved to an active
     * bank_account are included with resolvedDestinationId=null; the caller
     * (CrossSourceTransferLinker) filters them out at match time.
     *
     * Result is cached after the first call — safe to call repeatedly per sync run.
     *
     * @return list<TopupLink>
     */
    public function links(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $raw = $this->loadRaw();
        if ($raw === []) {
            return $this->resolved = [];
        }

        $this->resolved = $this->resolveDestinations($raw);

        return $this->resolved;
    }

    /**
     * Parse and validate the JSON config file, returning raw (pre-resolution) TopupLinks.
     * Returns [] when the file does not exist (not an error — operator has no topup mapping).
     *
     * @return list<TopupLink>
     *
     * @throws RuntimeException when the file exists but is malformed or missing required fields.
     */
    private function loadRaw(): array
    {
        $path = $this->configDir.'/'.self::CONFIG_FILENAME;

        if (! is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read topup link config at {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException(
                "Malformed JSON in topup link config {$path}: ".json_last_error_msg(),
            );
        }

        if (! isset($data['links']) || ! is_array($data['links'])) {
            // An empty links array is valid — operator may ship the file with no entries.
            return [];
        }

        $links = [];
        foreach ($data['links'] as $i => $entry) {
            $links[] = $this->parseEntry($entry, $path, $i);
        }

        return $links;
    }

    /**
     * @param  mixed  $entry
     *
     * @throws RuntimeException
     */
    private function parseEntry(mixed $entry, string $path, int $index): TopupLink
    {
        if (! is_array($entry)) {
            throw new RuntimeException("links[{$index}] in {$path} is not an object");
        }

        $required = [
            'funding_bank_slug', 'funding_card_last4', 'funding_marker',
            'destination_account_ref', 'apple_pay_tokens', 'amount_tolerance_days',
        ];
        foreach ($required as $field) {
            if (! array_key_exists($field, $entry)) {
                throw new RuntimeException(
                    "links[{$index}] in {$path} is missing required field '{$field}'",
                );
            }
        }

        foreach (['funding_bank_slug', 'funding_card_last4', 'funding_marker', 'destination_account_ref'] as $strField) {
            if (! is_string($entry[$strField]) || $entry[$strField] === '') {
                throw new RuntimeException(
                    "links[{$index}] in {$path}: '{$strField}' must be a non-empty string",
                );
            }
        }

        if (! is_array($entry['apple_pay_tokens'])) {
            throw new RuntimeException(
                "links[{$index}] in {$path}: 'apple_pay_tokens' must be an array",
            );
        }
        foreach ($entry['apple_pay_tokens'] as $j => $tok) {
            if (! is_string($tok) || $tok === '') {
                throw new RuntimeException(
                    "links[{$index}].apple_pay_tokens[{$j}] in {$path} must be a non-empty string",
                );
            }
        }

        if (! is_int($entry['amount_tolerance_days']) || $entry['amount_tolerance_days'] < 0) {
            throw new RuntimeException(
                "links[{$index}] in {$path}: 'amount_tolerance_days' must be a non-negative integer",
            );
        }

        /** @var list<string> $tokens */
        $tokens = array_values($entry['apple_pay_tokens']);

        return new TopupLink(
            fundingBankSlug: $entry['funding_bank_slug'],
            fundingCardLast4: $entry['funding_card_last4'],
            fundingMarker: $entry['funding_marker'],
            destinationAccountRef: $entry['destination_account_ref'],
            applePayTokens: $tokens,
            amountToleranceDays: $entry['amount_tolerance_days'],
        );
    }

    /**
     * Resolve each raw link's destination_account_ref to a bank_account_id.
     *
     * Runs a single DB query to load all active bank accounts, then matches
     * each link's ref against display_name (exact, case-insensitive) and
     * iban (normalised: trimmed, uppercased). Multiple matches are treated
     * as ambiguous; a warning is logged and resolvedDestinationId stays null.
     *
     * @param  list<TopupLink>  $raw
     * @return list<TopupLink>
     */
    private function resolveDestinations(array $raw): array
    {
        // Load all active bank accounts once.
        /** @var iterable<BankAccount> $allAccounts */
        $allAccounts = BankAccount::query()->where('active', true)->get();

        // Build lookup maps.
        /** @var array<string, list<string>> $byDisplayName  lower display_name → [id,...] */
        $byDisplayName = [];
        /** @var array<string, list<string>> $byIban  normalised IBAN → [id,...] */
        $byIban = [];

        foreach ($allAccounts as $account) {
            if (is_string($account->display_name) && $account->display_name !== '') {
                $key = mb_strtolower($account->display_name);
                $byDisplayName[$key][] = $account->id;
            }
            if (is_string($account->iban) && $account->iban !== '') {
                $normalised = strtoupper(preg_replace('/\s+/', '', $account->iban) ?? '');
                if ($normalised !== '') {
                    $byIban[$normalised][] = $account->id;
                }
            }
        }

        $resolved = [];
        foreach ($raw as $link) {
            $ref = $link->destinationAccountRef;
            $resolvedId = $this->resolveRef($ref, $byDisplayName, $byIban);

            if ($resolvedId === null) {
                Log::warning('TopupLinkLoader: could not resolve destination_account_ref to an active bank account.', [
                    'event' => 'topup_link.unresolved_ref',
                    'destination_account_ref' => $ref,
                    'funding_bank_slug' => $link->fundingBankSlug,
                    'funding_card_last4' => $link->fundingCardLast4,
                ]);
                $resolved[] = $link;
            } else {
                $resolved[] = $link->withResolvedDestination($resolvedId);
            }
        }

        return $resolved;
    }

    /**
     * Attempt to resolve a ref string to a single bank_account_id.
     *
     * Resolution order:
     *   1. Exact case-insensitive match against display_name.
     *   2. Normalised IBAN match (whitespace stripped, uppercased).
     *
     * Returns null when zero or multiple accounts match (ambiguous).
     *
     * @param  array<string, list<string>>  $byDisplayName
     * @param  array<string, list<string>>  $byIban
     */
    private function resolveRef(
        string $ref,
        array $byDisplayName,
        array $byIban,
    ): ?string {
        $lowerRef = mb_strtolower($ref);
        $nameHits = $byDisplayName[$lowerRef] ?? [];
        if (count($nameHits) === 1) {
            return $nameHits[0];
        }

        // Ambiguous display_name match.
        if (count($nameHits) > 1) {
            Log::warning('TopupLinkLoader: destination_account_ref matches multiple accounts by display_name — skipping.', [
                'event' => 'topup_link.ambiguous_display_name',
                'destination_account_ref' => $ref,
            ]);

            return null;
        }

        // Try IBAN lookup.
        $normRef = strtoupper(preg_replace('/\s+/', '', $ref) ?? '');
        $ibanHits = $byIban[$normRef] ?? [];
        if (count($ibanHits) === 1) {
            return $ibanHits[0];
        }

        if (count($ibanHits) > 1) {
            Log::warning('TopupLinkLoader: destination_account_ref matches multiple accounts by IBAN — skipping.', [
                'event' => 'topup_link.ambiguous_iban',
                'destination_account_ref' => $ref,
            ]);
        }

        return null;
    }
}
