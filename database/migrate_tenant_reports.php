<?php
/**
 * Migration: Create dedicated tenant_reports table for general non-maintenance requests.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'pgsql') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tenant_reports (
                id SERIAL PRIMARY KEY,
                tenant_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                unit_id INT NOT NULL REFERENCES units(id) ON DELETE CASCADE,
                category VARCHAR(100) NOT NULL,
                subject VARCHAR(180) NOT NULL,
                details TEXT NOT NULL,
                attachment_path VARCHAR(1000) NULL DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Pending',
                owner_remarks TEXT NULL DEFAULT NULL,
                reviewed_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_tr_tenant ON tenant_reports (tenant_id);
            CREATE INDEX IF NOT EXISTS idx_tr_unit ON tenant_reports (unit_id);
            CREATE INDEX IF NOT EXISTS idx_tr_status ON tenant_reports (status);
            CREATE INDEX IF NOT EXISTS idx_tr_created ON tenant_reports (created_at);
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tenant_reports (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL,
                unit_id INT UNSIGNED NOT NULL,
                category VARCHAR(100) NOT NULL,
                subject VARCHAR(180) NOT NULL,
                details TEXT NOT NULL,
                attachment_path VARCHAR(1000) NULL DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Pending',
                owner_remarks TEXT NULL DEFAULT NULL,
                reviewed_by INT UNSIGNED NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tr_tenant (tenant_id),
                KEY idx_tr_unit (unit_id),
                KEY idx_tr_status (status),
                KEY idx_tr_created (created_at),
                CONSTRAINT fk_tr_tenant
                    FOREIGN KEY (tenant_id) REFERENCES users (id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_tr_unit
                    FOREIGN KEY (unit_id) REFERENCES units (id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    echo "tenant_reports table ready.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
