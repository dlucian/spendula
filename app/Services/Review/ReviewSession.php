<?php

namespace App\Services\Review;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Money\Money;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * The interactive review loop (SPEC §7.1). Bootstrapped with raw-mode TTY
 * so single keypresses drive state transitions without requiring Enter.
 * The TTY mode is always restored in a finally block — leaving a terminal
 * in `-icanon -echo` is a painful footgun.
 *
 * This class is intentionally decoupled from Transaction's DB writes —
 * those live in TransactionActions so the business logic is testable
 * without a live stdin.
 */
class ReviewSession
{
    public function __construct(
        private readonly Command $command,
        private readonly TransactionActions $actions,
    ) {}

    /**
     * @return array{reviewed: int, approved: int, skipped: int, transferred: int, quit: bool}
     */
    public function run(): array
    {
        /** @var Collection<int, Transaction> $queue */
        $queue = Transaction::query()
            ->where('status', TransactionStatus::Fetched->value)
            ->with('bankAccount.bank')
            ->orderBy('bank_account_id')
            ->orderBy('booking_date')
            ->orderBy('occurrence')
            ->get();

        $total = $queue->count();
        if ($total === 0) {
            $this->command->info('Nothing to review — the fetched queue is empty.');

            return ['reviewed' => 0, 'approved' => 0, 'skipped' => 0, 'transferred' => 0, 'quit' => false];
        }

        if (! self::stdinIsTty()) {
            // Non-interactive invocation (tests, piped input, CI). Nothing to read keypresses from.
            $this->command->warn("{$total} transaction(s) awaiting review; skipping interactive loop because stdin is not a TTY.");

            return ['reviewed' => 0, 'approved' => 0, 'skipped' => 0, 'transferred' => 0, 'quit' => false];
        }

        $stats = ['reviewed' => 0, 'approved' => 0, 'skipped' => 0, 'transferred' => 0, 'quit' => false];

        $this->enterRawMode();
        try {
            foreach ($queue as $index => $transaction) {
                $this->printHeader($transaction, $index + 1, $total);

                $keep = true;
                while ($keep) {
                    $this->command->getOutput()->write('[a]pprove  [s]kip  [t]ransfer  [d]etails  [q]uit > ');
                    $key = strtolower((string) fgetc(STDIN));
                    $this->command->getOutput()->writeln(''); // newline after keypress echo

                    switch ($key) {
                        case 'a':
                            $this->actions->approve($transaction);
                            $stats['approved']++;
                            $stats['reviewed']++;
                            $keep = false;
                            break;

                        case 's':
                            $this->leaveRawMode();
                            $reason = (string) $this->command->ask('Skip reason (blank = none)', '');
                            $this->enterRawMode();
                            $this->actions->skip($transaction, $reason !== '' ? $reason : null);
                            $stats['skipped']++;
                            $stats['reviewed']++;
                            $keep = false;
                            break;

                        case 't':
                            $this->actions->markTransfer($transaction);
                            $stats['transferred']++;
                            $stats['reviewed']++;
                            $keep = false;
                            break;

                        case 'd':
                            $this->printDetails($transaction);
                            // loop repeats, re-prompt
                            break;

                        case 'q':
                            $stats['quit'] = true;

                            return $stats;

                        default:
                            // ignore unknown keys, re-prompt
                            break;
                    }
                }
            }
        } finally {
            $this->leaveRawMode();
        }

        return $stats;
    }

    private function printHeader(Transaction $transaction, int $position, int $total): void
    {
        $account = $transaction->bankAccount;
        $bank = $account !== null ? $account->bank : null;

        $currency = $transaction->currency;
        $amount = Money::format($transaction->amount_milliunits, $currency);
        $symbol = Money::symbol($currency);
        $signed = (str_starts_with($amount, '-') ? '−' : '+').$symbol.ltrim($amount, '-');

        $bankLabel = $bank !== null ? $bank->display_name : $transaction->bank_account_id;
        $accountLabel = ($account !== null && $account->display_name !== null) ? $account->display_name : '(unmapped)';

        $label = trim(sprintf('%s · %s · %s', $bankLabel, $accountLabel, $currency));

        $this->command->getOutput()->writeln('');
        $this->command->getOutput()->writeln(str_repeat('─', 60));
        $this->command->getOutput()->writeln(sprintf('[%d/%d]  %s', $position, $total, $label));
        $this->command->getOutput()->writeln(sprintf(
            '%s  %s  →  %s',
            $transaction->booking_date->toDateString(),
            $signed,
            $transaction->counterparty_name ?? '(unknown)',
        ));
        $this->command->getOutput()->writeln(sprintf(
            '        resolution level %d%s',
            $transaction->counterparty_resolution_level,
            $transaction->entry_reference !== null ? " · entry_ref={$transaction->entry_reference}" : '',
        ));
        $this->command->getOutput()->writeln(str_repeat('─', 24));
    }

    private function printDetails(Transaction $transaction): void
    {
        $this->leaveRawMode();
        $this->command->getOutput()->writeln('');
        $this->command->getOutput()->writeln((string) json_encode($transaction->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->command->getOutput()->writeln('');
        $this->enterRawMode();
    }

    private function enterRawMode(): void
    {
        if (! self::stdinIsTty()) {
            return;
        }
        // `-icanon -echo` = no line buffering, no character echo. min 1 time 0 = fgetc blocks until one byte arrives.
        system('stty -icanon -echo min 1 time 0 2>/dev/null');
    }

    private function leaveRawMode(): void
    {
        if (! self::stdinIsTty()) {
            return;
        }
        system('stty sane 2>/dev/null');
    }

    private static function stdinIsTty(): bool
    {
        // PHPUnit/Pest runners (especially watchers like phpunit-watcher) can
        // leave STDIN attached to a TTY and feed buffered keypresses into the
        // test process. Without this guard the review loop swallows whatever
        // bytes are in stdin and silently mutates seeded fixtures. App-level
        // testing flag is the most reliable signal — fall through to the
        // stream/posix check only outside the test environment.
        if (app()->runningUnitTests()) {
            return false;
        }

        if (! defined('STDIN')) {
            return false;
        }

        // stream_isatty is built into PHP 7.2+ (no extension required); the
        // production php:8.4-fpm-alpine image does not install ext-posix, so
        // posix_isatty would always return false and silently disable the
        // approval loop inside `docker compose exec -it`.
        return stream_isatty(STDIN);
    }
}
