<?php
require __DIR__ . '/lib.php';

function update_env(string $key, string $value): void {
    $path = __DIR__ . '/.env';
    $contents = file_get_contents($path);
    $line = "$key=$value";
    if (preg_match("/^$key=.*$/m", $contents)) {
        $contents = preg_replace("/^$key=.*$/m", $line, $contents);
    } else {
        $contents = rtrim($contents, "\n") . "\n" . $line . "\n";
    }
    file_put_contents($path, $contents);
}

[$status, $body] = ynab_request('GET', '/budgets');
if ($status !== 200) {
    fwrite(STDERR, "GET /budgets failed: HTTP $status\n");
    fwrite(STDERR, "Response:\n" . json_encode($body, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$budgets = $body['data']['budgets'] ?? [];
echo "budgets: " . count($budgets) . "\n";
foreach ($budgets as $b) {
    printf("  %-40s %-25s (%s)\n",
        $b['id'],
        $b['name'],
        $b['currency_format']['iso_code'] ?? '?'
    );
}

$spike = array_values(array_filter($budgets, fn($b) => $b['name'] === "Spendula Test"));
if (!$spike) {
    fwrite(STDERR, "\nNo budget named \"Spendula Test\" found\n");
    exit(1);
}
$budgetId = $spike[0]['id'];
update_env('YNAB_BUDGET_ID', $budgetId);
echo "\nYNAB_BUDGET_ID=$budgetId  (saved to .env)\n\n";

[$status, $body] = ynab_request('GET', "/budgets/$budgetId/accounts");
if ($status !== 200) {
    fwrite(STDERR, "GET /budgets/$budgetId/accounts failed: HTTP $status\n");
    fwrite(STDERR, "Response:\n" . json_encode($body, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$accounts = $body['data']['accounts'] ?? [];
echo "accounts: " . count($accounts) . "\n";
foreach ($accounts as $a) {
    printf("  %-40s %-25s (%s, on_budget=%s)\n",
        $a['id'],
        $a['name'],
        $a['type'],
        $a['on_budget'] ? 'true' : 'false'
    );
}

$test = array_values(array_filter($accounts, fn($a) => $a['name'] === 'Portofel'));
if (!$test) {
    fwrite(STDERR, "\nNo account named 'Portofel' found\n");
    exit(1);
}
$accountId = $test[0]['id'];
update_env('YNAB_ACCOUNT_ID', $accountId);
echo "\nYNAB_ACCOUNT_ID=$accountId  (saved to .env)\n";
