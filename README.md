# plaid-transaction-monitor

Bank account monitoring through Plaid, with a rules engine and alert delivery.

## Why

Plaid checks each linked account for new transactions on its own schedule, usually one to four times a day. If you need to know about a transaction within the hour, that isn't enough, and calling `/transactions/refresh` every hour may get expensive fast depending on how many accounts you own.

This package lets each monitored account have its own interval. Short intervals are driven by paid refresh calls. Long intervals wait for Plaid's free automatic updates and process them when enough time has passed.

It was built as a B2B product for Authorize Earth and never released.

## How it works

Each monitored account (an "integration") has an interval in minutes. There are three moving parts:

- **Scheduler** (`bin/scheduler.php`, cron every 10 minutes): for integrations below the paid cutoff (default 24h) that are due, records a pending refresh request and calls `/transactions/refresh`. Integrations on the same item share one call.
- **Webhook endpoint** (`public/webhook.php`): verifies Plaid's signature, queues the event and responds immediately.
- **Worker** (`bin/worker.php`): locks the item, then for each integration decides what the webhook means. A pending request means it's the answer to a paid refresh. Otherwise it's one of Plaid's automatic updates, which is only used by integrations at or above the cutoff, once enough of their interval has passed. It then syncs from the saved cursor, skips transactions it has already seen, runs the rules, saves everything and sends alerts.

Paid refresh is off by default. With it off, every integration uses Plaid's automatic updates.

A few details worth knowing:

- Timing is measured from when processing last **started**, against a fraction of the interval (0.9 for the scheduler, 0.85 for automatic updates), so runs that land a little early or take a while don't push integrations back a cycle.
- A paid refresh that gets no webhook means Plaid found nothing new. The next cycle replaces the request.
- If a second webhook arrives while an item is being processed, the worker runs once more before releasing the lock instead of dropping it.
- When an account is first connected, Plaid sends up to 90 days of history. That is stored as a baseline for `velocity` and `new_merchant`, not alerted on.
- Each transaction is evaluated once. A pending transaction that posts isn't evaluated again, but two separate identical purchases both are, so double charges get caught.
- Intervals shorter than 10 minutes behave as 10 minutes, since that's how often the scheduler runs.

`src/Processor.php` is the best single file to read to see what happens to a transaction.

## Rules

Rules are configured per integration. Each rule has an `id`, `type`, `name`, `enabled`, `emailAlert`, `slackAlert` and a `config` object, for example `{"operator": "greater_than", "threshold": 1000, "currency": "USD"}` for `amount_threshold`.

| Type | Config | Flags a transaction when |
|---|---|---|
| `amount_threshold` | `operator` (`greater_than`, `less_than`, `equals`, `between`), `threshold`, `minAmount`, `maxAmount`, `currency` | the amount meets the condition |
| `new_merchant` | `detectionType` (`new`, `rare`), `lookbackDays`, `maxPurchaseCount` | the merchant hasn't been seen in the lookback window, or has been seen on fewer than `maxPurchaseCount` days |
| `transaction_category` | `categories` (Plaid personal finance category codes) | the category is in the list |
| `velocity` | `velocityType` (`total`, `count`, `average`), `multiplier`, `timeWindowDays`, `baselineDays` | outgoing spending in the window exceeds the baseline before it by the multiplier |
| `geolocation` | `allowedZones` (circles, polygons or rectangles of `[lat, lng]` points), `includeOnlineTransactions` | the location is outside every allowed zone |
| `duplicate_detection` | `timeWindowHours`, `amountThreshold` (percent), `checkMerchant`, `checkDescription` | a matching transaction exists within the window |

Severity is `low`, `medium`, `high` or `critical`. Geolocation uses the Google Geocoding API for transactions with an address but no coordinates.

## Alerts

A flagged run produces one alert record and is delivered through each configured channel:

- **Webhook**: sent when a URL and signing secret are configured, with every flagged transaction
- **Email**: sent when a triggered rule has `emailAlert` set
- **Slack**: sent when a triggered rule has `slackAlert` set and a Slack webhook URL is configured

Bank connection problems (`PENDING_EXPIRATION`, `PENDING_DISCONNECT`, `ERROR`) are delivered the same way as an `account.error` event. A revoked item or account removes its integrations.

Example `fraud_alert.detected` payload:

```json
{
  "event": "fraud_alert.detected",
  "timestamp": "2026-10-03T14:00:00Z",
  "data": {
    "alert_id": "alert_3f9c...",
    "integration_id": "int_1",
    "item_id": "item_abc",
    "account_id": null,
    "monitored_account_name": "Checking",
    "institution_name": "First Platypus Bank",
    "source": "paid_refresh",
    "severity": "critical",
    "transactions_checked": 12,
    "total_flagged_transactions": 1,
    "total_flagged_amount": { "USD": 6000.0 },
    "flagged_transactions": [
      {
        "transaction_id": "lPNjeW1nR6CDn5okmGQ6hEpMo4lLNoSrzqDje",
        "account_id": "BxBXxLj1m4HMXBm9WZZmCWVbPjX16EHwv99vp",
        "amount": 6000.0,
        "currency": "USD",
        "merchant_name": "Jeweler",
        "category": "GENERAL_MERCHANDISE_OTHER_GENERAL_MERCHANDISE",
        "date": "2026-10-03",
        "pending": false,
        "severity": "critical",
        "triggered_rules": [
          {
            "rule_id": "rule_01",
            "rule_name": "Large purchase",
            "rule_type": "amount_threshold",
            "severity": "critical",
            "reason": "Transaction amount USD 6,000.00 exceeds threshold of USD 1,000.00",
            "email_alert": true,
            "slack_alert": false
          }
        ]
      }
    ]
  }
}
```

Each request has an `X-Webhook-Signature` header: the hex HMAC-SHA256 of `{timestamp}.{raw body}` with your signing secret. Reject requests whose timestamp is more than five minutes old. Failed deliveries are retried after 5, 10 and 15 seconds.

## Setup

Requires PHP 8.1+, Composer, MySQL 8 / MariaDB 10.6+ (or SQLite for a single server), and a Plaid account with Transactions. Transactions Refresh is only needed for paid refresh, and a Google Geocoding key only for the geolocation rule.

```bash
composer install
cp .env.example .env
php bin/init-db.php
```

Every setting is documented in `.env.example`. Point Plaid's webhook URL at `public/webhook.php`, then run `bin/scheduler.php` from cron every 10 minutes and `bin/worker.php` every minute (or `bin/worker.php --loop` under a supervisor).

Register accounts through `App::integrations()` after exchanging a Link public token; `bin/create-sandbox-item.php` shows the whole flow against the Plaid sandbox. Storage goes through interfaces in `src/Storage` with PDO implementations included, and an `EligibilityPolicy` hook lets you add your own checks such as billing. Set `ACCESS_TOKEN_KEY` to encrypt stored access tokens.

## Testing

`composer test` runs the unit tests offline. `composer test-sandbox` runs against the Plaid sandbox and needs sandbox credentials.

## License

MIT
