<?php
/**
 * RentConnect - Centralized Email Service
 * Powered by PHPMailer with Gmail SMTP TLS encryption.
 * Handles transactional emails, password resets, system event notifications, and scheduled reminders.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/mail.php';
require_once dirname(__DIR__) . '/includes/email_template.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!function_exists('sendViaHttpWebhook')) {
    /**
     * Send email via HTTPS (Port 443) using Google Apps Script Web App.
     * Bypasses Render Free Tier outbound SMTP port blocks (25, 465, 587).
     */
    function sendViaHttpWebhook(array $config, string $toEmail, string $subject, string $htmlBody, string $altBody = ''): array
    {
        $webhookUrl = $config['webhook_url'] ?? '';
        if (empty($webhookUrl)) {
            return ['success' => false, 'error' => 'EMAIL_WEBHOOK_URL is not configured.'];
        }

        $plainText = $altBody !== ''
            ? $altBody
            : strip_tags(str_replace(['<br>', '<br/>', '<p>', '</p>'], ["\n", "\n", "\n\n", ''], $htmlBody));

        $payload = json_encode([
            'secret'    => $config['webhook_secret'] ?? '',
            'to'        => $toEmail,
            'subject'   => $subject,
            'html'      => $htmlBody,
            'text'      => $plainText,
            'from_name' => $config['from_name'] ?? 'TaraRent',
        ]);

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true, // Follow Google's 302 redirect as GET (do NOT set CURLOPT_POSTREDIR)
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen((string)$payload),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return ['success' => false, 'error' => "Webhook cURL Error: {$curlErr}"];
        }

        $decoded = json_decode((string)$response, true);
        if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && !empty($decoded['success'])) {
            return ['success' => true, 'error' => null];
        }

        $apiError = is_array($decoded) && !empty($decoded['error'])
            ? $decoded['error']
            : "HTTP {$httpCode} - " . substr(strip_tags((string)$response), 0, 200);

        return ['success' => false, 'error' => "Webhook Error: {$apiError}"];
    }
}

if (!function_exists('resolveSmtpIpv4Host')) {
    /**
     * Resolve SMTP hostname to IPv4 on Windows/XAMPP to avoid IPv6 connection timeouts.
     */
    function resolveSmtpIpv4Host(string $host): string
    {
        $resolvedIp = gethostbyname($host);
        if ($resolvedIp !== $host && filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $resolvedIp;
        }
        return $host;
    }
}

if (!function_exists('sendViaPhpMailerSmtp')) {
    /**
     * Send email via PHPMailer SMTP with automatic TLS (587) -> SSL (465) fallback for Gmail.
     *
     * @return array{success: bool, error: ?string}
     */
    function sendViaPhpMailerSmtp(array $config, string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): array
    {
        if (empty($config['username']) || empty($config['password'])) {
            return ['success' => false, 'error' => 'SMTP credentials are not configured.'];
        }

        $primaryEnc = ($config['encryption'] === 'ssl' || $config['encryption'] === 'smtps')
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $primaryPort = (int)($config['port'] ?? 587);

        $attempts = [
            ['port' => $primaryPort, 'encryption' => $primaryEnc],
        ];
        if (stripos((string)$config['host'], 'gmail.com') !== false && $primaryPort !== 465) {
            $attempts[] = ['port' => 465, 'encryption' => PHPMailer::ENCRYPTION_SMTPS];
        }

        $plainText = $altBody !== ''
            ? $altBody
            : strip_tags(str_replace(['<br>', '<br/>', '<p>', '</p>'], ["\n", "\n", "\n\n", ''], $htmlBody));

        $lastError = 'Unknown SMTP error.';

        $smtpHost = resolveSmtpIpv4Host((string)$config['host']);

        foreach ($attempts as $attempt) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $smtpHost;
                $mail->SMTPAuth   = $config['auth'];
                $mail->Username   = $config['username'];
                $mail->Password   = $config['password'];
                $mail->SMTPSecure = $attempt['encryption'];
                $mail->Port       = $attempt['port'];
                $mail->CharSet    = 'UTF-8';
                $mail->Timeout    = 15;

                // Windows / XAMPP SSL stream options to ensure reliable TLS handshake
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ]
                ];

                $mail->setFrom($config['from_email'], $config['from_name']);
                $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
                $mail->addReplyTo($config['from_email'], $config['from_name']);

                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $htmlBody;
                $mail->AltBody = $plainText;

                $mail->send();
                return ['success' => true, 'error' => null];
            } catch (PHPMailerException $e) {
                $lastError = 'PHPMailer Error: ' . ($mail->ErrorInfo ?: $e->getMessage());
            } catch (Throwable $e) {
                $lastError = 'System Error: ' . $e->getMessage();
            }
        }

        return ['success' => false, 'error' => $lastError];
    }
}

if (!function_exists('dispatchConfiguredEmail')) {
    /**
     * Route outbound email across SMTP and HTTP webhook with automatic fallback.
     *
     * @return array{success: bool, error: ?string}
     */
    function dispatchConfiguredEmail(array $config, string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): array
    {
        $hasWebhook = !empty($config['has_webhook']);
        $hasSmtp = !empty($config['has_smtp']) || (!empty($config['username']) && !empty($config['password']));
        $isRender = (bool)getenv('RENDER');

        $transports = $isRender ? ['webhook', 'smtp'] : ['smtp', 'webhook'];
        $errors = [];

        foreach ($transports as $transport) {
            if ($transport === 'smtp' && $hasSmtp) {
                $res = sendViaPhpMailerSmtp($config, $toEmail, $toName, $subject, $htmlBody, $altBody);
                if (!empty($res['success'])) {
                    return $res;
                }
                if (!empty($res['error'])) {
                    $errors[] = $res['error'];
                }
            } elseif ($transport === 'webhook' && $hasWebhook) {
                $res = sendViaHttpWebhook($config, $toEmail, $subject, $htmlBody, $altBody);
                if (!empty($res['success'])) {
                    return $res;
                }
                if (!empty($res['error'])) {
                    $errors[] = $res['error'];
                }
            }
        }

        return [
            'success' => false,
            'error'   => !empty($errors) ? implode(' | ', $errors) : 'No working email transport configured.'
        ];
    }
}

if (!function_exists('sendEmail')) {
    /**
     * Reusable centralized email sending function using PHPMailer and Gmail SMTP.
     * The recipient email MUST come dynamically from the database.
     *
     * @param string $recipientEmail Recipient email dynamically retrieved from database
     * @param string $subject Subject line
     * @param string $message HTML message body
     * @param int|null $recipientUserId Optional recipient user/account ID from database
     * @param PDO|null $pdo Optional PDO database connection for logging to email_logs
     * @return bool True if successfully dispatched
     * @throws Exception If recipient email is invalid or delivery fails
     */
    function sendEmail(string $recipientEmail, string $subject, string $message, ?int $recipientUserId = null, ?PDO $pdo = null): bool
    {
        $recipientEmail = trim($recipientEmail);

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            if ($pdo !== null) {
                EmailService::logEmail($pdo, $recipientEmail, '', $subject, 'general', null, null, 'failed', 'Invalid recipient email address.', $recipientUserId);
            }
            throw new Exception("Invalid recipient email address.");
        }

        $config = MailConfig::get();

        // If credentials are not set in .env (Mock Mode), record and return true
        if (!empty($config['mock_mode'])) {
            if ($pdo !== null) {
                EmailService::logEmail($pdo, $recipientEmail, '', $subject, 'general', null, null, 'mock_sent', 'Dispatched via Mock Mode (Configure Gmail SMTP credentials in .env for live delivery).', $recipientUserId);
            }
            return true;
        }

        $res = dispatchConfiguredEmail($config, $recipientEmail, '', $subject, $message);
        if (!empty($res['success'])) {
            if ($pdo !== null) {
                EmailService::logEmail($pdo, $recipientEmail, '', $subject, 'general', null, null, 'sent', null, $recipientUserId);
            }
            return true;
        }

        $err = (string)($res['error'] ?? 'Failed to dispatch email.');
        if ($pdo !== null) {
            EmailService::logEmail($pdo, $recipientEmail, '', $subject, 'general', null, null, 'failed', $err, $recipientUserId);
        }
        error_log("[TaraRent Mail] Failed to send email to {$recipientEmail}: {$err}");
        throw new Exception($err);
    }
}

if (!function_exists('sendTenantNotification')) {
    /**
     * Reusable tenant notification email function.
     * Uses PHPMailer configured for Gmail SMTP (smtp.gmail.com:587/465, TLS/SSL)
     * and styles the HTML body using the TaraRent 60-30-10 palette.
     *
     * @param string $tenantEmail Tenant's Gmail address (from DB)
     * @param string $subject Email subject line
     * @param string $messageBody Content/message body
     * @param string|null $recipientName Optional tenant name
     * @param array $details Optional key-value details table
     * @param string|null $buttonText Optional CTA button text
     * @param string|null $buttonUrl Optional CTA button URL
     * @param int|null $tenantId Optional tenant user ID
     * @param PDO|null $pdo Optional PDO instance for logging
     * @return bool True if successfully dispatched
     */
    function sendTenantNotification(
        string $tenantEmail,
        string $subject,
        string $messageBody,
        ?string $recipientName = null,
        array $details = [],
        ?string $buttonText = null,
        ?string $buttonUrl = null,
        ?int $tenantId = null,
        ?PDO $pdo = null
    ): bool {
        return EmailService::sendTenantNotification(
            $tenantEmail,
            $subject,
            $messageBody,
            $recipientName,
            $details,
            $buttonText,
            $buttonUrl,
            $tenantId,
            $pdo
        );
    }
}

class EmailService
{
    /**
     * Send an email using PHPMailer with Gmail SMTP TLS
     *
     * @param PDO|null $pdo Database connection for logging
     * @param string $toEmail Recipient email address
     * @param string $toName Recipient full name
     * @param string $subject Email subject
     * @param string $htmlBody Rendered HTML body
     * @param string $templateType Category of email (password_reset, payment, etc.)
     * @param int|null $referenceId Optional reference entity ID
     * @param string|null $referenceType Optional reference entity type
     * @param bool $checkDuplicate Whether to block duplicate sends for this reference
     * @param string|null $altBody Optional plain-text alternative body
     * @param int|null $recipientUserId Optional recipient user/account ID from database
     * @return bool True if sent (or mock_sent), false on error
     */
    public static function send(
        ?PDO $pdo,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $templateType = 'general',
        ?int $referenceId = null,
        ?string $referenceType = null,
        bool $checkDuplicate = false,
        ?string $altBody = null,
        ?int $recipientUserId = null
    ): bool {
        $toEmail = trim($toEmail);
        $toName = trim($toName);

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEmail($pdo, $toEmail, $toName, $subject, $templateType, $referenceId, $referenceType, 'failed', 'Invalid recipient email format.', $recipientUserId);
            return false;
        }

        // Check deduplication if requested (prevent duplicate sends for the same reference event)
        if ($checkDuplicate && $pdo !== null) {
            try {
                if ($referenceId !== null && $referenceId > 0 && $referenceType !== null) {
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM email_logs 
                        WHERE recipient_email = :email 
                          AND template_type = :ttype 
                          AND reference_id = :ref_id 
                          AND reference_type = :ref_type 
                          AND status IN ('sent', 'mock_sent')
                        LIMIT 1
                    ");
                    $checkStmt->execute([
                        ':email'    => $toEmail,
                        ':ttype'    => $templateType,
                        ':ref_id'   => $referenceId,
                        ':ref_type' => (string)$referenceType
                    ]);
                } else {
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM email_logs 
                        WHERE recipient_email = :email 
                          AND template_type = :ttype 
                          AND subject = :subject
                          AND status IN ('sent', 'mock_sent')
                          AND sent_at >= (NOW() - INTERVAL '5 MINUTE')
                        LIMIT 1
                    ");
                    $checkStmt->execute([
                        ':email'   => $toEmail,
                        ':ttype'   => $templateType,
                        ':subject' => $subject
                    ]);
                }
                if ($checkStmt->fetchColumn()) {
                    // Already sent; return true to indicate handled
                    return true;
                }
            } catch (Throwable $e) {
                // Ignore DB lookup error and proceed with sending
            }
        }

        $config = MailConfig::get();

        // Mock / Development mode when SMTP credentials are not yet configured
        if (!empty($config['mock_mode'])) {
            self::logEmail($pdo, $toEmail, $toName, $subject, $templateType, $referenceId, $referenceType, 'mock_sent', 'Dispatched via Mock Mode (Configure Gmail SMTP credentials in .env for live delivery).', $recipientUserId);
            return true;
        }

        $res = dispatchConfiguredEmail($config, $toEmail, $toName, $subject, $htmlBody, (string)($altBody ?? ''));
        if (!empty($res['success'])) {
            self::logEmail($pdo, $toEmail, $toName, $subject, $templateType, $referenceId, $referenceType, 'sent', null, $recipientUserId);
            return true;
        }

        $errorMsg = (string)($res['error'] ?? 'Failed to send email.');
        self::logEmail($pdo, $toEmail, $toName, $subject, $templateType, $referenceId, $referenceType, 'failed', $errorMsg, $recipientUserId);
        error_log("[TaraRent Mail] Failed to send email to {$toEmail}: {$errorMsg}");
        return false;
    }

    /**
     * Send an email via the centralized sendEmail function
     */
    public static function sendEmail(string $recipientEmail, string $subject, string $message, ?int $recipientUserId = null, ?PDO $pdo = null): bool
    {
        return sendEmail($recipientEmail, $subject, $message, $recipientUserId, $pdo);
    }

    /**
     * Reusable mailer function that initializes PHPMailer, connects to Gmail's SMTP server
     * (smtp.gmail.com, port 465/587), and sends an HTML email styled using the
     * TaraRent 60-30-10 color palette (Light Beige, Medium Brown, Dark Brown).
     */
    public static function sendTenantNotification(
        string $tenantEmail,
        string $subject,
        string $messageBody,
        ?string $recipientName = null,
        array $details = [],
        ?string $buttonText = null,
        ?string $buttonUrl = null,
        ?int $tenantId = null,
        ?PDO $pdo = null
    ): bool {
        $tenantEmail = trim($tenantEmail);
        if (!filter_var($tenantEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("[TaraRent sendTenantNotification] Invalid email: {$tenantEmail}");
            return false;
        }

        if ($pdo === null && class_exists('Database')) {
            try {
                $pdo = Database::getConnection();
            } catch (Throwable $e) {}
        }

        $name = trim((string)($recipientName ?? ''));
        if ($name === '' && $pdo !== null && $tenantId !== null && $tenantId > 0) {
            try {
                $uStmt = $pdo->prepare("SELECT name FROM users WHERE id = :id LIMIT 1");
                $uStmt->execute([':id' => $tenantId]);
                $uRow = $uStmt->fetch();
                if ($uRow && !empty($uRow['name'])) {
                    $name = (string)$uRow['name'];
                }
            } catch (Throwable $e) {}
        }
        if ($name === '') {
            $name = 'Tenant';
        }

        // Style the HTML email body using the TaraRent 60-30-10 color palette
        if (str_contains($messageBody, '<html') || str_contains($messageBody, '<!DOCTYPE')) {
            $htmlBody = $messageBody;
        } else {
            $badge = 'NOTIFICATION';
            if (stripos($subject, 'payment') !== false) {
                $badge = 'RENTAL PAYMENT';
            } elseif (stripos($subject, 'maintenance') !== false) {
                $badge = 'MAINTENANCE';
            } elseif (stripos($subject, 'report') !== false) {
                $badge = 'TENANT REPORT';
            }

            $htmlBody = EmailTemplate::render(
                $subject,
                $badge,
                $name,
                $messageBody,
                $details,
                $buttonText,
                $buttonUrl,
                'Thank you for being a valued resident of TaraRent!'
            );
        }

        return self::send(
            $pdo,
            $tenantEmail,
            $name,
            $subject,
            $htmlBody,
            'tenant_notification',
            $tenantId,
            'tenant',
            false,
            null,
            $tenantId
        );
    }

    /**
     * Extract payment reference number from a payment record or generate a fallback PAY-000XX code.
     */
    public static function extractPaymentReference(array $payment): string
    {
        $explicitRef = trim((string)($payment['reference_number'] ?? ''));
        if ($explicitRef !== '') {
            return $explicitRef;
        }
        $methodStr = (string)($payment['payment_method'] ?? '');
        if ($methodStr !== '' && preg_match('/Ref:\s*([A-Za-z0-9_-]+)/i', $methodStr, $matches)) {
            return $matches[1];
        }
        $paymentId = (int)($payment['id'] ?? 0);
        return $paymentId > 0 ? ('PAY-' . str_pad((string)$paymentId, 5, '0', STR_PAD_LEFT)) : 'N/A';
    }

    /**
     * Notify Tenant when their account / identity has been verified and activated.
     */
    public static function notifyTenantAccountVerified(
        PDO $pdo,
        int $tenantId,
        ?string $verificationMethod = null,
        bool $checkDuplicate = true
    ): bool {
        $tenant = self::getTenantFromDatabase($pdo, $tenantId);
        if (!$tenant || empty($tenant['email'])) {
            return false;
        }

        $subject = 'TaraRent: Your Account Has Been Verified';
        $unitLabel = !empty($tenant['unit_number']) ? (string)$tenant['unit_number'] : 'Assigned Unit';

        $intro = "Great news! Your identity verification has been approved and your TaraRent tenant account is now <strong>fully verified and active</strong>.<br><br>"
            . "You now have full access to all resident features, including online rent payment submissions, maintenance request tracking, and general reports.";

        $details = [
            'Account Holder'  => $tenant['name'],
            'Assigned Unit'   => $unitLabel,
            'Account Status'  => 'Verified & Active',
            'Verified On'     => date('F j, Y • g:i A'),
        ];
        if (!empty($verificationMethod)) {
            $details['Verification Method'] = $verificationMethod;
        }

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php';

        $html = EmailTemplate::render(
            'Your Account Is Now Verified',
            'ACCOUNT VERIFIED',
            $tenant['name'],
            $intro,
            $details,
            'Open Tenant Portal',
            $actionUrl,
            'Thank you for completing verification and being part of the TaraRent community!'
        );

        return self::send(
            $pdo,
            $tenant['email'],
            $tenant['name'],
            $subject,
            $html,
            'account_verified',
            $tenantId,
            'tenant',
            $checkDuplicate,
            null,
            $tenantId
        );
    }

    /**
     * Notify Tenant of Landlord payment approval or rejection.
     * Includes amount, reference number, month covered, and rejection reason + resubmission instructions when rejected.
     */
    public static function notifyTenantPaymentDecision(
        PDO $pdo,
        int $tenantId,
        float $amount,
        string $billingMonth,
        string $status,
        ?string $reason = null,
        ?int $paymentId = null,
        ?string $referenceNumber = null
    ): bool {
        $tenant = self::getTenantFromDatabase($pdo, $tenantId);
        if (!$tenant || empty($tenant['email'])) {
            return false;
        }

        $formattedAmount = '₱' . number_format($amount, 2);
        $monthStr = (!empty($billingMonth) && strtotime($billingMonth) !== false)
            ? date('F Y', strtotime($billingMonth))
            : $billingMonth;
        if (empty($monthStr)) {
            $monthStr = date('F Y');
        }

        $refDisplay = trim((string)$referenceNumber);
        if ($refDisplay === '') {
            $refDisplay = $paymentId ? ('PAY-' . str_pad((string)$paymentId, 5, '0', STR_PAD_LEFT)) : 'N/A';
        }

        $statusNormalized = strtolower($status);
        $isApproved = in_array($statusNormalized, ['approved', 'verified'], true);
        $statusDisplay = $isApproved ? 'Approved' : 'Rejected';

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments&tab=tracker';

        $details = [
            'Reference Number' => $refDisplay,
            'Month Covered'    => $monthStr,
            'Amount'           => $formattedAmount,
            'Status'           => $isApproved ? 'Verified & Approved' : 'Rejected (Action Required)',
        ];

        if ($isApproved) {
            $subject = "TaraRent: Payment Verified & Approved - {$monthStr}";
            $heading = 'Payment Verified & Approved';
            $badge   = 'PAYMENT APPROVED';
            $messageBody = "Your rental payment of <strong>{$formattedAmount}</strong> for <strong>{$monthStr}</strong> (Reference: <strong>" . htmlspecialchars($refDisplay, ENT_QUOTES, 'UTF-8') . "</strong>) has been <strong>verified and approved</strong> by property management.";
            if (!empty($reason)) {
                $details['Admin Remarks'] = $reason;
            }
            $buttonText = 'View Payment History';
            $closing = 'Thank you for keeping your rental account in good standing!';
        } else {
            $subject = "TaraRent: Payment Proof Rejected - {$monthStr}";
            $heading = 'Payment Proof Rejected';
            $badge   = 'ACTION REQUIRED';
            $messageBody = "Your submitted payment proof of <strong>{$formattedAmount}</strong> for <strong>{$monthStr}</strong> (Reference: <strong>" . htmlspecialchars($refDisplay, ENT_QUOTES, 'UTF-8') . "</strong>) was <strong>rejected</strong> during review.";
            if (!empty($reason)) {
                $messageBody .= "<br><br><strong>Reason / Admin Remarks:</strong> " . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
                $details['Reason / Remarks'] = $reason;
            }
            $messageBody .= "<br><br><strong>How to Resubmit:</strong> Please log in to your TaraRent Tenant Portal, go to the <em>Payments</em> section, and upload a clear, valid receipt screenshot with the correct amount and reference number.";
            $details['Next Steps'] = 'Re-upload a clear, valid proof of payment in the Tenant Portal.';
            $buttonText = 'Resubmit Payment Proof';
            $closing = 'Please resubmit your payment proof promptly to avoid overdue notices.';
        }

        $html = EmailTemplate::render(
            $heading,
            $badge,
            $tenant['name'],
            $messageBody,
            $details,
            $buttonText,
            $actionUrl,
            $closing
        );

        $templateType = $isApproved ? 'payment_verified' : 'payment_rejected';
        return self::send($pdo, $tenant['email'], $tenant['name'], $subject, $html, $templateType, $paymentId, 'payment', false, null, $tenantId);
    }

    /**
     * Notify Tenant of Maintenance status update.
     */
    public static function notifyTenantMaintenanceStatusUpdate(
        PDO $pdo,
        int $tenantId,
        string $issueTitle,
        string $newStatus,
        ?string $notes = null,
        ?int $ticketId = null
    ): bool {
        $tenant = self::getTenantFromDatabase($pdo, $tenantId);
        if (!$tenant || empty($tenant['email'])) {
            return false;
        }

        $ticketRef = $ticketId ? ('#MT-' . str_pad((string)$ticketId, 4, '0', STR_PAD_LEFT)) : '#MT';
        $safeTitle = htmlspecialchars($issueTitle, ENT_QUOTES, 'UTF-8');
        $safeStatus = htmlspecialchars($newStatus, ENT_QUOTES, 'UTF-8');

        $messageBody = "The status of your maintenance request <strong>{$ticketRef}</strong> regarding '<strong>{$safeTitle}</strong>' has been updated to: <strong>{$safeStatus}</strong>.";
        if (!empty($notes)) {
            $messageBody .= "<br><br><strong>Owner Remarks / Instructions:</strong> " . htmlspecialchars($notes, ENT_QUOTES, 'UTF-8');
        }

        $subject = "TaraRent: Maintenance Request Update - {$issueTitle} ({$ticketRef})";
        $details = [
            'Request Reference' => $ticketRef,
            'Apartment Unit'    => !empty($tenant['unit_number']) ? $tenant['unit_number'] : 'Assigned Unit',
            'Issue / Title'     => $issueTitle,
            'Updated Status'    => $newStatus,
        ];
        if (!empty($notes)) {
            $details['Owner Remarks'] = $notes;
        }

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantMaintenance';

        $html = EmailTemplate::render(
            'Maintenance Request Updated',
            'MAINTENANCE UPDATE',
            $tenant['name'],
            $messageBody,
            $details,
            'View Maintenance Details',
            $actionUrl,
            'You can monitor repair progress and reply anytime from your Tenant Portal.'
        );

        $templateType = 'maintenance_updated_' . strtolower(str_replace([' ', '-'], '_', $newStatus));
        return self::send($pdo, $tenant['email'], $tenant['name'], $subject, $html, $templateType, $ticketId, 'ticket', false, null, $tenantId);
    }

    /**
     * Notify Tenant when a submitted report / concern is acknowledged or updated.
     */
    public static function notifyTenantReportStatusUpdate(
        PDO $pdo,
        int $tenantId,
        string $reportTitle,
        string $newStatus,
        ?string $remarks = null,
        ?int $reportId = null
    ): bool {
        $tenant = self::getTenantFromDatabase($pdo, $tenantId);
        if (!$tenant || empty($tenant['email'])) {
            return false;
        }

        $reportRef = $reportId ? ('#GR-' . str_pad((string)$reportId, 4, '0', STR_PAD_LEFT)) : '#GR';
        $safeTitle = htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8');
        $safeStatus = htmlspecialchars($newStatus, ENT_QUOTES, 'UTF-8');
        $statusLower = strtolower(trim($newStatus));

        if ($statusLower === 'acknowledged') {
            $subject = "TaraRent: Report Acknowledged - {$reportTitle} ({$reportRef})";
            $heading = 'Report & Concern Acknowledged';
            $badge   = 'REPORT ACKNOWLEDGED';
            $messageBody = "Your report/concern <strong>{$reportRef}</strong> regarding '<strong>{$safeTitle}</strong>' has been <strong>received and acknowledged</strong> by property management.";
            $nextSteps = !empty($remarks) ? 'Please review the owner remarks above. Further updates will be posted in your portal.' : 'Property management has logged your concern and will follow up as needed.';
        } else {
            $subject = "TaraRent: Report & Concern Update - {$reportTitle} ({$reportRef})";
            $heading = 'Report & Concern Updated';
            $badge   = 'REPORT UPDATE';
            $messageBody = "Your report/concern <strong>{$reportRef}</strong> regarding '<strong>{$safeTitle}</strong>' has been reviewed and updated to: <strong>{$safeStatus}</strong>.";
            $nextSteps = 'Log in to your Tenant Portal to view full report details and history.';
        }

        if (!empty($remarks)) {
            $messageBody .= "<br><br><strong>Owner Response / Remarks:</strong> " . htmlspecialchars($remarks, ENT_QUOTES, 'UTF-8');
        }

        $details = [
            'Report Reference' => $reportRef,
            'Report Subject'   => $reportTitle,
            'Status'           => $newStatus,
            'Owner Remarks'    => !empty($remarks) ? $remarks : 'Reviewed by property management.',
            'Next Steps'       => $nextSteps,
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantReport';

        $html = EmailTemplate::render(
            $heading,
            $badge,
            $tenant['name'],
            $messageBody,
            $details,
            'View Report Details',
            $actionUrl,
            'Thank you for keeping property management informed.'
        );

        $templateType = 'report_update_' . strtolower(str_replace([' ', '-'], '_', $newStatus));
        return self::send($pdo, $tenant['email'], $tenant['name'], $subject, $html, $templateType, $reportId, 'report', false, null, $tenantId);
    }

    public const DEFAULT_OWNER_EMAIL = 'rentconnect2026@gmail.com';

    /**
     * Dynamically retrieve property Owner (Landlord) record from database.
     * Guaranteed to return valid owner email (defaults to rentconnect2026@gmail.com).
     */
    public static function getOwnerFromDatabase(PDO $pdo): ?array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT id, name, email 
                FROM users 
                WHERE role = 'landlord' AND is_active = TRUE 
                ORDER BY id ASC 
                LIMIT 1
            ");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $email = !empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)
                    ? (string)$row['email']
                    : self::DEFAULT_OWNER_EMAIL;

                if (str_contains($email, 'lead.dimaculangan@rentconnect.ph')) {
                    $email = self::DEFAULT_OWNER_EMAIL;
                }

                return [
                    'id'    => (int)$row['id'],
                    'name'  => (string)($row['name'] ?? 'Leah Dimaculangan'),
                    'email' => $email
                ];
            }
        } catch (Throwable $e) {
            error_log('[EmailService] Database query error for Owner: ' . $e->getMessage());
        }
        return [
            'id'    => 1,
            'name'  => 'Leah Dimaculangan',
            'email' => self::DEFAULT_OWNER_EMAIL
        ];
    }

    /**
     * Dynamically retrieve a Tenant record from database by user ID.
     * Never uses hardcoded or fallback email strings.
     */
    public static function getTenantFromDatabase(PDO $pdo, int $tenantId): ?array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.email, u.unit_id, un.unit_number
                FROM users u
                LEFT JOIN units un ON u.unit_id = un.id
                WHERE u.id = :id AND u.role = 'tenant' AND (u.is_active = TRUE OR u.is_active IS NULL)
                LIMIT 1
            ");
            $stmt->execute([':id' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                return [
                    'id'          => (int)$row['id'],
                    'name'        => (string)$row['name'],
                    'email'       => (string)$row['email'],
                    'unit_id'     => $row['unit_id'] !== null ? (int)$row['unit_id'] : null,
                    'unit_number' => $row['unit_number'] ?? 'Unit'
                ];
            }
        } catch (Throwable $e) {
            error_log('[EmailService] Database query error for Tenant: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Send a general custom email with HTML and plain-text fallback
     */
    public static function sendCustomEmail(
        ?PDO $pdo,
        string $toEmail,
        string $subject,
        string $htmlBody,
        ?string $altBody = null,
        string $toName = '',
        string $templateType = 'general',
        ?int $referenceId = null,
        ?string $referenceType = null,
        bool $checkDuplicate = false,
        ?int $recipientUserId = null
    ): bool {
        return self::send(
            $pdo,
            $toEmail,
            $toName ?: $toEmail,
            $subject,
            $htmlBody,
            $templateType,
            $referenceId,
            $referenceType,
            $checkDuplicate,
            $altBody,
            $recipientUserId
        );
    }

    /**
     * Test and diagnose the Gmail SMTP connection
     */
    public static function testSmtpConnection(): array
    {
        $config = MailConfig::get(true);
        if (!empty($config['has_webhook']) && (getenv('RENDER') || empty($config['username']) || empty($config['password']))) {
            $ch = curl_init($config['webhook_url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            $decoded = json_decode((string)$resp, true);
            if ($err === '' && $code >= 200 && $code < 300 && is_array($decoded) && !empty($decoded['success'])) {
                return [
                    'success' => true,
                    'message' => 'Successfully connected to HTTP Email Webhook (Port 443 HTTPS).',
                    'details' => 'Ready to send emails on Render Free Tier.'
                ];
            }
            if (empty($config['username']) || empty($config['password'])) {
                return [
                    'success' => false,
                    'message' => 'HTTP Email Webhook test failed: ' . ($err ?: "HTTP {$code} (Ensure Apps Script access is set to Anyone)"),
                    'details' => substr(strip_tags((string)$resp), 0, 200)
                ];
            }
        }

        if (empty($config['username']) || empty($config['password'])) {
            return [
                'success' => false,
                'message' => 'Gmail SMTP credentials are not configured in environment or .env file.',
                'details' => 'Set SMTP_USER (your Gmail address) and SMTP_PASS (16-character App Password).'
            ];
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = resolveSmtpIpv4Host((string)$config['host']);
            $mail->SMTPAuth   = $config['auth'];
            $mail->Username   = $config['username'];
            $mail->Password   = $config['password'];
            $mail->SMTPSecure = ($config['encryption'] === 'ssl' || $config['encryption'] === 'smtps')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $config['port'];
            $mail->Timeout    = 10;

            // Windows / XAMPP SSL stream options to ensure reliable TLS handshake
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            if (!$mail->smtpConnect()) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to SMTP server: ' . $mail->ErrorInfo,
                    'details' => $mail->ErrorInfo
                ];
            }
            $mail->smtpClose();

            return [
                'success' => true,
                'message' => "Successfully connected and authenticated with Gmail SMTP ({$config['host']}:{$config['port']}).",
                'details' => 'STARTTLS connection established and authentication succeeded.'
            ];
        } catch (PHPMailerException $e) {
            return [
                'success' => false,
                'message' => 'PHPMailer Error: ' . $e->getMessage(),
                'details' => !empty($mail->ErrorInfo) ? $mail->ErrorInfo : $e->getMessage()
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection Exception: ' . $e->getMessage(),
                'details' => $e->getMessage()
            ];
        }
    }

    /**
     * Safely log email activity to database without sensitive tokens or passwords
     */
    public static function logEmail(
        ?PDO $pdo,
        string $email,
        string $name,
        string $subject,
        string $templateType,
        ?int $referenceId,
        ?string $referenceType,
        string $status,
        ?string $errorMessage,
        ?int $recipientUserId = null
    ): void {
        if ($pdo === null) return;
        try {
            $stmt = $pdo->prepare("
                INSERT INTO email_logs 
                    (recipient_user_id, recipient_email, recipient_name, subject, template_type, reference_id, reference_type, status, error_message, sent_at)
                VALUES 
                    (:user_id, :email, :name, :subject, :ttype, :ref_id, :ref_type, :status, :err, NOW())
            ");
            $stmt->execute([
                ':user_id'  => $recipientUserId,
                ':email'    => substr($email, 0, 191),
                ':name'     => substr($name, 0, 100),
                ':subject'  => substr($subject, 0, 255),
                ':ttype'    => substr($templateType, 0, 50),
                ':ref_id'   => $referenceId,
                ':ref_type' => $referenceType !== null ? substr($referenceType, 0, 50) : null,
                ':status'   => $status,
                ':err'      => $errorMessage
            ]);
        } catch (Throwable $e) {
            // Silently fail logging rather than breaking application execution
        }
    }

    // =========================================================================
    // 1. FORGOT PASSWORD EMAILS
    // =========================================================================

    /**
     * Send a 6-digit password reset verification code
     */
    public static function sendPasswordResetCode(
        PDO $pdo,
        string $recipientEmail,
        string $recipientName,
        string $code,
        int $expireMinutes = 5,
        ?int $recipientUserId = null
    ): bool {
        $subject = 'Password Reset Verification Code';
        $html = EmailTemplate::renderVerificationCode($recipientName, $code, $expireMinutes);
        return self::send($pdo, $recipientEmail, $recipientName, $subject, $html, 'password_reset_code', null, null, false, null, $recipientUserId);
    }

    /**
     * Send password reset link to user (Legacy / direct link support)
     */
    public static function sendPasswordReset(
        PDO $pdo,
        string $recipientEmail,
        string $recipientName,
        string $resetUrl,
        int $expireMinutes = 60,
        ?int $recipientUserId = null
    ): bool {
        $subject = 'TaraRent - Password Reset Request';
        $intro = "We received a request to reset the password for your TaraRent account. Click the button below to choose a new password. This link is valid for the next <strong>{$expireMinutes} minutes</strong>.";

        $details = [
            'Account Email' => $recipientEmail,
            'Request Time'  => date('F j, Y • g:i A'),
            'Link Expiry'   => "Within {$expireMinutes} minutes",
            'Security Tip'  => 'Never share your password reset link with anyone.'
        ];

        $html = EmailTemplate::render(
            'Password Reset Request',
            'SECURITY & ACCESS',
            $recipientName,
            $intro,
            $details,
            'Reset My Password',
            $resetUrl,
            'If you did not request a password reset, you can safely ignore this email. Your account remains secure.'
        );

        return self::send($pdo, $recipientEmail, $recipientName, $subject, $html, 'password_reset', null, null, false, null, $recipientUserId);
    }

    // =========================================================================
    // 2. OWNER NOTIFICATION EMAILS
    // =========================================================================

    /**
     * Notify Owner when a tenant submits payment proof
     */
    public static function notifyOwnerPaymentProofSubmitted(
        PDO $pdo,
        string $ownerEmail,
        string $ownerName,
        string $tenantName,
        string $unitNumber,
        float $amountPaid,
        string $billingCycle,
        int $paymentId,
        ?int $recipientUserId = null
    ): bool {
        if (empty($ownerEmail) || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || str_contains($ownerEmail, 'lead.dimaculangan@rentconnect.ph')) {
            $ownerEmail = self::DEFAULT_OWNER_EMAIL;
        }
        $subject = "New Payment Proof Submitted - {$unitNumber}";
        $monthStr = date('F Y', strtotime($billingCycle));
        $formattedAmount = '₱' . number_format($amountPaid, 2);

        $intro = "<strong>{$tenantName}</strong> from <strong>{$unitNumber}</strong> has submitted payment proof for the <strong>{$monthStr}</strong> billing cycle. Please review and verify this payment in your management portal.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Billing Cycle'   => $monthStr,
            'Amount Submitted'=> $formattedAmount,
            'Submission Date' => date('F j, Y • g:i A'),
            'Status'          => 'Pending Owner Verification'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/admin_dashboard.php?section=sectionPayments';

        $html = EmailTemplate::render(
            'Payment Verification Required',
            'PAYMENT RECEIVED',
            $ownerName,
            $intro,
            $details,
            'Review Payment in Portal',
            $actionUrl,
            'Prompt verification helps keep tenant billing records accurate.'
        );

        return self::send($pdo, $ownerEmail, $ownerName, $subject, $html, 'payment_proof_submitted', $paymentId, 'payment', true, null, $recipientUserId);
    }

    /**
     * Notify Owner when a tenant submits a new maintenance ticket
     */
    public static function notifyOwnerMaintenanceTicketCreated(
        PDO $pdo,
        string $ownerEmail,
        string $ownerName,
        string $tenantName,
        string $unitNumber,
        string $ticketTitle,
        string $category,
        string $priority,
        int $ticketId,
        ?int $recipientUserId = null
    ): bool {
        if (empty($ownerEmail) || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || str_contains($ownerEmail, 'lead.dimaculangan@rentconnect.ph')) {
            $ownerEmail = self::DEFAULT_OWNER_EMAIL;
        }
        $subject = "New Maintenance Request Received - {$unitNumber} (#{$ticketId})";

        $intro = "A new maintenance request has been submitted by <strong>{$tenantName}</strong> for <strong>{$unitNumber}</strong>. Please review the ticket details to take action.";

        $details = [
            'Ticket ID'       => "#{$ticketId}",
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Issue Title'     => $ticketTitle,
            'Category'        => ucfirst($category),
            'Priority'        => strtoupper($priority),
            'Date Reported'   => date('F j, Y • g:i A'),
            'Current Status'  => 'Open / Awaiting Action'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/admin_dashboard.php?section=sectionTickets';

        $html = EmailTemplate::render(
            'New Maintenance Request',
            'MAINTENANCE ALERT',
            $ownerName,
            $intro,
            $details,
            'View Ticket in Portal',
            $actionUrl
        );

        return self::send($pdo, $ownerEmail, $ownerName, $subject, $html, 'maintenance_ticket_created', $ticketId, 'ticket', true, null, $recipientUserId);
    }

    /**
     * Notify Owner when a tenant is registered or assigned to a unit
     */
    public static function notifyOwnerTenantRegistered(
        PDO $pdo,
        string $ownerEmail,
        string $ownerName,
        string $tenantName,
        string $unitNumber,
        int $tenantId,
        ?int $recipientUserId = null
    ): bool {
        if (empty($ownerEmail) || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || str_contains($ownerEmail, 'lead.dimaculangan@rentconnect.ph')) {
            $ownerEmail = self::DEFAULT_OWNER_EMAIL;
        }
        $subject = "Tenant Activity Notification - {$unitNumber}";

        $intro = "A new resident account for <strong>{$tenantName}</strong> has been successfully provisioned and assigned to <strong>{$unitNumber}</strong>.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Assignment Date' => date('F j, Y • g:i A'),
            'Occupancy Status'=> 'Active Resident'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/admin_dashboard.php?section=sectionDirectory';

        $html = EmailTemplate::render(
            'New Tenant Assigned',
            'TENANT DIRECTORY',
            $ownerName,
            $intro,
            $details,
            'Open Tenant Directory',
            $actionUrl
        );

        return self::send($pdo, $ownerEmail, $ownerName, $subject, $html, 'tenant_registered', $tenantId, 'tenant', true, null, $recipientUserId);
    }

    // =========================================================================
    // 3. TENANT NOTIFICATION EMAILS
    // =========================================================================

    /**
     * Send account creation notification to Tenant (Owner to Tenant)
     * Dispatches login username and temporary password to the tenant's registered email.
     */
    public static function sendTenantAccountCreated(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $username,
        string $temporaryPassword,
        string|int $unitNumber = '',
        int $tenantId = 0,
        ?int $recipientUserId = null
    ): bool {
        $subject = 'TaraRent: Your Account Credentials';
        $config = MailConfig::get();
        $loginUrl = $config['app_url'] . '/login.php';

        // Support backward compatibility if called with (pdo, email, name, unitNumber, setupPasswordUrl, tenantId, recipientUserId)
        $isLegacyUrlMode = !empty($temporaryPassword) && (filter_var($temporaryPassword, FILTER_VALIDATE_URL) || str_starts_with($temporaryPassword, 'http'));
        if ($isLegacyUrlMode) {
            $displayUnit = (string)$username;
            $displayUsername = $tenantEmail;
            $displayPassword = '(Sent via setup link)';
            $buttonUrl = $temporaryPassword;
            $buttonText = 'Set Up Your Password';
            $actualTenantId = is_numeric($unitNumber) ? (int)$unitNumber : $tenantId;
            $actualRecipientUserId = $tenantId ?: ($recipientUserId ?: ($actualTenantId > 0 ? $actualTenantId : null));

            $intro = "Welcome to <strong>TaraRent</strong>! Your tenant account has been created. Please use the link below to set up your password and verify your identity:";
            $details = [
                'Username / Email' => $displayUsername,
            ];
            if ($displayUnit !== '') {
                $details['Assigned Unit'] = $displayUnit;
            }
            $secondaryNotice = 'Once logged in, please complete your account verification and update your password in Account Settings.';
            $html = EmailTemplate::render(
                'Welcome to TaraRent — Your Account Credentials',
                'ACCOUNT CREATED',
                $tenantName,
                $intro,
                $details,
                $buttonText,
                $buttonUrl,
                $secondaryNotice
            );
        } else {
            $displayUnit = (string)$unitNumber;
            $displayUsername = $username;
            $displayPassword = $temporaryPassword;
            $buttonUrl = $loginUrl;
            $actualTenantId = $tenantId;
            $actualRecipientUserId = $recipientUserId ?: ($actualTenantId > 0 ? $actualTenantId : null);

            $html = EmailTemplate::renderAccountCredentials(
                $tenantName,
                $displayUsername,
                $tenantEmail,
                $displayPassword,
                $displayUnit,
                $buttonUrl
            );
        }

        $altBody = "Hello {$tenantName},\n\n" .
            "Welcome to TaraRent! Your tenant account has been successfully created.\n\n" .
            "Here are your login credentials:\n" .
            ($displayUnit !== '' ? "- Assigned Unit: {$displayUnit}\n" : '') .
            "- Username: {$displayUsername}\n" .
            "- Registered Email: {$tenantEmail}\n" .
            "- Temporary Password: {$displayPassword}\n" .
            "- Login Link: {$buttonUrl}\n\n" .
            "Important Next Steps:\n" .
            "1. Log in and complete your account identity verification (Valid ID & Face Verification).\n" .
            "2. For your security, we recommend updating your password in Account Settings after logging in.\n\n" .
            "Thank you,\nTaraRent Property Management";

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'tenant_account_created', $actualTenantId, 'tenant', true, $altBody, $actualRecipientUserId);
    }

    /**
     * Notify Tenant when their payment proof is verified
     */
    public static function notifyTenantPaymentVerified(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        float $amountPaid,
        string $billingCycle,
        int $paymentId,
        ?int $recipientUserId = null,
        ?string $referenceNumber = null
    ): bool {
        $monthStr = (!empty($billingCycle) && strtotime($billingCycle) !== false)
            ? date('F Y', strtotime($billingCycle))
            : ($billingCycle ?: date('F Y'));
        $subject = "TaraRent: Payment Verified & Approved - {$monthStr}";
        $formattedAmount = '₱' . number_format($amountPaid, 2);
        $refDisplay = trim((string)$referenceNumber) !== ''
            ? trim((string)$referenceNumber)
            : ('PAY-' . str_pad((string)$paymentId, 5, '0', STR_PAD_LEFT));

        $intro = "Great news! Your rental payment of <strong>{$formattedAmount}</strong> for <strong>{$monthStr}</strong> (Reference: <strong>" . htmlspecialchars($refDisplay, ENT_QUOTES, 'UTF-8') . "</strong>) has been officially verified and approved by property management.";

        $details = [
            'Apartment Unit'    => $unitNumber,
            'Reference Number'  => $refDisplay,
            'Month Covered'     => $monthStr,
            'Verified Amount'   => $formattedAmount,
            'Verification Date' => date('F j, Y • g:i A'),
            'Payment Status'    => 'Verified & Approved'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments';

        $html = EmailTemplate::render(
            'Payment Verified & Approved',
            'PAYMENT APPROVED',
            $tenantName,
            $intro,
            $details,
            'View Payment History',
            $actionUrl,
            'Thank you for keeping your account in good standing!'
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'payment_verified', $paymentId, 'payment', false, null, $recipientUserId);
    }

    /**
     * Notify Tenant when their payment proof is rejected / requires correction
     */
    public static function notifyTenantPaymentRejected(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        float $amountPaid,
        string $reason,
        int $paymentId,
        ?int $recipientUserId = null,
        ?string $referenceNumber = null,
        ?string $billingCycle = null
    ): bool {
        $monthStr = (!empty($billingCycle) && strtotime($billingCycle) !== false)
            ? date('F Y', strtotime($billingCycle))
            : ($billingCycle ?: '');
        $subject = $monthStr !== ''
            ? "TaraRent: Payment Proof Rejected - {$monthStr}"
            : "TaraRent: Payment Proof Rejected - {$unitNumber}";
        $formattedAmount = '₱' . number_format($amountPaid, 2);
        $refDisplay = trim((string)$referenceNumber) !== ''
            ? trim((string)$referenceNumber)
            : ('PAY-' . str_pad((string)$paymentId, 5, '0', STR_PAD_LEFT));

        $intro = "Your recently submitted payment proof of <strong>{$formattedAmount}</strong> for <strong>{$unitNumber}</strong> (Reference: <strong>" . htmlspecialchars($refDisplay, ENT_QUOTES, 'UTF-8') . "</strong>) was rejected during review."
            . "<br><br><strong>How to Resubmit:</strong> Please log in to your TaraRent Tenant Portal, open the <em>Payments</em> tab, and upload a clear receipt screenshot matching the billed amount and reference number.";

        $details = [
            'Apartment Unit'   => $unitNumber,
            'Reference Number' => $refDisplay,
            'Amount'           => $formattedAmount,
            'Status'           => 'Rejected (Action Required)',
            'Reason / Remarks' => $reason ?: 'Payment proof was unclear or did not match the expected amount.',
            'Action Needed'    => 'Please upload a clear screenshot or official receipt in the Tenant Portal.'
        ];
        if ($monthStr !== '') {
            $details['Month Covered'] = $monthStr;
        }

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments';

        $html = EmailTemplate::render(
            'Payment Proof Rejected',
            'ACTION REQUIRED',
            $tenantName,
            $intro,
            $details,
            'Resubmit Payment Proof',
            $actionUrl,
            'If you have questions regarding this notice, please contact property management.'
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'payment_rejected', $paymentId, 'payment', false, null, $recipientUserId);
    }

    /**
     * Notify Tenant of Maintenance Ticket status updates
     */
    public static function notifyTenantMaintenanceUpdated(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        string $ticketTitle,
        string $status,
        string $resolutionNotes,
        int $ticketId,
        ?int $recipientUserId = null
    ): bool {
        $statusLabels = [
            'open'        => 'Open / Received',
            'in_progress' => 'In Progress / Assigned',
            'resolved'    => 'Resolved'
        ];
        $readableStatus = $statusLabels[$status] ?? ucfirst($status);
        $subject = "Maintenance Request Update - {$ticketTitle} (#{$ticketId})";

        $intro = "There is an update on your maintenance request <strong>#{$ticketId} ({$ticketTitle})</strong> for <strong>{$unitNumber}</strong>.";

        $details = [
            'Ticket ID'       => "#{$ticketId}",
            'Apartment Unit'  => $unitNumber,
            'Issue'           => $ticketTitle,
            'Updated Status'  => $readableStatus,
            'Resolution Note' => $resolutionNotes ?: 'Status updated by property administration.',
            'Update Time'     => date('F j, Y • g:i A')
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantMaintenance';

        $html = EmailTemplate::render(
            'Maintenance Status Update',
            'MAINTENANCE UPDATE',
            $tenantName,
            $intro,
            $details,
            'View Ticket Details',
            $actionUrl
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'maintenance_updated_' . $status, $ticketId, 'ticket', false, null, $recipientUserId);
    }

    /**
     * Notify Tenant when Owner replies to a maintenance request
     *
     * Sample email structure:
     * Subject: Maintenance Request Update - [Ticket Title]
     * Hello [Tenant Name],
     * The Owner has responded to your maintenance request.
     * Ticket Details:
     * - Ticket Title: [Ticket Title]
     * - Owner's Response: [Owner's Message/Response]
     * - Status: [Current Status]
     * You can view the full details of your request by visiting your dashboard:
     * [Link to Tenant Maintenance Page]
     * Thank you!
     */
    public static function notifyTenantTicketReply(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $ticketTitle,
        string $ownerReply,
        string $ticketStatus,
        int $ticketId = 0,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Maintenance Request Update - {$ticketTitle}";
        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantMaintenance';

        $statusLabels = [
            'open'        => 'Open',
            'in_progress' => 'In Progress',
            'resolved'    => 'Resolved',
            'closed'      => 'Closed'
        ];
        $readableStatus = $statusLabels[strtolower($ticketStatus)] ?? ucfirst($ticketStatus);

        $intro = "The Owner has responded to your maintenance request.<br><br><strong>Ticket Details:</strong>";

        $details = [
            'Ticket Title'     => $ticketTitle,
            'Owner\'s Response' => !empty(trim($ownerReply)) ? htmlspecialchars($ownerReply) : 'Status updated by Owner.',
            'Status'           => $readableStatus
        ];

        $closing = "You can view the full details of your request by visiting your dashboard:<br><a href=\"{$actionUrl}\" style=\"color: #4f46e5; font-weight: 600;\">{$actionUrl}</a><br><br>Thank you!";

        $html = EmailTemplate::render(
            'Maintenance Request Update',
            'MAINTENANCE UPDATE',
            $tenantName,
            $intro,
            $details,
            'View Maintenance in Dashboard',
            $actionUrl,
            $closing
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'ticket_reply', $ticketId ?: null, 'ticket', false, null, $recipientUserId);
    }

    /**
     * Send rental payment reminder to Tenant (upcoming_3days, upcoming_1day, due_today, overdue)
     *
     * Sample email structure:
     * Subject: Rental Payment Reminder - [Unit Number]
     * Hello [Tenant Name],
     * This is a reminder regarding your upcoming rental payment.
     * Payment Details:
     * - Apartment Unit: [Unit Number]
     * - Rental Amount: ₱[Amount]
     * - Due Date: [Due Date]
     * - Payment Status: Unpaid
     * Please make sure to complete your payment on or before the due date.
     * Thank you!
     */
    public static function notifyTenantPaymentReminder(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        float $amountDue,
        string $dueDate,
        string $reminderType, // 'upcoming', 'upcoming_2_3days', 'upcoming_3days', 'upcoming_2days', 'upcoming_1day', 'due_today', 'overdue'
        int $paymentId,
        ?int $recipientUserId = null,
        ?int $daysRemaining = null,
        bool $checkDuplicate = true
    ): bool {
        $formattedAmount = '₱' . number_format($amountDue, 2);
        $dueFormatted = date('F j, Y', strtotime($dueDate));
        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments';

        if ($daysRemaining === null && !empty($dueDate) && strtotime($dueDate) !== false) {
            $today = new DateTimeImmutable('today');
            $dueObj = new DateTimeImmutable(date('Y-m-d', strtotime($dueDate)));
            $daysRemaining = (int)$today->diff($dueObj)->format('%r%a');
        }

        switch ($reminderType) {
            case 'overdue':
                $subject    = "TaraRent: Overdue Rent Payment Notice - {$unitNumber}";
                $heading    = "Payment Overdue Notice";
                $badge      = "OVERDUE NOTICE";
                $intro      = "Our records indicate that your rent payment for <strong>{$unitNumber}</strong> is currently overdue. Please settle your balance and upload your proof of payment as soon as possible.";
                $buttonText = "Upload Proof of Payment";
                $closing    = "Please make sure to complete your payment and upload your receipt promptly.<br><br>Thank you!";
                break;
            case 'due_today':
                $subject    = "TaraRent: Your Rent Payment is Due Today ({$unitNumber})";
                $heading    = "Rent Payment Due Today";
                $badge      = "DUE TODAY";
                $intro      = "This is a friendly reminder that your rental payment for <strong>{$unitNumber}</strong> is due <strong>today ({$dueFormatted})</strong>. Please upload your proof of payment once settled.";
                $buttonText = "Upload Proof of Payment";
                $closing    = "Please make sure to complete your payment before the end of the day.<br><br>Thank you!";
                break;
            case 'upcoming_1day':
                $subject    = "TaraRent: Your Rent is Due Tomorrow ({$unitNumber})";
                $heading    = "Upcoming Rent Reminder (Due Tomorrow)";
                $badge      = "DUE TOMORROW";
                $intro      = "This is a friendly reminder regarding your upcoming rental payment for <strong>{$unitNumber}</strong> due tomorrow (<strong>{$dueFormatted}</strong>). Please upload your proof of payment once completed.";
                $buttonText = "Upload Proof of Payment";
                $closing    = "Please make sure to complete your payment on or before the due date.<br><br>Thank you!";
                break;
            case 'upcoming_2_3days':
            case 'upcoming_3days':
            case 'upcoming_2days':
            case 'upcoming':
            default:
                $daysLabel  = ($daysRemaining !== null && $daysRemaining >= 1) ? "{$daysRemaining} Day" . ($daysRemaining === 1 ? '' : 's') : '3 Days';
                $subject    = ($daysRemaining !== null && $daysRemaining >= 1)
                    ? "TaraRent: Your Rent is Due in {$daysLabel}"
                    : "TaraRent: Rental Payment Reminder - {$unitNumber}";
                $heading    = ($daysRemaining !== null && $daysRemaining >= 1)
                    ? "Rent Payment Due in {$daysLabel}"
                    : "Rental Payment Reminder";
                $badge      = "RENT DUE REMINDER";
                $intro      = "This is a friendly reminder that your upcoming rent payment for <strong>{$unitNumber}</strong> is due in <strong>{$daysLabel}</strong> (on <strong>{$dueFormatted}</strong>).<br><br>Once you complete your payment, please upload your proof of payment in the TaraRent Tenant Portal for verification.";
                $buttonText = "Upload Proof of Payment";
                $closing    = "Prompt submission helps keep your account and billing records up to date.<br><br>Thank you!";
                break;
        }

        $details = [
            'Apartment Unit' => $unitNumber,
            'Amount Due'     => $formattedAmount,
            'Due Date'       => $dueFormatted,
            'Payment Status' => 'Unpaid'
        ];

        $html = EmailTemplate::render(
            $heading,
            $badge,
            $tenantName,
            $intro,
            $details,
            $buttonText,
            $actionUrl,
            $closing
        );

        $templateType = 'payment_reminder_' . $reminderType;
        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, $templateType, $paymentId, 'payment', $checkDuplicate, null, $recipientUserId);
    }

    /**
     * Send lease expiration reminder to Tenant
     */
    public static function notifyTenantLeaseExpiring(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        string $leaseEndDate,
        int $daysRemaining,
        int $tenantId,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Upcoming Lease Expiration Notice - {$unitNumber}";
        $endFormatted = date('F j, Y', strtotime($leaseEndDate));

        $intro = "Your residential lease agreement for <strong>{$unitNumber}</strong> is scheduled to conclude on <strong>{$endFormatted}</strong> (approximately <strong>{$daysRemaining} days</strong> remaining).";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Lease End Date'  => $endFormatted,
            'Days Remaining'  => "{$daysRemaining} days",
            'Next Steps'      => 'Contact property administration regarding lease renewal or move-out procedures.'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantProfile';

        $html = EmailTemplate::render(
            'Lease Expiration Notice',
            'LEASE NOTICE',
            $tenantName,
            $intro,
            $details,
            'View Lease Details',
            $actionUrl
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'lease_expiring_' . $daysRemaining, $tenantId, 'lease', true, null, $recipientUserId);
    }

    /**
     * Notify Owner when a tenant updates their profile or contact info
     */
    public static function notifyOwnerTenantAccountUpdated(
        PDO $pdo,
        string $ownerEmail,
        string $ownerName,
        string $tenantName,
        string $unitNumber,
        string $updateDetails,
        int $tenantId,
        ?int $recipientUserId = null
    ): bool {
        if (empty($ownerEmail) || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || str_contains($ownerEmail, 'lead.dimaculangan@rentconnect.ph')) {
            $ownerEmail = self::DEFAULT_OWNER_EMAIL;
        }
        $subject = "Tenant Profile Updated - {$unitNumber} ({$tenantName})";

        $intro = "<strong>{$tenantName}</strong> from <strong>{$unitNumber}</strong> has updated their account or contact information in the portal.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Update Summary'  => $updateDetails ?: 'Profile information updated',
            'Update Time'     => date('F j, Y • g:i A'),
            'Action'          => 'Review updated record in tenant directory'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/admin_dashboard.php?section=sectionDirectory';

        $html = EmailTemplate::render(
            'Tenant Profile Updated',
            'ACCOUNT UPDATE',
            $ownerName,
            $intro,
            $details,
            'Open Tenant Directory',
            $actionUrl
        );

        return self::send($pdo, $ownerEmail, $ownerName, $subject, $html, 'tenant_profile_update', $tenantId, 'tenant', true, null, $recipientUserId);
    }

    /**
     * Send community / management announcement to Tenant
     */
    public static function notifyTenantAnnouncement(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $title,
        string $announcementMessage,
        int $announcementId = 0,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Important Announcement: {$title}";

        $intro = "Property management has posted an important announcement for all residents:";

        $details = [
            'Announcement'   => $title,
            'Date Posted'    => date('F j, Y • g:i A'),
            'Message'        => $announcementMessage
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php';

        $html = EmailTemplate::render(
            $title,
            'COMMUNITY ANNOUNCEMENT',
            $tenantName,
            $intro,
            $details,
            'View in Tenant Portal',
            $actionUrl,
            'If you have questions regarding this announcement, please contact property management.'
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'announcement', $announcementId ?: null, 'announcement', false, null, $recipientUserId);
    }

    /**
     * Send rent due announcement to Tenant (Owner to Tenants)
     *
     * Sample email structure matching specification:
     * Subject: Rent Due Announcement - [Announcement Title]
     * Hello [Tenant Name],
     * This is an announcement regarding your rent payment for [Unit Number].
     * Announcement Details:
     * - Title: [Announcement Title]
     * - Message: [Owner's Custom Message]
     * - Due Date: [Due Date]
     * - Unit: [Unit Number]
     * - Rental Amount: ₱[Amount]
     * Please ensure payment is settled on or before the specified due date.
     * Thank you!
     * Apartment Management
     */
    public static function sendRentDueAnnouncement(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $title,
        string $ownerMessage,
        ?string $dueDate,
        string $unitNumber,
        float $amount,
        int $announcementId = 0,
        ?int $recipientUserId = null
    ): bool {
        $subject = "Rent Due Announcement - {$title}";
        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php?section=tenantPayments';

        $dueFormatted = (!empty($dueDate)) ? date('F j, Y', strtotime($dueDate)) : 'As scheduled in lease';
        $formattedAmount = '₱' . number_format($amount, 2);

        $intro = "This is an announcement regarding your rent payment for <strong>{$unitNumber}</strong>.<br><br><strong>Announcement Details:</strong>";

        $details = [
            'Title'         => $title,
            'Message'       => nl2br(htmlspecialchars($ownerMessage, ENT_QUOTES, 'UTF-8')),
            'Due Date'      => $dueFormatted,
            'Unit'          => $unitNumber,
            'Rental Amount' => $formattedAmount
        ];

        $closing = "Please ensure payment is settled on or before the specified due date.<br><br>Thank you!<br><strong>Apartment Management</strong>";

        $html = EmailTemplate::render(
            'Rent Due Announcement',
            'RENT ANNOUNCEMENT',
            $tenantName,
            $intro,
            $details,
            'View in Tenant Portal',
            $actionUrl,
            $closing
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'rent_announcement', $announcementId ?: null, 'announcement', false, null, $recipientUserId);
    }

    /**
     * Send welcome onboarding email to new Tenant
     */
    public static function notifyTenantWelcome(
        PDO $pdo,
        string $tenantEmail,
        string $tenantName,
        string $unitNumber,
        int $tenantId,
        ?int $recipientUserId = null
    ): bool {
        $recipientUserId = $recipientUserId ?: $tenantId;
        $subject = "Welcome to TaraRent - {$unitNumber}";

        $intro = "Welcome to your new home! Your resident account for <strong>{$unitNumber}</strong> has been successfully configured.";

        $details = [
            'Apartment Unit'  => $unitNumber,
            'Resident Name'   => $tenantName,
            'Portal Access'   => 'Active',
            'Online Features' => 'Pay rent, submit maintenance tickets, view notices'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/tenant_portal.php';

        $html = EmailTemplate::render(
            'Welcome to TaraRent',
            'WELCOME ABOARD',
            $tenantName,
            $intro,
            $details,
            'Log In to Tenant Portal',
            $actionUrl,
            'Please keep your login credentials secure.'
        );

        return self::send($pdo, $tenantEmail, $tenantName, $subject, $html, 'tenant_welcome', $tenantId, 'tenant', true, null, $recipientUserId);
    }

    /**
     * Send security confirmation when password has been successfully reset
     */
    public static function notifyPasswordResetSuccess(
        PDO $pdo,
        string $recipientEmail,
        string $recipientName,
        ?int $recipientUserId = null
    ): bool {
        $subject = 'TaraRent - Password Changed Successfully';

        $intro = "The password for your TaraRent account (<strong>{$recipientEmail}</strong>) was changed successfully. If you initiated this change, no further action is required.";

        $details = [
            'Account Email' => $recipientEmail,
            'Change Time'   => date('F j, Y • g:i A'),
            'Security Status' => 'Password Updated'
        ];

        $config = MailConfig::get();
        $actionUrl = $config['app_url'] . '/login.php';

        $html = EmailTemplate::render(
            'Password Changed Successfully',
            'SECURITY NOTICE',
            $recipientName,
            $intro,
            $details,
            'Log In to Your Account',
            $actionUrl,
            'If you did NOT change your password, please contact property management immediately to secure your account.'
        );

        return self::send($pdo, $recipientEmail, $recipientName, $subject, $html, 'password_changed', null, null, false, null, $recipientUserId);
    }

    /**
     * Send general system notification email
     */
    public static function sendGeneralNotification(
        PDO $pdo,
        string $toEmail,
        string $toName,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null,
        ?int $recipientUserId = null
    ): bool {
        $subject = "TaraRent Notification: {$title}";

        $config = MailConfig::get();
        $url = $actionUrl ?: $config['app_url'];
        $btnText = $actionText ?: 'Open TaraRent';

        $details = [
            'Subject'   => $title,
            'Sent Time' => date('F j, Y • g:i A'),
            'Details'   => $message
        ];

        $html = EmailTemplate::render(
            $title,
            'SYSTEM NOTIFICATION',
            $toName,
            $message,
            $details,
            $btnText,
            $url
        );

        return self::send($pdo, $toEmail, $toName, $subject, $html, 'general_notification', null, null, false, null, $recipientUserId);
    }
}
