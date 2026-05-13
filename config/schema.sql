CREATE DATABASE IF NOT EXISTS health_watches
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
    imei          VARCHAR(15)    NOT NULL PRIMARY KEY,
    model         VARCHAR(50)    NOT NULL,
    label         VARCHAR(255)   NOT NULL DEFAULT '',
    enabled       TINYINT(1)     NOT NULL DEFAULT 1,
    registered_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_events (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    imei           VARCHAR(15)     NOT NULL,
    model          VARCHAR(50)     NOT NULL,
    native_type    VARCHAR(100)    NOT NULL,
    feature        VARCHAR(50)     NULL,
    native_payload JSON            NOT NULL,
    received_at    BIGINT UNSIGNED NOT NULL COMMENT 'epoch milliseconds',
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_imei (imei),
    INDEX idx_events_imei_received (imei, received_at DESC),
    INDEX idx_events_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
