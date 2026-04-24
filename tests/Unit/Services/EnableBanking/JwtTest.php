<?php

namespace Tests\Unit\Services\EnableBanking;

use App\Services\EnableBanking\Jwt;
use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

class JwtTest extends TestCase
{
    public function test_signed_token_round_trips_against_the_public_key(): void
    {
        [$privatePem, $publicPem] = self::generateKeypair();

        $jwt = new Jwt('app-under-test', $privatePem);
        $token = $jwt->sign();

        $decoded = FirebaseJwt::decode($token, new Key($publicPem, 'RS256'));

        $this->assertSame('enablebanking.com', $decoded->iss);
        $this->assertSame('api.enablebanking.com', $decoded->aud);
        $this->assertIsInt($decoded->iat);
        $this->assertIsInt($decoded->exp);
        $this->assertGreaterThan($decoded->iat, $decoded->exp);
    }

    public function test_ttl_is_respected(): void
    {
        [$privatePem] = self::generateKeypair();

        $before = time();
        $token = (new Jwt('any-app-id', $privatePem))->sign(ttlSeconds: 60);

        // Peek at claims without verifying the signature.
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        /** @var array<string, int> $payload */
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertGreaterThanOrEqual($before, $payload['iat']);
        $this->assertSame($payload['iat'] + 60, $payload['exp']);
    }

    public function test_key_id_matches_the_configured_app_id(): void
    {
        [$privatePem] = self::generateKeypair();

        $token = (new Jwt('my-unique-app-id', $privatePem))->sign();

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        /** @var array<string, string> $header */
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('my-unique-app-id', $header['kid']);
    }

    /** @return array{0: string, 1: string} */
    private static function generateKeypair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            self::fail('openssl_pkey_new failed to generate keypair.');
        }

        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);

        if ($details === false) {
            self::fail('openssl_pkey_get_details failed.');
        }

        return [$privatePem, (string) $details['key']];
    }
}
