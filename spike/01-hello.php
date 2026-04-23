<?php
require __DIR__ . '/lib.php';

[$status, $body] = enablebanking_request('GET', '/application');

echo "HTTP $status\n";
echo json_encode($body, JSON_PRETTY_PRINT) . "\n";
