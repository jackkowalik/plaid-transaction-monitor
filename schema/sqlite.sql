-- plaid-transaction-monitor schema for SQLite.
-- All timestamps are UTC 'YYYY-MM-DD HH:MM:SS' strings, written by the application.

CREATE TABLE IF NOT EXISTS items (
    item_id          TEXT NOT NULL PRIMARY KEY,
    access_token     TEXT NOT NULL,
    institution_name TEXT NULL,
    created_at       TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS integrations (
    integration_id               TEXT    NOT NULL PRIMARY KEY,
    item_id                      TEXT    NOT NULL,
    account_id                   TEXT    NULL,
    name                         TEXT    NULL,
    interval_minutes             INTEGER NOT NULL,
    status                       TEXT    NOT NULL DEFAULT 'active',
    rules                        TEXT    NOT NULL,
    alert_webhook_url            TEXT    NULL,
    alert_webhook_secret         TEXT    NULL,
    alert_email                  TEXT    NULL,
    slack_webhook_url            TEXT    NULL,
    sync_cursor                  TEXT    NULL,
    last_processing_started_at   TEXT    NULL,
    last_processed_at            TEXT    NULL,
    last_paid_refresh_at         TEXT    NULL,
    last_automatic_update_at     TEXT    NULL,
    baseline_complete            INTEGER NOT NULL DEFAULT 0,
    total_transactions_processed INTEGER NOT NULL DEFAULT 0,
    total_alerts_generated       INTEGER NOT NULL DEFAULT 0,
    created_at                   TEXT    NOT NULL,
    updated_at                   TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_integrations_item ON integrations (item_id);
CREATE INDEX IF NOT EXISTS idx_integrations_status_interval ON integrations (status, interval_minutes);

CREATE TABLE IF NOT EXISTS transactions (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    integration_id   TEXT    NOT NULL,
    transaction_id   TEXT    NOT NULL,
    account_id       TEXT    NOT NULL,
    fingerprint      TEXT    NOT NULL,
    transaction_date TEXT    NOT NULL,
    amount           REAL    NOT NULL,
    currency         TEXT    NOT NULL,
    merchant         TEXT    NOT NULL,
    name             TEXT    NOT NULL,
    category         TEXT    NULL,
    pending          INTEGER NOT NULL DEFAULT 0,
    flagged          INTEGER NOT NULL DEFAULT 0,
    triggered_rules  TEXT    NULL,
    data             TEXT    NOT NULL,
    created_at       TEXT    NOT NULL,
    updated_at       TEXT    NOT NULL,
    UNIQUE (integration_id, transaction_id)
);
CREATE INDEX IF NOT EXISTS idx_transactions_fingerprint ON transactions (integration_id, fingerprint);
CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions (integration_id, transaction_date);

CREATE TABLE IF NOT EXISTS merchant_history (
    integration_id TEXT NOT NULL,
    merchant       TEXT NOT NULL,
    seen_date      TEXT NOT NULL,
    PRIMARY KEY (integration_id, merchant, seen_date)
);

CREATE TABLE IF NOT EXISTS refresh_requests (
    request_id     TEXT NOT NULL PRIMARY KEY,
    integration_id TEXT NOT NULL,
    item_id        TEXT NOT NULL,
    created_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_refresh_requests_integration ON refresh_requests (integration_id);

CREATE TABLE IF NOT EXISTS alerts (
    alert_id       TEXT NOT NULL PRIMARY KEY,
    kind           TEXT NOT NULL,
    item_id        TEXT NOT NULL,
    integration_id TEXT NULL,
    severity       TEXT NOT NULL,
    payload        TEXT NOT NULL,
    created_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_alerts_integration ON alerts (integration_id, created_at);
CREATE INDEX IF NOT EXISTS idx_alerts_item ON alerts (item_id, created_at);

CREATE TABLE IF NOT EXISTS item_locks (
    item_id         TEXT    NOT NULL PRIMARY KEY,
    token           TEXT    NOT NULL,
    locked_at       TEXT    NOT NULL,
    expires_at      TEXT    NOT NULL,
    rerun_requested INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS webhook_jobs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id       TEXT    NOT NULL,
    webhook_type  TEXT    NOT NULL,
    webhook_code  TEXT    NOT NULL,
    payload       TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',
    attempts      INTEGER NOT NULL DEFAULT 0,
    available_at  TEXT    NOT NULL,
    claimed_until TEXT    NULL,
    last_error    TEXT    NULL,
    created_at    TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_webhook_jobs_status ON webhook_jobs (status, available_at);

CREATE TABLE IF NOT EXISTS cache_entries (
    cache_key  TEXT NOT NULL PRIMARY KEY,
    value      TEXT NOT NULL,
    expires_at TEXT NULL
);
