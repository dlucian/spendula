<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:accounts:seed-mock
    {--bank-account-id= : Spendula bank_account UUID to map}
    {--ynab-account-id= : YNAB account UUID to map to}
    {--ynab-account-type=on_budget : on_budget or tracking}
    {--display-name= : Human-readable label stored on the bank_account row}
    {--import-cutoff-date= : YYYY-MM-DD; transactions before this date auto-skip}
')]
#[Description('One-off phase-1 mapper: wire a Spendula bank_account to a YNAB account.')]
class AccountsSeedMockCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:accounts:seed-mock — not yet implemented (phase 1).');

        return self::SUCCESS;
    }
}
