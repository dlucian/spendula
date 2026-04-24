<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:push')]
#[Description('Push approved/transfer transactions to YNAB via /plans/{plan_id}/transactions.')]
class PushCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:push — not yet implemented (phase 1).');

        return self::SUCCESS;
    }
}
