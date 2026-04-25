<?php

namespace App\Http\Controllers;

use App\Services\EnableBanking\CallbackHandler;
use App\Services\EnableBanking\Exceptions\EnableBankingException;
use App\Services\EnableBanking\Exceptions\InvalidCallbackStateException;
use App\Services\EnableBanking\Exceptions\LocalConfigException;
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
        } catch (LocalConfigException $e) {
            // Local JWT/config failure raised by Client::preflight(). The
            // auth_request has NOT been consumed yet and `/sessions` was
            // never called, so the operator can fix config (app id, private
            // key path) and retry the same callback URL — do NOT tell them
            // to start a fresh auth flow.
            Log::warning('Enable Banking callback aborted before exchange (local config error)', [
                'event' => 'callback.local_config_error',
                'reason' => $e->getMessage(),
            ]);

            return $this->error(
                'Local Enable Banking configuration error: '.$e->getMessage()
                .' Fix the configuration and reload this URL — no new auth flow is needed.',
                status: 500,
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
