<?php
declare(strict_types=1);

// Asks Plaid to send a sandbox webhook for one of your items, optionally
// adding random transactions first so the sync has something to process.
//
//   php bin/fire-sandbox-webhook.php <item_id> [webhook_code] [--add-transactions=N]

require __DIR__ . '/bootstrap.php';

const WEBHOOK_TYPES = [
    'SYNC_UPDATES_AVAILABLE'  => 'TRANSACTIONS',
    'ERROR'                   => 'ITEM',
    'PENDING_DISCONNECT'      => 'ITEM',
    'USER_PERMISSION_REVOKED' => 'ITEM',
    'USER_ACCOUNT_REVOKED'    => 'ITEM',
    'NEW_ACCOUNTS_AVAILABLE'  => 'ITEM',
    'LOGIN_REPAIRED'          => 'ITEM',
];

const MERCHANTS = [
    'Amazon', 'Walmart', 'Target', 'Starbucks', 'Shell', 'CVS Pharmacy', 'Uber',
    'Netflix', 'Whole Foods', 'Home Depot', 'Best Buy', 'Costco', 'Delta Air Lines',
];

$positional = [];
$addTransactions = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--add-transactions=')) {
        $addTransactions = min(10, max(0, (int) substr($arg, 19)));
    } elseif ($arg === '--help' || $arg === '-h') {
        $positional = [];
        break;
    } else {
        $positional[] = $arg;
    }
}

if ($positional === []) {
    echo "Usage: php bin/fire-sandbox-webhook.php <item_id> [webhook_code] [--add-transactions=N]\n\n";
    echo "Webhook codes:\n";
    foreach (WEBHOOK_TYPES as $code => $type) {
        printf("  %-24s %s\n", $code, $type);
    }
    exit(0);
}

$itemId = $positional[0];
$code = strtoupper($positional[1] ?? 'SYNC_UPDATES_AVAILABLE');

if (!isset(WEBHOOK_TYPES[$code])) {
    fwrite(STDERR, "Unsupported webhook code: {$code}\n");
    exit(1);
}

$app = bootstrapCli();
$item = $app->integrations()->findItem($itemId);
if ($item === null) {
    fwrite(STDERR, "Unknown item: {$itemId}\n");
    exit(1);
}

$sandbox = $app->sandbox();

if ($addTransactions > 0) {
    $transactions = [];
    for ($i = 0; $i < $addTransactions; $i++) {
        $date = gmdate('Y-m-d', strtotime('-' . random_int(0, 2) . ' days'));
        $transactions[] = [
            'date_transacted'   => $date,
            'date_posted'       => $date,
            'amount'            => round(random_int(500, 50000) / 100, 2),
            'description'       => MERCHANTS[array_rand(MERCHANTS)],
            'iso_currency_code' => 'USD',
        ];
    }
    $sandbox->createTransactions($item->accessToken, $transactions);
    echo "Added {$addTransactions} sandbox transactions\n";
}

$sandbox->fireWebhook($item->accessToken, $code, WEBHOOK_TYPES[$code]);
echo "Fired {$code} for {$itemId}\n";
