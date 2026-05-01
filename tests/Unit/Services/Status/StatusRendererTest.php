<?php

namespace Tests\Unit\Services\Status;

use App\Services\Status\BankRow;
use App\Services\Status\StatusRenderer;
use App\Services\Status\StatusSnapshot;
use App\Services\Status\StuckTransactionRow;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

class StatusRendererTest extends TestCase
{
    public function test_empty_snapshot_renders_friendly_message(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [],
            stuckTransactions: [],
            hasRedOrStuckRows: false,
            isEmpty: true,
            generatedAt: Carbon::parse('2026-05-01 12:00:00'),
        );

        $output = $this->capture($snapshot);

        $this->assertStringContainsString('Nothing to show', $output);
        $this->assertStringNotContainsString('Consent', $output);
    }

    public function test_renders_yellow_warning_with_color_tag_in_decorated_output(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [$this->bankRow(consentWarningLevel: 'yellow', daysRemaining: 10)],
            stuckTransactions: [],
            hasRedOrStuckRows: false,
            isEmpty: false,
            generatedAt: Carbon::parse('2026-05-01 12:00:00'),
        );

        $output = $this->capture($snapshot, decorated: true);

        // Decorated output emits ANSI escape sequences for the yellow tag.
        $this->assertStringContainsString("\033[33m", $output);
    }

    public function test_renders_red_warning_for_red_consent(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [$this->bankRow(consentWarningLevel: 'red', daysRemaining: 2)],
            stuckTransactions: [],
            hasRedOrStuckRows: true,
            isEmpty: false,
            generatedAt: Carbon::parse('2026-05-01 12:00:00'),
        );

        $output = $this->capture($snapshot, decorated: true);

        $this->assertStringContainsString("\033[31m", $output);
    }

    public function test_renders_stale_annotation_when_sync_stale(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [$this->bankRow(syncStale: true)],
            stuckTransactions: [],
            hasRedOrStuckRows: true,
            isEmpty: false,
            generatedAt: Carbon::parse('2026-05-01 12:00:00'),
        );

        $output = $this->capture($snapshot);

        $this->assertStringContainsString('stale', $output);
    }

    public function test_omits_warnings_section_when_no_stuck_transactions(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [$this->bankRow()],
            stuckTransactions: [],
            hasRedOrStuckRows: false,
            isEmpty: false,
            generatedAt: Carbon::parse('2026-05-01 12:00:00'),
        );

        $output = $this->capture($snapshot);

        $this->assertStringNotContainsString('Push-stuck transactions', $output);
    }

    public function test_renders_stuck_section_with_per_row_lines(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [$this->bankRow()],
            stuckTransactions: [
                new StuckTransactionRow(
                    bankDisplayName: 'Millennium BCP',
                    bankAccountDisplayName: 'My Checking',
                    transactionId: 'tx-1',
                    bookingDate: Carbon::parse('2026-04-15'),
                    amountMilliunits: -45670,
                    currency: 'EUR',
                    counterpartyName: 'Acme',
                    pushAttemptCount: 6,
                    lastPushAttemptAt: Carbon::parse('2026-05-01 11:00:00'),
                    lastPushError: 'YNAB 422: bad payload',
                ),
            ],
            hasRedOrStuckRows: true,
            isEmpty: false,
            generatedAt: Carbon::parse('2026-05-01 12:00:00'),
        );

        $output = $this->capture($snapshot);

        $this->assertStringContainsString('Push-stuck transactions', $output);
        $this->assertStringContainsString('Millennium BCP', $output);
        $this->assertStringContainsString('My Checking', $output);
        $this->assertStringContainsString('2026-04-15', $output);
        $this->assertStringContainsString('attempts=6', $output);
        $this->assertStringContainsString('Acme', $output);
        $this->assertStringContainsString('YNAB 422: bad payload', $output);
    }

    public function test_renders_unknown_counterparty_placeholder(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [$this->bankRow()],
            stuckTransactions: [
                new StuckTransactionRow(
                    bankDisplayName: 'Millennium BCP',
                    bankAccountDisplayName: 'My Checking',
                    transactionId: 'tx-1',
                    bookingDate: Carbon::parse('2026-04-15'),
                    amountMilliunits: 1000,
                    currency: 'EUR',
                    counterpartyName: null,
                    pushAttemptCount: 5,
                    lastPushAttemptAt: null,
                    lastPushError: null,
                ),
            ],
            hasRedOrStuckRows: true,
            isEmpty: false,
            generatedAt: Carbon::parse('2026-05-01 12:00:00'),
        );

        $output = $this->capture($snapshot);

        $this->assertStringContainsString('(unknown)', $output);
    }

    private function bankRow(
        string $consentWarningLevel = 'green',
        ?int $daysRemaining = 60,
        bool $syncStale = false,
    ): BankRow {
        return new BankRow(
            slug: 'bcp',
            displayName: 'Millennium BCP',
            bankActive: true,
            consentValidUntil: Carbon::parse('2026-07-01 12:00:00'),
            consentDaysRemaining: $daysRemaining,
            consentStatus: 'active',
            effectiveConsentStatus: 'active',
            consentWarningLevel: $consentWarningLevel,
            queuedCounts: ['fetched' => 0, 'approved' => 0, 'transfer' => 0, 'tracking' => 0],
            lastSyncedAt: Carbon::parse('2026-05-01 06:00:00'),
            lastPushedAt: null,
            lastSnapshotAt: null,
            syncStale: $syncStale,
        );
    }

    private function capture(StatusSnapshot $snapshot, bool $decorated = false): string
    {
        $buffer = new BufferedOutput(
            $decorated ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_NORMAL,
            $decorated,
        );
        $output = new OutputStyle(new ArrayInput([]), $buffer);

        (new StatusRenderer)->render($snapshot, $output);

        return $buffer->fetch();
    }
}
