<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:sync {--bank= : Limit sync to a single bank slug}')]
#[Description('Fetch new transactions from Enable Banking into Spendula.')]
class SyncCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:sync — not yet implemented (phase 1).');

        return self::SUCCESS;
    }
}
