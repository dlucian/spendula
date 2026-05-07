<?php

namespace App\Services\Counterparty\Rules;

/**
 * Loads counterparty cleanup rules from a directory of JSON files.
 *
 * Production usage: instantiate with the path to
 * config/counterparty-rules-enabled/ — the directory of operator-
 * managed symlinks. Test fixture self-test instantiates with the
 * available/ directory to exercise every shipped rule regardless
 * of enable state.
 *
 * File naming: each file's basename (sans extension) is the bank
 * slug — bcp.json maps to bank slug "bcp".
 *
 * Validation: every file's rules are validated on first access for
 * that bank slug. Validation covers JSON parseability, required
 * fields, regex compilability, post-hook names, and non-empty
 * fixtures. Any failure throws RuleValidationException with the
 * file path and rule name in the message.
 *
 * Caching: rules are cached per bank slug after the first call to
 * forBank() — subsequent calls in the same process don't re-read
 * the file. Call clearCache() between long-running operations if
 * the rule files might have changed (CLI commands typically run
 * to completion before the operator edits anything).
 */
final class RuleLoader
{
    /** @var array<string, list<Rule>> */
    private array $cache = [];

    /** @var array<string, list<Rule>>|null */
    private ?array $availableCache = null;

    public function __construct(
        private readonly string $rulesDir,
    ) {}

    /**
     * Return rules for the given bank slug.
     *
     * Success: returns the Rule[] loaded and validated from {rulesDir}/{bankSlug}.json.
     *   Returns [] if no file exists for the slug (not an error — bank may have no rules).
     *
     * Failure: throws RuleValidationException if the file exists but fails validation
     *   (malformed JSON, missing required fields, bad regex, unknown post hook, empty
     *   tests array), or if {rulesDir}/{bankSlug}.json is a symlink that points to a
     *   missing or unreadable target (dangling symlink). The exception message includes
     *   the file path and, for dangling symlinks, the target path.
     *
     * Side effects: reads {rulesDir}/{bankSlug}.json on first call per slug. No DB access.
     *
     * Idempotency: safe to call repeatedly; result is cached per slug after the first call.
     *
     * Concurrency: no locking. Files are expected to be stable for the lifetime of a
     *   single CLI command invocation. Call clearCache() if rules may change mid-run.
     *
     * @return list<Rule>
     *
     * @throws RuleValidationException
     */
    public function forBank(string $bankSlug): array
    {
        if (array_key_exists($bankSlug, $this->cache)) {
            return $this->cache[$bankSlug];
        }

        $path = "{$this->rulesDir}/{$bankSlug}.json";

        // is_link() detects the symlink itself (not its target). is_file() follows
        // the link. A symlink to a missing/renamed target reports is_file()=false
        // — that's the dangling case we want to fail loudly on.
        if (is_link($path) && ! is_file($path)) {
            $target = readlink($path) ?: '(unreadable)';
            throw new RuleValidationException(
                "Dangling symlink at {$path} (points to {$target}, which doesn't exist or isn't readable). ".
                'Remove the symlink or restore the target file.'
            );
        }

        if (! is_file($path)) {
            return $this->cache[$bankSlug] = [];
        }

        return $this->cache[$bankSlug] = $this->loadFile($path);
    }

    /**
     * Return all rule files in the directory, keyed by bank slug.
     *
     * Success: returns [bankSlug => Rule[]] for every *.json file found.
     *   Files starting with '.' are skipped. Returns [] if the directory is empty
     *   or does not exist.
     *
     * Failure: throws RuleValidationException if any file fails validation or if any
     *   *.json entry is a dangling symlink (points to a missing/unreadable target).
     *   The exception message includes the path and, for dangling symlinks, the target.
     *
     * Side effects: reads all *.json files in rulesDir on first call. No DB access.
     *
     * Idempotency: result is cached; subsequent calls return the same array.
     *
     * @return array<string, list<Rule>>
     *
     * @throws RuleValidationException
     */
    public function available(): array
    {
        if ($this->availableCache !== null) {
            return $this->availableCache;
        }

        $result = [];
        if (! is_dir($this->rulesDir)) {
            return $this->availableCache = $result;
        }

        $files = glob("{$this->rulesDir}/*.json") ?: [];
        foreach ($files as $file) {
            $bankSlug = basename($file, '.json');
            if (str_starts_with($bankSlug, '.')) {
                continue;
            }
            if (is_link($file) && ! is_file($file)) {
                $target = readlink($file) ?: '(unreadable)';
                throw new RuleValidationException(
                    "Dangling symlink at {$file} (points to {$target}, which doesn't exist or isn't readable). ".
                    'Remove the symlink or restore the target file.'
                );
            }
            $result[$bankSlug] = $this->loadFile($file);
        }

        return $this->availableCache = $result;
    }

    /**
     * Clear the per-bank and available caches, forcing re-reads on the next access.
     *
     * Side effects: none beyond resetting internal state.
     *
     * Idempotency: safe to call multiple times.
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->availableCache = null;
    }

    /**
     * @return list<Rule>
     *
     * @throws RuleValidationException
     */
    private function loadFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuleValidationException("Could not read {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuleValidationException(
                "Malformed JSON in {$path}: ".json_last_error_msg(),
            );
        }

        if (! isset($data['rules']) || ! is_array($data['rules'])) {
            throw new RuleValidationException(
                "Top-level 'rules' array missing or not an array in {$path}",
            );
        }

        $rules = [];
        foreach ($data['rules'] as $i => $ruleData) {
            $rules[] = $this->parseRule($ruleData, $path, $i);
        }

        return $rules;
    }

    /**
     * @throws RuleValidationException
     */
    private function parseRule(mixed $ruleData, string $path, int $index): Rule
    {
        if (! is_array($ruleData)) {
            throw new RuleValidationException(
                "Rule at index {$index} in {$path} is not an object",
            );
        }

        $required = ['name', 'description', 'pattern', 'replacement', 'tests'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $ruleData)) {
                $name = is_string($ruleData['name'] ?? null) ? $ruleData['name'] : "(index {$index})";
                throw new RuleValidationException(
                    "Rule '{$name}' in {$path} is missing required field '{$field}'",
                );
            }
        }

        $name = $ruleData['name'];
        if (! is_string($name) || $name === '') {
            throw new RuleValidationException("Rule at index {$index} in {$path} has invalid 'name'");
        }

        $description = $ruleData['description'];
        if (! is_string($description)) {
            throw new RuleValidationException("Rule '{$name}' in {$path} has non-string 'description'");
        }

        $pattern = $ruleData['pattern'];
        if (! is_string($pattern)) {
            throw new RuleValidationException("Rule '{$name}' in {$path} has non-string 'pattern'");
        }
        $this->validateRegex($pattern, $name, $path);

        $replacement = $ruleData['replacement'];
        if (! is_string($replacement)) {
            throw new RuleValidationException("Rule '{$name}' in {$path} has non-string 'replacement'");
        }

        $postHooks = $ruleData['post'] ?? [];
        if (! is_array($postHooks)) {
            throw new RuleValidationException("Rule '{$name}' in {$path} has non-array 'post'");
        }
        foreach ($postHooks as $hook) {
            if (! is_string($hook) || ! in_array($hook, PostHook::known(), true)) {
                throw new RuleValidationException(
                    "Rule '{$name}' in {$path} references unknown post hook '".(is_string($hook) ? $hook : '?')."'. Known: ".implode(', ', PostHook::known()),
                );
            }
        }

        $testsData = $ruleData['tests'];
        if (! is_array($testsData) || $testsData === []) {
            throw new RuleValidationException(
                "Rule '{$name}' in {$path} has empty or missing 'tests' array (at least one fixture is required)",
            );
        }

        $fixtures = [];
        foreach ($testsData as $j => $fixtureData) {
            if (! is_array($fixtureData) || ! isset($fixtureData['in']) || ! isset($fixtureData['out'])) {
                throw new RuleValidationException(
                    "Rule '{$name}' fixture index {$j} in {$path} must have 'in' and 'out' keys",
                );
            }
            if (! is_string($fixtureData['in']) || ! is_string($fixtureData['out'])) {
                throw new RuleValidationException(
                    "Rule '{$name}' fixture index {$j} in {$path} has non-string 'in' or 'out'",
                );
            }
            $fixtures[] = new RuleFixture($fixtureData['in'], $fixtureData['out']);
        }

        return new Rule(
            name: $name,
            description: $description,
            pattern: $pattern,
            replacement: $replacement,
            postHooks: array_values($postHooks),
            fixtures: $fixtures,
        );
    }

    /**
     * @throws RuleValidationException
     */
    private function validateRegex(string $pattern, string $ruleName, string $path): void
    {
        // Suppress the "preg_match(): Compilation failed" warning we expect
        // for invalid regexes; we surface the message via RuleValidationException.
        // Use restore_error_handler() (not set_error_handler($previous)) so the
        // handler stack is properly balanced — PHPUnit tracks handler depth.
        set_error_handler(static fn () => true);
        try {
            $result = @preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            $error = preg_last_error_msg();
            throw new RuleValidationException(
                "Rule '{$ruleName}' in {$path} has uncompilable regex pattern: {$error}",
            );
        }
    }
}
