<?php
/**
 * TaraRent - Email Log Viewer (dev only - delete after debugging)
 * URL: http://localhost/TaraRent/email_log_viewer.php?token=TaraRentDev2026
 */
$token = $_GET['token'] ?? '';
if ($token !== 'TaraRentDev2026') { http_response_code(403); die('403'); }

require_once __DIR__ . '/config/auth.php';
$pdo = Database::getConnection();

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html>
<head><title>Email Log Viewer</title>
<style>
body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 20px; }
table { border-collapse: collapse; width: 100%; font-size: 12px; }
th { background: #1e3a5f; padding: 8px; text-align: left; }
td { padding: 6px 8px; border-bottom: 1px solid #334155; vertical-align: top; max-width: 300px; word-break: break-word; }
.sent { color: #4ade80; } .failed { color: #f87171; } .mock_sent { color: #facc15; }
h2 { color: #38bdf8; }
</style>
</head>
<body>
<h2>📧 TaraRent Email Logs (Last 20)</h2>
<p style="color:#94a3b8">Time: <?= date('Y-m-d H:i:s') ?></p>
<?php
$stmt = $pdo->query("SELECT id, recipient_email, subject, template_type, status, error_message, sent_at FROM email_logs ORDER BY sent_at DESC LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo '<table><tr><th>ID</th><th>To</th><th>Subject</th><th>Type</th><th>Status</th><th>Error</th><th>Time</th></tr>';
foreach ($rows as $r) {
    $cls = htmlspecialchars($r['status']);
    echo "<tr>
        <td>{$r['id']}</td>
        <td>" . htmlspecialchars($r['recipient_email']) . "</td>
        <td>" . htmlspecialchars($r['subject']) . "</td>
        <td>" . htmlspecialchars($r['template_type']) . "</td>
        <td class='{$cls}'><strong>{$cls}</strong></td>
        <td>" . htmlspecialchars((string)($r['error_message'] ?? '')) . "</td>
        <td>" . htmlspecialchars((string)($r['sent_at'] ?? '')) . "</td>
    </tr>";
}
echo '</table>';

// Also show MailConfig state
$config = \MailConfig::get();
echo "<h2 style='margin-top:30px'>⚙️ MailConfig State</h2>";
echo "<pre style='background:#1e293b;padding:16px;border-radius:8px'>";
echo "SMTP Host  : " . htmlspecialchars($config['host']) . "\n";
echo "SMTP Port  : " . $config['port'] . "\n";
echo "SMTP Enc   : " . htmlspecialchars($config['encryption']) . "\n";
echo "SMTP User  : " . htmlspecialchars($config['username']) . "\n";
echo "Has SMTP   : " . ($config['has_smtp'] ? 'YES ✅' : 'NO ❌') . "\n";
echo "Has Webhook: " . ($config['has_webhook'] ? 'YES (fallback only)' : 'NO') . "\n";
echo "Mock Mode  : " . ($config['mock_mode'] ? 'YES ⚠️ (emails are NOT sent!)' : 'NO ✅') . "\n";
echo "Is Configured: " . ($config['is_configured'] ? 'YES ✅' : 'NO ❌') . "\n";
echo "</pre>";
echo "<p style='color:#64748b;font-size:11px'>⚠️ Delete email_log_viewer.php after debugging.</p>";
?>
</body></html>
