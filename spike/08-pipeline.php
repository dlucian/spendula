<?php
require __DIR__ . '/lib.php';

$budgetId  = env('YNAB_BUDGET_ID');
$accountId = env('YNAB_ACCOUNT_ID');

$raw    = json_decode(file_get_contents(__DIR__ . '/.transactions.json'), true);
$source = $raw['transactions'] ?? [];

echo "source transactions: " . count($source) . "\n\n";

$ynabTxns = [];
foreach ($source as $t) {
    $amount = (int) round((float)($t['transaction_amount']['amount'] ?? 0) * 1000);
    if (($t['credit_debit_indicator'] ?? '') === 'DBIT') {
        $amount = -$amount;
    }

    $payee = $t['creditor']['name'] ?? $t['debtor']['name'] ?? 'Unknown';
    $payee = substr($payee, 0, 50);

    $date = $t['booking_date'] ?? $t['transaction_date'] ?? '';

    $remit = $t['remittance_information'] ?? [];
    $remit = is_array($remit) ? implode('; ', $remit) : (string)$remit;
    $memo  = trim(sprintf(
        '%s %s %s',
        $t['transaction_amount']['currency'] ?? '',
        $t['transaction_amount']['amount']   ?? '',
        $remit
    ));
    $memo = substr($memo, 0, 200);

    $importId = 'SPIKE:' . substr(sha1($date . $amount . $payee), 0, 30);

    $ynabTxns[] = [
        'account_id' => $accountId,
        'date'       => $date,
        'amount'     => $amount,
        'payee_name' => $payee,
        'memo'       => $memo,
        'cleared'    => 'cleared',
        'import_id'  => $importId,
    ];
}

echo "prepared YNAB payloads:\n";
foreach ($ynabTxns as $tx) {
    printf("  %s  %+-8d  %-30s  %s\n",
        $tx['date'], $tx['amount'], $tx['payee_name'], $tx['import_id']);
}
echo "\n";

[$status, $body] = ynab_request('POST', "/budgets/$budgetId/transactions", [
    'transactions' => $ynabTxns,
]);

echo "HTTP $status\n";

if ($status < 200 || $status >= 300) {
    echo json_encode($body, JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$created    = $body['data']['transactions'] ?? [];
$duplicates = $body['data']['duplicate_import_ids'] ?? [];

echo "created:    " . count($created) . "\n";
echo "duplicates: " . count($duplicates) . "\n";

if ($duplicates) {
    echo "\nduplicate import_ids:\n";
    foreach ($duplicates as $did) echo "  $did\n";
}

echo "\nfull response:\n" . json_encode($body, JSON_PRETTY_PRINT) . "\n";
