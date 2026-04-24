<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:accounts:map')]
#[Description('Interactively map Spendula bank accounts to YNAB accounts.')]
class AccountsMapCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:accounts:map — not yet implemented (phase 2). Use spendula:accounts:seed-mock in phase 1.');

        return self::SUCCESS;
    }
}
