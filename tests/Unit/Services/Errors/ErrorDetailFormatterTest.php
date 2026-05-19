<?php

namespace Tests\Unit\Services\Errors;

use App\Services\EnableBanking\Exceptions\EnableBankingHttpException;
use App\Services\Errors\ErrorDetailFormatter;
use App\Services\Ynab\Exceptions\YnabValidationException;
use RuntimeException;
use Tests\TestCase;

class ErrorDetailFormatterTest extends TestCase
{
    public function test_message_only_when_exception_carries_no_body(): void
    {
        $e = new RuntimeException('plain failure');

        $this->assertSame('plain failure', ErrorDetailFormatter::format($e));
    }

    public function test_eb_exception_with_body_appends_json_envelope(): void
    {
        $e = new EnableBankingHttpException(
            'Enable Banking returned HTTP 422 on GET /accounts/abc/transactions.',
            422,
            ['code' => 'INVALID_DATE_FROM', 'message' => 'date_from must not be in the future'],
        );

        $out = ErrorDetailFormatter::format($e);

        $this->assertStringStartsWith('Enable Banking returned HTTP 422', $out);
        $this->assertStringContainsString("\n\nResponse: ", $out);
        $this->assertStringContainsString('"code":"INVALID_DATE_FROM"', $out);
        $this->assertStringContainsString('"date_from must not be in the future"', $out);
    }

    public function test_ynab_exception_with_body_appends_json_envelope(): void
    {
        $e = new YnabValidationException(
            'YNAB returned HTTP 400 on POST /plans/x/transactions.',
            400,
            ['error' => ['id' => '400', 'name' => 'bad_request', 'detail' => 'date must be …']],
        );

        $out = ErrorDetailFormatter::format($e);

        $this->assertStringStartsWith('YNAB returned HTTP 400', $out);
        $this->assertStringContainsString("\n\nResponse: ", $out);
        $this->assertStringContainsString('"name":"bad_request"', $out);
    }

    public function test_null_body_uses_message_only(): void
    {
        $e = new EnableBankingHttpException('msg', 400, null);

        $this->assertSame('msg', ErrorDetailFormatter::format($e));
    }

    public function test_empty_body_array_uses_message_only(): void
    {
        $e = new EnableBankingHttpException('msg', 400, []);

        $this->assertSame('msg', ErrorDetailFormatter::format($e));
    }

    public function test_unicode_preserved_via_unescaped_flags(): void
    {
        $e = new EnableBankingHttpException(
            'oops',
            400,
            ['merchant' => 'Café São Paulo'],
        );

        $this->assertStringContainsString('Café São Paulo', ErrorDetailFormatter::format($e));
    }

    public function test_truncation_happens_after_appending_body_not_before(): void
    {
        // Message length leaves room for the "\n\nResponse: {…" delimiter to
        // survive the MAX_LEN truncation even though the body itself spills.
        $message = str_repeat('M', 900);
        $body = ['detail' => str_repeat('B', 2000)];
        $e = new EnableBankingHttpException($message, 400, $body);

        $out = ErrorDetailFormatter::format($e);

        $this->assertSame(ErrorDetailFormatter::MAX_LEN, strlen($out));
        $this->assertStringStartsWith($message, $out);
        $this->assertStringContainsString("\n\nResponse: ", $out);
    }

    public function test_max_len_cap_holds_for_oversized_message_alone(): void
    {
        $message = str_repeat('M', 5000);
        $e = new RuntimeException($message);

        $out = ErrorDetailFormatter::format($e);

        $this->assertSame(ErrorDetailFormatter::MAX_LEN, strlen($out));
        $this->assertSame(str_repeat('M', ErrorDetailFormatter::MAX_LEN), $out);
    }

    public function test_multibyte_body_truncated_on_codepoint_boundary(): void
    {
        // A 3-byte UTF-8 codepoint ('€' = E2 82 AC) padded out so the
        // truncation cut would fall mid-codepoint if `substr` were used.
        // mb_strcut must back off to a valid boundary so the resulting
        // string stays valid UTF-8 (which Postgres `text` requires).
        $body = ['merchant' => str_repeat('€', 500)];
        $e = new EnableBankingHttpException('m', 400, $body);

        $out = ErrorDetailFormatter::format($e);

        $this->assertLessThanOrEqual(ErrorDetailFormatter::MAX_LEN, strlen($out));
        // mb_check_encoding rejects malformed UTF-8; the substr path would
        // have failed this on a truncation that landed inside '€'.
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'), 'Truncated detail must be valid UTF-8.');
    }
}
