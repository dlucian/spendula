<?php

namespace App\Console\Commands\Spendula;

use App\Models\PayeeRule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:rules:list
    {--bank= : Filter to a single bank slug.}
')]
#[Description('List per-(bank, payee) auto-decision rules (GH #39).')]
class RulesListCommand extends Command
{
    /**
     * Print every payee_rules row in a fixed-column table:
     * id  bank_slug  counterparty_name  action  skip_reason. The id
     * column is the full UUID — operators copy/paste it into
     * `spendula:rules:delete <id>` to remove a rule.
     */
    public function handle(): int
    {
        $bank = (string) $this->option('bank');

        $query = PayeeRule::query()->orderBy('bank_slug')->orderBy('counterparty_name');
        if ($bank !== '') {
            $query->where('bank_slug', $bank);
        }

        $rules = $query->get();
        if ($rules->isEmpty()) {
            $this->info($bank === ''
                ? 'No payee rules recorded yet.'
                : "No payee rules for bank '{$bank}'.");

            return self::SUCCESS;
        }

        // Plain TSV-style output keeps the line testable via
        // expectsOutputToContain — Laravel's $this->table() routes
        // through Symfony's table renderer which the testing harness
        // doesn't surface to the line-output assertion buffer.
        foreach ($rules as $rule) {
            $this->line(sprintf(
                '%s  %s  %s  %s%s',
                $rule->id,
                $rule->bank_slug,
                $rule->counterparty_name,
                $rule->action->value,
                $rule->skip_reason !== null ? "  ({$rule->skip_reason})" : '',
            ));
        }

        return self::SUCCESS;
    }
}
