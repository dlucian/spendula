<?php

namespace App\Services\Status;

use App\Services\Money\Money;
use Illuminate\Console\OutputStyle;

/**
 * Pure renderer for the spendula:status dashboard.
 *
 * Takes a fully-built StatusSnapshot (no DB calls) and writes the four
 * sections to the supplied OutputStyle. Uses Symfony Console color tags;
 * non-TTY output (cron, log files) drops the tags automatically.
 */
class StatusRenderer
{
    private const int LAST_ERROR_TRUNCATE = 80;

    public function render(StatusSnapshot $snapshot, OutputStyle $output): void
    {
        if ($snapshot->isEmpty) {
            $output->writeln(
                'Nothing to show — run `spendula:banks:sync` and `spendula:auth:start` to get started.'
            );

            return;
        }

        $this->renderConsent($snapshot, $output);
        $output->writeln('');
        $this->renderQueuedCounts($snapshot, $output);
        $output->writeln('');
        $this->renderWallTimes($snapshot, $output);

        if ($snapshot->stuckTransactions !== []) {
            $output->writeln('');
            $this->renderStuck($snapshot, $output);
        }
    }

    private function renderConsent(StatusSnapshot $snapshot, OutputStyle $output): void
    {
        $output->writeln('<options=bold>Consent</>');

        if ($snapshot->banks === []) {
            $output->writeln('  (no active banks)');

            return;
        }

        $rows = [];
        foreach ($snapshot->banks as $b) {
            $tag = $this->tagFor($b->consentWarningLevel);
            $statusLabel = $b->effectiveConsentStatus;

            // Mark drift between stored and effective status so the operator
            // notices a stored 'active' that's actually past valid_until.
            if ($b->effectiveConsentStatus !== $b->consentStatus) {
                $statusLabel .= ' ('.$b->consentStatus.')';
            }

            $daysCell = $b->consentDaysRemaining !== null
                ? (string) $b->consentDaysRemaining
                : '-';

            $validUntilCell = $b->consentValidUntil !== null
                ? $b->consentValidUntil->format('Y-m-d H:i')
                : '-';

            $rows[] = [
                'bank' => $b->displayName,
                'status' => $tag !== null ? "<{$tag}>{$statusLabel}</>" : $statusLabel,
                'valid_until' => $validUntilCell,
                'days_remaining' => $daysCell,
            ];
        }

        $output->table(
            ['Bank', 'Consent', 'Valid until', 'Days left'],
            $rows,
        );
    }

    private function renderQueuedCounts(StatusSnapshot $snapshot, OutputStyle $output): void
    {
        $output->writeln('<options=bold>Queued transactions</>');

        if ($snapshot->banks === []) {
            $output->writeln('  (no active banks)');

            return;
        }

        $rows = [];
        foreach ($snapshot->banks as $b) {
            $rows[] = [
                'bank' => $b->displayName,
                'fetched' => (string) $b->queuedCounts['fetched'],
                'approved' => (string) $b->queuedCounts['approved'],
                'transfer' => (string) $b->queuedCounts['transfer'],
                'tracking' => (string) $b->queuedCounts['tracking'],
            ];
        }

        $output->table(
            ['Bank', 'Fetched', 'Approved', 'Transfer', 'Tracking'],
            $rows,
        );
    }

    private function renderWallTimes(StatusSnapshot $snapshot, OutputStyle $output): void
    {
        $output->writeln('<options=bold>Last activity</>');

        if ($snapshot->banks === []) {
            $output->writeln('  (no active banks)');

            return;
        }

        $rows = [];
        foreach ($snapshot->banks as $b) {
            $sync = $b->lastSyncedAt?->format('Y-m-d H:i') ?? 'never';
            if ($b->syncStale) {
                $sync = '<fg=red>'.$sync.' (stale)</>';
            }

            $push = $b->lastPushedAt?->format('Y-m-d H:i') ?? '-';
            $snap = $b->lastSnapshotAt?->format('Y-m-d H:i') ?? '-';

            $rows[] = [
                'bank' => $b->displayName,
                'last_sync' => $sync,
                'last_push' => $push,
                'last_snapshot' => $snap,
            ];
        }

        $output->table(
            ['Bank', 'Last sync', 'Last push', 'Last snapshot'],
            $rows,
        );
    }

    private function renderStuck(StatusSnapshot $snapshot, OutputStyle $output): void
    {
        $output->writeln('<fg=red;options=bold>Push-stuck transactions</>');
        $output->writeln(
            sprintf(
                '%d transaction(s) at >=%d push attempts and not yet pushed:',
                count($snapshot->stuckTransactions),
                Thresholds::PUSH_STUCK_ATTEMPTS,
            ),
        );

        foreach ($snapshot->stuckTransactions as $t) {
            $amount = Money::format($t->amountMilliunits, $t->currency).' '.$t->currency;
            $counterparty = $t->counterpartyName !== null && $t->counterpartyName !== ''
                ? $t->counterpartyName
                : '(unknown)';
            $error = $this->truncate($t->lastPushError ?? '');

            $output->writeln(sprintf(
                '  <fg=red>•</> %s · %s · %s · %s · %s · attempts=%d · last_error="%s"',
                $t->bankDisplayName,
                $t->bankAccountDisplayName,
                $t->bookingDate->format('Y-m-d'),
                $amount,
                $counterparty,
                $t->pushAttemptCount,
                $error,
            ));
        }
    }

    /**
     * Map a warning level to a Symfony Console foreground tag (or null
     * for the default colour, which keeps the cell uncoloured).
     */
    private function tagFor(string $warningLevel): ?string
    {
        return match ($warningLevel) {
            'green' => 'fg=green',
            'yellow' => 'fg=yellow',
            'red' => 'fg=red',
            default => null,
        };
    }

    private function truncate(string $s): string
    {
        if ($s === '') {
            return '';
        }

        if (mb_strlen($s) <= self::LAST_ERROR_TRUNCATE) {
            return $s;
        }

        return mb_substr($s, 0, self::LAST_ERROR_TRUNCATE - 1).'…';
    }
}
