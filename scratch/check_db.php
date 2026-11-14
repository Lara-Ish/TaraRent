<?php
require_once 'config/db.php';
$pdo = Database::getConnection();
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'verification_status'");
print_r($stmt->fetch(PDO::FETCH_ASSOC));

$stmt2 = $pdo->query("SHOW COLUMNS FROM users");
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    if (strpos($row['Field'], 'verif') !== false || strpos($row['Field'], 'id_') !== false) {
        echo $row['Field'] . ' -> ' . $row['Type'] . "\n";
    }
}
