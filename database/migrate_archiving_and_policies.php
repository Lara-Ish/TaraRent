<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Running database migration for Tenant Record Archiving...\n";

    // 1. Add archival columns to users table if not existing
    $colStmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'users'");
    $userCols = array_map('strtolower', $colStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!in_array('is_archived', $userCols, true)) {
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE");
        } else {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        }
        echo "- Added 'is_archived' column to users.\n";
    }

    if (!in_array('deleted_at', $userCols, true)) {
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
        } else {
            $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER is_archived");
        }
        echo "- Added 'deleted_at' column to users.\n";
    }

    if (!in_array('archived_unit_number', $userCols, true)) {
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS archived_unit_number VARCHAR(50) NULL");
        } else {
            $pdo->exec("ALTER TABLE users ADD COLUMN archived_unit_number VARCHAR(50) NULL AFTER deleted_at");
        }
        echo "- Added 'archived_unit_number' column to users.\n";
    }

    if (!in_array('archived_monthly_rent', $userCols, true)) {
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS archived_monthly_rent DECIMAL(10,2) NULL");
        } else {
            $pdo->exec("ALTER TABLE users ADD COLUMN archived_monthly_rent DECIMAL(10,2) NULL AFTER archived_unit_number");
        }
        echo "- Added 'archived_monthly_rent' column to users.\n";
    }

    if (!in_array('archived_spaces_text', $userCols, true)) {
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS archived_spaces_text VARCHAR(255) NULL");
        } else {
            $pdo->exec("ALTER TABLE users ADD COLUMN archived_spaces_text VARCHAR(255) NULL AFTER archived_monthly_rent");
        }
        echo "- Added 'archived_spaces_text' column to users.\n";
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
