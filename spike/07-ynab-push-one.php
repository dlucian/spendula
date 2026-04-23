<?php
require __DIR__ . '/lib.php';

$budgetId  = env('YNAB_BUDGET_ID');
$accountId = env('YNAB_ACCOUNT_ID');

$payload = [
    'transaction' => [
        'account_id' => $accountId,
        'date'       => date('Y-m-d'),
        'amount'     => -12340,
        'payee_name' => 'Spike Test',
        'memo'       => 'hardcoded test from milestone 7',
        'cleared'    => 'cleared',
    ],
];

[$status, $body] = ynab_request('POST', "/budgets/$budgetId/transactions", $payload);

echo "HTTP $status\n";
echo json_encode($body, JSON_PRETTY_PRINT) . "\n";

if ($status < 200 || $status >= 300) {
    exit(1);
}
