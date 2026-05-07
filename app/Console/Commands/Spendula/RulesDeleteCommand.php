<?php

namespace App\Console\Commands\Spendula;

use App\Models\PayeeRule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:rules:delete
    {id : The UUID of the payee_rules row to delete.}
')]
#[Description('Hard-delete a payee_rules row by id (GH #39).')]
class RulesDeleteCommand extends Command
{
    /**
     * Hard-delete one rule. There is no soft-delete / superseded path
     * (per DECISIONS.md): rules are operator-managed metadata, not a
     * regulated audit trail.
     *
     * Returns SUCCESS even when the row was found and removed; returns
     * FAILURE only when the id does not match an existing row, so
     * scripts can distinguish "did nothing" from "deleted".
     */
    public function handle(): int
    {
        $id = (string) $this->argument('id');

        $rule = PayeeRule::query()->whereKey($id)->first();
        if ($rule === null) {
            $this->error("No rule found with id '{$id}'.");

            return self::FAILURE;
        }

        $rule->delete();
        $this->info("Deleted rule '{$rule->bank_slug} → {$rule->counterparty_name}' (was: {$rule->action->value}).");

        return self::SUCCESS;
    }
}
