<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:review {--bulk-approve-trivial : Auto-approve rows whose resolution level ≤ 1 and currency matches SPENDULA_BASE_CURRENCY}')]
#[Description('Interactive CLI queue: Approve / Skip / Transfer fetched transactions.')]
class ReviewCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:review — not yet implemented (phase 1).');

        return self::SUCCESS;
    }
}
