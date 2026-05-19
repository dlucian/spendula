<?php

namespace App\Console\Commands\Spendula;

use App\Models\PushRunError;
use App\Services\Locks\LockBusyException;
use App\Services\Push\PushRunner;
use App\Services\Ynab\Exceptions\YnabAuthException;
use App\Services\Ynab\Exceptions\YnabRateLimitException;
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
        } catch (YnabRateLimitException $e) {
            $this->error('YNAB returned 429 Too Many Requests: '.$e->getMessage());
            $this->warn('SPEC §10.2 aborts the push run on rate-limit; re-run spendula:push after a short wait.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'push_run_id=%d  pushed=%d  duplicate=%d  errors=%d',
            $result->run->id,
            $result->pushed,
            $result->duplicate,
            $result->errors,
        ));

        if ($result->errors > 0) {
            $this->printErrorTail($result->run->id);
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Print each push_run_errors row from this run as one line, so the operator
     * doesn't have to chain `spendula:status` or query the DB to see what
     * failed. Format matches the renderer's RECENT_DETAIL_TRUNCATE shape.
     */
    private function printErrorTail(int $pushRunId): void
    {
        $errors = PushRunError::query()
            ->with('transaction.bankAccount.bank')
            ->where('push_run_id', $pushRunId)
            ->orderBy('created_at')
            ->get();

        if ($errors->isEmpty()) {
            return;
        }

        $this->line('Errors this run:');
        foreach ($errors as $err) {
            $bankAccount = $err->transaction?->bankAccount;
            $bank = $bankAccount?->bank?->display_name ?? '-';
            $account = $bankAccount?->display_name ?? $bankAccount?->iban ?? '-';
            $label = ($bank === '-' && $account === '-')
                ? '-'
                : trim($bank.' / '.$account, ' /');

            $http = $err->http_status !== null ? (string) $err->http_status : '-';
            $detail = $this->collapseDetail((string) $err->error_detail);

            $this->line(sprintf(
                '  <fg=red>•</> [%s] HTTP %s  %s  %s',
                $err->error_type->value,
                $http,
                $label,
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
