# Counterparty rule sets

This directory holds the example rule sets shipped with Spendula. The
`Resolver` consults rules to clean up noisy counterparty strings before
sending them to YNAB.

## Directory layout

- `config/counterparty-rules-available/` — example rule sets (this dir).
  Filename = bank slug, e.g. `bcp.json` applies to bank slug `bcp`.
- `config/counterparty-rules-enabled/` — symlinks (or copies) of the
  rule files the operator wants active. The contents of this dir are
  gitignored; each operator manages it locally.

The `RuleLoader` reads from `counterparty-rules-enabled/` in production.
The `available/` directory exists so contributors can ship rule fixtures
without forcing every operator to consume them.

## Enabling a rule set

```bash
cd config/counterparty-rules-enabled
ln -s ../counterparty-rules-available/<bank>.json .
```

Or, if you prefer copies over symlinks:

```bash
cp config/counterparty-rules-available/<bank>.json config/counterparty-rules-enabled/
```

## Disclaimer

The shipped example sets (`bcp.json`, `revolut.json`,
`ing-ro-business.json`, `ing-ro-personal.json`) reflect real EB
production observations from one operator's setup. They are useful
starting points if you bank with those institutions; they are not
required and are not endorsements. Adapt, replace, or ignore as
appropriate.

`ing-ro-personal.json` is a symlink to `ing-ro-business.json`: ING
Romania uses the same remittance envelope on personal and business
accounts.

See `docs/SPEC.md` (§ counterparty resolution) for the rule schema and
the available `post` hooks.
