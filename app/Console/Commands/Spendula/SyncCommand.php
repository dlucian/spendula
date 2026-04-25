<?php

namespace App\Console\Commands\Spendula;

use App\Services\EnableBanking\Exceptions\EnableBankingAuthException;
use App\Services\Locks\LockBusyException;
use App\Services\Sync\SyncRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:sync {--bank= : Optional bank slug to restrict the sync to}')]
#[Description('Fetch new transactions from Enable Banking into Spendula.')]
class SyncCommand extends Command
{
    public function handle(SyncRunner $runner): int
    {
        $bankSlug = (string) $this->option('bank');

        try {
            $result = $runner->run($bankSlug !== '' ? $bankSlug : null);
        } catch (LockBusyException $e) {
            $this->error($e->getMessage());
            $this->warn('Another sync is already running. Try again shortly.');

            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (EnableBankingAuthException $e) {
            $this->error('Enable Banking refused our JWT: '.$e->getMessage());
            $this->warn('Fix SPENDULA_ENABLE_BANKING_APP_ID and/or the private key, then re-run.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'sync_run_id=%d  inserted=%d  updated=%d  deduped=%d  errors=%d',
            $result->run->id,
            $result->inserted,
            $result->updated,
            $result->deduped,
            $result->errors,
        ));

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
