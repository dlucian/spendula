<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:tracking:snapshot {--account= : Spendula bank_account UUID; omit to snapshot all tracking accounts}')]
#[Description('Compute and push tracking-account balance snapshots to YNAB.')]
class TrackingSnapshotCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:tracking:snapshot — not yet implemented (phase 3).');

        return self::SUCCESS;
    }
}
