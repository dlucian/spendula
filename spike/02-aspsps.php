<?php
require __DIR__ . '/lib.php';

[$status, $body] = enablebanking_request('GET', '/aspsps');

if ($status !== 200) {
    fwrite(STDERR, "HTTP $status\n" . json_encode($body, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$aspsps = $body['aspsps'] ?? [];
echo "Total ASPSPs: " . count($aspsps) . "\n";

$fi = array_values(array_filter($aspsps, fn($a) => ($a['country'] ?? '') === 'FI'));
echo "FI ASPSPs:    " . count($fi) . "\n\n";

printf("%-25s %-4s %-20s %-5s %s\n", 'name', 'cty', 'psu_types', 'beta', 'auth_methods (name/approach)');
echo str_repeat('-', 110) . "\n";
foreach ($fi as $a) {
    $methods = array_map(
        fn($m) => ($m['name'] ?? '?') . '/' . ($m['approach'] ?? '?'),
        $a['auth_methods'] ?? []
    );
    printf("%-25s %-4s %-20s %-5s %s\n",
        $a['name'] ?? '?',
        $a['country'] ?? '?',
        implode(',', $a['psu_types'] ?? []),
        ($a['beta'] ?? false) ? 'yes' : 'no',
        implode(' | ', $methods)
    );
}

$mock = array_values(array_filter($fi, fn($a) => $a['name'] === 'Mock ASPSP'));
if ($mock) {
    echo "\nMock ASPSP (full entry):\n";
    echo json_encode($mock[0], JSON_PRETTY_PRINT) . "\n";
}
