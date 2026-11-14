<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();

// Check if columns exist
$cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'pending_id_path'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN pending_id_path VARCHAR(255) NULL DEFAULT NULL AFTER id_photo_filename");
    echo "Added pending_id_path\n";
} else {
    echo "pending_id_path already exists\n";
}

$cols2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'pending_id_type'")->fetchAll();
if (empty($cols2)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN pending_id_type VARCHAR(50) NULL DEFAULT NULL AFTER pending_id_path");
    echo "Added pending_id_type\n";
} else {
    echo "pending_id_type already exists\n";
}

echo "Done.\n";
