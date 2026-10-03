<?php
declare(strict_types=1);

// Creates a Plaid sandbox item for the user_transactions_dynamic test user
// and registers an integration for it, so you can try the full flow without
// Plaid Link.
//
//   php bin/create-sandbox-item.php --interval=60 --alert-webhook=https://example.com/hook --secret=whsec_test

require __DIR__ . '/bootstrap.php';

use PlaidMonitor\Storage\Ids;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\Item;

$options = getopt('', ['interval:', 'alert-webhook:', 'secret:', 'email:', 'slack:', 'name:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php bin/create-sandbox-item.php [--interval=MINUTES] [--name=NAME]\n"
        . "       [--alert-webhook=URL --secret=SECRET] [--email=ADDRESS] [--slack=URL]\n";
    exit(0);
}

$app = bootstrapCli();
$plaid = $app->plaid();

if ($plaid->environment() !== 'sandbox') {
    fwrite(STDERR, "This script only runs with PLAID_ENV=sandbox\n");
    exit(1);
}

$config = $app->config();
$publicToken = $app->sandbox()->createPublicToken(webhook: $config->plaidWebhookUrl());
$exchange = $plaid->exchangePublicToken($publicToken);

$app->integrations()->saveItem(new Item($exchange['item_id'], $exchange['access_token'], 'Sandbox Bank'));

$integration = new Integration(
    integrationId: Ids::generate('int'),
    itemId: $exchange['item_id'],
    accountId: null,
    intervalMinutes: (int) ($options['interval'] ?? 60),
    rules: [
        [
            'id'         => 'large_purchase',
            'type'       => 'amount_threshold',
            'name'       => 'Large purchase',
            'enabled'    => true,
            'emailAlert' => true,
            'slackAlert' => true,
            'config'     => ['operator' => 'greater_than', 'threshold' => 100, 'currency' => 'USD'],
        ],
        [
            'id'         => 'new_merchant',
            'type'       => 'new_merchant',
            'name'       => 'New merchant',
            'enabled'    => true,
            'emailAlert' => false,
            'slackAlert' => false,
            'config'     => ['detectionType' => 'new', 'lookbackDays' => 90],
        ],
    ],
    name: $options['name'] ?? 'Sandbox checking',
    alertWebhookUrl: $options['alert-webhook'] ?? null,
    alertWebhookSecret: $options['secret'] ?? null,
    alertEmail: $options['email'] ?? null,
    slackWebhookUrl: $options['slack'] ?? null,
);
$app->integrations()->saveIntegration($integration);

echo "Item:        {$exchange['item_id']}\n";
echo "Integration: {$integration->integrationId} ({$integration->intervalMinutes} minute interval)\n";
if ($config->plaidWebhookUrl() === null) {
    echo "PLAID_WEBHOOK_URL is not set, so Plaid will not send webhooks for this item.\n";
}
