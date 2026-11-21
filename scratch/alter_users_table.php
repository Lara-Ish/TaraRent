<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo = Database::getConnection();
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS id_photo_back_filename VARCHAR(255)");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS pending_id_back_path VARCHAR(255)");
    echo "Successfully added columns id_photo_back_filename and pending_id_back_path!\n";
} catch (Exception $e) {
    echo "Alter table error: " . $e->getMessage() . "\n";
}
