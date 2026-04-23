<?php
require __DIR__ . '/lib.php';

function uuid_v4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

$state       = uuid_v4();
$validUntil  = gmdate('Y-m-d\TH:i:s\Z', time() + 90 * 86400);
$redirectUrl = 'http://localhost:8000/banking/callback';

$payload = [
    'access' => [
        'valid_until' => $validUntil,
    ],
    'aspsp' => [
        'name'    => 'Mock ASPSP',
        'country' => 'FI',
    ],
    'psu_type'     => 'personal',
    'redirect_url' => $redirectUrl,
    'state'        => $state,
];

[$status, $body] = enablebanking_request('POST', '/auth', $payload);

if ($status !== 200) {
    fwrite(STDERR, "POST /auth failed: HTTP $status\n\n");
    fwrite(STDERR, "Request payload:\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n");
    fwrite(STDERR, "Response body:\n" . json_encode($body, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

file_put_contents(__DIR__ . '/.state.json', json_encode([
    'state'        => $state,
    'redirect_url' => $redirectUrl,
    'aspsp'        => ['name' => 'Mock ASPSP', 'country' => 'FI'],
    'created_at'   => date('c'),
], JSON_PRETTY_PRINT));

echo "state: $state\n";
echo "url:   " . ($body['url'] ?? '(no url in response — full body below)') . "\n";

if (!isset($body['url'])) {
    echo "\nFull response:\n" . json_encode($body, JSON_PRETTY_PRINT) . "\n";
}

echo "\nSaved state to .state.json.\n";
echo "Open the URL above, complete the sandbox login, and copy the ?code=... param from the redirect URL.\n";
