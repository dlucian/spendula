<?php

namespace App\Services\Errors;

use App\Services\EnableBanking\Exceptions\EnableBankingException;
use App\Services\Ynab\Exceptions\YnabException;
use Throwable;

/**
 * Formats a thrown exception into the text that lands in
 * `sync_run_errors.error_detail` / `push_run_errors.error_detail`.
 *
 * Success contract: returns a string at most MAX_LEN bytes long. The leading
 *   line is always `$e->getMessage()` (preserves the existing grep-friendly
 *   prefix). When the exception carries a non-null `body` (the parsed
 *   upstream JSON envelope from Enable Banking or YNAB), a blank line plus
 *   `Response: <json>` is appended. Truncation happens AFTER appending so
 *   the prefix is never cut.
 *
 * Failure modes: none — pure string formatting, no I/O.
 *
 * Side effects: none.
 *
 * Idempotency: trivially idempotent.
 *
 * Concurrency: stateless static helper, safe to call from any thread.
 */
final class ErrorDetailFormatter
{
    /** Matches the existing substr() cap callers used before this helper existed. */
    public const int MAX_LEN = 1000;

    public static function format(Throwable $e): string
    {
        $message = $e->getMessage();
        $body = self::bodyFor($e);

        if ($body === null || $body === []) {
            return substr($message, 0, self::MAX_LEN);
        }

        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            // json_encode failed (e.g. recursive structure that shouldn't be
            // possible from an HTTP-decoded array). Fall back to just the
            // message rather than throwing — diagnostics are best-effort.
            return substr($message, 0, self::MAX_LEN);
        }

        return substr($message."\n\nResponse: ".$encoded, 0, self::MAX_LEN);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function bodyFor(Throwable $e): ?array
    {
        if ($e instanceof EnableBankingException) {
            return $e->body;
        }

        if ($e instanceof YnabException) {
            return $e->body;
        }

        return null;
    }
}
