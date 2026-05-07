# Counterparty rule engine — design

Date: 2026-05-06
Status: design, awaiting approval

## Why

The current `Resolver` hard-codes BCP-specific cleanup patterns (DD shape
extractor, LEV ATM, COM.MAN.CONTA, COMPRA prefix, AIR SERBIA embedded id,
etc.) directly in PHP code. Two consequences make this approach brittle
as the project goes open-source:

1. **Each new noisy string requires a code change + test + PR.** The
   recent codex-review loops on PR #25 and #26 spent six and four
   rounds respectively defending Portuguese-bank-specific regexes
   against hypothetical edge cases for other operators' banks.
2. **Bank-specific rules pollute upstream.** A Danish operator who
   forks Spendula for their own use carries the entire Portuguese
   ruleset — none of it applies. They'd want to start with an empty
   ruleset and add their own.

The fix is to move bank-specific cleanup out of code and into per-bank
JSON rule files. Rule files ship with the repo as opt-in
"available" modules; operators symlink the ones they want into an
"enabled" directory (Apache mods-style). New noisy strings turn into
one-line rule additions via an artisan command — no code change, no
PR, no codex round.

## Architecture

```
+---------+
| sync    | -- raw_payload --> Resolver
+---------+                       |
                              L0/L1: debtor/creditor extraction
                                     (pure code, universal)
                                     |
                              L2: rule engine
                                     |
                                     +--- load enabled rule files for bank
                                     +--- apply rules in array order
                                     +--- first terminal match wins
                                     +--- no match -> trimmed remittance
                                     |
                              L3: additional_information fallback
                                     (pure code, universal)
                                     |
                              L4: "(Unknown)"
```

**Components**

- `app/Services/Counterparty/Resolver.php` — slim orchestrator. L0/L1/L3/L4
  stay in code (universal logic); L2 delegates to the rule engine.
- `app/Services/Counterparty/RuleEngine.php` — applies a list of rules to a
  remittance string and returns the resolved name (or null if none match).
- `app/Services/Counterparty/RuleLoader.php` — scans
  `config/counterparty-rules-enabled/`, parses each JSON file, compiles
  regexes, and builds a map from bank slug to rule list. Loaded once per
  process; load-time fatal on malformed regex or missing required field.
- `config/counterparty-rules-available/<bank>.json` — committed rule
  files, one per bank. Repo ships with whatever the maintainers
  curated.
- `config/counterparty-rules-enabled/<bank>.json` — gitignored
  symlinks to `../counterparty-rules-available/<bank>.json`.
  Operator manages via `spendula:counterparty:rules:enable <bank>` /
  `disable <bank>`.

## Rule file schema

```json
{
  "name": "Millennium BCP (Portugal)",
  "rules": [
    {
      "name": "lev-atm-with-location",
      "description": "BCP ATM withdrawal — extract location, drop cardholder echo",
      "pattern": "/^LEV\\s+ATM\\s+\\d+\\s+\\d+\\s+(.+?)\\s{4,}\\S.*$/iu",
      "replacement": "ATM $1",
      "post": ["collapse"],
      "tests": [
        {"in": "LEV ATM 5962 703   LISBOA        Mario Nunes E", "out": "ATM LISBOA"},
        {"in": "LEV ATM 5962 703   VILA  NOVA        Mario Nunes E", "out": "ATM VILA NOVA"}
      ]
    }
  ]
}
```

**Required fields per rule**

- `name` (string, kebab-case, unique within the file) — identifier for
  CLI references and error messages.
- `description` (string) — one-line human summary; shows up in
  `rules:list` and in failed-fixture error output.
- `pattern` (string) — full PCRE pattern including delimiters and
  flags. Compiled once at load time.
- `replacement` (string) — `preg_replace` template. Backreferences
  via `$1`, `$2`, etc. Empty string is allowed (pure strip).
- `tests` (array, non-empty) — at least one fixture
  `{"in": "...", "out": "..."}` exercising the rule against real
  data. Validates the rule does what it claims; doubles as
  documentation. Empty array is rejected at load time.

**Optional fields**

- `post` (array of strings) — named finalizers applied to the result
  after `preg_replace`. v1 ships two: `trim` (strip leading/trailing
  whitespace and the small punctuation set ` \t\n\r-_.,;:`),
  `collapse` (collapse internal whitespace runs to single space).
  Applied in array order.

**Rule semantics**

- All rules are **terminal**: first regex match in the file wins,
  engine returns the (post-processed) replacement, no further rules
  evaluated.
- Specific rules go above general rules — the operator orders by
  specificity.
- Patterns include their own anchors (`^`/`$`) where appropriate;
  the engine does not add them.
- The engine never modifies the input remittance with non-matching
  rules; either a rule fires terminally or the engine returns the
  trimmed raw remittance unchanged.

## Engine flow

```
function resolveRemittance(remittance, bankSlug):
    rules = RuleLoader::forBank(bankSlug)   // [] if no file enabled

    for rule in rules:
        if preg_match(rule.pattern, remittance, matches):
            result = preg_replace(rule.pattern, rule.replacement, remittance)
            for hook in rule.post:
                result = applyPostHook(hook, result)
            result = result.trim()
            if result != '':
                return result
            // Empty after post-processing: try next rule.

    return remittance.trim()
```

**No match → trimmed remittance.** Preserves the current Resolver's
behaviour: clean remittances like `EXAMPLE COMPANY  SRL` pass through
intact, noisy ones like `SUNSETFITGYM   2010           PT81118656`
also pass through intact (visible to the operator, who can write a
rule if it bothers them). Empty remittance still falls through to
L3 (`additional_information`) and L4 ("(Unknown)").

**Empty result after post-processing → continue to next rule.** Edge
case: a rule whose replacement template + post hooks yield the empty
string. Rather than returning empty (which would mask the
counterparty), the engine treats this as a no-match and tries
subsequent rules. If all rules empty out, the trimmed-remittance
default applies. The fixture validation at add-time and test-time
should catch this for any rule whose intent is to produce a non-
empty payee.

**Always L2.** Whatever the engine returns is `level=2`. The
remittance-derivation came from level 2 of the SPEC §6.8 ladder;
the rule engine is just a more flexible implementation of that
level.

## Validation layers

Three checkpoints, each with a clear failure mode. Trust the JSON
between them.

| Layer | When | Behaviour |
|---|---|---|
| **Add-time** | `rules:add` | Compile regex, run supplied fixture(s). Refuse to write file if either fails. |
| **Load-time** | First resolver use per process | Compile every regex. Malformed regex → fatal, command refuses to run. |
| **Test-time** | `phpunit` | Auto-discovered `RuleFixtureTest` iterates all enabled rules, asserts every fixture. CI / pre-commit safety net. |

Review start does **not** re-test rules — review reads
`counterparty_name` from the row (set at sync time), so the rule
engine isn't even invoked. Re-testing there would be belt-and-
suspenders for no real win.

## CLI surface (v1)

| Command | Purpose |
|---|---|
| `spendula:counterparty:rules:add [--bank=slug] [--from-transaction=id]` | Interactive: prompts for name, pattern, replacement, fixture in/out. Validates compile + fixture pass. With `--from-transaction`, pulls raw remittance from a real DB row, previews how many other transactions the rule would affect. Writes to `config/counterparty-rules-available/<bank>.json` (or appends if file exists). |
| `spendula:counterparty:rules:enable <bank>` | Create symlink `config/counterparty-rules-enabled/<bank>.json` → `../counterparty-rules-available/<bank>.json`. Idempotent. |
| `spendula:counterparty:rules:disable <bank>` | Remove the symlink. Doesn't delete the available rule file. |
| `spendula:counterparty:rules:test [--bank=slug]` | Standalone fixture runner — same logic as the auto-discovered phpunit test, invokable without the test framework. |

**Deferred to v2** (JSON file is hand-editable in the meantime): `rules:list`, `rules:edit`, `rules:remove`.

### `--from-transaction` workflow

```
$ php artisan spendula:counterparty:rules:add --bank=bcp --from-transaction=01abc...
Raw remittance: COMPRA 5962 AIR SERBIA A21296337235 Belgrade RS
> Rule name: air-serbia-flight
> Description: AIR SERBIA flight bookings via COMPRA — drop A<id>
> Pattern: /^COMPRA\s+\d{3,5}\s+(AIR\s+SERBIA)\s+[A-Z]\d{8,}\s+(.+?)(?:\s+CONTACTLESS)?\s*$/i
> Replacement: $1 $2
> Post hooks (comma-separated, blank for none): 
✓ Fixture passes (input → "AIR SERBIA Belgrade RS").

Preview impact on existing BCP transactions:
  7 rows would change:
    COMPRA 5962 AIR SERBIA A44027514935 Belgrade RS → AIR SERBIA Belgrade RS
    COMPRA 5962 AIR SERBIA A21296337224 Belgrade RS → AIR SERBIA Belgrade RS
    ...

Save rule? [Y/n]
```

The "input → expected" fixture is captured automatically from the
raw remittance + the regex result; the operator just confirms.

## File layout

```
config/
  counterparty-rules-available/
    .gitkeep
    bcp.json                    (committed; ships with repo)
    ...
  counterparty-rules-enabled/
    .gitignore                  (* but !.gitignore)
    bcp.json -> ../counterparty-rules-available/bcp.json   (operator-managed symlink)
```

The `enabled/` symlinks are gitignored so operators don't accidentally
commit each other's preferences. `available/` is committed.

## Migration plan

Single PR — supersedes (and closes) PR #26, which becomes obsolete
since its trailing-reference and embedded-id patterns are reframed
as data rules.

1. **Add the engine + loader + validation classes.** No behaviour
   change yet — the new code is unused.
2. **Create `config/counterparty-rules-available/bcp.json`** with all
   current Resolver patterns ported to rules: COMPRA card-purchase,
   TRF transfer-to-person, DD-with-reference shape, DD fall-through,
   LEV ATM extract, LEV ATM fallback, PAGSERV (with EPD-specific
   rule above generic), PAG BXVAL- Via Verde, COM.MAN.CONTA, AIR
   SERBIA flight, plus CONTACTLESS suffix where it can't be folded
   into a shape rule. Each rule ships with at least one fixture
   from real BCP data.
3. **Refactor `Resolver::resolve()`** so the L2 path calls
   `RuleEngine::apply($remittance, $bankSlug)`. L0/L1/L3/L4 logic
   stays unchanged.
4. **Drop the BCP-specific code constants** (`REMITTANCE_PREFIX_PATTERNS`,
   `REMITTANCE_TRIVIAL_SUFFIX_PATTERNS`,
   `REMITTANCE_REFERENCE_SUFFIX_PATTERNS`, `REMITTANCE_EMBEDDED_PATTERNS`,
   `BCP_DD_WITH_REFERENCE_PATTERN`, `BCP_LEV_ATM_PATTERN`,
   `BCP_FORMATTED_LINE_PATTERN`, `ING_STRUCTURED_PATTERN`,
   `extractFromLevAtm`, `extractFromDdWithReference`,
   `extractFromStructured`, `stripPrefixes`, `stripSuffixes`,
   `stripEmbeddedReferences`).
5. **Existing `ResolverTest` cases** stay green throughout — they
   exercise behaviour, not internals. Each previously-passing test
   becomes a smoke test that the rules engine + bcp.json produce
   the same output.
6. **Add `RuleFixtureTest`** that auto-discovers and runs every
   rule's `tests[]` block.
7. **Add the four CLI commands** plus the `--from-transaction`
   workflow.
8. **Enable bcp.json by default** for the operator (create the
   symlink as part of the migration, since the operator's existing
   data assumes BCP rules are active).

ING-RO structured extraction (currently `extractFromStructured`)
becomes the only rule in `ing-ro.json` (shipped, enabled if the
operator runs ING). Mock ASPSP gets no rule file (no cleanup needed).

## Risks & open questions

- **Regex performance**: per-resolve regex evaluation across 10-20
  rules per bank is fine. PCRE is fast and the total transaction
  volume is tiny (single-operator, per-day batch). No caching needed
  in v1.
- **Rule ordering correctness**: operators must put specific rules
  above general ones. The `--from-transaction` preview helps catch
  ordering mistakes (if a more-general rule fires first, the preview
  shows the wrong result and the operator notices before saving).
  v2 could add a "rule shadow detection" check that warns when a
  later rule could never fire because an earlier one always wins.
- **Symlink portability**: works on macOS / Linux. Spendula is
  documented as macOS-dev / Linux-prod, so this is fine. Windows
  developers (none expected) would need to use the
  `mklink /D` equivalent or run via WSL.
- **Rule file syntax**: JSON is fine. JSON-with-comments / YAML
  considered and rejected — JSON is universal, parses out of the
  box, and the lack of comments forces the `description` field to
  earn its keep.
- **What about non-bank-scoped rules?** v1 has none — every rule is
  per-bank. If a truly cross-bank cleanup ever appears, add a
  `_generic.json` (loaded for every transaction) at that point.
  YAGNI for now.

## Out of scope (v2 candidates)

- Rule shadowing detection
- `rules:list`, `rules:edit`, `rules:remove` CLI commands
- Bulk rule import/export (e.g. for sharing curated rulesets)
- Rule precedence groups / priorities (current: array order is
  enough)
- Rule comments / metadata for traceability (e.g.
  `created_at`, `created_by`)
