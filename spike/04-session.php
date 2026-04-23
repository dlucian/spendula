<?php
require __DIR__ . '/lib.php';

if (!isset($argv[1])) {
    fwrite(STDERR, "Usage: php 04-session.php <code>\n");
    exit(1);
}

$code = $argv[1];

[$status, $body] = enablebanking_request('POST', '/sessions', ['code' => $code]);

if ($status !== 200) {
    fwrite(STDERR, "POST /sessions failed: HTTP $status\n\n");
    fwrite(STDERR, "Request body:\n" . json_encode(['code' => $code], JSON_PRETTY_PRINT) . "\n\n");
    fwrite(STDERR, "Response:\n" . json_encode($body, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

file_put_contents(__DIR__ . '/.session.json', json_encode($body, JSON_PRETTY_PRINT));

$accounts = $body['accounts'] ?? [];
echo "session_id: " . ($body['session_id'] ?? '?') . "\n";
echo "accounts:   " . count($accounts) . "\n\n";

if ($accounts) {
    echo "First account (raw, to confirm field shape):\n";
    echo json_encode($accounts[0], JSON_PRETTY_PRINT) . "\n\n";
}

printf("%-40s %-25s %-4s %s\n", 'uid', 'iban', 'cur', 'name');
echo str_repeat('-', 110) . "\n";
foreach ($accounts as $acc) {
    printf("%-40s %-25s %-4s %s\n",
        $acc['uid'] ?? '?',
        $acc['account_id']['iban'] ?? '?',
        $acc['currency'] ?? '?',
        $acc['name'] ?? $acc['product'] ?? '?'
    );
}

echo "\nSaved full session to .session.json.\n";
