<?php

namespace App\Console\Commands\Spendula;

use App\Models\SyncRunError;
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

        if ($result->errors > 0) {
            $this->printErrorTail($result->run->id);
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Print each sync_run_errors row from this run as one line, so the operator
     * doesn't have to chain `spendula:status` or query the DB to see what
     * failed. Format matches the renderer's RECENT_DETAIL_TRUNCATE shape.
     */
    private function printErrorTail(int $syncRunId): void
    {
        $errors = SyncRunError::query()
            ->with('bankAccount.bank')
            ->where('sync_run_id', $syncRunId)
            ->orderBy('created_at')
            ->get();

        if ($errors->isEmpty()) {
            return;
        }

        $this->line('Errors this run:');
        foreach ($errors as $err) {
            $bank = $err->bankAccount?->bank?->display_name ?? '-';
            $account = $err->bankAccount?->display_name ?? $err->bankAccount?->iban ?? '-';
            $bankAccount = ($bank === '-' && $account === '-')
                ? '-'
                : trim($bank.' / '.$account, ' /');

            $http = $err->http_status !== null ? (string) $err->http_status : '-';
            $detail = $this->collapseDetail((string) $err->error_detail);

            $this->line(sprintf(
                '  <fg=red>•</> [%s] HTTP %s  %s  %s',
                $err->error_type->value,
                $http,
                $bankAccount,
                $detail,
            ));
        }
    }

    private function collapseDetail(string $s): string
    {
        $s = str_replace("\n\nResponse: ", ' — Response: ', $s);
        $s = str_replace(["\r", "\n"], ' ', $s);

        if (mb_strlen($s) <= 200) {
            return $s;
        }

        return mb_substr($s, 0, 199).'…';
    }
}
