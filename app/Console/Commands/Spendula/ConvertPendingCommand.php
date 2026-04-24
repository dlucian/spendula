<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:convert-pending')]
#[Description('Retry failed currency conversions for tracking-account transactions.')]
class ConvertPendingCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:convert-pending — not yet implemented (phase 4).');

        return self::SUCCESS;
    }
}
