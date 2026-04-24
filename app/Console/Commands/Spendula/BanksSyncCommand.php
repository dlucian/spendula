<?php

namespace App\Console\Commands\Spendula;

use App\Models\Bank;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('spendula:banks:sync')]
#[Description('Reconcile the banks table with config/spendula-banks.php.')]
class BanksSyncCommand extends Command
{
    public function handle(): int
    {
        /** @var array<string, array<string, mixed>> $config */
        $config = config('spendula-banks', []);

        DB::transaction(function () use ($config): void {
            foreach ($config as $slug => $row) {
                Bank::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'display_name' => $row['display_name'],
                        'aspsp_name' => $row['aspsp_name'],
                        'aspsp_country' => $row['aspsp_country'],
                        'psu_type' => $row['psu_type'],
                        'default_currency' => $row['default_currency'],
                        'sync_lookback_days' => $row['sync_lookback_days'],
                        'active' => true,
                    ]
                );
            }

            $configSlugs = array_keys($config);

            Bank::query()
                ->when($configSlugs !== [], fn ($q) => $q->whereNotIn('slug', $configSlugs))
                ->where('active', true)
                ->update(['active' => false]);
        });

        $active = Bank::query()->where('active', true)->count();
        $inactive = Bank::query()->where('active', false)->count();

        $this->info("banks:sync — {$active} active, {$inactive} inactive.");

        return self::SUCCESS;
    }
}
