<?php

namespace App\Console\Commands\Spendula;

use App\Services\Locks\LockBusyException;
use App\Services\Push\PushRunner;
use App\Services\Ynab\Exceptions\YnabAuthException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:push')]
#[Description('Push approved/transfer transactions to YNAB via /plans/{plan_id}/transactions.')]
class PushCommand extends Command
{
    public function handle(PushRunner $runner): int
    {
        try {
            $result = $runner->run();
        } catch (LockBusyException $e) {
            $this->error($e->getMessage());
            $this->warn('Another push is already running. Try again shortly.');

            return self::FAILURE;
        } catch (YnabAuthException $e) {
            $this->error('YNAB rejected the access token: '.$e->getMessage());
            $this->warn('Fix SPENDULA_YNAB_ACCESS_TOKEN in .env, then re-run.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'push_run_id=%d  pushed=%d  duplicate=%d  errors=%d',
            $result->run->id,
            $result->pushed,
            $result->duplicate,
            $result->errors,
        ));

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
