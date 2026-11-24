<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();
    $u1 = $pdo->query("SELECT id, name, role, email, profile_picture FROM users WHERE id = 1")->fetch();
    echo "User 1 profile_picture: " . var_export($u1['profile_picture'], true) . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
