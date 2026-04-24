<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:banks:sync')]
#[Description('Reconcile the banks table with config/spendula-banks.php.')]
class BanksSyncCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:banks:sync — not yet implemented (phase 1).');

        return self::SUCCESS;
    }
}
