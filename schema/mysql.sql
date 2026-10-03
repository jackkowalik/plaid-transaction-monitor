-- plaid-transaction-monitor schema for MySQL 8 / MariaDB 10.6+.
-- All timestamps are UTC, written by the application.

CREATE TABLE IF NOT EXISTS items (
    item_id          VARCHAR(64)  NOT NULL PRIMARY KEY,
    access_token     VARCHAR(512) NOT NULL,
    institution_name VARCHAR(255) NULL,
    created_at       DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integrations (
    integration_id               VARCHAR(64)  NOT NULL PRIMARY KEY,
    item_id                      VARCHAR(64)  NOT NULL,
    account_id                   VARCHAR(64)  NULL,
    name                         VARCHAR(255) NULL,
    interval_minutes             INT          NOT NULL,
    status                       VARCHAR(16)  NOT NULL DEFAULT 'active',
    rules                        JSON         NOT NULL,
    alert_webhook_url            VARCHAR(2048) NULL,
    alert_webhook_secret         VARCHAR(255) NULL,
    alert_email                  VARCHAR(320) NULL,
    slack_webhook_url            VARCHAR(2048) NULL,
    sync_cursor                  TEXT         NULL,
    last_processing_started_at   DATETIME     NULL,
    last_processed_at            DATETIME     NULL,
    last_paid_refresh_at         DATETIME     NULL,
    last_automatic_update_at     DATETIME     NULL,
    baseline_complete            TINYINT(1)   NOT NULL DEFAULT 0,
    total_transactions_processed INT          NOT NULL DEFAULT 0,
    total_alerts_generated       INT          NOT NULL DEFAULT 0,
    created_at                   DATETIME     NOT NULL,
    updated_at                   DATETIME     NOT NULL,
    INDEX idx_integrations_item (item_id),
    INDEX idx_integrations_status_interval (status, interval_minutes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    integration_id   VARCHAR(64)    NOT NULL,
    transaction_id   VARCHAR(128)   NOT NULL,
    account_id       VARCHAR(64)    NOT NULL,
    fingerprint      CHAR(64)       NOT NULL,
    transaction_date DATE           NOT NULL,
    amount           DECIMAL(14, 2) NOT NULL,
    currency         CHAR(3)        NOT NULL,
    merchant         VARCHAR(255)   NOT NULL,
    name             VARCHAR(255)   NOT NULL,
    category         VARCHAR(128)   NULL,
    pending          TINYINT(1)     NOT NULL DEFAULT 0,
    flagged          TINYINT(1)     NOT NULL DEFAULT 0,
    triggered_rules  JSON           NULL,
    data             JSON           NOT NULL,
    created_at       DATETIME       NOT NULL,
    updated_at       DATETIME       NOT NULL,
    UNIQUE KEY uq_transactions_integration_txn (integration_id, transaction_id),
    INDEX idx_transactions_fingerprint (integration_id, fingerprint),
    INDEX idx_transactions_date (integration_id, transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS merchant_history (
    integration_id VARCHAR(64)  NOT NULL,
    merchant       VARCHAR(255) NOT NULL,
    seen_date      DATE         NOT NULL,
    PRIMARY KEY (integration_id, merchant, seen_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_requests (
    request_id     VARCHAR(64) NOT NULL PRIMARY KEY,
    integration_id VARCHAR(64) NOT NULL,
    item_id        VARCHAR(64) NOT NULL,
    created_at     DATETIME    NOT NULL,
    INDEX idx_refresh_requests_integration (integration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
    alert_id       VARCHAR(64) NOT NULL PRIMARY KEY,
    kind           VARCHAR(32) NOT NULL,
    item_id        VARCHAR(64) NOT NULL,
    integration_id VARCHAR(64) NULL,
    severity       VARCHAR(16) NOT NULL,
    payload        JSON        NOT NULL,
    created_at     DATETIME    NOT NULL,
    INDEX idx_alerts_integration (integration_id, created_at),
    INDEX idx_alerts_item (item_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_locks (
    item_id         VARCHAR(64) NOT NULL PRIMARY KEY,
    token           VARCHAR(64) NOT NULL,
    locked_at       DATETIME    NOT NULL,
    expires_at      DATETIME    NOT NULL,
    rerun_requested TINYINT(1)  NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_jobs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id       VARCHAR(64) NOT NULL,
    webhook_type  VARCHAR(64) NOT NULL,
    webhook_code  VARCHAR(64) NOT NULL,
    payload       JSON        NOT NULL,
    status        VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts      INT         NOT NULL DEFAULT 0,
    available_at  DATETIME    NOT NULL,
    claimed_until DATETIME    NULL,
    last_error    TEXT        NULL,
    created_at    DATETIME    NOT NULL,
    INDEX idx_webhook_jobs_status (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cache_entries (
    cache_key  VARCHAR(191) NOT NULL PRIMARY KEY,
    value      MEDIUMTEXT   NOT NULL,
    expires_at DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
