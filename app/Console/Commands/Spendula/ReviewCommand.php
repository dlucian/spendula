<?php

namespace App\Console\Commands\Spendula;

use App\Services\Locks\AdvisoryLock;
use App\Services\Locks\LockBusyException;
use App\Services\Review\ReviewSession;
use App\Services\Review\TransactionActions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:review {--bulk-approve-trivial : Auto-approve rows whose resolution level ≤ 1 and currency matches SPENDULA_BASE_CURRENCY}')]
#[Description('Interactive CLI queue: Approve / Skip / Transfer fetched transactions.')]
class ReviewCommand extends Command
{
    public function handle(TransactionActions $actions): int
    {
        try {
            return AdvisoryLock::withLock(AdvisoryLock::REVIEW, function () use ($actions): int {
                if ($this->option('bulk-approve-trivial')) {
                    $baseCurrency = (string) config('spendula.base_currency', 'EUR');
                    $approved = $actions->bulkApproveTrivial($baseCurrency);
                    $this->info("Bulk-approved {$approved} trivial transaction(s) in {$baseCurrency}.");
                }

                $session = new ReviewSession($this, $actions);
                $stats = $session->run();

                $this->newLine();
                $this->info(sprintf(
                    'Reviewed %d: approved=%d skipped=%d transferred=%d%s',
                    $stats['reviewed'],
                    $stats['approved'],
                    $stats['skipped'],
                    $stats['transferred'],
                    $stats['quit'] ? ' (quit early)' : '',
                ));

                return self::SUCCESS;
            });
        } catch (LockBusyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
