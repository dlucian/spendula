<?php
require __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;

function env(string $key): string {
    static $cache = null;
    if ($cache === null) {
        $cache = parse_ini_file(__DIR__ . '/.env');
    }
    if (empty($cache[$key])) {
        fwrite(STDERR, "Missing or empty env var: $key\n");
        exit(1);
    }
    return $cache[$key];
}

function enablebanking_request(string $method, string $path, ?array $body = null): array {
    $appId = env('ENABLEBANKING_APP_ID');
    $privateKey = file_get_contents(__DIR__ . '/private.key');

    $now = time();
    $jwt = JWT::encode([
        'iss' => 'enablebanking.com',
        'aud' => 'api.enablebanking.com',
        'iat' => $now,
        'exp' => $now + 3600,
    ], $privateKey, 'RS256', $appId);

    $headers = [
        'Authorization: Bearer ' . $jwt,
        'Accept: application/json',
    ];

    $ch = curl_init('https://api.enablebanking.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($response, true)];
}

function ynab_request(string $method, string $path, ?array $body = null): array {
    $token = env('YNAB_ACCESS_TOKEN');

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];

    $ch = curl_init('https://api.ynab.com/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($response, true)];
}
