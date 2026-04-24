<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:status')]
#[Description('Dashboard: consent expiry, queued transactions, last sync/push times.')]
class StatusCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:status — not yet implemented (phase 4).');

        return self::SUCCESS;
    }
}
