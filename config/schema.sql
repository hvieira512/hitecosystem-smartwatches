CREATE DATABASE IF NOT EXISTS health_watches
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50)  NOT NULL,
    enabled     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS models (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id  INT UNSIGNED NOT NULL,
    code         VARCHAR(50)  NOT NULL,
    name         VARCHAR(255) NOT NULL,
    protocol     VARCHAR(100) NOT NULL,
    transport    VARCHAR(100) NOT NULL,
    source_doc   VARCHAR(255) NULL,
    enabled      TINYINT(1)   NOT NULL DEFAULT 1,
    passive      JSON         NOT NULL,
    active       JSON         NOT NULL,
    features     JSON         NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_models_code (code),
    KEY idx_models_supplier (supplier_id),
    KEY idx_models_protocol_transport (protocol, transport),
    CONSTRAINT fk_models_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
    imei          VARCHAR(15)   NOT NULL PRIMARY KEY,
    model_id      INT UNSIGNED  NOT NULL,
    enabled       TINYINT(1)    NOT NULL DEFAULT 1,
    registered_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_devices_model_id (model_id),
    KEY idx_devices_enabled (enabled),
    CONSTRAINT fk_devices_model
        FOREIGN KEY (model_id) REFERENCES models(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_events (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    imei           VARCHAR(15)     NOT NULL,
    native_type    VARCHAR(100)    NOT NULL,
    feature        VARCHAR(50)     NULL,
    native_payload JSON            NOT NULL,
    received_at    BIGINT UNSIGNED NOT NULL COMMENT 'epoch milliseconds',
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_imei (imei),
    INDEX idx_events_imei_received (imei, received_at DESC),
    INDEX idx_events_created (created_at DESC),
    CONSTRAINT fk_events_device
        FOREIGN KEY (imei) REFERENCES devices(imei)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
