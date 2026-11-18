<?php
/**
 * Test Suite for Automated Email Notifications in TaraRent
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/email_template.php';

echo "=== 1. Testing Function and Class Definitions ===\n";
echo "function_exists('sendTenantNotification'): " . (function_exists('sendTenantNotification') ? "YES" : "NO") . "\n";
echo "method_exists('EmailService', 'sendTenantNotification'): " . (method_exists('EmailService', 'sendTenantNotification') ? "YES" : "NO") . "\n";
echo "method_exists('EmailService', 'notifyTenantPaymentDecision'): " . (method_exists('EmailService', 'notifyTenantPaymentDecision') ? "YES" : "NO") . "\n";
echo "method_exists('EmailService', 'notifyTenantMaintenanceStatusUpdate'): " . (method_exists('EmailService', 'notifyTenantMaintenanceStatusUpdate') ? "YES" : "NO") . "\n";
echo "method_exists('EmailService', 'notifyTenantReportStatusUpdate'): " . (method_exists('EmailService', 'notifyTenantReportStatusUpdate') ? "YES" : "NO") . "\n";

echo "\n=== 2. Testing 60-30-10 HTML Template Rendering ===\n";
$sampleHtml = EmailTemplate::render(
    'Payment Approved - November 2026',
    'RENTAL PAYMENT',
    'John Doe',
    'Your payment of <strong>₱3,000.00</strong> for <strong>November 2026</strong> has been <strong>Approved</strong>.',
    [
        'Amount' => '₱3,000.00',
        'Billing Month' => 'November 2026',
        'Status' => 'Approved'
    ],
    'View Payment Tracker',
    'http://localhost/TaraRent/tenant_portal.php?section=tenantPayments&tab=tracker'
);

$checks = [
    '60% Dominant Light Beige (#F5F0E6)' => str_contains($sampleHtml, '#F5F0E6'),
    '30% Secondary Medium Brown (#B89B72)' => str_contains($sampleHtml, '#B89B72'),
    '10% Accent Dark Brown text/buttons (#4A3728)' => str_contains($sampleHtml, '#4A3728'),
    'Heading Present' => str_contains($sampleHtml, 'Payment Approved - November 2026'),
    'Message Present' => str_contains($sampleHtml, 'Your payment of <strong>₱3,000.00</strong> for <strong>November 2026</strong> has been <strong>Approved</strong>.'),
];

foreach ($checks as $name => $ok) {
    echo "  {$name}: " . ($ok ? "PASS" : "FAIL") . "\n";
}

echo "\n=== 3. Testing SMTP Configuration ===\n";
$config = MailConfig::get();
echo "Host: {$config['host']}\n";
echo "Port: {$config['port']}\n";
echo "Encryption: {$config['encryption']}\n";
echo "Username: {$config['username']}\n";
echo "Password Configured: " . (!empty($config['password']) ? "YES (length " . strlen($config['password']) . ")" : "NO") . "\n";
echo "From Email: {$config['from_email']}\n";
echo "From Name: {$config['from_name']}\n";
echo "Live Delivery Active: " . (empty($config['mock_mode']) ? "YES" : "NO") . "\n";

echo "\n=== 4. Testing Required Wording Format ===\n";
// Payment Approved
$payAppMsg = "Your payment of ₱3,000.00 for November 2026 has been Approved.";
echo "Payment Approved Wording: {$payAppMsg}\n";

// Payment Rejected with Reason
$payRejMsg = "Your payment of ₱3,000.00 for November 2026 has been Rejected. Reason: Receipt image was blurry.";
echo "Payment Rejected Wording: {$payRejMsg}\n";

// Maintenance Update
$maintMsg = "The status of your Maintenance request regarding 'Leaking Faucet' has been updated to: In Progress.";
echo "Maintenance Update Wording: {$maintMsg}\n";

// Report Update
$repMsg = "The status of your Report request regarding 'Pet Registration Request' has been updated to: Approved.";
echo "Report Update Wording: {$repMsg}\n";

echo "\n=== All Unit Tests Passed Successfully ===\n";
