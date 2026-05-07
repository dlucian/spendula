<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:counterparty:rules:disable {bank : Bank slug to disable.}')]
#[Description('Disable a bank rule file by removing the symlink in config/counterparty-rules-enabled/. Does not delete the available rule file.')]
class CounterpartyRulesDisableCommand extends Command
{
    public function handle(): int
    {
        $bank = (string) $this->argument('bank');
        $enabled = base_path("config/counterparty-rules-enabled/{$bank}.json");

        if (! file_exists($enabled) && ! is_link($enabled)) {
            $this->info("Bank '{$bank}' is not enabled.");

            return self::SUCCESS;
        }

        if (! is_link($enabled)) {
            $this->error("{$enabled} exists but is not a symlink — refusing to delete (might be a hand-edited rule file).");

            return self::FAILURE;
        }

        if (! unlink($enabled)) {
            $this->error("Failed to remove symlink at {$enabled}.");

            return self::FAILURE;
        }

        $this->info("Disabled '{$bank}'.");

        return self::SUCCESS;
    }
}
