<?php

namespace App\Http\Controllers;

use App\Services\EnableBanking\CallbackHandler;
use App\Services\EnableBanking\Exceptions\EnableBankingException;
use App\Services\EnableBanking\Exceptions\InvalidCallbackStateException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class BankingCallbackController extends Controller
{
    public function handle(Request $request, CallbackHandler $handler): Response
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            return $this->error("Enable Banking returned error={$error}.", status: 400);
        }

        if ($state === '' || $code === '') {
            return $this->error('Missing state or code in callback URL.', status: 400);
        }

        try {
            $result = $handler->handle($state, $code);
        } catch (InvalidCallbackStateException) {
            return $this->error(
                'This callback link has expired or has already been consumed. Start a new auth flow with '
                .'`php artisan spendula:auth:start <bank_slug>`.',
                status: 400,
            );
        } catch (EnableBankingException $e) {
            Log::warning('Enable Banking callback failed', [
                'event' => 'callback.eb_error',
                'http_status' => $e->httpStatus,
            ]);

            return $this->error('Enable Banking declined the session exchange: '.$e->getMessage(), status: 502);
        } catch (\RuntimeException $e) {
            // Malformed-but-200 session payloads (e.g. missing session_id). The
            // auth_request was already consumed before exchangeCode, so the user
            // must restart the flow — show the callback error page instead of
            // a raw 500 stack trace.
            Log::warning('Enable Banking session payload was malformed', [
                'event' => 'callback.malformed_session',
                'reason' => $e->getMessage(),
            ]);

            return $this->error(
                'Enable Banking returned an unexpected response shape. Start a new auth flow with '
                .'`php artisan spendula:auth:start <bank_slug>`.',
                status: 502,
            );
        }

        return response()->view('banking.callback-success', [
            'connection' => $result['connection'],
            'accounts' => $result['accounts'],
        ]);
    }

    private function error(string $message, int $status): Response
    {
        return response()->view('banking.callback-error', ['message' => $message], $status);
    }
}
