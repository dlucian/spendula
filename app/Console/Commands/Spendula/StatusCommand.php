<?php

namespace App\Console\Commands\Spendula;

use App\Services\Status\StatusRenderer;
use App\Services\Status\StatusSnapshotBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:status {--include-mock : Include the seeded mock bank in the dashboard.}')]
#[Description('Dashboard: consent expiry, queued transactions, last sync/push times, push-stuck warnings.')]
class StatusCommand extends Command
{
    public function handle(StatusSnapshotBuilder $builder, StatusRenderer $renderer): int
    {
        $snapshot = $builder->build(
            includeMock: (bool) $this->option('include-mock'),
        );

        $renderer->render($snapshot, $this->getOutput());

        return $snapshot->hasRedOrStuckRows() ? self::FAILURE : self::SUCCESS;
    }
}
