<?php
require __DIR__ . '/lib.php';

$session = json_decode(file_get_contents(__DIR__ . '/.session.json'), true);
if (!$session) {
    fwrite(STDERR, ".session.json missing or invalid — run 04-session.php first\n");
    exit(1);
}

$accounts = $session['accounts'] ?? [];
$eur = array_values(array_filter($accounts, fn($a) => ($a['currency'] ?? '') === 'EUR'));
if (!$eur) {
    fwrite(STDERR, "No EUR account in session\n");
    exit(1);
}

$account  = $eur[0];
$uid      = $account['uid'];
$dateFrom = gmdate('Y-m-d', time() - 30 * 86400);

echo "account:    {$account['name']} ({$account['currency']}) uid={$uid}\n";
echo "date_from:  {$dateFrom}\n\n";

[$status, $body] = enablebanking_request('GET', "/accounts/{$uid}/transactions?date_from={$dateFrom}");

if ($status !== 200) {
    fwrite(STDERR, "GET /accounts/{$uid}/transactions failed: HTTP $status\n\n");
    fwrite(STDERR, "Response:\n" . json_encode($body, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

file_put_contents(__DIR__ . '/.transactions.json', json_encode($body, JSON_PRETTY_PRINT));

$txns = $body['transactions'] ?? [];
echo "transactions: " . count($txns) . "\n\n";

if ($txns) {
    echo "First transaction (raw, to confirm field shape):\n";
    echo json_encode($txns[0], JSON_PRETTY_PRINT) . "\n\n";
}

printf("%-12s %-10s %-4s %-30s %s\n", 'date', 'amount', 'cur', 'counterparty', 'remittance');
echo str_repeat('-', 130) . "\n";
foreach ($txns as $t) {
    $amount = $t['transaction_amount']['amount'] ?? '?';
    $sign   = ($t['credit_debit_indicator'] ?? '') === 'DBIT' ? '-' : '+';
    $name   = $t['creditor']['name'] ?? $t['debtor']['name'] ?? '';
    $remit  = $t['remittance_information'] ?? [];
    $remit  = is_array($remit) ? implode('; ', $remit) : (string)$remit;
    printf("%-12s %-10s %-4s %-30s %s\n",
        $t['booking_date'] ?? $t['transaction_date'] ?? '?',
        $sign . $amount,
        $t['transaction_amount']['currency'] ?? '?',
        substr($name, 0, 30),
        substr($remit, 0, 70)
    );
}

echo "\nSaved raw response to .transactions.json.\n";
