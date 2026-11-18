<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT id, username, name, email, role, is_active FROM users ORDER BY id ASC");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$r['id']} | Username: '{$r['username']}' | Email: '{$r['email']}' | Name: '{$r['name']}' | Role: '{$r['role']}'\n";
}
