<?php

namespace App\Console\Commands\Spendula;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:counterparty:rules:enable
    {bank? : Bank slug to enable. Omit when --all is given.}
    {--all : Enable every rule file in config/counterparty-rules-available/ that isn\'t already enabled.}
')]
#[Description('Enable a bank rule file by creating a symlink in config/counterparty-rules-enabled/.')]
class CounterpartyRulesEnableCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->enableAll();
        }

        $bank = (string) $this->argument('bank');
        if ($bank === '') {
            $this->error('Provide a bank slug or use --all to enable every available rule file.');

            return self::FAILURE;
        }

        return $this->enableOne($bank);
    }

    private function enableAll(): int
    {
        $availableDir = base_path('config/counterparty-rules-available');
        $files = glob("{$availableDir}/*.json") ?: [];
        $enabled = 0;
        foreach ($files as $file) {
            $bank = basename($file, '.json');
            if (str_starts_with($bank, '.')) {
                continue;
            }
            $result = $this->enableOne($bank, quiet: true);
            if ($result === self::FAILURE) {
                // enableOne already printed an error
                return self::FAILURE;
            }
            if ($result === self::SUCCESS) {
                $enabled++;
            }
        }

        $this->info("Enabled {$enabled} rule file(s).");

        return self::SUCCESS;
    }

    private function enableOne(string $bank, bool $quiet = false): int
    {
        $availableDir = base_path('config/counterparty-rules-available');
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $available = "{$availableDir}/{$bank}.json";
        $enabled = "{$enabledDir}/{$bank}.json";

        if (! is_file($available)) {
            $this->error("No rule file at {$available}");
            $this->line("Run 'spendula:counterparty:rules:add --bank={$bank}' first to create one.");

            return self::FAILURE;
        }

        if (file_exists($enabled)) {
            if (is_link($enabled)) {
                if (! $quiet) {
                    $this->info("Bank '{$bank}' is already enabled.");
                }

                return self::SUCCESS;
            }
            $this->error("{$enabled} exists and is not a symlink — refusing to overwrite.");

            return self::FAILURE;
        }

        if (! symlink("../counterparty-rules-available/{$bank}.json", $enabled)) {
            $this->error("Failed to create symlink at {$enabled}.");

            return self::FAILURE;
        }

        if (! $quiet) {
            $this->info("Enabled '{$bank}'.");
        }

        return self::SUCCESS;
    }
}
