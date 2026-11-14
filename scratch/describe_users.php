<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getConnection();
$cols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . " (" . $c['Type'] . ")\n";
}
