<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:auth:start {bank_slug?}')]
#[Description('Begin an Enable Banking consent flow for a bank; prints the consent URL.')]
class AuthStartCommand extends Command
{
    public function handle(): int
    {
        $this->warn('spendula:auth:start — not yet implemented (phase 1).');

        return self::SUCCESS;
    }
}
