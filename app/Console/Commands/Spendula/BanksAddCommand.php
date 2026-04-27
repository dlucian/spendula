<?php

namespace App\Console\Commands\Spendula;

use App\Enums\PsuType;
use App\Models\Bank;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:banks:add
    {--slug= : Stable internal identifier (lowercase, ascii). Used as banks.slug.}
    {--display-name= : Human-readable label shown in command output.}
    {--aspsp-name= : Exact ASPSP name as returned by Enable Banking /aspsps. Case-sensitive.}
    {--aspsp-country= : ISO 3166-1 alpha-2 country code, e.g. PT, RO, LT.}
    {--psu-type=personal : personal or business.}
    {--default-currency= : ISO 4217, e.g. EUR, RON.}
    {--sync-lookback-days=90 : Days of history to pull on first sync (max 90 per EB).}
')]
#[Description('Add an operator bank directly into the banks table. Operator banks never appear in source.')]
class BanksAddCommand extends Command
{
    public function handle(): int
    {
        $slug = (string) $this->option('slug');
        $displayName = (string) $this->option('display-name');
        $aspspName = (string) $this->option('aspsp-name');
        $aspspCountry = strtoupper((string) $this->option('aspsp-country'));
        $psuTypeInput = (string) $this->option('psu-type');
        $currency = strtoupper((string) $this->option('default-currency'));
        $lookbackInput = (string) $this->option('sync-lookback-days');

        foreach (['slug' => $slug, 'display-name' => $displayName, 'aspsp-name' => $aspspName, 'aspsp-country' => $aspspCountry, 'default-currency' => $currency] as $name => $value) {
            if ($value === '') {
                $this->error("--{$name} is required.");

                return self::FAILURE;
            }
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            $this->error("--slug must be lowercase ascii (letters, digits, hyphens). Got '{$slug}'.");

            return self::FAILURE;
        }

        if (preg_match('/^[A-Z]{2}$/', $aspspCountry) !== 1) {
            $this->error("--aspsp-country must be a 2-letter ISO code. Got '{$aspspCountry}'.");

            return self::FAILURE;
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $this->error("--default-currency must be a 3-letter ISO 4217 code. Got '{$currency}'.");

            return self::FAILURE;
        }

        $psuType = PsuType::tryFrom($psuTypeInput);
        if ($psuType === null) {
            $this->error("--psu-type must be 'personal' or 'business'. Got '{$psuTypeInput}'.");

            return self::FAILURE;
        }

        if (! ctype_digit($lookbackInput) || (int) $lookbackInput < 1 || (int) $lookbackInput > 90) {
            $this->error("--sync-lookback-days must be an integer between 1 and 90. Got '{$lookbackInput}'.");

            return self::FAILURE;
        }
        $lookback = (int) $lookbackInput;

        if (Bank::query()->whereKey($slug)->exists()) {
            $this->error("Bank with slug '{$slug}' already exists. Use a different slug or update the row directly.");

            return self::FAILURE;
        }

        Bank::query()->create([
            'slug' => $slug,
            'display_name' => $displayName,
            'aspsp_name' => $aspspName,
            'aspsp_country' => $aspspCountry,
            'psu_type' => $psuType,
            'default_currency' => $currency,
            'sync_lookback_days' => $lookback,
            'active' => true,
        ]);

        $this->info("Added bank slug={$slug} ({$displayName}, {$aspspCountry}, {$currency}). Run `spendula:auth:start {$slug}` next.");

        return self::SUCCESS;
    }
}
