<?php

namespace App\Console\Commands\Spendula;

use App\Enums\YnabAccountType;
use App\Models\BankAccount;
use App\Services\Ynab\Client as YnabClient;
use App\Services\Ynab\Exceptions\YnabException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('spendula:accounts:map
    {--bank-account-id= : Map a single bank_account by id (optional).}
    {--ynab-account-id= : Pair with --bank-account-id for non-interactive mapping (optional).}
    {--include-mapped : Re-offer bank_accounts that already have a YNAB mapping.}
')]
#[Description('Interactively map Spendula bank accounts to YNAB accounts.')]
class AccountsMapCommand extends Command
{
    /**
     * Walk active bank_accounts and pair each with a YNAB account.
     *
     * Default invocation walks every active row whose `ynab_account_id` is
     * NULL; `--include-mapped` also re-offers already-mapped rows. Pass both
     * `--bank-account-id` and `--ynab-account-id` to map a single row
     * non-interactively (useful for scripts / CI).
     *
     * `ynab_account_type` is derived from `is_base_currency`: base currency
     * → on_budget, foreign currency → tracking. The DB CHECK constraint
     * enforces this independently (SPEC §4.3); the command sets the right
     * value up front so it never trips.
     *
     * Side effects: HTTPS call to YNAB to list accounts, plus one or more
     * UPDATE statements on `bank_accounts`. No advisory lock — concurrent
     * runs would race on UPDATE-LAST-WINS, which is acceptable for a
     * manually-driven mapping tool.
     */
    public function handle(YnabClient $ynab): int
    {
        $bankAccountFilter = (string) $this->option('bank-account-id');
        $explicitYnabId = (string) $this->option('ynab-account-id');

        // The scripted path requires BOTH flags. Accepting only one would
        // silently fall through to the interactive walker — and under
        // --no-interaction (Symfony returns the choice default), that
        // walker exits 0 after skipping every row, which masks typos and
        // missing env vars.
        if (($bankAccountFilter === '') !== ($explicitYnabId === '')) {
            $this->error('--bank-account-id and --ynab-account-id must be passed together (or neither, for the interactive walker).');

            return self::FAILURE;
        }

        // Local validations FIRST, before any YNAB API call. Network or
        // auth issues at YNAB shouldn't mask a bad bank-account-id, an
        // empty queue, or a misconfigured --no-interaction call: we want
        // the local error message in those cases.
        if ($bankAccountFilter === '' && $explicitYnabId === '') {
            $candidates = BankAccount::query()
                ->where('active', true)
                ->when(! $this->option('include-mapped'), fn ($q) => $q->whereNull('ynab_account_id'))
                ->orderBy('bank_slug')
                ->orderBy('currency')
                ->get();

            if ($candidates->isEmpty()) {
                $this->info(
                    $this->option('include-mapped')
                        ? 'No active bank accounts to map.'
                        : 'No unmapped bank accounts. Pass --include-mapped to re-offer existing mappings.'
                );

                return self::SUCCESS;
            }

            // The walker can't function without a real TTY: Symfony returns
            // each prompt's default answer, so cron / piped-stdin /
            // --no-interaction runs would otherwise skip every row via the
            // `[skip]` default and exit 0, masking misconfiguration. We
            // detect this three ways:
            //   1) explicit --no-interaction option,
            //   2) Symfony auto-detected non-interactive input (ArgvInput),
            //   3) STDIN is not actually a TTY (covers `</dev/null`,
            //      backgrounded scripts, cron without --no-interaction).
            // Tests bypass the TTY check because PHPUnit's STDIN is not a
            // TTY but the Laravel test harness mocks prompt answers.
            // Use stream_isatty (PHP 7.2+, cross-platform) rather than
            // posix_isatty so detection works on Windows and minimal
            // CLI images that ship without ext-posix.
            $hasTty = ! defined('STDIN')
                ? true
                : (bool) @stream_isatty(STDIN);
            $effectivelyNonInteractive =
                (bool) $this->option('no-interaction')
                || $this->input->isInteractive() === false
                || (! app()->runningUnitTests() && ! $hasTty);
            if ($effectivelyNonInteractive) {
                $this->error('No TTY available. The interactive walker needs a real terminal; pass --bank-account-id and --ynab-account-id for non-interactive use.');

                return self::FAILURE;
            }

            try {
                $ynabAccounts = $this->fetchYnabAccounts($ynab);
            } catch (YnabException $e) {
                $this->error('YNAB request failed: '.$e->getMessage());

                return self::FAILURE;
            }

            $mapped = 0;
            $skipped = 0;
            $aborted = false;

            foreach ($candidates as $account) {
                $result = $this->promptMapping($account, $ynabAccounts);
                if ($result === 'abort') {
                    $aborted = true;
                    break;
                }
                $result === 'mapped' ? $mapped++ : $skipped++;
            }

            $this->newLine();
            $this->info(sprintf(
                'Done — mapped=%d skipped=%d%s.',
                $mapped,
                $skipped,
                $aborted ? ' (aborted on invalid input)' : ''
            ));

            return $aborted ? self::FAILURE : self::SUCCESS;
        }

        // Scripted path. Local validation (bank_account exists) before YNAB.
        $account = BankAccount::query()->find($bankAccountFilter);
        if (! $account instanceof BankAccount) {
            $this->error("No bank_account with id={$bankAccountFilter}.");

            return self::FAILURE;
        }

        try {
            $ynabAccounts = $this->fetchYnabAccounts($ynab);
        } catch (YnabException $e) {
            $this->error('YNAB request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return $this->mapSingle($account, $explicitYnabId, $ynabAccounts);
    }

    /**
     * @param  array<string, array<string, mixed>>  $ynabAccounts  keyed by id.
     */
    private function mapSingle(BankAccount $account, string $ynabAccountId, array $ynabAccounts): int
    {
        $ynab = $ynabAccounts[$ynabAccountId] ?? null;
        if ($ynab === null) {
            $this->error("YNAB account '{$ynabAccountId}' not found in the test plan's open accounts.");

            return self::FAILURE;
        }

        // YNAB plans are single-currency: all accounts within a plan share
        // the plan's currency, regardless of what the underlying bank
        // account holds. The compatibility filter is therefore on the
        // YNAB account's TYPE, not its currency: a foreign-currency
        // bank_account must map to a YNAB tracking account
        // (`on_budget=false`); a base-currency bank_account is free to
        // pick either. deriveType() catches the bad pairing later, but
        // surfacing it as a clear "tracking required" message here is
        // more useful for scripted callers.
        if (! $account->is_base_currency && ($ynab['on_budget'] ?? false) === true) {
            $this->error(sprintf(
                "Foreign-currency bank_account (%s) requires a YNAB tracking account, but '%s' is on_budget. SPEC §4.3.",
                $account->currency,
                $ynabAccountId,
            ));

            return self::FAILURE;
        }

        // Remap-friendly defaults: keep existing display_name and
        // import_cutoff_date if they're already set. Silently overwriting
        // either is bad — advancing the cutoff makes the next sync auto-
        // skip everything booked between the old and new cutoff dates.
        $displayName = is_string($account->display_name) && $account->display_name !== ''
            ? $account->display_name
            : (string) ($ynab['name'] ?? '');
        $cutoffDate = $account->import_cutoff_date instanceof Carbon
            ? $account->import_cutoff_date
            : Carbon::today();

        $saved = $this->persistMapping(
            $account,
            $ynabAccountId,
            $ynab,
            displayName: $displayName,
            cutoffDate: $cutoffDate,
        );

        return $saved ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Prompt the operator for a YNAB target, display name, and cutoff date
     * for one bank_account.
     *
     * Returns 'mapped' on a successful save, 'skipped' if the operator
     * picked the skip option, or 'abort' on a fatal input error (only the
     * cutoff-date validation can return abort — display name is free-form).
     *
     * @param  array<string, array<string, mixed>>  $ynabAccounts  keyed by id.
     * @return 'mapped'|'skipped'|'abort'
     */
    private function promptMapping(BankAccount $account, array $ynabAccounts): string
    {
        // Filter by YNAB account TYPE, not currency: YNAB plans are
        // single-currency, so the YNAB account list never carries a
        // per-account currency that matches a foreign bank_account. The
        // SPEC §4.3 rule is on type instead — foreign-currency bank
        // accounts can only map to tracking (on_budget=false). Base
        // currency accounts are free to pick either.
        $compatible = $account->is_base_currency
            ? $ynabAccounts
            : array_filter($ynabAccounts, fn (array $a): bool => ($a['on_budget'] ?? false) !== true);

        if ($compatible === []) {
            $this->warn(sprintf(
                'No %s YNAB accounts are open on the plan.',
                $account->is_base_currency ? 'open' : 'tracking'
            ));
        }

        $skipLabel = '[skip this account]';
        $labelToId = [];
        foreach ($compatible as $id => $a) {
            $idStr = (string) $id;
            // Include a short id suffix in the label to keep duplicate-named
            // YNAB accounts addressable (two "Checking" accounts, etc.).
            // Type is shown so the operator can spot tracking vs. on-budget.
            $label = sprintf(
                '%s (%s, on_budget=%s) [%s]',
                $a['name'] ?? $idStr,
                $a['type'] ?? '?',
                ($a['on_budget'] ?? false) ? 'true' : 'false',
                substr($idStr, 0, 8)
            );
            $labelToId[$label] = $idStr;
        }
        $choices = array_merge(array_keys($labelToId), [$skipLabel]);

        // Always include the bank_account id (short prefix) in the prompt
        // so two same-currency unmapped accounts without an IBAN are
        // still distinguishable by the operator.
        $idPrefix = substr($account->id, 0, 8);
        $ibanPart = $account->iban ?? '(no IBAN)';
        $pickedRaw = $this->choice(
            "Pick a YNAB account for {$account->currency} {$ibanPart} [{$idPrefix}]",
            $choices,
            $skipLabel,
        );
        // Symfony Console's choice() returns string when not multi-select; the
        // is_string narrowing pins the type for PHPStan.
        $picked = is_string($pickedRaw) ? $pickedRaw : $skipLabel;

        if ($picked === $skipLabel || ! isset($labelToId[$picked])) {
            return 'skipped';
        }

        $ynabId = $labelToId[$picked];
        // Defaults prefer existing values on remap so an Enter-through
        // doesn't quietly rewrite a custom display_name or advance the
        // cutoff date past already-skipped transactions.
        $defaultName = is_string($account->display_name) && $account->display_name !== ''
            ? $account->display_name
            : (string) ($ynabAccounts[$ynabId]['name'] ?? '');
        $defaultCutoff = $account->import_cutoff_date instanceof Carbon
            ? $account->import_cutoff_date->toDateString()
            : Carbon::today()->toDateString();

        $displayName = (string) $this->ask('Display name', $defaultName !== '' ? $defaultName : null);

        // Re-prompt on invalid cutoff so a typo on one row doesn't abort
        // the whole walk. We're already past the TTY guard, so this loop
        // can't spin against EOF input. Cap at 5 tries as a guard against
        // a misbehaving terminal that keeps echoing the same bad string.
        $cutoffDate = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $cutoffInput = (string) $this->ask('Import cutoff date (YYYY-MM-DD)', $defaultCutoff);
            $cutoffDate = $this->parseCutoff($cutoffInput);
            if ($cutoffDate instanceof Carbon) {
                break;
            }
            $this->error("Invalid date '{$cutoffInput}'. Expected YYYY-MM-DD.");
        }

        if (! $cutoffDate instanceof Carbon) {
            $this->error('Too many invalid date attempts; skipping this row.');

            return 'skipped';
        }

        return $this->persistMapping($account, $ynabId, $ynabAccounts[$ynabId], $displayName, $cutoffDate)
            ? 'mapped'
            : 'abort';
    }

    /**
     * @param  array<string, mixed>  $ynab
     * @return YnabAccountType|null null with a friendly error already
     *                              emitted when the pairing is invalid.
     */
    private function deriveType(BankAccount $account, array $ynab): ?YnabAccountType
    {
        $type = ($ynab['on_budget'] ?? null) === true
            ? YnabAccountType::OnBudget
            : YnabAccountType::Tracking;

        // SPEC §4.3 / DB CHECK: a non-base-currency bank_account can only
        // map to tracking. Without this guard the bad pairing turns into an
        // uncaught QueryException at save time; surface it as a normal
        // validation error instead.
        if (! $account->is_base_currency && $type === YnabAccountType::OnBudget) {
            $this->error(sprintf(
                'Refusing to map %s bank_account to an on_budget YNAB account. SPEC §4.3: foreign-currency accounts must map to tracking only. Pick a YNAB account with on_budget=false.',
                $account->currency
            ));

            return null;
        }

        return $type;
    }

    /** @param  array<string, mixed>  $ynab */
    private function persistMapping(BankAccount $account, string $ynabId, array $ynab, string $displayName, Carbon $cutoffDate): bool
    {
        // Source of truth for the bank_accounts.ynab_account_type column is
        // the YNAB account itself — its `on_budget` flag classifies it as
        // budget vs. tracking. Hardcoding the type from is_base_currency
        // would silently misroute base-currency bank accounts paired with a
        // tracking-type YNAB target (and vice versa).
        $type = $this->deriveType($account, $ynab);
        if ($type === null) {
            return false;
        }

        $account->ynab_account_id = $ynabId;
        $account->ynab_account_type = $type;
        $account->import_cutoff_date = $cutoffDate;
        if ($displayName !== '') {
            $account->display_name = $displayName;
        }
        $account->save();

        $this->line(sprintf(
            '  → %s (%s) → %s as %s; cutoff=%s.',
            $account->id,
            $account->currency,
            $ynabId,
            $type->value,
            $cutoffDate->toDateString(),
        ));

        return true;
    }

    /**
     * Parse a literal YYYY-MM-DD. Carbon::createFromFormat silently accepts
     * calendar-invalid dates (2026-02-30 → 2026-03-02) — guard against that
     * with a round-trip format comparison.
     */
    private function parseCutoff(string $input): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $input);
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed instanceof Carbon || $parsed->format('Y-m-d') !== $input) {
            return null;
        }

        return $parsed;
    }

    /**
     * Fetch open, non-deleted YNAB accounts and key them by id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchYnabAccounts(YnabClient $ynab): array
    {
        // YNAB Client auto-unwraps the {data: …} envelope, so accounts is at top level.
        $response = $ynab->accounts();
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($response['accounts'] ?? null) ? $response['accounts'] : [];

        $byId = [];
        foreach ($rows as $row) {
            if (($row['closed'] ?? false) || ($row['deleted'] ?? false)) {
                continue;
            }
            $id = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
            if ($id === '') {
                continue;
            }
            $byId[$id] = $row;
        }

        return $byId;
    }
}
