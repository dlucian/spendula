<?php

namespace App\Console\Commands\Spendula;

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Counterparty\Rules\PostHook;
use App\Services\Counterparty\Rules\Rule;
use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleFixture;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:counterparty:rules:add
    {--bank= : Bank slug. Required if --from-transaction is not given.}
    {--from-transaction= : Pull a real remittance from a transaction id; pre-fills bank slug and source remittance, auto-derives the fixture and previews impact across the bank.}
')]
#[Description('Add a counterparty cleanup rule to a bank rule file. Validates regex + fixture before writing.')]
class CounterpartyRulesAddCommand extends Command
{
    public function handle(RuleEngine $engine): int
    {
        $txId = (string) $this->option('from-transaction');
        $prefilledRemittance = null;
        $bank = (string) $this->option('bank');

        if ($txId !== '') {
            $tx = Transaction::with('bankAccount')->find($txId);
            if ($tx === null) {
                $this->error("No transaction found with id {$txId}.");

                return self::FAILURE;
            }
            $bank = $tx->bankAccount->bank_slug ?? '';
            if ($bank === '') {
                $this->error("Transaction {$txId} has no bank account / bank slug.");

                return self::FAILURE;
            }
            $remittance = $tx->raw_payload['remittance_information'][0] ?? null;
            if (! is_string($remittance) || $remittance === '') {
                $this->error("Transaction {$txId} has no remittance_information[0].");

                return self::FAILURE;
            }
            $prefilledRemittance = $remittance;
            $this->info("Bank: {$bank}");
            $this->info("Raw remittance: {$remittance}");
        }

        if ($bank === '') {
            $this->error('--bank=<slug> is required when --from-transaction is not given.');

            return self::FAILURE;
        }

        $path = base_path("config/counterparty-rules-available/{$bank}.json");
        try {
            $existing = $this->loadExisting($path);
        } catch (\RuntimeException $e) {
            foreach (explode("\n", $e->getMessage()) as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        $name = (string) $this->ask('Rule name (kebab-case)');
        if ($name === '') {
            $this->error('Rule name cannot be empty.');

            return self::FAILURE;
        }
        foreach ($existing['rules'] as $rule) {
            if (($rule['name'] ?? null) === $name) {
                $this->error("Rule '{$name}' already exists in {$path}.");

                return self::FAILURE;
            }
        }

        $description = (string) $this->ask('Description');
        $pattern = (string) $this->ask('Pattern (full PCRE, e.g. /^X$/i)');

        // Validate regex compiles before going further.
        set_error_handler(static fn () => true);
        $compileResult = @preg_match($pattern, '');
        restore_error_handler();
        if ($compileResult === false) {
            $this->error('regex did not compile: '.preg_last_error_msg());

            return self::FAILURE;
        }

        $replacement = (string) $this->ask('Replacement');
        $postRaw = (string) $this->ask('Post hooks (comma-separated; blank for none)');
        $postHooks = $postRaw === '' ? [] : array_map('trim', explode(',', $postRaw));

        foreach ($postHooks as $hook) {
            if (! in_array($hook, PostHook::known(), true)) {
                $this->error("Unknown post hook '{$hook}'. Known: ".implode(', ', PostHook::known()));

                return self::FAILURE;
            }
        }

        if ($prefilledRemittance !== null) {
            $candidate = new Rule(
                name: $name,
                description: $description,
                pattern: $pattern,
                replacement: $replacement,
                postHooks: $postHooks,
                fixtures: [],
            );
            $derived = $engine->apply($prefilledRemittance, [$candidate]);
            if ($derived === trim($prefilledRemittance)) {
                $this->error('Pattern does not match the source remittance — adjust pattern or add manually.');

                return self::FAILURE;
            }
            $this->info("Auto-derived expected output: {$derived}");
            if (! $this->confirm('Use this as the fixture?', true)) {
                $this->line('Aborted.');

                return self::SUCCESS;
            }
            $fixtureIn = $prefilledRemittance;
            $fixtureOut = $derived;
        } else {
            $fixtureIn = (string) $this->ask('Fixture input');
            $fixtureOut = (string) $this->ask('Expected output');
        }

        // Build the candidate Rule and run the fixture through the engine.
        $candidate = new Rule(
            name: $name,
            description: $description,
            pattern: $pattern,
            replacement: $replacement,
            postHooks: $postHooks,
            fixtures: [new RuleFixture($fixtureIn, $fixtureOut)],
        );
        $actual = $engine->apply($fixtureIn, [$candidate]);
        if ($actual !== $fixtureOut) {
            $this->error(sprintf(
                'Fixture failed: input %s produced %s, expected %s.',
                var_export($fixtureIn, true),
                var_export($actual, true),
                var_export($fixtureOut, true),
            ));

            return self::FAILURE;
        }

        $this->info('Fixture passes.');

        // Shadowing check: ensure the new rule actually fires when added to
        // the bank's full rule list. If an existing earlier rule would match
        // the same input first and produce a different result, the new rule
        // is dead-on-arrival in production. Refuse to save in that case.
        $existingRules = $this->existingRulesAsObjects($existing['rules']);
        $shadowingResult = $engine->apply($fixtureIn, [...$existingRules, $candidate]);
        if ($shadowingResult !== $fixtureOut) {
            $this->error(sprintf(
                'Rule is shadowed by an earlier rule. With the full bank list, input %s resolves to %s, not %s. Reorder the rules in %s manually so %s comes before any rule that matches its input first.',
                var_export($fixtureIn, true),
                var_export($shadowingResult, true),
                var_export($fixtureOut, true),
                $path,
                $name,
            ));

            return self::FAILURE;
        }

        // Preview impact (only with --from-transaction). Run each row through
        // the full Resolver — both with and without the candidate rule appended
        // — so the count reflects rows whose stored counterparty_name would
        // actually change after save + recompute. Rows resolved at L0/L1 from
        // debtor/creditor names, or already cleaned by an earlier rule, do not
        // get counted because they don't reach the candidate.
        if ($prefilledRemittance !== null) {
            $bankAccountIds = BankAccount::query()
                ->where('bank_slug', $bank)
                ->pluck('id');

            $existingRules = $this->existingRulesAsObjects($existing['rules']);
            $rulesWithCandidate = [...$existingRules, $candidate];

            $impacted = 0;
            $samples = [];
            Transaction::query()
                ->whereIn('bank_account_id', $bankAccountIds)
                ->orderBy('id')
                ->chunk(500, function ($chunk) use (&$impacted, &$samples, $existingRules, $rulesWithCandidate) {
                    foreach ($chunk as $row) {
                        $payload = $row->raw_payload;
                        $beforeName = $this->resolveWithRules($payload, $existingRules);
                        $afterName = $this->resolveWithRules($payload, $rulesWithCandidate);
                        if ($beforeName !== $afterName) {
                            $impacted++;
                            if (count($samples) < 5) {
                                $rem = $payload['remittance_information'][0] ?? '';
                                $samples[] = "{$rem} -> {$afterName}";
                            }
                        }
                    }
                });

            $this->line("Preview impact on existing {$bank} transactions:");
            $this->line("  {$impacted} row(s) would change.");
            if (count($samples) > 0) {
                $sampleCount = count($samples);
                $this->line("  First {$sampleCount} sample(s):");
                foreach ($samples as $s) {
                    $this->line("    {$s}");
                }
            }
        }

        if (! $this->confirm('Save rule?', true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $existing['rules'][] = [
            'name' => $name,
            'description' => $description,
            'pattern' => $pattern,
            'replacement' => $replacement,
            'post' => $postHooks,
            'tests' => [['in' => $fixtureIn, 'out' => $fixtureOut]],
        ];

        // Strip 'post' field if empty for tidiness.
        foreach ($existing['rules'] as &$r) {
            if (($r['post'] ?? []) === []) {
                unset($r['post']);
            }
        }
        unset($r);

        $written = file_put_contents(
            $path,
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );
        if ($written === false) {
            $this->error("Failed to write rule file at {$path} — check permissions / disk space.");

            return self::FAILURE;
        }

        $this->info("Saved rule '{$name}' to {$path}.");

        return self::SUCCESS;
    }

    /**
     * Load existing bank rule file or return a stub for a missing file.
     * Throws RuntimeException for files that exist but fail to parse —
     * better to abort than silently overwrite the operator's existing
     * rule library with a fresh single-rule file.
     *
     * All top-level keys (name, description, etc.) are preserved so that
     * save() doesn't silently drop metadata when appending a new rule.
     *
     * @return array<string, mixed>
     */
    private function loadExisting(string $path): array
    {
        $bank = basename($path, '.json');

        if (! is_file($path)) {
            return ['name' => $bank, 'rules' => []];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read existing rule file at {$path}.");
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new \RuntimeException(
                "Existing rule file at {$path} is malformed JSON: ".json_last_error_msg().".\n".
                'Refusing to overwrite — fix the file by hand or delete it.'
            );
        }

        if (! isset($data['rules']) || ! is_array($data['rules'])) {
            throw new \RuntimeException(
                "Existing rule file at {$path} is missing the 'rules' array.\n".
                'Refusing to overwrite — fix the file by hand or delete it.'
            );
        }

        // Preserve all top-level keys (name, description, etc.) so save() doesn't
        // silently drop metadata. We only override 'name' to ensure it's a string.
        $data['name'] = is_string($data['name'] ?? null) ? $data['name'] : $bank;

        return $data;
    }

    /**
     * Convert the JSON-decoded existing rules array into Rule objects so
     * the shadowing check can run them through the engine. Skip any rule
     * we can't reconstruct cleanly — the load-time validation in
     * RuleLoader will catch malformed entries on the next run.
     *
     * @param  array<int, mixed>  $rulesData
     * @return list<Rule>
     */
    private function existingRulesAsObjects(array $rulesData): array
    {
        $rules = [];
        foreach ($rulesData as $rd) {
            if (! is_array($rd)) {
                continue;
            }
            $name = is_string($rd['name'] ?? null) ? $rd['name'] : '';
            $description = is_string($rd['description'] ?? null) ? $rd['description'] : '';
            $pattern = is_string($rd['pattern'] ?? null) ? $rd['pattern'] : '';
            $replacement = is_string($rd['replacement'] ?? null) ? $rd['replacement'] : '';
            $postHooks = is_array($rd['post'] ?? null) ? array_values(array_filter($rd['post'], 'is_string')) : [];
            if ($pattern === '') {
                continue;
            }
            $rules[] = new Rule(
                name: $name,
                description: $description,
                pattern: $pattern,
                replacement: $replacement,
                postHooks: $postHooks,
                fixtures: [],
            );
        }

        return $rules;
    }

    /**
     * Resolve a transaction's counterparty name using the supplied rule
     * list at L2. Mirrors Resolver::resolve()'s ladder semantics so the
     * impact preview reflects what the operator would see after a real
     * recompute. Returns the same name Resolver would store in
     * counterparty_name.
     *
     * The duplication of L0/L1/L3/L4 logic from Resolver is pragmatic:
     * exposing a custom-rule-list code path on Resolver would couple it
     * to a concern it doesn't need elsewhere. Factor out a shared
     * ResolverLadder helper if a third caller appears.
     *
     * @param  array<string, mixed>  $transaction
     * @param  list<Rule>  $rules
     */
    private function resolveWithRules(array $transaction, array $rules): string
    {
        $cdi = isset($transaction['credit_debit_indicator']) && is_string($transaction['credit_debit_indicator'])
            ? strtoupper($transaction['credit_debit_indicator'])
            : '';

        $creditor = $this->extractName($transaction, 'creditor');
        $debtor = $this->extractName($transaction, 'debtor');

        // L0: direction-correct party name.
        $direct = match ($cdi) {
            'CRDT' => $debtor,
            'DBIT' => $creditor,
            default => null,
        };
        if (is_string($direct) && $direct !== '') {
            return mb_substr($direct, 0, 64);
        }

        // L1: inverted party name.
        $inverted = match ($cdi) {
            'CRDT' => $creditor,
            'DBIT' => $debtor,
            default => null,
        };
        if (is_string($inverted) && $inverted !== '') {
            return mb_substr($inverted, 0, 64);
        }

        // L2: rule engine against remittance_information[0].
        $engine = app(RuleEngine::class);
        if (isset($transaction['remittance_information']) && is_array($transaction['remittance_information'])) {
            $first = $transaction['remittance_information'][0] ?? null;
            if (is_string($first) && trim($first) !== '') {
                $extracted = $engine->apply($first, $rules);
                if ($extracted !== '') {
                    return mb_substr($extracted, 0, 64);
                }
            }
        }

        // L3: additional_information, falling back to bank_transaction_code.description.
        if (isset($transaction['additional_information']) && is_string($transaction['additional_information'])) {
            $trimmed = trim($transaction['additional_information']);
            if ($trimmed !== '') {
                return mb_substr($trimmed, 0, 64);
            }
        }

        if (isset($transaction['bank_transaction_code']) && is_array($transaction['bank_transaction_code'])) {
            $description = $transaction['bank_transaction_code']['description'] ?? null;
            if (is_string($description)) {
                $trimmed = trim($description);
                if ($trimmed !== '') {
                    return mb_substr($trimmed, 0, 64);
                }
            }
        }

        // L4: unknown.
        return '(Unknown)';
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function extractName(array $transaction, string $party): ?string
    {
        $node = $transaction[$party] ?? null;
        if (! is_array($node)) {
            return null;
        }
        $name = $node['name'] ?? null;
        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        return null;
    }
}
