<?php

namespace App\Console\Commands\Spendula;

use App\Models\Bank;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('spendula:banks:sync')]
#[Description('Upsert baseline banks from config/spendula-banks.php into the banks table.')]
class BanksSyncCommand extends Command
{
    /**
     * Upserts the fixture banks shipped in config/spendula-banks.php (just the
     * mock bank, used by tests). Does NOT deactivate or delete operator-added
     * rows — operator banks live in the database, are added via banks:add, and
     * are never named in source code.
     */
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
        });

        $upserted = count($config);
        $totalActive = Bank::query()->where('active', true)->count();
        $this->info("banks:sync — {$upserted} fixture(s) upserted; {$totalActive} active total.");

        return self::SUCCESS;
    }
}
