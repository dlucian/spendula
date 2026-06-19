<?php

namespace Tests\Unit\Services\Status;

use App\Services\Status\BankRow;
use App\Services\Status\RecentErrorRow;
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

    public function test_recent_errors_panel_renders_when_rows_present(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [$this->bankRow()],
            stuckTransactions: [],
            hasRedOrStuckRows: false,
            isEmpty: false,
            generatedAt: Carbon::parse('2026-05-19 12:00:00'),
            recentErrors: [
                new RecentErrorRow(
                    createdAt: Carbon::parse('2026-05-19 09:30:00'),
                    runKind: 'sync',
                    runId: 42,
                    httpStatus: 422,
                    bankDisplayName: 'Millennium BCP',
                    bankAccountDisplayName: 'My Checking',
                    detail: "Enable Banking returned HTTP 422 on GET /accounts/x/transactions.\n\nResponse: {\"code\":\"INVALID_DATE_FROM\"}",
                ),
                new RecentErrorRow(
                    createdAt: Carbon::parse('2026-05-19 11:00:00'),
                    runKind: 'push',
                    runId: 7,
                    httpStatus: 400,
                    bankDisplayName: 'Millennium BCP',
                    bankAccountDisplayName: 'My Checking',
                    detail: 'YNAB returned HTTP 400 on POST /plans/y/transactions.',
                ),
            ],
        );

        $output = $this->capture($snapshot);

        $this->assertStringContainsString('Recent sync/push errors', $output);
        $this->assertStringContainsString('s#42', $output);
        $this->assertStringContainsString('p#7', $output);
        $this->assertStringContainsString('422', $output);
        $this->assertStringContainsString('400', $output);
        $this->assertStringContainsString('Millennium BCP / My Checking', $output);
        // The "\n\nResponse: " marker is collapsed into a single-line " — Response: ".
        $this->assertStringContainsString('— Response:', $output);
        $this->assertStringNotContainsString("Response:\n", $output);
    }

    public function test_recent_errors_panel_omitted_when_empty(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [$this->bankRow()],
            stuckTransactions: [],
            hasRedOrStuckRows: false,
            isEmpty: false,
            generatedAt: Carbon::parse('2026-05-19 12:00:00'),
            recentErrors: [],
        );

        $output = $this->capture($snapshot);

        $this->assertStringNotContainsString('Recent sync/push errors', $output);
    }

    public function test_recent_errors_panel_falls_back_to_dash_for_connection_level_errors(): void
    {
        $snapshot = new StatusSnapshot(
            banks: [$this->bankRow()],
            stuckTransactions: [],
            hasRedOrStuckRows: false,
            isEmpty: false,
            generatedAt: Carbon::parse('2026-05-19 12:00:00'),
            recentErrors: [
                new RecentErrorRow(
                    createdAt: Carbon::parse('2026-05-19 09:30:00'),
                    runKind: 'sync',
                    runId: 1,
                    httpStatus: 401,
                    bankDisplayName: null,
                    bankAccountDisplayName: null,
                    detail: 'Enable Banking returned HTTP 401 — consent revoked.',
                ),
            ],
        );

        $output = $this->capture($snapshot);

        $this->assertStringContainsString('Recent sync/push errors', $output);
        // Bank/Account column shows a dash when both bank and account are null;
        // it sits between the HTTP cell (`401`) and the Detail cell.
        $this->assertMatchesRegularExpression(
            '/401\s+-\s+Enable Banking returned HTTP 401/u',
            $output,
        );
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
            queuedCounts: ['fetched' => 0, 'approved' => 0, 'transfer' => 0, 'tracking' => 0, 'transfer_dropped' => 0],
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
