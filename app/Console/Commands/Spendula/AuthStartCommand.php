<?php

namespace App\Console\Commands\Spendula;

use App\Models\AuthRequest;
use App\Models\Bank;
use App\Services\EnableBanking\Client;
use App\Services\EnableBanking\Exceptions\EnableBankingException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Signature('spendula:auth:start {bank_slug}')]
#[Description('Begin an Enable Banking consent flow for a bank; prints the consent URL.')]
class AuthStartCommand extends Command
{
    public function handle(Client $client): int
    {
        $slug = (string) $this->argument('bank_slug');

        $bank = Bank::query()->where('slug', $slug)->where('active', true)->first();
        if (! $bank instanceof Bank) {
            $this->error("No active bank with slug '{$slug}'. Run `php artisan spendula:banks:sync` first.");

            return self::FAILURE;
        }

        $state = (string) Str::uuid7();

        $authRequest = AuthRequest::query()->create([
            'state' => $state,
            'bank_slug' => $bank->slug,
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $payload = [
            'access' => [
                'valid_until' => Carbon::now()->addDays(90)->toIso8601ZuluString(),
            ],
            'aspsp' => [
                'name' => $bank->aspsp_name,
                'country' => $bank->aspsp_country,
            ],
            'psu_type' => $bank->psu_type->value,
            'redirect_url' => (string) config('spendula.callback_url'),
            'state' => $state,
        ];

        try {
            $response = $client->startAuth($payload);
        } catch (EnableBankingException $e) {
            // The auth_request row is left in the DB unconsumed; it will expire in 15 minutes.
            $this->error('Enable Banking rejected the auth request: '.$e->getMessage());

            return self::FAILURE;
        }

        $url = (string) ($response['url'] ?? '');
        if ($url === '') {
            $this->error('Enable Banking did not return a consent URL. Raw response saved; check auth_requests.state='.$state);

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Consent URL for {$bank->display_name}:");
        $this->line($url);
        $this->newLine();
        $this->warn('Heads-up: Enable Banking\'s consent sessions expire in under 10 minutes.');
        $this->warn('Open the URL promptly; if Mock ASPSP has no accounts, provision one first at https://enablebanking.com/cp/mock-aspsp.');
        $this->newLine();
        $this->line("state={$authRequest->state} (auth_requests.id={$authRequest->id})");

        return self::SUCCESS;
    }
}
