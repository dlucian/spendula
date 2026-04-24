<?php

namespace App\Services\EnableBanking;

use Firebase\JWT\JWT as FirebaseJwt;
use RuntimeException;

/**
 * RS256 JWT minter for Enable Banking. Isolates firebase/php-jwt so the rest
 * of the code doesn't import its types directly, and so we can swap the
 * library later without touching the client.
 */
class Jwt
{
    public function __construct(
        private readonly string $appId,
        private readonly string $privateKey,
    ) {}

    public function sign(int $ttlSeconds = 3600): string
    {
        $now = time();

        return FirebaseJwt::encode(
            [
                'iss' => 'enablebanking.com',
                'aud' => 'api.enablebanking.com',
                'iat' => $now,
                'exp' => $now + $ttlSeconds,
            ],
            $this->privateKey,
            'RS256',
            $this->appId,
        );
    }

    public static function fromConfig(): self
    {
        $appId = (string) config('spendula.enable_banking.app_id');
        if ($appId === '') {
            throw new RuntimeException('SPENDULA_ENABLE_BANKING_APP_ID is not configured.');
        }

        $path = (string) config('spendula.enable_banking.private_key_path');
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_readable($absolute)) {
            throw new RuntimeException("Enable Banking private key not readable at: {$absolute}");
        }

        $key = file_get_contents($absolute);
        if ($key === false || $key === '') {
            throw new RuntimeException("Enable Banking private key is empty at: {$absolute}");
        }

        return new self($appId, $key);
    }
}
