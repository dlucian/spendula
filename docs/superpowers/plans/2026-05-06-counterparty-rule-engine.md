# Counterparty Rule Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hard-coded BCP-specific cleanup patterns in `Resolver.php` with a data-driven rule engine that loads per-bank JSON rule files, so operators can add new cleanup rules via an artisan command without code changes.

**Architecture:** Resolver keeps L0/L1/L3/L4 logic in code (universal); L2 (remittance parsing) delegates to a new `RuleEngine` that consumes `Rule[]` objects loaded from `config/counterparty-rules-available/<bank>.json` (committed) via `config/counterparty-rules-enabled/<bank>.json` symlinks (gitignored). Rules are pure regex-pattern + replacement-template + named post-hooks (`trim`, `collapse`); first terminal match wins; no match returns trimmed remittance.

**Tech Stack:** PHP 8.4, Laravel 13, PHPUnit, PHPStan level 8 (existing project conventions). No new dependencies.

**Spec:** `docs/superpowers/specs/2026-05-06-counterparty-rule-engine-design.md`

---

## File Structure

**New files (engine + loader):**
- `app/Services/Counterparty/Rules/Rule.php` — value object (name, description, pattern, replacement, postHooks, fixtures)
- `app/Services/Counterparty/Rules/RuleFixture.php` — value object (input, expected)
- `app/Services/Counterparty/Rules/PostHook.php` — applies named post hooks (`trim`, `collapse`)
- `app/Services/Counterparty/Rules/RuleEngine.php` — applies a `Rule[]` to a remittance string
- `app/Services/Counterparty/Rules/RuleLoader.php` — scans rule directories, parses JSON, validates, returns `Rule[]`
- `app/Services/Counterparty/Rules/RuleValidationException.php` — thrown by loader on bad rules

**New JSON files (data):**
- `config/counterparty-rules-available/bcp.json` — Portuguese BCP rules
- `config/counterparty-rules-available/ing-ro.json` — ING RO structured extraction
- `config/counterparty-rules-available/.gitkeep`
- `config/counterparty-rules-enabled/.gitignore` — `*` plus `!.gitignore`

**New CLI commands:**
- `app/Console/Commands/Spendula/CounterpartyRulesAddCommand.php`
- `app/Console/Commands/Spendula/CounterpartyRulesEnableCommand.php`
- `app/Console/Commands/Spendula/CounterpartyRulesDisableCommand.php`
- `app/Console/Commands/Spendula/CounterpartyRulesTestCommand.php`

**New tests:**
- `tests/Unit/Services/Counterparty/Rules/RuleTest.php`
- `tests/Unit/Services/Counterparty/Rules/RuleFixtureTest.php`
- `tests/Unit/Services/Counterparty/Rules/PostHookTest.php`
- `tests/Unit/Services/Counterparty/Rules/RuleEngineTest.php`
- `tests/Unit/Services/Counterparty/Rules/RuleLoaderTest.php`
- `tests/Feature/Services/Counterparty/RuleFixtureSelfTest.php` — auto-discovers + asserts every fixture in `config/counterparty-rules-available/*.json`
- `tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php`
- `tests/Feature/Commands/Spendula/CounterpartyRulesEnableCommandTest.php`
- `tests/Feature/Commands/Spendula/CounterpartyRulesDisableCommandTest.php`
- `tests/Feature/Commands/Spendula/CounterpartyRulesTestCommandTest.php`

**Modified files:**
- `app/Services/Counterparty/Resolver.php` — drop hard-coded BCP/ING patterns, delegate L2 to `RuleEngine`. Add `?string $bankSlug` parameter to `resolve()`.
- `app/Services/Counterparty/ResolvedCounterparty.php` — no change expected; verify
- `app/Services/Sync/MatchUpdateOrInsert.php` — pass bank slug into `Resolver::resolve()`
- `app/Console/Commands/Spendula/CounterpartyRecomputeCommand.php` — pass bank slug into `Resolver::resolve()`
- `tests/Unit/Services/Counterparty/ResolverTest.php` — pass `bankSlug` arg in BCP/ING-specific test cases; rewrite `test_level_2_remittance_fallback_strips_banking_prefix_and_truncates` to assert truncation only (banking-prefix stripping is now data-driven)
- `SUMMARY.md` — new latest-task entry
- `README.md` — `§14 Artisan commands` table updates with the four new commands

---

## Phase 1: Engine core

Build the pure-logic value objects and engine first. No I/O, no Laravel dependencies. Standalone unit-tested.

### Task 1: Rule + RuleFixture value objects

**Files:**
- Create: `app/Services/Counterparty/Rules/Rule.php`
- Create: `app/Services/Counterparty/Rules/RuleFixture.php`
- Create: `tests/Unit/Services/Counterparty/Rules/RuleTest.php`
- Create: `tests/Unit/Services/Counterparty/Rules/RuleFixtureTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Counterparty/Rules/RuleFixtureTest.php`:

```php
<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\RuleFixture;
use PHPUnit\Framework\TestCase;

class RuleFixtureTest extends TestCase
{
    public function test_holds_input_and_expected(): void
    {
        $fixture = new RuleFixture('COMPRA 5962 SHOP', 'SHOP');

        $this->assertSame('COMPRA 5962 SHOP', $fixture->input);
        $this->assertSame('SHOP', $fixture->expected);
    }
}
```

Create `tests/Unit/Services/Counterparty/Rules/RuleTest.php`:

```php
<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\Rule;
use App\Services\Counterparty\Rules\RuleFixture;
use PHPUnit\Framework\TestCase;

class RuleTest extends TestCase
{
    public function test_holds_all_required_fields(): void
    {
        $rule = new Rule(
            name: 'compra-card-purchase',
            description: 'BCP card purchase prefix strip',
            pattern: '/^COMPRA\s+\d{3,5}\s+(.+)$/i',
            replacement: '$1',
            postHooks: ['trim'],
            fixtures: [new RuleFixture('COMPRA 5962 SHOP', 'SHOP')],
        );

        $this->assertSame('compra-card-purchase', $rule->name);
        $this->assertSame('BCP card purchase prefix strip', $rule->description);
        $this->assertSame('/^COMPRA\s+\d{3,5}\s+(.+)$/i', $rule->pattern);
        $this->assertSame('$1', $rule->replacement);
        $this->assertSame(['trim'], $rule->postHooks);
        $this->assertCount(1, $rule->fixtures);
        $this->assertInstanceOf(RuleFixture::class, $rule->fixtures[0]);
    }

    public function test_post_hooks_default_empty(): void
    {
        $rule = new Rule(
            name: 'foo',
            description: 'desc',
            pattern: '/^X$/',
            replacement: '',
            postHooks: [],
            fixtures: [new RuleFixture('X', '')],
        );

        $this->assertSame([], $rule->postHooks);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Services/Counterparty/Rules/ --colors=never 2>&1 | tail -10
```

Expected: failures (classes don't exist).

- [ ] **Step 3: Implement the value objects**

Create `app/Services/Counterparty/Rules/RuleFixture.php`:

```php
<?php

namespace App\Services\Counterparty\Rules;

/**
 * Single test case for a Rule: input remittance string and the expected
 * resolved output after the rule's regex + post-hooks are applied.
 *
 * Fixtures are part of the rule's contract — they're loaded from the
 * rule's JSON file, validated at add-time and load-time, and exercised
 * by RuleFixtureSelfTest at every PHPUnit run.
 */
final readonly class RuleFixture
{
    public function __construct(
        public string $input,
        public string $expected,
    ) {}
}
```

Create `app/Services/Counterparty/Rules/Rule.php`:

```php
<?php

namespace App\Services\Counterparty\Rules;

/**
 * Counterparty cleanup rule: a regex pattern + replacement template +
 * optional named post-hooks. First terminal match wins in the engine
 * (per-bank rule list, ordered most-specific first).
 *
 * Pattern is a full PCRE (delimiters + flags included). Replacement
 * uses preg_replace backreference syntax ($1, $2, ...). Post hooks
 * are named finalizers applied to the substitution result; v1 ships
 * "trim" and "collapse" (see PostHook::HOOKS).
 */
final readonly class Rule
{
    /**
     * @param  string[]  $postHooks
     * @param  RuleFixture[]  $fixtures
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $pattern,
        public string $replacement,
        public array $postHooks,
        public array $fixtures,
    ) {}
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Services/Counterparty/Rules/ --colors=never 2>&1 | tail -8
```

Expected: 3 tests, 6 assertions, OK.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Counterparty/Rules/Rule.php app/Services/Counterparty/Rules/RuleFixture.php tests/Unit/Services/Counterparty/Rules/RuleTest.php tests/Unit/Services/Counterparty/Rules/RuleFixtureTest.php
git commit -m "$(cat <<'EOF'
feat(counterparty): Rule + RuleFixture value objects

Read-only DTOs for the rule engine. Rule holds name, description,
pattern (full PCRE), replacement (preg_replace template), post hooks,
and fixtures. RuleFixture is a single input/expected test case.

No I/O, no Laravel dependencies — pure value types.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: PostHook (named finalizers)

**Files:**
- Create: `app/Services/Counterparty/Rules/PostHook.php`
- Create: `tests/Unit/Services/Counterparty/Rules/PostHookTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Counterparty/Rules/PostHookTest.php`:

```php
<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\PostHook;
use PHPUnit\Framework\TestCase;

class PostHookTest extends TestCase
{
    public function test_trim_strips_whitespace(): void
    {
        $this->assertSame('hello', PostHook::apply('trim', '  hello  '));
    }

    public function test_trim_strips_punctuation_set(): void
    {
        // The "trim" hook strips small punctuation (-_.,;:) plus whitespace
        // — handles BCP's "EDP COMERCIAL-" hyphen artifact and similar.
        $this->assertSame('EDP COMERCIAL', PostHook::apply('trim', 'EDP COMERCIAL-'));
        $this->assertSame('hello', PostHook::apply('trim', '_hello,'));
    }

    public function test_collapse_replaces_internal_whitespace_runs_with_single_space(): void
    {
        $this->assertSame('VILA NOVA', PostHook::apply('collapse', 'VILA  NOVA'));
        $this->assertSame('a b c', PostHook::apply('collapse', "a\t\tb   c"));
    }

    public function test_unknown_hook_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown post hook.*foo/i');

        PostHook::apply('foo', 'anything');
    }

    public function test_known_returns_supported_hooks(): void
    {
        $known = PostHook::known();

        $this->assertContains('trim', $known);
        $this->assertContains('collapse', $known);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Services/Counterparty/Rules/PostHookTest.php --colors=never 2>&1 | tail -10
```

Expected: failures (class doesn't exist).

- [ ] **Step 3: Implement PostHook**

Create `app/Services/Counterparty/Rules/PostHook.php`:

```php
<?php

namespace App\Services\Counterparty\Rules;

use InvalidArgumentException;

/**
 * Named finalizers applied to a Rule's preg_replace result. v1 ships
 * "trim" (strip leading/trailing whitespace + small punctuation) and
 * "collapse" (replace internal whitespace runs with a single space).
 *
 * New hooks are added by extending HOOKS and adding a private static
 * helper. The named-set design keeps rule files self-contained — no
 * inline PHP code in JSON, no eval.
 */
final class PostHook
{
    /**
     * @var list<string>
     */
    private const array HOOKS = ['trim', 'collapse'];

    /** Characters trimmed by the "trim" hook beyond whitespace. */
    private const string TRIM_PUNCTUATION = " \t\n\r\0\x0B-_.,;:";

    public static function apply(string $hook, string $text): string
    {
        return match ($hook) {
            'trim' => trim($text, self::TRIM_PUNCTUATION),
            'collapse' => (string) preg_replace('/\s+/', ' ', $text),
            default => throw new InvalidArgumentException("Unknown post hook: '{$hook}'. Known: ".implode(', ', self::HOOKS)),
        };
    }

    /**
     * @return list<string>
     */
    public static function known(): array
    {
        return self::HOOKS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Services/Counterparty/Rules/PostHookTest.php --colors=never 2>&1 | tail -8
```

Expected: 5 tests, 7 assertions, OK.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Counterparty/Rules/PostHook.php tests/Unit/Services/Counterparty/Rules/PostHookTest.php
git commit -m "$(cat <<'EOF'
feat(counterparty): PostHook with trim and collapse finalizers

Named post-processing hooks applied to Rule preg_replace output.
v1 ships:
- trim: strip whitespace + small punctuation (-_.,;:) — handles
  BCP's "EDP COMERCIAL-" hyphen artifact
- collapse: replace internal whitespace runs with single space —
  BCP's "VILA  NOVA" double-space ATM locations

Unknown hook name throws InvalidArgumentException, surfaced at
add-time and load-time validation.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: RuleEngine (apply rules to a remittance string)

**Files:**
- Create: `app/Services/Counterparty/Rules/RuleEngine.php`
- Create: `tests/Unit/Services/Counterparty/Rules/RuleEngineTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Counterparty/Rules/RuleEngineTest.php`:

```php
<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\Rule;
use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleFixture;
use PHPUnit\Framework\TestCase;

class RuleEngineTest extends TestCase
{
    public function test_no_rules_returns_trimmed_remittance(): void
    {
        $engine = new RuleEngine();

        $this->assertSame('EXAMPLE COMPANY SRL', $engine->apply('  EXAMPLE COMPANY SRL  ', []));
    }

    public function test_single_rule_match_returns_replacement(): void
    {
        $engine = new RuleEngine();
        $rule = new Rule(
            name: 'compra',
            description: 'BCP card purchase',
            pattern: '/^COMPRA\s+\d{3,5}\s+(.+)$/i',
            replacement: '$1',
            postHooks: [],
            fixtures: [],
        );

        $this->assertSame('SHOP LISBOA', $engine->apply('COMPRA 5962 SHOP LISBOA', [$rule]));
    }

    public function test_no_match_returns_trimmed_remittance(): void
    {
        $engine = new RuleEngine();
        $rule = new Rule('foo', 'desc', '/^DD\s+(.+)$/i', '$1', [], []);

        $this->assertSame('SHOP 12345', $engine->apply('SHOP 12345', [$rule]));
    }

    public function test_first_terminal_match_wins(): void
    {
        $engine = new RuleEngine();
        $specific = new Rule(
            'air-serbia',
            'specific',
            '/^COMPRA\s+\d{3,5}\s+(AIR\s+SERBIA)\s+[A-Z]\d{8,}\s+(.+)$/i',
            '$1 $2',
            [],
            [],
        );
        $general = new Rule(
            'compra-generic',
            'general',
            '/^COMPRA\s+\d{3,5}\s+(.+)$/i',
            '$1',
            [],
            [],
        );

        $this->assertSame(
            'AIR SERBIA Belgrade RS',
            $engine->apply('COMPRA 5962 AIR SERBIA A21296337235 Belgrade RS', [$specific, $general]),
        );
    }

    public function test_post_hooks_applied_in_order(): void
    {
        $engine = new RuleEngine();
        $rule = new Rule(
            'lev-atm',
            'BCP ATM with location',
            '/^LEV\s+ATM\s+\d+\s+\d+\s+(.+?)\s{4,}\S.*$/i',
            'ATM $1',
            ['collapse'],
            [],
        );

        // VILA  NOVA inside location → ATM VILA NOVA after collapse
        $this->assertSame(
            'ATM VILA NOVA',
            $engine->apply('LEV ATM 5962 703   VILA  NOVA        Mario Nunes E', [$rule]),
        );
    }

    public function test_empty_result_after_post_hooks_falls_through_to_next_rule(): void
    {
        // Rule that matches but produces empty output should not block
        // subsequent rules from firing.
        $engine = new RuleEngine();
        $emptier = new Rule(
            'wipe',
            'replaces with empty',
            '/^STARTS-WITH/',
            '',
            ['trim'],
            [],
        );
        $catchAll = new Rule(
            'catch',
            'matches anything',
            '/^(.*)$/',
            'CAUGHT',
            [],
            [],
        );

        $this->assertSame('CAUGHT', $engine->apply('STARTS-WITH stuff', [$emptier, $catchAll]));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Services/Counterparty/Rules/RuleEngineTest.php --colors=never 2>&1 | tail -10
```

Expected: failures (RuleEngine doesn't exist).

- [ ] **Step 3: Implement RuleEngine**

Create `app/Services/Counterparty/Rules/RuleEngine.php`:

```php
<?php

namespace App\Services\Counterparty\Rules;

/**
 * Applies a list of Rule objects to a remittance string. Pure: no I/O,
 * no Laravel dependencies, no logging side effects. Loaders feed it
 * pre-built Rule[] arrays.
 *
 * Semantics:
 *   - Iterate rules in array order; first terminal match wins.
 *   - Terminal match = pattern matches AND post-processed result is
 *     non-empty after trim().
 *   - If a rule matches but post-processing yields empty, fall through
 *     to subsequent rules (so a buggy/over-aggressive rule can't mask
 *     the counterparty entirely).
 *   - No rules match (or all empty out): return trim($remittance).
 */
final class RuleEngine
{
    /**
     * @param  Rule[]  $rules
     */
    public function apply(string $remittance, array $rules): string
    {
        foreach ($rules as $rule) {
            if (preg_match($rule->pattern, $remittance) !== 1) {
                continue;
            }

            $result = preg_replace($rule->pattern, $rule->replacement, $remittance, 1);
            if (! is_string($result)) {
                continue;
            }

            foreach ($rule->postHooks as $hook) {
                $result = PostHook::apply($hook, $result);
            }

            $result = trim($result);
            if ($result !== '') {
                return $result;
            }
        }

        return trim($remittance);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Services/Counterparty/Rules/RuleEngineTest.php --colors=never 2>&1 | tail -8
```

Expected: 6 tests, 6 assertions, OK.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Counterparty/Rules/RuleEngine.php tests/Unit/Services/Counterparty/Rules/RuleEngineTest.php
git commit -m "$(cat <<'EOF'
feat(counterparty): RuleEngine — apply Rule[] to a remittance string

Pure stateless engine. Iterates rules in array order, first terminal
match wins. Terminal = pattern matches AND post-processed result is
non-empty. Empty-after-post-processing falls through to next rule
(safety against over-aggressive rules masking the counterparty).
No match returns trim(remittance).

No I/O. Loaders feed pre-built Rule[] arrays.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 2: Loader + validation

The loader scans `config/counterparty-rules-enabled/`, parses JSON, validates rules (regex compile, required fields, fixtures non-empty), and returns `Rule[]` per bank.

### Task 4: RuleValidationException

**Files:**
- Create: `app/Services/Counterparty/Rules/RuleValidationException.php`

- [ ] **Step 1: Implement (no test needed; will be exercised by loader tests)**

Create `app/Services/Counterparty/Rules/RuleValidationException.php`:

```php
<?php

namespace App\Services\Counterparty\Rules;

use RuntimeException;

/**
 * Thrown by RuleLoader when a rule file fails validation. The message
 * names the file path and (when applicable) the offending rule's name
 * so the operator can locate the issue quickly.
 *
 * Add-time validation in CounterpartyRulesAddCommand catches the same
 * class of errors before writing the file; this exception is the
 * load-time fatal that fires if a hand-edited file slips past.
 */
class RuleValidationException extends RuntimeException
{
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Counterparty/Rules/RuleValidationException.php
git commit -m "$(cat <<'EOF'
feat(counterparty): RuleValidationException

Thrown by RuleLoader on invalid rule files (malformed JSON, missing
required fields, regex compile errors, empty fixtures). Load-time
fatal — the operator hears about it before any data is processed.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: RuleLoader

**Files:**
- Create: `app/Services/Counterparty/Rules/RuleLoader.php`
- Create: `tests/Unit/Services/Counterparty/Rules/RuleLoaderTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Counterparty/Rules/RuleLoaderTest.php`:

```php
<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\Rule;
use App\Services\Counterparty\Rules\RuleLoader;
use App\Services\Counterparty\Rules\RuleValidationException;
use PHPUnit\Framework\TestCase;

class RuleLoaderTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/spendula-rules-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = "{$dir}/{$f}";
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
        rmdir($dir);
    }

    private function writeRuleFile(string $filename, array $data): void
    {
        file_put_contents("{$this->tempDir}/{$filename}", json_encode($data));
    }

    public function test_for_bank_returns_empty_when_no_file(): void
    {
        $loader = new RuleLoader($this->tempDir);

        $this->assertSame([], $loader->forBank('bcp'));
    }

    public function test_for_bank_loads_rules_from_matching_filename(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                [
                    'name' => 'compra',
                    'description' => 'BCP card purchase',
                    'pattern' => '/^COMPRA\s+\d{3,5}\s+(.+)$/i',
                    'replacement' => '$1',
                    'tests' => [
                        ['in' => 'COMPRA 5962 SHOP', 'out' => 'SHOP'],
                    ],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);
        $rules = $loader->forBank('bcp');

        $this->assertCount(1, $rules);
        $this->assertInstanceOf(Rule::class, $rules[0]);
        $this->assertSame('compra', $rules[0]->name);
        $this->assertCount(1, $rules[0]->fixtures);
        $this->assertSame('COMPRA 5962 SHOP', $rules[0]->fixtures[0]->input);
        $this->assertSame('SHOP', $rules[0]->fixtures[0]->expected);
    }

    public function test_for_bank_returns_empty_for_unknown_bank(): void
    {
        $this->writeRuleFile('bcp.json', ['name' => 'BCP', 'rules' => []]);

        $loader = new RuleLoader($this->tempDir);

        $this->assertSame([], $loader->forBank('revolut-lt'));
    }

    public function test_malformed_json_throws_validation_exception(): void
    {
        file_put_contents("{$this->tempDir}/bad.json", '{ not json');

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/bad\.json/');

        $loader->forBank('bad');
    }

    public function test_missing_top_level_rules_array_throws(): void
    {
        $this->writeRuleFile('bcp.json', ['name' => 'BCP']);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/rules.*missing|missing.*rules/i');

        $loader->forBank('bcp');
    }

    public function test_rule_with_missing_required_field_throws(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                ['name' => 'compra', 'pattern' => '/^X$/'],  // missing description, replacement, tests
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);

        $loader->forBank('bcp');
    }

    public function test_rule_with_empty_tests_array_throws(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                [
                    'name' => 'compra',
                    'description' => 'd',
                    'pattern' => '/^X$/',
                    'replacement' => '',
                    'tests' => [],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/tests.*empty|empty.*tests/i');

        $loader->forBank('bcp');
    }

    public function test_rule_with_uncompilable_regex_throws(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                [
                    'name' => 'broken',
                    'description' => 'd',
                    'pattern' => '/[broken/',  // unclosed character class
                    'replacement' => '',
                    'tests' => [['in' => 'X', 'out' => 'X']],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/regex|pattern/i');

        $loader->forBank('bcp');
    }

    public function test_rule_with_unknown_post_hook_throws(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                [
                    'name' => 'foo',
                    'description' => 'd',
                    'pattern' => '/^X$/',
                    'replacement' => '',
                    'post' => ['nonexistent-hook'],
                    'tests' => [['in' => 'X', 'out' => 'X']],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/post hook|hook/i');

        $loader->forBank('bcp');
    }

    public function test_available_returns_all_rule_files_keyed_by_bank_slug(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]);
        $this->writeRuleFile('ing-ro.json', [
            'name' => 'ING RO',
            'rules' => [
                ['name' => 'r2', 'description' => 'd', 'pattern' => '/^Y$/', 'replacement' => '', 'tests' => [['in' => 'Y', 'out' => '']]],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);
        $all = $loader->available();

        $this->assertArrayHasKey('bcp', $all);
        $this->assertArrayHasKey('ing-ro', $all);
        $this->assertCount(1, $all['bcp']);
        $this->assertCount(1, $all['ing-ro']);
    }

    public function test_caches_per_bank_after_first_load(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);
        $first = $loader->forBank('bcp');

        // Mutate the file — cached call should not reflect the change.
        $this->writeRuleFile('bcp.json', ['name' => 'BCP', 'rules' => []]);
        $second = $loader->forBank('bcp');

        $this->assertSame(count($first), count($second));
        $this->assertCount(1, $second);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Services/Counterparty/Rules/RuleLoaderTest.php --colors=never 2>&1 | tail -10
```

Expected: failures (RuleLoader doesn't exist).

- [ ] **Step 3: Implement RuleLoader**

Create `app/Services/Counterparty/Rules/RuleLoader.php`:

```php
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
     * @return list<Rule>
     */
    public function forBank(string $bankSlug): array
    {
        if (array_key_exists($bankSlug, $this->cache)) {
            return $this->cache[$bankSlug];
        }

        $path = "{$this->rulesDir}/{$bankSlug}.json";
        if (! is_file($path)) {
            return $this->cache[$bankSlug] = [];
        }

        return $this->cache[$bankSlug] = $this->loadFile($path);
    }

    /**
     * @return array<string, list<Rule>>
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
            $result[$bankSlug] = $this->loadFile($file);
        }

        return $this->availableCache = $result;
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->availableCache = null;
    }

    /**
     * @return list<Rule>
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
     * @param  mixed  $ruleData
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

    private function validateRegex(string $pattern, string $ruleName, string $path): void
    {
        // Suppress the "preg_match(): Compilation failed" warning we expect
        // for invalid regexes; we surface the message via RuleValidationException.
        $previous = set_error_handler(static fn () => true);
        try {
            $result = @preg_match($pattern, '');
        } finally {
            set_error_handler($previous);
        }

        if ($result === false) {
            $error = preg_last_error_msg();
            throw new RuleValidationException(
                "Rule '{$ruleName}' in {$path} has uncompilable regex pattern: {$error}",
            );
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Services/Counterparty/Rules/RuleLoaderTest.php --colors=never 2>&1 | tail -8
```

Expected: 10 tests, OK.

- [ ] **Step 5: Run full unit suite for the new namespace**

```bash
./vendor/bin/phpunit tests/Unit/Services/Counterparty/Rules/ --colors=never 2>&1 | tail -3
```

Expected: 24 tests, all green.

- [ ] **Step 6: PHPStan check**

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G 2>&1 | tail -3
```

Expected: `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Counterparty/Rules/RuleLoader.php tests/Unit/Services/Counterparty/Rules/RuleLoaderTest.php
git commit -m "$(cat <<'EOF'
feat(counterparty): RuleLoader scans + validates rule files

Loads per-bank JSON rule files from a directory. forBank(slug) reads
slug.json on first call, caches Rule[] per slug. available() returns
[slug => Rule[]] for every file in the directory (used by the
fixture self-test to exercise all shipped rules regardless of enable
state).

Validation throws RuleValidationException on:
- malformed JSON
- missing top-level 'rules' array
- missing required rule fields (name/description/pattern/replacement/tests)
- uncompilable regex
- unknown post hook
- empty or missing tests array

Tests use a temp dir to verify each failure mode in isolation.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 3: Data files + directory layout

Set up the on-disk structure: directories, gitignore for symlinks, and the two initial rule files.

### Task 6: Directory scaffolding + gitignore

**Files:**
- Create: `config/counterparty-rules-available/.gitkeep`
- Create: `config/counterparty-rules-enabled/.gitignore`

- [ ] **Step 1: Create the directories and gitignore**

```bash
mkdir -p config/counterparty-rules-available config/counterparty-rules-enabled
touch config/counterparty-rules-available/.gitkeep
cat > config/counterparty-rules-enabled/.gitignore <<'EOF'
*
!.gitignore
EOF
```

- [ ] **Step 2: Verify gitignore is committed but symlinks won't be**

```bash
ln -s ../counterparty-rules-available/example.json config/counterparty-rules-enabled/example.json
git status --short config/
```

Expected output (the symlink is ignored, only the gitkeep + gitignore show as untracked):
```
?? config/counterparty-rules-available/.gitkeep
?? config/counterparty-rules-enabled/.gitignore
```

If anything else shows up, delete the test symlink. Either way:

```bash
rm -f config/counterparty-rules-enabled/example.json
```

- [ ] **Step 3: Commit**

```bash
git add config/counterparty-rules-available/.gitkeep config/counterparty-rules-enabled/.gitignore
git commit -m "$(cat <<'EOF'
feat(counterparty): scaffolding for rule directory layout

config/counterparty-rules-available/ holds committed rule files
shipped with the repo. config/counterparty-rules-enabled/ holds
gitignored symlinks the operator manages via the upcoming
spendula:counterparty:rules:enable / disable commands. The * but
!.gitignore pattern keeps the directory itself in git while ignoring
its contents.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: bcp.json with all current BCP patterns

**Files:**
- Create: `config/counterparty-rules-available/bcp.json`

- [ ] **Step 1: Create bcp.json**

The file consolidates every BCP-specific behavior currently encoded in `Resolver.php` constants and shape-extractor methods into rule rows. Each rule has at least one fixture pulled from the existing `ResolverTest.php` cases.

Create `config/counterparty-rules-available/bcp.json`:

```json
{
  "name": "Millennium BCP (Portugal)",
  "description": "Cleanup rules for Millennium BCP remittance shapes observed in real EB production data. Rules are ordered most-specific first; first terminal match wins.",
  "rules": [
    {
      "name": "lev-atm-with-location",
      "description": "BCP ATM withdrawal — extract location, drop cardholder echo. Cardholder gap is >=4 spaces; internal location double-spaces (VILA  NOVA) collapsed.",
      "pattern": "/^LEV\\s+ATM\\s+\\d+\\s+\\d+\\s+(.+?)\\s{4,}\\S.*$/iu",
      "replacement": "ATM $1",
      "post": ["collapse"],
      "tests": [
        {"in": "LEV ATM 5962 703   LISBOA        Mario Nunes E", "out": "ATM LISBOA"},
        {"in": "LEV ATM 5962 703   VILA  NOVA        Mario Nunes E", "out": "ATM VILA NOVA"}
      ]
    },
    {
      "name": "lev-atm-fallback",
      "description": "Line starts with LEV ATM but doesn't fit the location-extraction shape — collapse to bare ATM rather than leaving the noisy line.",
      "pattern": "/^LEV\\s+ATM\\b.*$/i",
      "replacement": "ATM",
      "tests": [
        {"in": "LEV ATM 5962", "out": "ATM"}
      ]
    },
    {
      "name": "dd-with-reference",
      "description": "BCP direct debit: 'DD <merchant> <8+ digit ref> [<alpha sub-product>] (PT|DI)<id>'. Merchant is non-digit so descriptors that embed numbers (DD ACME 2024 PT...) fall through to dd-fallthrough rather than mis-cutting.",
      "pattern": "/^DD\\s+(?P<merchant>[^\\d]+?)\\s+\\d{8,}(?:\\s+\\p{L}+)?\\s+(?:PT|DI)\\d{6,}\\s*$/iu",
      "replacement": "$1",
      "post": ["trim"],
      "tests": [
        {"in": "DD GIN CLUBE PORT 00335110554    PT22100415", "out": "GIN CLUBE PORT"},
        {"in": "DD NOS Comunicaco 06258979526    PT20100839", "out": "NOS Comunicaco"},
        {"in": "DD OCIDENTAL 00346849108 MEDIS       DI72078874", "out": "OCIDENTAL"},
        {"in": "DD OCIDENTAL 00346849108 MÉDIS       DI72078874", "out": "OCIDENTAL"},
        {"in": "DD EDP COMERCIAL- 16010014044135 PT34100781", "out": "EDP COMERCIAL"}
      ]
    },
    {
      "name": "dd-fallthrough",
      "description": "DD line that didn't match dd-with-reference. Strip 'DD ' prefix only, preserve the rest. Handles short refs (EXAMPLEGYM 2010), refs without PT/DI suffix, and merchant names that contain digits.",
      "pattern": "/^DD\\s+(.+)$/i",
      "replacement": "$1",
      "tests": [
        {"in": "DD EDP COMERCIAL  16", "out": "EDP COMERCIAL  16"},
        {"in": "DD ACME 2024 PT12345678", "out": "ACME 2024 PT12345678"}
      ]
    },
    {
      "name": "compra-card-purchase",
      "description": "BCP card purchase: 'COMPRA NNNN <merchant> [CONTACTLESS]'. NNNN is the last-4 of the card or a category code (5962, 9800).",
      "pattern": "/^COMPRA\\s+\\d{3,5}\\s+(.+?)(?:\\s+CONTACTLESS)?\\s*$/i",
      "replacement": "$1",
      "tests": [
        {"in": "COMPRA 9800 Vinted Vilnius LT", "out": "Vinted Vilnius LT"},
        {"in": "COMPRA 5962 CONTINENTE LISBOA PT", "out": "CONTINENTE LISBOA PT"},
        {"in": "COMPRA 9800 MACAS DE ADAO LISBOA PT CONTACTLESS", "out": "MACAS DE ADAO LISBOA PT"}
      ]
    },
    {
      "name": "trf-person",
      "description": "BCP transfer to/from person: 'TRF <variant> <name>'. Variants: DE (incoming), MB WAY P (mobile pay), P (outgoing), P O (outgoing alternate).",
      "pattern": "/^TRF\\.?\\s+(?:DE|MB\\s+WAY\\s+P|P\\s+O|P)\\s+(.+)$/i",
      "replacement": "$1",
      "tests": [
        {"in": "TRF DE Apparte - Emergency fund", "out": "Apparte - Emergency fund"},
        {"in": "TRF MB WAY P  SONAM MALLA", "out": "SONAM MALLA"},
        {"in": "TRF. P O NIKOLAY SAVCHENKO", "out": "NIKOLAY SAVCHENKO"}
      ]
    },
    {
      "name": "pagserv-empresa-portuguesa-das",
      "description": "BCP service payment to Empresa Portuguesa Das (postal/telecom) — strip service code + phone-number tail.",
      "pattern": "/^PAGSERV\\s+(EMPRESA\\s+PORTUGUESA\\s+DAS).*$/i",
      "replacement": "$1",
      "tests": [
        {"in": "PAGSERV EMPRESA PORTUGUESA DAS 20811 962735560", "out": "EMPRESA PORTUGUESA DAS"}
      ]
    },
    {
      "name": "pagserv-generic",
      "description": "Generic BCP service payment — strip 'PAGSERV ' prefix, leave the rest.",
      "pattern": "/^PAGSERV\\s+(.+)$/i",
      "replacement": "$1",
      "tests": [
        {"in": "PAGSERV CTT EXPRESSO", "out": "CTT EXPRESSO"}
      ]
    },
    {
      "name": "pag-bxval-via-verde",
      "description": "BCP toll/parking payment via the Via Verde tag system — strip 'PAG BXVAL- NNNN ' prefix.",
      "pattern": "/^PAG\\s+BXVAL-\\s+\\d+\\s+(.+)$/i",
      "replacement": "$1",
      "tests": [
        {"in": "PAG BXVAL- 5962 VIAVERDE", "out": "VIAVERDE"}
      ]
    },
    {
      "name": "com-man-conta-monthly-fee",
      "description": "BCP monthly account-maintenance fee with trailing 6-digit period code (MMyyyy). Drop the period so all months aggregate.",
      "pattern": "/^(COM\\.MAN\\.CONTA[^\\d]*?)\\s+\\d{6}\\s*$/i",
      "replacement": "$1",
      "tests": [
        {"in": "COM.MAN.CONTA PACOTE CLIENTE FREQUENTE   022026", "out": "COM.MAN.CONTA PACOTE CLIENTE FREQUENTE"}
      ]
    }
  ]
}
```

- [ ] **Step 2: Verify the file is valid JSON and the loader parses it**

```bash
php -r "json_decode(file_get_contents('config/counterparty-rules-available/bcp.json'), true) ?: throw new \Exception('JSON invalid: '.json_last_error_msg()); echo \"OK\n\";"
```

Expected: `OK`.

- [ ] **Step 3: Verify all fixtures pass via the engine**

```bash
php artisan tinker --execute="
\$loader = new App\Services\Counterparty\Rules\RuleLoader(base_path('config/counterparty-rules-available'));
\$engine = new App\Services\Counterparty\Rules\RuleEngine();
\$rules = \$loader->forBank('bcp');
\$failed = 0;
foreach (\$rules as \$rule) {
    foreach (\$rule->fixtures as \$f) {
        \$got = \$engine->apply(\$f->input, [\$rule]);
        if (\$got !== \$f->expected) {
            echo \"FAIL [{\$rule->name}]: '{\$f->input}' -> '{\$got}' (expected '{\$f->expected}')\".PHP_EOL;
            \$failed++;
        }
    }
}
echo \"Passed: \".(count(\$rules) - \$failed).\" rules, Failed fixtures: \$failed\".PHP_EOL;
"
```

Expected: `Passed: 10 rules, Failed fixtures: 0`.

- [ ] **Step 4: Commit**

```bash
git add config/counterparty-rules-available/bcp.json
git commit -m "$(cat <<'EOF'
feat(counterparty): bcp.json — Portuguese Millennium BCP rules

Ten rules covering every BCP shape currently encoded in Resolver.php
constants and shape-extractor methods, ordered most-specific first:

1. lev-atm-with-location: ATM withdrawals -> 'ATM <city>'
2. lev-atm-fallback: malformed LEV ATM -> 'ATM'
3. dd-with-reference: direct debits with PT/DI creditor id
4. dd-fallthrough: DD without creditor id -> strip prefix only
5. compra-card-purchase: card purchase + optional CONTACTLESS
6. trf-person: TRF DE/P/MB WAY/P O variants
7. pagserv-empresa-portuguesa-das: EPD phone bills
8. pagserv-generic: generic PAGSERV prefix strip
9. pag-bxval-via-verde: Via Verde toll payments
10. com-man-conta-monthly-fee: monthly fee with period code

Each rule ships with at least one fixture from real BCP data. All
fixtures verified via tinker against the engine.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: ing-ro.json with structured remittance extraction

**Files:**
- Create: `config/counterparty-rules-available/ing-ro.json`

- [ ] **Step 1: Create ing-ro.json**

```json
{
  "name": "ING Bank Romania (Business)",
  "description": "Cleanup rules for ING RO Business card-purchase remittance, which is a structured CSV-like string.",
  "rules": [
    {
      "name": "structured-card-purchase",
      "description": "ING RO Business card purchase: 'Card number, **** XXXX, Transaction at, MERCHANT, Authorization date, ...'. Capture the merchant, drop the surrounding metadata.",
      "pattern": "/^Card number,\\s*\\*+\\s*\\d+,\\s*Transaction at,\\s*(?P<merchant>.+?)(?=,\\s*Authorization date,|$)/iu",
      "replacement": "$1",
      "post": ["trim"],
      "tests": [
        {"in": "Card number, **** 0429, Transaction at, GITHUB, INC.  US  GITHUB.COM, Authorization date, 24-04-2026, Authorization number, 071280, Amount, 4,00  USD, Settlement amount, 3,43 EUR", "out": "GITHUB, INC.  US  GITHUB.COM"},
        {"in": "Card number, **** 0429, Transaction at, ELEVENLABS.IO  US  ELEVENLABS.IO", "out": "ELEVENLABS.IO  US  ELEVENLABS.IO"}
      ]
    }
  ]
}
```

- [ ] **Step 2: Verify fixtures pass**

```bash
php artisan tinker --execute="
\$loader = new App\Services\Counterparty\Rules\RuleLoader(base_path('config/counterparty-rules-available'));
\$engine = new App\Services\Counterparty\Rules\RuleEngine();
\$rules = \$loader->forBank('ing-ro');
foreach (\$rules as \$rule) {
    foreach (\$rule->fixtures as \$f) {
        \$got = \$engine->apply(\$f->input, [\$rule]);
        echo (\$got === \$f->expected ? 'PASS' : 'FAIL').\": '{\$f->input}' -> '{\$got}'\".PHP_EOL;
    }
}
"
```

Expected: both lines `PASS`.

- [ ] **Step 3: Commit**

```bash
git add config/counterparty-rules-available/ing-ro.json
git commit -m "$(cat <<'EOF'
feat(counterparty): ing-ro.json — ING RO Business structured remittance

One rule. ING RO returns card purchases as a CSV-like string starting
with 'Card number, **** XXXX, Transaction at, MERCHANT, ...'. The
rule captures the MERCHANT field and drops the metadata.

Two fixtures: a full descriptor with all CSV fields, and a truncated
form that ends right after the merchant slot.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 4: Resolver migration

Refactor `Resolver::resolve()` to accept a bank slug and delegate L2 to the rule engine. Update callers. Drop hard-coded patterns.

### Task 9: RuleFixtureSelfTest — auto-discover all available rule files

This goes first so we have a safety net before touching the resolver.

**Files:**
- Create: `tests/Feature/Services/Counterparty/RuleFixtureSelfTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Services\Counterparty;

use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use Tests\TestCase;

class RuleFixtureSelfTest extends TestCase
{
    /**
     * Auto-discovered fixture self-test: walks every rule in every
     * config/counterparty-rules-available/*.json file and asserts
     * that the engine returns the expected output for each fixture.
     *
     * Failures show up as one assertion error per failing fixture
     * with the bank slug, rule name, and input/expected/actual in
     * the message — no need to track down which rule broke.
     */
    public function test_every_available_rule_fixture_passes(): void
    {
        $loader = new RuleLoader(base_path('config/counterparty-rules-available'));
        $engine = new RuleEngine();

        $totalFixtures = 0;
        foreach ($loader->available() as $bankSlug => $rules) {
            foreach ($rules as $rule) {
                foreach ($rule->fixtures as $fixture) {
                    $actual = $engine->apply($fixture->input, [$rule]);
                    $this->assertSame(
                        $fixture->expected,
                        $actual,
                        "Rule {$bankSlug}/{$rule->name}: input '{$fixture->input}' should resolve to '{$fixture->expected}', got '{$actual}'",
                    );
                    $totalFixtures++;
                }
            }
        }

        // Sanity check: the test discovered something. If the available
        // dir is empty, future regressions wouldn't be caught silently.
        $this->assertGreaterThan(0, $totalFixtures, 'No rule fixtures discovered — is config/counterparty-rules-available/ empty?');
    }
}
```

- [ ] **Step 2: Run the test**

```bash
./vendor/bin/phpunit tests/Feature/Services/Counterparty/RuleFixtureSelfTest.php --colors=never 2>&1 | tail -8
```

Expected: 1 test, OK. (Fixtures from bcp.json + ing-ro.json all pass.)

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Services/Counterparty/RuleFixtureSelfTest.php
git commit -m "$(cat <<'EOF'
test(counterparty): auto-discovered rule fixture self-test

Walks every rule in every config/counterparty-rules-available/*.json
file and asserts engine output matches the rule's fixtures. Runs as
part of the standard PHPUnit suite — operators get rule regressions
caught alongside code regressions.

Includes a sanity assertion that at least one fixture was discovered,
so an empty available/ dir doesn't silently disable the safety net.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Refactor Resolver to delegate L2 to RuleEngine

This is the biggest task. Updates: Resolver, two callers, the existing ResolverTest. Run full suite at the end.

**Files:**
- Modify: `app/Services/Counterparty/Resolver.php`
- Modify: `app/Services/Sync/MatchUpdateOrInsert.php` (pass bank slug)
- Modify: `app/Console/Commands/Spendula/CounterpartyRecomputeCommand.php` (pass bank slug)
- Modify: `tests/Unit/Services/Counterparty/ResolverTest.php` (pass bank slug, drop legacy "CARD PAYMENT" prefix-strip assertion)

- [ ] **Step 1: Add a service-container binding for RuleLoader**

Open `app/Providers/AppServiceProvider.php` and find the `register()` method. Add the binding for the singleton:

```php
// Inside AppServiceProvider::register():
$this->app->singleton(\App\Services\Counterparty\Rules\RuleLoader::class, function () {
    return new \App\Services\Counterparty\Rules\RuleLoader(
        base_path('config/counterparty-rules-enabled'),
    );
});
```

If the file doesn't have `register()` yet, add one. If it already binds other classes, add this alongside.

- [ ] **Step 2: Refactor Resolver — add bankSlug param, delegate L2**

Replace the body of `app/Services/Counterparty/Resolver.php` with:

```php
<?php

namespace App\Services\Counterparty;

use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;

/**
 * SPEC §6.8 counterparty ladder.
 *
 * - L0: direction-correct debtor/creditor name (universal)
 * - L1: direction-inverted debtor/creditor name (Mock ASPSP, some RO banks)
 * - L2: remittance_information[0] processed by per-bank rule engine
 * - L3: additional_information fallback
 * - L4: "(Unknown)"
 *
 * L0/L1/L3/L4 are universal and stay in code. L2 delegates to the
 * RuleEngine, which loads bank-specific rules from
 * config/counterparty-rules-enabled/<bank>.json (managed by the
 * spendula:counterparty:rules:* commands). Pass null bank slug for
 * transactions whose bank is unknown — no rules will apply, the
 * trimmed remittance is returned.
 */
class Resolver
{
    public function __construct(
        private readonly RuleLoader $ruleLoader,
        private readonly RuleEngine $ruleEngine,
    ) {}

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function resolve(array $transaction, ?string $bankSlug = null): ResolvedCounterparty
    {
        $cdi = isset($transaction['credit_debit_indicator']) && is_string($transaction['credit_debit_indicator'])
            ? strtoupper($transaction['credit_debit_indicator'])
            : '';

        $creditor = $this->extractName($transaction, 'creditor');
        $debtor = $this->extractName($transaction, 'debtor');

        // Level 0: direction-correct.
        $directCorrect = match ($cdi) {
            'CRDT' => $debtor,
            'DBIT' => $creditor,
            default => null,
        };
        if (is_string($directCorrect) && $directCorrect !== '') {
            return new ResolvedCounterparty($directCorrect, 0);
        }

        // Level 1: direction-inverted (Mock ASPSP + some RO banks).
        $inverted = match ($cdi) {
            'CRDT' => $creditor,
            'DBIT' => $debtor,
            default => null,
        };
        if (is_string($inverted) && $inverted !== '') {
            return new ResolvedCounterparty($inverted, 1);
        }

        // Level 2: rule engine over remittance_information[0].
        if (isset($transaction['remittance_information']) && is_array($transaction['remittance_information'])) {
            $first = $transaction['remittance_information'][0] ?? null;
            if (is_string($first) && trim($first) !== '') {
                $rules = $bankSlug !== null ? $this->ruleLoader->forBank($bankSlug) : [];
                $extracted = $this->ruleEngine->apply($first, $rules);
                if ($extracted !== '') {
                    return new ResolvedCounterparty(mb_substr($extracted, 0, 64), 2);
                }
            }
        }

        // Level 3: additional_information.
        if (isset($transaction['additional_information']) && is_string($transaction['additional_information'])) {
            $trimmed = trim($transaction['additional_information']);
            if ($trimmed !== '') {
                return new ResolvedCounterparty(mb_substr($trimmed, 0, 64), 3);
            }
        }

        // Level 4: unknown.
        return new ResolvedCounterparty('(Unknown)', 4);
    }

    /**
     * Normalized counterparty used for dedup fundamentals (SPEC §6.3):
     * lowercased, whitespace-collapsed, non-alphanumerics stripped.
     * Empty string is valid — matches §6.3 "if empty, use empty string".
     */
    public static function normalize(?string $counterparty): string
    {
        if ($counterparty === null || $counterparty === '') {
            return '';
        }

        $lower = mb_strtolower($counterparty);
        // Unicode-aware: \p{L}\p{N} preserves diacritics and non-Latin scripts.
        $noAlphanum = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower) ?? '';

        return trim(preg_replace('/\s+/', ' ', $noAlphanum) ?? '');
    }

    /** @param  array<string, mixed>  $transaction */
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
```

This drops every BCP-specific constant and method (`REMITTANCE_PREFIX_PATTERNS`, `REMITTANCE_SUFFIX_PATTERNS`, `ING_STRUCTURED_PATTERN`, `BCP_DD_WITH_REFERENCE_PATTERN`, `BCP_LEV_ATM_PATTERN`, `extractFromLevAtm`, `extractFromDdWithReference`, `extractFromStructured`, `stripPrefixes`, `stripSuffixes`).

- [ ] **Step 3: Update MatchUpdateOrInsert to pass the bank slug**

Open `app/Services/Sync/MatchUpdateOrInsert.php`. Find the call to `$this->resolver->resolve(...)` (search for `resolve(`). Locate the surrounding context — `MatchUpdateOrInsert` operates on a `BankAccount`, which has a `bank_slug` column. Pass it explicitly:

```php
// Before:
$resolved = $this->resolver->resolve($parsed->toArray());

// After:
$resolved = $this->resolver->resolve($parsed->toArray(), $bankAccount->bank_slug);
```

(Adjust variable names to whatever the surrounding code uses for the bank account / parsed transaction.)

- [ ] **Step 4: Update CounterpartyRecomputeCommand to pass the bank slug**

Open `app/Console/Commands/Spendula/CounterpartyRecomputeCommand.php`. The command iterates transactions in chunks and calls `$resolver->resolve($tx->raw_payload)`. It needs the bank slug from the linked bank account. Modify the chunk callback:

Find the existing `$resolved = $resolver->resolve($tx->raw_payload);` line. Replace with:

```php
$bankSlug = $tx->bankAccount?->bank_slug;
$resolved = $resolver->resolve($tx->raw_payload, $bankSlug);
```

If `Transaction::bankAccount()` doesn't exist as an Eloquent relation, add it on the model (`app/Models/Transaction.php`):

```php
public function bankAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(BankAccount::class);
}
```

To avoid N+1 queries during recompute, eager-load `bankAccount` in the chunk query. Find the `$query = Transaction::query()` line and add `->with('bankAccount')`:

```php
$query = Transaction::query()->with('bankAccount')->orderBy('id');
```

- [ ] **Step 5: Refactor ResolverTest to pass bank slug + drop legacy assertion**

Open `tests/Unit/Services/Counterparty/ResolverTest.php`. Two kinds of changes:

1. Every test that exercises an L2 BCP shape (COMPRA, TRF, DD, PAGSERV, PAG BXVAL, LEV ATM, COM.MAN.CONTA) calls `resolve()` with `'bcp'` as the second arg.
2. Every test that exercises ING-structured remittance calls `resolve()` with `'ing-ro'`.
3. The legacy `test_level_2_remittance_fallback_strips_banking_prefix_and_truncates` test — which assumed `CARD PAYMENT` was a known generic prefix — drops the "doesn't start with CARD PAYMENT" assertion. The 64-char truncation is the only universal behavior left to test there.

The Resolver constructor now takes `RuleLoader` and `RuleEngine`. Tests must instantiate them: use `app(Resolver::class)` (Laravel's container resolves dependencies). To use `app()` from `PHPUnit\Framework\TestCase`, change the parent class to `Tests\TestCase` (the Laravel-aware one) so the application is booted. This is a search-and-replace at the top of the file:

```php
// Before:
use PHPUnit\Framework\TestCase;
class ResolverTest extends TestCase

// After:
use Tests\TestCase;
class ResolverTest extends TestCase
```

For each test method, replace `(new Resolver)->resolve(...)` with `app(Resolver::class)->resolve(..., 'bcp')` (or `'ing-ro'` for ING tests, or omit the slug for tests that don't depend on rules).

Specifically:

```php
// test_level_0_direction_correct_crdt_picks_debtor — no slug needed (L0 fires)
$resolved = app(Resolver::class)->resolve([...]);

// test_level_2_remittance_fallback_strips_banking_prefix_and_truncates — rewrite:
public function test_level_2_returns_truncated_remittance_when_no_rule_matches(): void
{
    $long = str_repeat('X', 200);
    $resolved = app(Resolver::class)->resolve([
        'credit_debit_indicator' => 'DBIT',
        'creditor' => null,
        'debtor' => null,
        'remittance_information' => [$long],
    ]);

    $this->assertSame(2, $resolved->level);
    $this->assertSame(64, mb_strlen($resolved->name));
}

// test_level_2_strips_bcp_compra_card_number_prefix:
$resolved = app(Resolver::class)->resolve([...], 'bcp');

// test_level_2_extracts_merchant_from_ing_structured_remittance:
$resolved = app(Resolver::class)->resolve([...], 'ing-ro');

// test_normalize_lowercases_strips_non_alphanumerics_and_collapses_whitespace — no change (calls static method)
```

Since BCP rules aren't enabled by default (no symlink yet), tests need a way to exercise them. Two options:

  (a) Tests instantiate `Resolver` directly with a `RuleLoader` pointing to `available/`:
  ```php
  $loader = new RuleLoader(base_path('config/counterparty-rules-available'));
  $resolver = new Resolver($loader, new RuleEngine());
  $resolved = $resolver->resolve([...], 'bcp');
  ```

  (b) Override the binding in test setUp:
  ```php
  protected function setUp(): void
  {
      parent::setUp();
      $this->app->singleton(RuleLoader::class, fn () => new RuleLoader(base_path('config/counterparty-rules-available')));
  }
  ```

Pick (a) — explicit, no test-only container manipulation, works without the `Tests\TestCase` parent.

So: revert step 5's parent-class change. Keep `extends TestCase` (the PHPUnit one). Add a setUp that instantiates Resolver with the available-dir loader:

```php
<?php

namespace Tests\Unit\Services\Counterparty;

use App\Services\Counterparty\Resolver;
use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use PHPUnit\Framework\TestCase;

class ResolverTest extends TestCase
{
    private Resolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        // Use the available/ dir directly so tests exercise every shipped
        // rule regardless of whether the operator has it enabled in the
        // local enabled/ symlinks.
        $availableDir = __DIR__.'/../../../../config/counterparty-rules-available';
        $this->resolver = new Resolver(
            new RuleLoader($availableDir),
            new RuleEngine(),
        );
    }

    // ... existing tests ...
}
```

Then update each existing test to call `$this->resolver->resolve(...)` (with optional second arg `'bcp'` or `'ing-ro'` as appropriate).

This is a lot of mechanical search/replace. Be careful to preserve every assertion. The full re-written file should have the same test method names and behaviors as before, just routed through the new constructor and bank-slug parameter.

- [ ] **Step 6: Run the full suite**

```bash
./vendor/bin/phpunit --colors=never 2>&1 | tail -5
```

Expected: every test green. Specifically, the existing ResolverTest cases should all still pass because bcp.json was constructed to preserve their behavior.

If any fail: the most likely cause is a mismatch between a rule's regex/replacement and the test's expected output. Adjust the rule (and its fixtures) so it produces what the test asserts. The fixture self-test is the quick way to confirm: if the rule's own fixtures pass but the ResolverTest fails, the inputs differ in some way.

- [ ] **Step 7: PHPStan check**

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G 2>&1 | tail -3
```

Expected: `[OK] No errors`.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Counterparty/Resolver.php app/Services/Sync/MatchUpdateOrInsert.php app/Console/Commands/Spendula/CounterpartyRecomputeCommand.php app/Models/Transaction.php tests/Unit/Services/Counterparty/ResolverTest.php app/Providers/AppServiceProvider.php
git commit -m "$(cat <<'EOF'
refactor(counterparty): Resolver delegates L2 to rule engine

Resolver constructor now takes RuleLoader + RuleEngine.
Resolver::resolve() accepts an optional bank slug; L2 looks up that
bank's rules and runs them through the engine, returning the first
terminal match (or trimmed remittance if no rule matches).

Hard-coded BCP and ING patterns removed from Resolver.php — they
now live in config/counterparty-rules-available/{bcp,ing-ro}.json.
~200 lines of regex constants + extractor methods deleted; the
class shrinks to just the L0/L1/L3/L4 ladder.

Callers updated to pass bank slug:
- MatchUpdateOrInsert: from BankAccount::bank_slug
- CounterpartyRecomputeCommand: from Transaction::bankAccount->bank_slug
  (with eager-load on bankAccount to avoid N+1)

ResolverTest still passes — fixtures in bcp.json reproduce the
behavior the tests assert. Legacy 'CARD PAYMENT' prefix-strip
assertion dropped (banking prefixes are now data-driven; no
generic rules in v1).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 5: CLI commands

Four artisan commands give the operator the rule add/enable/disable/test workflow. Build the simple ones first, then the rich `add` command with `--from-transaction`.

### Task 11: spendula:counterparty:rules:test

**Files:**
- Create: `app/Console/Commands/Spendula/CounterpartyRulesTestCommand.php`
- Create: `tests/Feature/Commands/Spendula/CounterpartyRulesTestCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/Spendula/CounterpartyRulesTestCommandTest.php`:

```php
<?php

namespace Tests\Feature\Commands\Spendula;

use App\Services\Counterparty\Rules\RuleLoader;
use Tests\TestCase;

class CounterpartyRulesTestCommandTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/spendula-rules-test-cmd-'.uniqid();
        mkdir($this->tempDir, 0755, true);
        // Override binding to read from temp dir for these tests
        $this->app->instance(RuleLoader::class, new RuleLoader($this->tempDir));
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob("{$this->tempDir}/*") ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function writeRules(string $bank, array $rules): void
    {
        file_put_contents(
            "{$this->tempDir}/{$bank}.json",
            json_encode(['name' => $bank, 'rules' => $rules]),
        );
    }

    public function test_succeeds_when_all_fixtures_pass(): void
    {
        $this->writeRules('bcp', [
            [
                'name' => 'compra',
                'description' => 'card purchase',
                'pattern' => '/^COMPRA\\s+\\d+\\s+(.+)$/i',
                'replacement' => '$1',
                'tests' => [['in' => 'COMPRA 5962 SHOP', 'out' => 'SHOP']],
            ],
        ]);

        $this->artisan('spendula:counterparty:rules:test')
            ->expectsOutputToContain('Passed: 1')
            ->expectsOutputToContain('Failed: 0')
            ->assertSuccessful();
    }

    public function test_fails_when_a_fixture_fails(): void
    {
        $this->writeRules('bcp', [
            [
                'name' => 'broken',
                'description' => 'broken rule',
                'pattern' => '/^X$/',
                'replacement' => 'Y',
                'tests' => [['in' => 'X', 'out' => 'Z']],  // expected Z but rule produces Y
            ],
        ]);

        $this->artisan('spendula:counterparty:rules:test')
            ->expectsOutputToContain('FAIL')
            ->expectsOutputToContain('bcp/broken')
            ->assertFailed();
    }

    public function test_filters_by_bank_when_option_given(): void
    {
        $this->writeRules('bcp', [
            ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => 'Y', 'tests' => [['in' => 'X', 'out' => 'Y']]],
        ]);
        $this->writeRules('ing-ro', [
            ['name' => 'r2', 'description' => 'd', 'pattern' => '/^A$/', 'replacement' => 'B', 'tests' => [['in' => 'A', 'out' => 'C']]],  // failing
        ]);

        $this->artisan('spendula:counterparty:rules:test --bank=bcp')
            ->expectsOutputToContain('Passed: 1')
            ->assertSuccessful();
    }
}
```

- [ ] **Step 2: Run test, verify it fails (command doesn't exist yet)**

```bash
./vendor/bin/phpunit tests/Feature/Commands/Spendula/CounterpartyRulesTestCommandTest.php --colors=never 2>&1 | tail -8
```

Expected: failures with "Command 'spendula:counterparty:rules:test' is not defined".

- [ ] **Step 3: Implement the command**

Create `app/Console/Commands/Spendula/CounterpartyRulesTestCommand.php`:

```php
<?php

namespace App\Console\Commands\Spendula;

use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:counterparty:rules:test {--bank= : Optional bank slug to scope to.}')]
#[Description('Run every rule fixture in config/counterparty-rules-available/ (or scope to one bank).')]
class CounterpartyRulesTestCommand extends Command
{
    /**
     * Standalone fixture runner — same logic as the auto-discovered
     * RuleFixtureSelfTest, invokable without the test framework.
     * Useful when iterating on rules at the CLI without firing up
     * the full PHPUnit suite.
     *
     * Reads from available/ (not enabled/) so every shipped rule is
     * exercised regardless of operator enable state.
     */
    public function handle(RuleEngine $engine): int
    {
        $loader = new RuleLoader(base_path('config/counterparty-rules-available'));

        $bank = (string) $this->option('bank');
        $rulesByBank = $bank !== ''
            ? [$bank => $loader->forBank($bank)]
            : $loader->available();

        $passed = 0;
        $failed = 0;
        foreach ($rulesByBank as $slug => $rules) {
            foreach ($rules as $rule) {
                foreach ($rule->fixtures as $fixture) {
                    $actual = $engine->apply($fixture->input, [$rule]);
                    if ($actual === $fixture->expected) {
                        $passed++;
                    } else {
                        $failed++;
                        $this->error(sprintf(
                            'FAIL [%s/%s]: %s -> %s (expected %s)',
                            $slug,
                            $rule->name,
                            var_export($fixture->input, true),
                            var_export($actual, true),
                            var_export($fixture->expected, true),
                        ));
                    }
                }
            }
        }

        $this->line(sprintf('Passed: %d, Failed: %d', $passed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

```bash
./vendor/bin/phpunit tests/Feature/Commands/Spendula/CounterpartyRulesTestCommandTest.php --colors=never 2>&1 | tail -8
```

Expected: 3 tests, OK.

Note: The test rebinds RuleLoader, but the command reads from `available/` directly via `base_path()`. The test setup writes rule files to a temp dir and rebinds the loader. To make the command use the rebound loader, the command should accept a `RuleLoader` from DI rather than constructing its own.

Adjust the implementation: replace the manual `new RuleLoader(...)` with a constructor injection. Since artisan commands are resolved from the container, the rebound loader will be injected:

```php
public function handle(RuleLoader $loader, RuleEngine $engine): int
{
    // ... use $loader instead of constructing one
}
```

But the production case (no rebinding) needs the loader pointed at `available/` by default. The default binding in `AppServiceProvider` points at `enabled/`. To resolve this cleanly, accept the directory via an optional command option:

Actually, simpler: this command is the *test* runner. It should always read from `available/` (the canonical, shipped rules). Don't rely on DI. The test should override differently — write rules to the available/ dir.

Update the test setup to write to a different location and pass the path via env. Or: the test creates a real temp dir, writes files, and uses `--available-dir` flag.

Even simpler: the command takes an optional `--dir=<path>` option, defaulting to `base_path('config/counterparty-rules-available')`. Tests pass the temp dir.

Update the command signature:

```php
#[Signature('spendula:counterparty:rules:test {--bank= : Optional bank slug to scope to.} {--dir= : Override the available rule directory (testing only).}')]
```

```php
public function handle(RuleEngine $engine): int
{
    $dir = (string) $this->option('dir');
    if ($dir === '') {
        $dir = base_path('config/counterparty-rules-available');
    }
    $loader = new RuleLoader($dir);
    // ...
}
```

Update the test to pass `--dir=` option:

```php
$this->artisan('spendula:counterparty:rules:test --dir='.$this->tempDir)
    ->expectsOutputToContain('Passed: 1')
    ->...
```

Now the test works without container manipulation. Re-run:

```bash
./vendor/bin/phpunit tests/Feature/Commands/Spendula/CounterpartyRulesTestCommandTest.php --colors=never 2>&1 | tail -8
```

Expected: 3 tests, OK.

- [ ] **Step 5: Verify against real shipped rules**

```bash
php artisan spendula:counterparty:rules:test
```

Expected output: `Passed: <N>, Failed: 0` with `<N>` matching the total fixture count in bcp.json + ing-ro.json (12 + 2 = 14).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/Spendula/CounterpartyRulesTestCommand.php tests/Feature/Commands/Spendula/CounterpartyRulesTestCommandTest.php
git commit -m "$(cat <<'EOF'
feat(counterparty): spendula:counterparty:rules:test

Standalone fixture runner — iterates every rule in
config/counterparty-rules-available/ and asserts each rule's
fixtures resolve to the expected output through the engine.
Same logic as the auto-discovered RuleFixtureSelfTest, but
invokable from the CLI without firing up PHPUnit.

--bank=<slug> scopes to one rule file.
--dir=<path> overrides the available directory (testing only).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: spendula:counterparty:rules:enable / disable

**Files:**
- Create: `app/Console/Commands/Spendula/CounterpartyRulesEnableCommand.php`
- Create: `app/Console/Commands/Spendula/CounterpartyRulesDisableCommand.php`
- Create: `tests/Feature/Commands/Spendula/CounterpartyRulesEnableCommandTest.php`
- Create: `tests/Feature/Commands/Spendula/CounterpartyRulesDisableCommandTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Commands/Spendula/CounterpartyRulesEnableCommandTest.php`:

```php
<?php

namespace Tests\Feature\Commands\Spendula;

use Tests\TestCase;

class CounterpartyRulesEnableCommandTest extends TestCase
{
    public function test_creates_symlink_pointing_to_available_file(): void
    {
        $availableDir = base_path('config/counterparty-rules-available');
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $bank = 'bcp-test-'.uniqid();

        // Set up: a rule file exists in available/.
        file_put_contents("{$availableDir}/{$bank}.json", json_encode([
            'name' => 'Test', 'rules' => [],
        ]));

        $this->artisan("spendula:counterparty:rules:enable {$bank}")
            ->expectsOutputToContain('Enabled')
            ->assertSuccessful();

        $this->assertTrue(is_link("{$enabledDir}/{$bank}.json"));
        $this->assertSame(
            "../counterparty-rules-available/{$bank}.json",
            readlink("{$enabledDir}/{$bank}.json"),
        );

        // Cleanup
        unlink("{$enabledDir}/{$bank}.json");
        unlink("{$availableDir}/{$bank}.json");
    }

    public function test_idempotent_when_already_enabled(): void
    {
        $availableDir = base_path('config/counterparty-rules-available');
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $bank = 'bcp-test-'.uniqid();

        file_put_contents("{$availableDir}/{$bank}.json", json_encode(['name' => 'T', 'rules' => []]));
        symlink("../counterparty-rules-available/{$bank}.json", "{$enabledDir}/{$bank}.json");

        $this->artisan("spendula:counterparty:rules:enable {$bank}")
            ->expectsOutputToContain('already enabled')
            ->assertSuccessful();

        unlink("{$enabledDir}/{$bank}.json");
        unlink("{$availableDir}/{$bank}.json");
    }

    public function test_fails_when_no_available_file(): void
    {
        $bank = 'nonexistent-'.uniqid();

        $this->artisan("spendula:counterparty:rules:enable {$bank}")
            ->expectsOutputToContain('No rule file')
            ->assertFailed();
    }
}
```

Create `tests/Feature/Commands/Spendula/CounterpartyRulesDisableCommandTest.php`:

```php
<?php

namespace Tests\Feature\Commands\Spendula;

use Tests\TestCase;

class CounterpartyRulesDisableCommandTest extends TestCase
{
    public function test_removes_symlink_when_enabled(): void
    {
        $availableDir = base_path('config/counterparty-rules-available');
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $bank = 'bcp-test-'.uniqid();

        file_put_contents("{$availableDir}/{$bank}.json", json_encode(['name' => 'T', 'rules' => []]));
        symlink("../counterparty-rules-available/{$bank}.json", "{$enabledDir}/{$bank}.json");

        $this->artisan("spendula:counterparty:rules:disable {$bank}")
            ->expectsOutputToContain('Disabled')
            ->assertSuccessful();

        $this->assertFalse(file_exists("{$enabledDir}/{$bank}.json"));

        unlink("{$availableDir}/{$bank}.json");
    }

    public function test_idempotent_when_not_enabled(): void
    {
        $bank = 'not-enabled-'.uniqid();

        $this->artisan("spendula:counterparty:rules:disable {$bank}")
            ->expectsOutputToContain('not enabled')
            ->assertSuccessful();
    }

    public function test_refuses_to_remove_a_real_file_thats_not_a_symlink(): void
    {
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $bank = 'real-file-'.uniqid();

        file_put_contents("{$enabledDir}/{$bank}.json", '{}');

        $this->artisan("spendula:counterparty:rules:disable {$bank}")
            ->expectsOutputToContain('not a symlink')
            ->assertFailed();

        $this->assertTrue(file_exists("{$enabledDir}/{$bank}.json"));
        unlink("{$enabledDir}/{$bank}.json");
    }
}
```

- [ ] **Step 2: Run tests, verify they fail**

```bash
./vendor/bin/phpunit tests/Feature/Commands/Spendula/CounterpartyRulesEnableCommandTest.php tests/Feature/Commands/Spendula/CounterpartyRulesDisableCommandTest.php --colors=never 2>&1 | tail -8
```

Expected: failures (commands not defined).

- [ ] **Step 3: Implement EnableCommand**

Create `app/Console/Commands/Spendula/CounterpartyRulesEnableCommand.php`:

```php
<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:counterparty:rules:enable {bank : Bank slug (matches the basename of the rule file).}')]
#[Description('Enable a bank rule file by creating a symlink in config/counterparty-rules-enabled/.')]
class CounterpartyRulesEnableCommand extends Command
{
    public function handle(): int
    {
        $bank = (string) $this->argument('bank');
        $availableDir = base_path('config/counterparty-rules-available');
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $available = "{$availableDir}/{$bank}.json";
        $enabled = "{$enabledDir}/{$bank}.json";

        if (! is_file($available)) {
            $this->error("No rule file at {$available}");
            $this->line("Run 'spendula:counterparty:rules:add --bank={$bank}' first to create one.");
            return self::FAILURE;
        }

        if (file_exists($enabled)) {
            if (is_link($enabled)) {
                $this->info("Bank '{$bank}' is already enabled.");
                return self::SUCCESS;
            }
            $this->error("{$enabled} exists and is not a symlink — refusing to overwrite.");
            return self::FAILURE;
        }

        if (! symlink("../counterparty-rules-available/{$bank}.json", $enabled)) {
            $this->error("Failed to create symlink at {$enabled}.");
            return self::FAILURE;
        }

        $this->info("Enabled '{$bank}'.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Implement DisableCommand**

Create `app/Console/Commands/Spendula/CounterpartyRulesDisableCommand.php`:

```php
<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:counterparty:rules:disable {bank : Bank slug to disable.}')]
#[Description('Disable a bank rule file by removing the symlink in config/counterparty-rules-enabled/. Does not delete the available rule file.')]
class CounterpartyRulesDisableCommand extends Command
{
    public function handle(): int
    {
        $bank = (string) $this->argument('bank');
        $enabled = base_path("config/counterparty-rules-enabled/{$bank}.json");

        if (! file_exists($enabled) && ! is_link($enabled)) {
            $this->info("Bank '{$bank}' is not enabled.");
            return self::SUCCESS;
        }

        if (! is_link($enabled)) {
            $this->error("{$enabled} exists but is not a symlink — refusing to delete (might be a hand-edited rule file).");
            return self::FAILURE;
        }

        if (! unlink($enabled)) {
            $this->error("Failed to remove symlink at {$enabled}.");
            return self::FAILURE;
        }

        $this->info("Disabled '{$bank}'.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run tests, verify they pass**

```bash
./vendor/bin/phpunit tests/Feature/Commands/Spendula/CounterpartyRulesEnableCommandTest.php tests/Feature/Commands/Spendula/CounterpartyRulesDisableCommandTest.php --colors=never 2>&1 | tail -8
```

Expected: 6 tests, OK.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/Spendula/CounterpartyRulesEnableCommand.php app/Console/Commands/Spendula/CounterpartyRulesDisableCommand.php tests/Feature/Commands/Spendula/CounterpartyRulesEnableCommandTest.php tests/Feature/Commands/Spendula/CounterpartyRulesDisableCommandTest.php
git commit -m "$(cat <<'EOF'
feat(counterparty): spendula:counterparty:rules:enable / disable

Apache mods-style enable/disable via symlinks. enable creates
config/counterparty-rules-enabled/<bank>.json -> ../counterparty-
rules-available/<bank>.json, idempotent on re-run, fails if no
available file or if a real file (not symlink) is in the way.

disable removes the symlink. Idempotent if not enabled. Refuses
to delete real files (defensive — a hand-placed rule file should
never be silently removed).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: spendula:counterparty:rules:add (basic interactive)

The richer `--from-transaction` flow comes in Task 14; this task ships the simple interactive prompt + validation + write path.

**Files:**
- Create: `app/Console/Commands/Spendula/CounterpartyRulesAddCommand.php`
- Create: `tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php`:

```php
<?php

namespace Tests\Feature\Commands\Spendula;

use Tests\TestCase;

class CounterpartyRulesAddCommandTest extends TestCase
{
    private string $tempBank;
    private string $availablePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBank = 'addtest-'.uniqid();
        $this->availablePath = base_path("config/counterparty-rules-available/{$this->tempBank}.json");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->availablePath)) {
            unlink($this->availablePath);
        }
        parent::tearDown();
    }

    public function test_creates_new_rule_file_when_none_exists(): void
    {
        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'compra')
            ->expectsQuestion('Description', 'BCP card purchase')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^COMPRA\s+\d+\s+(.+)$/i')
            ->expectsQuestion('Replacement', '$1')
            ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
            ->expectsQuestion('Fixture input', 'COMPRA 5962 SHOP')
            ->expectsQuestion('Expected output', 'SHOP')
            ->expectsConfirmation('Save rule?', 'yes')
            ->expectsOutputToContain('Saved')
            ->assertSuccessful();

        $this->assertFileExists($this->availablePath);
        $data = json_decode(file_get_contents($this->availablePath), true);
        $this->assertCount(1, $data['rules']);
        $this->assertSame('compra', $data['rules'][0]['name']);
    }

    public function test_appends_to_existing_rule_file(): void
    {
        file_put_contents($this->availablePath, json_encode([
            'name' => 'Test Bank',
            'rules' => [
                ['name' => 'existing', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]));

        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'new-rule')
            ->expectsQuestion('Description', 'desc')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^Y$/')
            ->expectsQuestion('Replacement', 'Z')
            ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
            ->expectsQuestion('Fixture input', 'Y')
            ->expectsQuestion('Expected output', 'Z')
            ->expectsConfirmation('Save rule?', 'yes')
            ->assertSuccessful();

        $data = json_decode(file_get_contents($this->availablePath), true);
        $this->assertCount(2, $data['rules']);
        $this->assertSame('existing', $data['rules'][0]['name']);
        $this->assertSame('new-rule', $data['rules'][1]['name']);
    }

    public function test_refuses_to_save_when_fixture_fails(): void
    {
        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'broken')
            ->expectsQuestion('Description', 'd')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^X$/')
            ->expectsQuestion('Replacement', 'Y')
            ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
            ->expectsQuestion('Fixture input', 'X')
            ->expectsQuestion('Expected output', 'Z')  // wrong: rule produces Y, expected says Z
            ->expectsOutputToContain('Fixture failed')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->availablePath);
    }

    public function test_refuses_to_save_when_regex_does_not_compile(): void
    {
        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'broken')
            ->expectsQuestion('Description', 'd')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/[broken/')
            ->expectsOutputToContain('regex')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->availablePath);
    }

    public function test_refuses_to_save_when_rule_name_already_exists(): void
    {
        file_put_contents($this->availablePath, json_encode([
            'name' => 'Test',
            'rules' => [
                ['name' => 'compra', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]));

        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'compra')
            ->expectsOutputToContain('already')
            ->assertFailed();
    }
}
```

- [ ] **Step 2: Run tests, verify they fail**

```bash
./vendor/bin/phpunit tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php --colors=never 2>&1 | tail -8
```

Expected: failures (command not defined).

- [ ] **Step 3: Implement AddCommand (interactive flow only — Task 14 adds --from-transaction)**

Create `app/Console/Commands/Spendula/CounterpartyRulesAddCommand.php`:

```php
<?php

namespace App\Console\Commands\Spendula;

use App\Services\Counterparty\Rules\PostHook;
use App\Services\Counterparty\Rules\Rule;
use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleFixture;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:counterparty:rules:add
    {--bank= : Bank slug. Required if --from-transaction is not given.}
    {--from-transaction= : (Future) Pull a real remittance from a transaction id and pre-fill the prompt.}
')]
#[Description('Add a counterparty cleanup rule to a bank rule file. Validates regex + fixture before writing.')]
class CounterpartyRulesAddCommand extends Command
{
    public function handle(RuleEngine $engine): int
    {
        $bank = (string) $this->option('bank');
        if ($bank === '') {
            $this->error('--bank=<slug> is required.');
            return self::FAILURE;
        }

        $path = base_path("config/counterparty-rules-available/{$bank}.json");
        $existing = $this->loadExisting($path);

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
        $previous = set_error_handler(static fn () => true);
        $compileResult = @preg_match($pattern, '');
        set_error_handler($previous);
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

        $fixtureIn = (string) $this->ask('Fixture input');
        $fixtureOut = (string) $this->ask('Expected output');

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
                "Fixture failed: input %s produced %s, expected %s.",
                var_export($fixtureIn, true),
                var_export($actual, true),
                var_export($fixtureOut, true),
            ));
            return self::FAILURE;
        }

        $this->info('Fixture passes.');

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

        file_put_contents(
            $path,
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->info("Saved rule '{$name}' to {$path}.");
        return self::SUCCESS;
    }

    /**
     * @return array{name: string, rules: array<int, array<string, mixed>>}
     */
    private function loadExisting(string $path): array
    {
        if (! is_file($path)) {
            $bank = basename($path, '.json');
            return ['name' => $bank, 'rules' => []];
        }
        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['rules']) || ! is_array($data['rules'])) {
            return ['name' => basename($path, '.json'), 'rules' => []];
        }
        return ['name' => $data['name'] ?? basename($path, '.json'), 'rules' => $data['rules']];
    }
}
```

- [ ] **Step 4: Run tests, verify they pass**

```bash
./vendor/bin/phpunit tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php --colors=never 2>&1 | tail -8
```

Expected: 5 tests, OK.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/Spendula/CounterpartyRulesAddCommand.php tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php
git commit -m "$(cat <<'EOF'
feat(counterparty): spendula:counterparty:rules:add (interactive)

Interactive prompt: rule name, description, pattern, replacement,
post hooks, fixture in/out. Validates regex compiles, post hooks
are known, fixture passes through the engine — refuses to save if
any check fails.

Appends to existing config/counterparty-rules-available/<bank>.json
or creates a new file. Refuses to overwrite a rule with an existing
name. JSON output is pretty-printed with unescaped slashes/unicode
for readability and clean diffs.

--from-transaction stub present for now; the rich preview workflow
lands in the next task.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 14: --from-transaction with preview

Extends `rules:add` to pull a real remittance from a transaction by id, auto-derive the expected output by running the candidate pattern, and preview impact across other existing transactions before saving.

**Files:**
- Modify: `app/Console/Commands/Spendula/CounterpartyRulesAddCommand.php`
- Modify: `tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php` (add a `--from-transaction` test case)

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php`:

```php
public function test_from_transaction_pulls_remittance_and_previews_impact(): void
{
    // Seed: a bank, a bank account, a transaction. Use mock bank so we
    // don't depend on external schema beyond what's in master.
    $tx = \App\Models\Transaction::factory()->create([
        'raw_payload' => [
            'remittance_information' => ['COMPRA 5962 AIR SERBIA A21296337235 Belgrade RS'],
            'credit_debit_indicator' => 'DBIT',
        ],
    ]);
    $bankSlug = $tx->bankAccount->bank_slug;

    // Seed a second matching transaction so the preview shows >1 row.
    \App\Models\Transaction::factory()->create([
        'bank_account_id' => $tx->bank_account_id,
        'raw_payload' => [
            'remittance_information' => ['COMPRA 5962 AIR SERBIA A44027514935 Belgrade RS'],
            'credit_debit_indicator' => 'DBIT',
        ],
    ]);

    $this->artisan("spendula:counterparty:rules:add --from-transaction={$tx->id}")
        ->expectsOutputToContain('Raw remittance: COMPRA 5962 AIR SERBIA')
        ->expectsQuestion('Rule name (kebab-case)', 'air-serbia')
        ->expectsQuestion('Description', 'Air Serbia flight booking')
        ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^COMPRA\s+\d+\s+(AIR\s+SERBIA)\s+[A-Z]\d+\s+(.+)$/i')
        ->expectsQuestion('Replacement', '$1 $2')
        ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
        ->expectsOutputToContain('Auto-derived expected output: AIR SERBIA Belgrade RS')
        ->expectsConfirmation('Use this as the fixture?', 'yes')
        ->expectsOutputToContain('2 row(s) would change')
        ->expectsConfirmation('Save rule?', 'yes')
        ->assertSuccessful();

    // Cleanup: delete the rule file
    $availablePath = base_path("config/counterparty-rules-available/{$bankSlug}.json");
    if (file_exists($availablePath)) {
        $existing = json_decode(file_get_contents($availablePath), true);
        $existing['rules'] = array_values(array_filter(
            $existing['rules'] ?? [],
            fn ($r) => ($r['name'] ?? '') !== 'air-serbia',
        ));
        if (count($existing['rules']) === 0) {
            unlink($availablePath);
        } else {
            file_put_contents($availablePath, json_encode($existing));
        }
    }
}
```

This test uses Eloquent factories. You may need to add `use Illuminate\Foundation\Testing\RefreshDatabase;` at the top of the test class and add the trait to the class.

- [ ] **Step 2: Run test, verify it fails**

```bash
./vendor/bin/phpunit tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php::test_from_transaction_pulls_remittance_and_previews_impact --colors=never 2>&1 | tail -10
```

Expected: failure (`--from-transaction` not implemented yet).

- [ ] **Step 3: Implement --from-transaction**

Modify `app/Console/Commands/Spendula/CounterpartyRulesAddCommand.php` `handle()` to support the flag:

```php
// Near the top of handle(), after reading $bank option:
$txId = (string) $this->option('from-transaction');
$prefilledRemittance = null;

if ($txId !== '') {
    /** @var \App\Models\Transaction|null $tx */
    $tx = \App\Models\Transaction::with('bankAccount')->find($txId);
    if ($tx === null) {
        $this->error("No transaction found with id {$txId}.");
        return self::FAILURE;
    }
    $bank = $tx->bankAccount?->bank_slug ?? '';
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

// ... existing prompts for name, description, pattern, replacement, post ...
```

Then, between collecting the pattern and asking for fixture, when `$prefilledRemittance` is set:

```php
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
        return self::SUCCESS;
    }
    $fixtureIn = $prefilledRemittance;
    $fixtureOut = $derived;
} else {
    $fixtureIn = (string) $this->ask('Fixture input');
    $fixtureOut = (string) $this->ask('Expected output');
}
```

After the fixture passes and before the final save confirm, add the impact preview:

```php
if ($prefilledRemittance !== null) {
    // Preview: how many other transactions for this bank match the same pattern?
    $bankAccountIds = \App\Models\BankAccount::query()
        ->where('bank_slug', $bank)
        ->pluck('id');

    $impacted = 0;
    $samples = [];
    \App\Models\Transaction::query()
        ->whereIn('bank_account_id', $bankAccountIds)
        ->orderBy('id')
        ->chunk(500, function ($chunk) use (&$impacted, &$samples, $candidate, $engine) {
            foreach ($chunk as $row) {
                $rem = $row->raw_payload['remittance_information'][0] ?? null;
                if (! is_string($rem)) {
                    continue;
                }
                $resolved = $engine->apply($rem, [$candidate]);
                if ($resolved !== trim($rem)) {
                    $impacted++;
                    if (count($samples) < 5) {
                        $samples[] = "{$rem} -> {$resolved}";
                    }
                }
            }
        });

    $this->line("Preview impact on existing {$bank} transactions:");
    $this->line("  {$impacted} row(s) would change. First {$samples = (count($samples))} samples:");
    foreach ($samples as $s) {
        $this->line("    {$s}");
    }
}
```

Note the variable shadowing in the line above is incorrect — fix it:

```php
$this->line("  {$impacted} row(s) would change.");
if (count($samples) > 0) {
    $sampleCount = count($samples);
    $this->line("  First {$sampleCount} sample(s):");
    foreach ($samples as $s) {
        $this->line("    {$s}");
    }
}
```

- [ ] **Step 4: Run tests, verify they pass**

```bash
./vendor/bin/phpunit tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php --colors=never 2>&1 | tail -8
```

Expected: 6 tests, OK.

- [ ] **Step 5: PHPStan check**

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G 2>&1 | tail -3
```

Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/Spendula/CounterpartyRulesAddCommand.php tests/Feature/Commands/Spendula/CounterpartyRulesAddCommandTest.php
git commit -m "$(cat <<'EOF'
feat(counterparty): rules:add --from-transaction with preview

Pulls a real remittance from the DB by transaction id, pre-fills
the bank slug and remittance, auto-derives the expected fixture
output by running the candidate rule through the engine. After
fixture confirmation, previews how many existing transactions for
that bank would also be affected by the rule (with up to 5
samples). Operator confirms before save.

Workflow: see noisy payee in spendula:review -> grab transaction
id -> spendula:counterparty:rules:add --from-transaction=<id> ->
write pattern -> save. No copy-paste, no manual fixture authoring.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 6: Operator setup + docs

### Task 15: Enable bcp.json + ing-ro.json by default for the operator

**Files:**
- Modify: local `config/counterparty-rules-enabled/` (gitignored, so this is just local setup, not a commit)

- [ ] **Step 1: Enable both rule files**

```bash
php artisan spendula:counterparty:rules:enable bcp
php artisan spendula:counterparty:rules:enable ing-ro
```

Expected output: `Enabled 'bcp'.` and `Enabled 'ing-ro'.`

- [ ] **Step 2: Verify symlinks**

```bash
ls -la config/counterparty-rules-enabled/
```

Expected: two symlinks pointing to `../counterparty-rules-available/bcp.json` and `../counterparty-rules-available/ing-ro.json`, plus the `.gitignore` file.

- [ ] **Step 3: Verify recompute reproduces current state**

```bash
php artisan spendula:counterparty:recompute --bank=bcp --dry-run
```

Expected output: `Scanned 337 transaction(s) for bank='bcp'. level_changed=0 name_changed=0.` — the current DB state matches the rule engine's output exactly.

If `name_changed > 0`: a rule's behavior diverged from what's stored. Investigate which rule (use `spendula:counterparty:rules:test --bank=bcp`); fix the rule or the test fixture.

- [ ] **Step 4: No commit needed** — symlinks are gitignored.

---

### Task 16: Documentation updates

**Files:**
- Modify: `SUMMARY.md` (prepend a new "Latest task summary" entry)
- Modify: `README.md` (§14 Artisan commands table — add the four new commands)

- [ ] **Step 1: Update SUMMARY.md**

Prepend to `SUMMARY.md` (above the most recent entry):

```markdown
## Counterparty rule engine — JSON-driven, per-bank cleanup rules

### What changed

- Bank-specific cleanup patterns moved out of `Resolver.php` and
  into per-bank JSON rule files at
  `config/counterparty-rules-available/<bank>.json`. Operators
  enable rules via symlinks in
  `config/counterparty-rules-enabled/<bank>.json` (Apache mods-style).
- New `RuleEngine` + `RuleLoader` + `Rule` / `RuleFixture` value
  objects + `PostHook` finalizers under
  `app/Services/Counterparty/Rules/`.
- Resolver shrunk from ~290 lines to ~85 — L0/L1/L3/L4 ladder
  stays in code (universal); L2 delegates to the rule engine
  with the transaction's bank slug.
- Two rule files shipped: `bcp.json` (10 rules covering BCP's
  COMPRA / TRF / DD / PAGSERV / PAG BXVAL / LEV ATM / COM.MAN.CONTA
  shapes) and `ing-ro.json` (1 rule for ING RO Business
  structured remittance).
- Four CLI commands:
  `spendula:counterparty:rules:add` (interactive; supports
  `--from-transaction=<id>` to pull a real remittance, auto-derive
  fixture output, and preview impact on existing transactions
  before saving),
  `spendula:counterparty:rules:enable <bank>` (symlink),
  `spendula:counterparty:rules:disable <bank>` (unlink),
  `spendula:counterparty:rules:test [--bank=<slug>]` (standalone
  fixture runner, parallel of the auto-discovered phpunit test).
- Auto-discovered `RuleFixtureSelfTest` walks every rule's fixtures
  in every available rule file; runs as part of `vendor/bin/phpunit`.

### Assumptions made

- **Rule files at `config/counterparty-rules-available/`**: this
  directory is committed; new operators get the shipped rule
  library on checkout. The `enabled/` directory contains gitignored
  symlinks per operator preference.
- **Bank slug is per-transaction**: derived from
  `Transaction::bankAccount::bank_slug`. The Resolver accepts an
  optional `?string $bankSlug` parameter; null means no rules
  apply (trimmed remittance returned as-is).
- **`dedup_hash` independence**: unchanged from prior work — the
  hash uses raw EB fields, not the resolver's L2 output. Rule
  changes don't shift dedup hashes for existing rows.
- **Tests required for every rule**: validated at add-time (the
  CLI refuses to save a rule whose fixture doesn't pass) and at
  load-time (`RuleLoader` throws on empty `tests` array).

### Blast radius

- **`spendula:sync` path**: `MatchUpdateOrInsert` now passes
  `$bankAccount->bank_slug` to the resolver. The resolver looks up
  rules for that bank and applies the engine. Behavior on shipped
  banks (bcp, ing-ro) is preserved.
- **`spendula:counterparty:recompute --bank=bcp`** (dry-run on
  the operator's 337-row dataset) reports 0 changes — the rule
  engine reproduces the prior code-based resolver output exactly.
- **No effect on `dedup_hash`**: same as prior PRs.
- **No effect on existing tests**: `ResolverTest` continues to
  pass against the rule engine. `RuleFixtureSelfTest` adds new
  coverage for every rule's fixtures. Full suite green; PHPStan
  level 8 clean.

### Open threads

- **`_generic.json` for cross-bank cleanup**: deferred to v2.
  Per-bank rules cover all observed shapes today. If a truly
  cross-bank pattern appears, add a `_generic.json` (loaded for
  every transaction).
- **Rule shadowing detection**: deferred to v2. Operators rely on
  manual ordering (most-specific-first) plus the
  `--from-transaction` preview to spot ordering mistakes before
  saving.
- **`rules:list`, `rules:edit`, `rules:remove` CLI commands**:
  deferred to v2. JSON files are hand-editable; `phpunit` catches
  hand-edit breakage.
- **Bulk rule sharing**: deferred. The committed `available/`
  directory already serves as the canonical shared library; any
  operator adding a rule via `rules:add` can `git diff` to send
  the new rule upstream as a PR.

```

- [ ] **Step 2: Update README §14 Artisan commands table**

Open `README.md`. Find the section `## 14. Artisan commands` (around line 491). Add four rows under the "Implemented" table (alphabetical after `spendula:counterparty:recompute`):

```markdown
| `spendula:counterparty:rules:add [--bank=<slug>] [--from-transaction=<id>]` | Interactive: add a counterparty cleanup rule. Validates regex + fixture before saving. With `--from-transaction`, pulls a real remittance and previews impact on existing transactions. |
| `spendula:counterparty:rules:enable <bank>` | Enable a bank's cleanup rules by creating a symlink in `config/counterparty-rules-enabled/`. |
| `spendula:counterparty:rules:disable <bank>` | Disable a bank's cleanup rules (removes the symlink; doesn't delete the rule file). |
| `spendula:counterparty:rules:test [--bank=<slug>]` | Run every rule fixture in `config/counterparty-rules-available/`. Same logic as the auto-discovered phpunit test. |
```

- [ ] **Step 3: Run the full test suite + phpstan one final time**

```bash
./vendor/bin/phpunit --colors=never 2>&1 | tail -3 && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G 2>&1 | tail -3
```

Expected: full suite green, PHPStan clean.

- [ ] **Step 4: Commit**

```bash
git add SUMMARY.md README.md
git commit -m "$(cat <<'EOF'
docs: counterparty rule engine — SUMMARY + README

SUMMARY.md gets a fresh latest-task entry covering the rule engine
architecture, shipped rule files (bcp.json, ing-ro.json), CLI
commands, and the deferred v2 features. README.md §14 Artisan
commands gets four new rows for rules:add, rules:enable,
rules:disable, rules:test.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Final verification

### Task 17: Smoke test the full flow

- [ ] **Step 1: Verify rules are enabled and tests pass**

```bash
ls -la config/counterparty-rules-enabled/  # should show bcp + ing-ro symlinks
php artisan spendula:counterparty:rules:test
```

Expected: `Passed: <N>, Failed: 0` with N matching the total fixture count across both files.

- [ ] **Step 2: Verify recompute is a no-op against existing data**

```bash
php artisan spendula:counterparty:recompute --bank=bcp --dry-run
```

Expected: `name_changed=0`.

- [ ] **Step 3: Verify full PHPUnit + PHPStan**

```bash
./vendor/bin/phpunit 2>&1 | tail -3
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G 2>&1 | tail -3
```

Expected: full suite green; PHPStan clean.

- [ ] **Step 4: Push the branch and open the PR**

```bash
git push -u origin counterparty-rule-engine
gh pr create --title "feat(counterparty): JSON-driven per-bank rule engine" --body "$(cat <<'EOF'
## Summary

- Bank-specific cleanup patterns moved out of `Resolver.php` into
  per-bank JSON rule files at `config/counterparty-rules-available/<bank>.json`.
  Operators symlink the ones they want into `config/counterparty-rules-enabled/`
  (Apache mods-style).
- New `RuleEngine` + `RuleLoader` + value objects under
  `app/Services/Counterparty/Rules/`. Resolver shrinks from ~290
  lines to ~85; L0/L1/L3/L4 stays in code, L2 delegates to the engine.
- Four artisan commands: `rules:add` (with `--from-transaction=<id>`
  for the golden path), `rules:enable`, `rules:disable`, `rules:test`.
- Auto-discovered `RuleFixtureSelfTest` runs every rule's fixtures
  as part of `vendor/bin/phpunit`.

Supersedes (and closes) #26 — its trailing-reference and embedded-id
patterns become rule entries, not code.

## Real-data verification

- `spendula:counterparty:recompute --bank=bcp --dry-run`:
  `name_changed=0` (the rule engine reproduces the prior code-based
  resolver output exactly on the operator's 337 BCP transactions).
- `spendula:counterparty:rules:test`: all fixtures pass.

## Test plan

- [ ] `vendor/bin/phpunit` — full suite green
- [ ] `vendor/bin/phpstan analyse --memory-limit=1G` — level 8 clean
- [ ] `php artisan spendula:counterparty:rules:test` — every fixture passes
- [ ] `php artisan spendula:counterparty:recompute --bank=bcp --dry-run` — no diffs
- [ ] Eyeball a single fresh sync: `php artisan spendula:sync --bank=bcp` and confirm new rows resolve through the engine

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Closes #26 (the trailing/embedded patterns from #26 are reframed as rule data in `bcp.json`).

---

## Self-review

**Spec coverage:**

- ✓ Architecture (Resolver delegates L2 to engine) → Task 10
- ✓ Rule schema (name, description, pattern, replacement, post hooks, tests) → Task 1
- ✓ Engine semantics (terminal match, post-processing, no-match → trimmed) → Task 3
- ✓ Validation layers (add-time, test-time, load-time) → Tasks 5, 9, 13
- ✓ CLI v1 (add, enable, disable, test) → Tasks 11–14
- ✓ `--from-transaction` workflow → Task 14
- ✓ File layout (`config/counterparty-rules-available/`, `config/counterparty-rules-enabled/`) → Task 6
- ✓ Migration plan (BCP patterns → bcp.json, ING → ing-ro.json, Resolver refactor) → Tasks 7, 8, 10
- ✓ Auto-discovered fixture self-test → Task 9

**Placeholder scan:** No "TBD", "TODO", or vague language. Every step has the actual code/command needed.

**Type consistency:** `Rule`, `RuleFixture`, `RuleEngine`, `RuleLoader`, `RuleValidationException`, `PostHook` referenced consistently across all tasks. Method names: `forBank()`, `available()`, `apply()`, `known()` consistent. JSON field names (`name`, `description`, `pattern`, `replacement`, `post`, `tests`, `in`, `out`) consistent across schema, parser, fixtures, and CLI prompts.

**Scope:** Single coherent implementation plan for a single PR. No subsystem decomposition needed.
