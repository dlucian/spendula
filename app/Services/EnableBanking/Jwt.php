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
    /**
     * Either $privateKey holds the PEM contents directly (test path), or
     * $privateKeyPath points at a file that's read on first sign() (production
     * path via fromConfig). Production must defer the read so a missing/unreadable
     * key surfaces inside command/controller error handling rather than as an
     * uncaught throw during container resolution.
     */
    public function __construct(
        private readonly string $appId,
        private readonly string $privateKey = '',
        private readonly ?string $privateKeyPath = null,
    ) {}

    public function sign(int $ttlSeconds = 3600): string
    {
        if ($this->appId === '') {
            throw new RuntimeException('SPENDULA_ENABLE_BANKING_APP_ID is not configured.');
        }

        $key = $this->resolvePrivateKey();

        $now = time();

        return FirebaseJwt::encode(
            [
                'iss' => 'enablebanking.com',
                'aud' => 'api.enablebanking.com',
                'iat' => $now,
                'exp' => $now + $ttlSeconds,
            ],
            $key,
            'RS256',
            $this->appId,
        );
    }

    private function resolvePrivateKey(): string
    {
        if ($this->privateKey !== '') {
            return $this->privateKey;
        }

        if ($this->privateKeyPath === null || $this->privateKeyPath === '') {
            throw new RuntimeException('Enable Banking private key path is not configured.');
        }

        $absolute = str_starts_with($this->privateKeyPath, '/')
            ? $this->privateKeyPath
            : base_path($this->privateKeyPath);

        if (! is_readable($absolute)) {
            throw new RuntimeException("Enable Banking private key not readable at: {$absolute}");
        }

        $key = file_get_contents($absolute);
        if ($key === false || $key === '') {
            throw new RuntimeException("Enable Banking private key is empty at: {$absolute}");
        }

        return $key;
    }

    public static function fromConfig(): self
    {
        return new self(
            appId: (string) config('spendula.enable_banking.app_id'),
            privateKey: '',
            privateKeyPath: (string) config('spendula.enable_banking.private_key_path'),
        );
    }
}
