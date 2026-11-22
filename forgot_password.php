<?php
/**
 * TaraRent - Secure Forgot Password Workflow
 * 
 * 4 Focused Screens:
 *   Screen 1: Forgot Password (Registered Email)
 *   Screen 2: Verify Your Email (6-Digit Numeric Code)
 *   Screen 3: Change Password (Gated by cryptographic token)
 *   Screen 4: Password Reset Successful
 *
 * Theme:
 *   Warm brown claymorphic card (reference: TaraRent Unit Card)
 *   Gradient: linear-gradient(180deg, #7D5124 0%, #563815 48%, #3A220B 100%)
 *   High contrast warm cream typography (#FFFFFF, #F5EFEB, #EFE7DA)
 *   Pill-shaped action buttons & tactile inputs
 */

declare(strict_types=1);

ob_start();

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/email_service.php';
require_once __DIR__ . '/includes/notification_service.php';

// Redirect if already authenticated
if (Auth::check()) {
    if (Auth::isLandlord()) {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: tenant_portal.php');
    }
    exit;
}

$pdo = Database::getConnection();

// Helper: check if incoming request expects JSON
function isJsonRequest(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    return str_contains($accept, 'application/json') 
        || str_contains($contentType, 'application/json')
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
}

/**
 * Output a clean, uncorrupted JSON response and terminate.
 */
function sendJsonResponse(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

// -----------------------------------------------------------------------------
// EXPLICIT ABANDONMENT VIA GET PARAMETER
// -----------------------------------------------------------------------------
if (isset($_GET['abandon']) && $_GET['abandon'] === '1') {
    if (!empty($_SESSION['forgot_pwd']['reset_id'])) {
        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL")
            ->execute([':id' => (int)$_SESSION['forgot_pwd']['reset_id']]);
    }
    unset($_SESSION['forgot_pwd'], $_SESSION['forgot_pwd_done']);
    header('Location: login.php');
    exit;
}

// Check initial screen state based on active session
$initialScreen = 1;
$initialEmail = '';
$initialResetToken = '';
if (!empty($_SESSION['forgot_pwd']['email']) && empty($_SESSION['forgot_pwd_done'])) {
    $initialEmail = (string)$_SESSION['forgot_pwd']['email'];
    if (!empty($_SESSION['forgot_pwd']['verified']) && !empty($_SESSION['forgot_pwd']['reset_token'])) {
        $initialScreen = 3;
        $initialResetToken = (string)$_SESSION['forgot_pwd']['reset_token'];
    } else {
        $initialScreen = 2;
    }
}

// =============================================================================
// POST ACTION HANDLERS (AJAX API & Form Submissions)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $jsonInput = json_decode($rawInput, true);
    $input = is_array($jsonInput) ? $jsonInput : $_POST;

    $action = trim((string)($input['action'] ?? 'send_code'));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // -------------------------------------------------------------------------
    // ACTION: ABANDON RESET SESSION
    // -------------------------------------------------------------------------
    if ($action === 'abandon') {
        if (!empty($_SESSION['forgot_pwd']['reset_id'])) {
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL")
                ->execute([':id' => (int)$_SESSION['forgot_pwd']['reset_id']]);
        }
        unset($_SESSION['forgot_pwd'], $_SESSION['forgot_pwd_done']);

        if (isJsonRequest()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'success', 'message' => 'Reset process abandoned.']);
            exit;
        }
        header('Location: login.php');
        exit;
    }

    // -------------------------------------------------------------------------
    // ACTION 1: SEND VERIFICATION CODE (Screen 1 -> Screen 2)
    // -------------------------------------------------------------------------
    if ($action === 'send_code') {
        $email = trim((string)($input['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resp = ['status' => 'error', 'message' => 'Please provide a valid registered email address.'];
            if (isJsonRequest()) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode($resp);
                exit;
            }
            $initError = $resp['message'];
        } else {
            // Rate Limiting: Max 6 reset requests per IP in 15 minutes
            $rateStmt = $pdo->prepare("
                SELECT COUNT(*) FROM password_resets 
                WHERE ip_address = :ip AND created_at >= (NOW() - INTERVAL '15 MINUTE')
            ");
            $rateStmt->execute([':ip' => $ip]);
            $recentAttempts = (int)$rateStmt->fetchColumn();

            if ($recentAttempts >= 6) {
                $resp = [
                    'status' => 'error', 
                    'message' => 'Too many password reset requests from this network. Please wait a few minutes before trying again.'
                ];
                if (isJsonRequest()) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode($resp);
                    exit;
                }
                $initError = $resp['message'];
            } else {
                // Query user by registered email (active accounts only)
                $userStmt = $pdo->prepare("
                    SELECT id, name, email, role, is_active 
                    FROM users 
                    WHERE LOWER(email) = LOWER(:email) AND is_active = TRUE AND (is_archived = FALSE OR is_archived IS NULL)
                    LIMIT 1
                ");
                $userStmt->execute([':email' => $email]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $resp = [
                        'status' => 'error',
                        'message' => 'No active account found with this email address. Please verify and try again.'
                    ];
                    if (isJsonRequest()) {
                        header('Content-Type: application/json; charset=UTF-8');
                        echo json_encode($resp);
                        exit;
                    }
                    $initError = $resp['message'];
                } else {
                    $userId = (int)$user['id'];

                    // Invalidate prior active verification codes for this user
                    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")
                        ->execute([':uid' => $userId]);

                    // Generate cryptographically secure 6-digit numeric verification code
                    $verificationCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $codeHash = hash('sha256', $verificationCode);

                    // Expiration calculation: strictly at least 5 minutes from generation, with 10-minute buffer
                    $nowUnix = time();
                    $expiryDuration = 10 * 60; // 10 minutes safety buffer
                    $expiresAtUnix = $nowUnix + $expiryDuration;
                    $expiresAtUtc = gmdate('Y-m-d H:i:s', $expiresAtUnix);
                    $createdAtUtc = gmdate('Y-m-d H:i:s', $nowUnix);

                    // Store hashed code with explicit UTC expiration & max 5 attempts
                    $insertStmt = $pdo->prepare("
                        INSERT INTO password_resets 
                            (user_id, email, token_hash, ip_address, expires_at, attempts, max_attempts, created_at)
                        VALUES 
                            (:uid, :email, :thash, :ip, :expires_at, 0, 5, :created_at)
                    ");
                    $insertStmt->execute([
                        ':uid'        => $userId,
                        ':email'      => $user['email'],
                        ':thash'      => $codeHash,
                        ':ip'         => $ip,
                        ':expires_at' => $expiresAtUtc,
                        ':created_at' => $createdAtUtc
                    ]);
                    $resetId = (int)$pdo->lastInsertId();

                    // Dispatch email dynamically to registered address
                    EmailService::sendPasswordResetCode(
                        $pdo,
                        (string)$user['email'],
                        (string)$user['name'],
                        $verificationCode,
                        5,
                        $userId
                    );

                    // Store temporary session state with absolute UNIX timestamps
                    $_SESSION['forgot_pwd'] = [
                        'email'           => (string)$user['email'],
                        'name'            => (string)$user['name'],
                        'user_id'         => $userId,
                        'reset_id'        => $resetId,
                        'code_sent_at'    => $nowUnix,
                        'expires_at_unix' => $expiresAtUnix,
                        'verified'        => false,
                        'verified_at'     => null,
                        'reset_token'     => null,
                        'screen'          => 2
                    ];

                    $resp = [
                        'status'  => 'success',
                        'message' => 'A 6-digit verification code has been sent to your registered email address.',
                        'email'   => (string)$user['email'],
                        'screen'  => 2
                    ];

                    if (isJsonRequest()) {
                        header('Content-Type: application/json; charset=UTF-8');
                        echo json_encode($resp);
                        exit;
                    }

                    header('Location: forgot_password.php');
                    exit;
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // ACTION 2: VERIFY 6-DIGIT CODE (Screen 2 -> Screen 3)
    // -------------------------------------------------------------------------
    elseif ($action === 'verify_code') {
        $email = trim((string)($input['email'] ?? ($_SESSION['forgot_pwd']['email'] ?? '')));
        $code = trim((string)($input['code'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resp = ['status' => 'error', 'message' => 'Registered email is missing or invalid.'];
        } elseif (empty($code) || !preg_match('/^[0-9]{6}$/', $code)) {
            $resp = ['status' => 'error', 'message' => 'Please enter the 6-digit numeric verification code.'];
        } else {
            $stmt = $pdo->prepare("
                SELECT pr.id, pr.user_id, pr.email, pr.token_hash, pr.expires_at, pr.used_at, 
                       pr.attempts, pr.max_attempts, u.name, u.role
                FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE LOWER(pr.email) = LOWER(:email)
                  AND pr.used_at IS NULL
                ORDER BY pr.id DESC
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            // Compute expiration safely using UTC comparison
            $rawExpiresAt = trim((string)($record['expires_at'] ?? ''));
            $recordExpiresUnix = 0;
            if (!empty($rawExpiresAt)) {
                // If the string lacks an explicit timezone offset, treat it as UTC
                if (!preg_match('/[Z\+\-]\d{2}(:\d{2})?$/i', $rawExpiresAt)) {
                    $recordExpiresUnix = strtotime($rawExpiresAt . ' UTC');
                } else {
                    $recordExpiresUnix = strtotime($rawExpiresAt);
                }
            }

            // Fallback to session timestamp if database formatting had skew
            if ($recordExpiresUnix <= 0 && !empty($_SESSION['forgot_pwd']['expires_at_unix'])) {
                $recordExpiresUnix = (int)$_SESSION['forgot_pwd']['expires_at_unix'];
            }

            $currentTime = time();

            if (!$record) {
                $resp = [
                    'status' => 'error', 
                    'message' => 'No active verification code found for this email. Please request a new code.'
                ];
            } elseif ((int)$record['attempts'] >= (int)$record['max_attempts']) {
                $resp = [
                    'status' => 'error', 
                    'message' => 'Too many failed attempts. For your security, this code is no longer valid. Please request a new code.'
                ];
            } elseif ($recordExpiresUnix > 0 && $currentTime > $recordExpiresUnix) {
                $resp = [
                    'status' => 'error', 
                    'message' => 'This verification code has expired. Codes are only valid for 5 minutes. Please request a new code.'
                ];
            } else {
                $submittedHash = hash('sha256', $code);

                if (!hash_equals($record['token_hash'], $submittedHash)) {
                    // Increment failed attempt counter
                    $pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = :id")
                        ->execute([':id' => $record['id']]);

                    $remaining = max(0, (int)$record['max_attempts'] - ((int)$record['attempts'] + 1));
                    $resp = [
                        'status' => 'error',
                        'message' => "Invalid verification code. Please check your email. ({$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining)",
                        'remaining_attempts' => $remaining
                    ];
                } else {
                    // Verification Succeeded! Generate Single-Use Cryptographic Reset Token
                    $resetToken = bin2hex(random_bytes(32));

                    $_SESSION['forgot_pwd'] = [
                        'email'           => (string)$record['email'],
                        'name'            => (string)$record['name'],
                        'user_id'         => (int)$record['user_id'],
                        'reset_id'        => (int)$record['id'],
                        'code_sent_at'    => $_SESSION['forgot_pwd']['code_sent_at'] ?? $currentTime,
                        'expires_at_unix' => $recordExpiresUnix,
                        'verified'        => true,
                        'verified_at'     => $currentTime,
                        'reset_token'     => $resetToken,
                        'screen'          => 3
                    ];

                    $resp = [
                        'status'      => 'success',
                        'message'     => 'Your email has been successfully verified. You can now create a new password.',
                        'screen'      => 3,
                        'reset_token' => $resetToken
                    ];
                }
            }
        }

        if (isJsonRequest()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($resp);
            exit;
        }

        if (($resp['status'] ?? '') === 'success') {
            header('Location: forgot_password.php');
            exit;
        } else {
            $initError = $resp['message'] ?? 'Verification failed.';
        }
    }

    // -------------------------------------------------------------------------
    // ACTION 3: RESEND VERIFICATION CODE (Screen 2)
    // -------------------------------------------------------------------------
    elseif ($action === 'resend_code') {
        $email = trim((string)($input['email'] ?? ($_SESSION['forgot_pwd']['email'] ?? '')));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resp = ['status' => 'error', 'message' => 'Registered email is missing or invalid.'];
        } else {
            $lastSent = (int)($_SESSION['forgot_pwd']['code_sent_at'] ?? 0);
            $cooldown = 45;
            $timeSince = time() - $lastSent;

            if ($timeSince < $cooldown && $lastSent > 0) {
                $remainingSec = $cooldown - $timeSince;
                $resp = [
                    'status' => 'error',
                    'message' => "Please wait {$remainingSec} second" . ($remainingSec === 1 ? '' : 's') . " before requesting another code.",
                    'cooldown_remaining' => $remainingSec
                ];
            } else {
                $userStmt = $pdo->prepare("
                    SELECT id, name, email, is_active 
                    FROM users 
                    WHERE LOWER(email) = LOWER(:email) AND is_active = TRUE AND (is_archived = FALSE OR is_archived IS NULL)
                    LIMIT 1
                ");
                $userStmt->execute([':email' => $email]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $resp = ['status' => 'error', 'message' => 'No active account found with this email address.'];
                } else {
                    $userId = (int)$user['id'];

                    // Invalidate previous active codes
                    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")
                        ->execute([':uid' => $userId]);

                    // Generate new 6-digit code
                    $verificationCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $codeHash = hash('sha256', $verificationCode);

                    $nowUnix = time();
                    $expiryDuration = 10 * 60; // 10 minutes safety buffer
                    $expiresAtUnix = $nowUnix + $expiryDuration;
                    $expiresAtUtc = gmdate('Y-m-d H:i:s', $expiresAtUnix);
                    $createdAtUtc = gmdate('Y-m-d H:i:s', $nowUnix);

                    $insertStmt = $pdo->prepare("
                        INSERT INTO password_resets 
                            (user_id, email, token_hash, ip_address, expires_at, attempts, max_attempts, created_at)
                        VALUES 
                            (:uid, :email, :thash, :ip, :expires_at, 0, 5, :created_at)
                    ");
                    $insertStmt->execute([
                        ':uid'        => $userId,
                        ':email'      => $user['email'],
                        ':thash'      => $codeHash,
                        ':ip'         => $ip,
                        ':expires_at' => $expiresAtUtc,
                        ':created_at' => $createdAtUtc
                    ]);
                    $resetId = (int)$pdo->lastInsertId();

                    EmailService::sendPasswordResetCode(
                        $pdo,
                        (string)$user['email'],
                        (string)$user['name'],
                        $verificationCode,
                        5,
                        $userId
                    );

                    $_SESSION['forgot_pwd'] = [
                        'email'           => (string)$user['email'],
                        'name'            => (string)$user['name'],
                        'user_id'         => $userId,
                        'reset_id'        => $resetId,
                        'code_sent_at'    => $nowUnix,
                        'expires_at_unix' => $expiresAtUnix,
                        'verified'        => false,
                        'verified_at'     => null,
                        'reset_token'     => null,
                        'screen'          => 2
                    ];

                    $resp = [
                        'status'  => 'success',
                        'message' => 'A new 6-digit verification code has been sent to your email.',
                        'email'   => (string)$user['email'],
                        'screen'  => 2
                    ];
                }
            }
        }

        if (isJsonRequest()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($resp);
            exit;
        }

        header('Location: forgot_password.php');
        exit;
    }

    // -------------------------------------------------------------------------
    // ACTION 4: RESET PASSWORD (Screen 3 -> Screen 4)
    // -------------------------------------------------------------------------
    elseif ($action === 'reset_password') {
        // MANDATORY SECURITY CHECKS: Cannot bypass Screen 2 verification
        $isVerified = !empty($_SESSION['forgot_pwd']['verified']) && $_SESSION['forgot_pwd']['verified'] === true;
        $verifiedAt = (int)($_SESSION['forgot_pwd']['verified_at'] ?? 0);
        $sessionToken = (string)($_SESSION['forgot_pwd']['reset_token'] ?? '');
        $submittedToken = trim((string)($input['reset_token'] ?? ''));

        if (!$isVerified || empty($sessionToken)) {
            $resp = [
                'status'  => 'error',
                'message' => 'Unauthorized: You must verify your email with the 6-digit code before changing your password.',
                'screen'  => 2
            ];
        } elseif ((time() - $verifiedAt) > 900) { // 15-minute verification lifetime
            unset($_SESSION['forgot_pwd']);
            $resp = [
                'status'  => 'error',
                'message' => 'Your verification session has timed out. Please enter your email to request a new code.',
                'screen'  => 1
            ];
        } elseif (!hash_equals($sessionToken, $submittedToken)) {
            $resp = [
                'status'  => 'error',
                'message' => 'Invalid or expired security token. Please verify again.',
                'screen'  => 2
            ];
        } else {
            $newPassword = (string)($input['new_password'] ?? '');
            $confirmPassword = (string)($input['confirm_password'] ?? '');

            if (empty($newPassword) || strlen($newPassword) < 6) {
                $resp = ['status' => 'error', 'message' => 'Password must be at least 6 characters long.'];
            } elseif ($newPassword !== $confirmPassword) {
                $resp = ['status' => 'error', 'message' => 'Passwords do not match.'];
            } else {
                $userId = (int)$_SESSION['forgot_pwd']['user_id'];

                // Fetch user role and existing password hash
                $userCheckStmt = $pdo->prepare("SELECT role, password_hash FROM users WHERE id = :id LIMIT 1");
                $userCheckStmt->execute([':id' => $userId]);
                $userRow = $userCheckStmt->fetch(PDO::FETCH_ASSOC);
                $userRole = (string)($userRow['role'] ?? 'tenant');
                $currentHash = $userRow['password_hash'] ?? null;

                if (!empty($currentHash) && password_verify($newPassword, (string)$currentHash)) {
                    $resp = [
                        'status'  => 'error',
                        'success' => false,
                        'message' => 'You cannot use your previous password as your new password.'
                    ];
                } else {
                    $dbCommitted = false;
                    try {
                        $pdo->beginTransaction();

                        $resetId = (int)$_SESSION['forgot_pwd']['reset_id'];
                        $userEmail = (string)$_SESSION['forgot_pwd']['email'];
                        $userName = (string)($_SESSION['forgot_pwd']['name'] ?? 'User');
                        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                        // 1. Update user password
                        $upd = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
                        $upd->execute([':hash' => $newHash, ':id' => $userId]);

                        // 2. Mark this reset record used (Single-use enforcement)
                        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id")->execute([':id' => $resetId]);

                        // 3. Invalidate all other active reset tokens for this user
                        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")
                            ->execute([':uid' => $userId]);

                        $pdo->commit();
                        $dbCommitted = true;
                    } catch (Throwable $dbEx) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("[Password Reset DB Error]: " . $dbEx->getMessage());
                        $resp = [
                            'status'  => 'error',
                            'success' => false,
                            'message' => 'Unable to reset password. Please try again later.'
                        ];
                    }

                    if ($dbCommitted) {
                        // 4. Send security confirmation email (Isolated so mail errors do not fail the reset)
                        try {
                            EmailService::notifyPasswordResetSuccess($pdo, $userEmail, $userName, $userId);
                        } catch (Throwable $mailEx) {
                            error_log("[Password Reset Email Error]: " . $mailEx->getMessage());
                        }

                        // 5. In-App Notification (strictly matching signature of NotificationService::create)
                        try {
                            NotificationService::create(
                                $pdo,
                                $userId,
                                $userRole,
                                'account',
                                'Password Reset Successfully',
                                'Your account password was updated successfully. You can now log in using your new credentials.',
                                'login.php',
                                $userId,
                                'password_reset',
                                false // sendEmail = false to prevent duplicate email dispatch
                            );
                        } catch (Throwable $notifEx) {
                            error_log("[Password Reset Notification Error]: " . $notifEx->getMessage());
                        }

                        // 6. Destroy reset session and flag completion
                        unset($_SESSION['forgot_pwd']);
                        $_SESSION['forgot_pwd_done'] = true;

                        $resp = [
                            'status'  => 'success',
                            'success' => true,
                            'message' => 'Password successfully updated! You can now log in.',
                            'screen'  => 4
                        ];
                    }
                }
            }
        }

        if (isJsonRequest()) {
            sendJsonResponse($resp);
        }

        if (($resp['status'] ?? '') === 'success') {
            header('Location: forgot_password.php');
            exit;
        } else {
            $initError = $resp['message'] ?? 'Password reset failed.';
        }
    }

    // Default Fallback
    if (isJsonRequest()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaraRent – Reset Password</title>

  <!-- Global Favicon -->
  <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32.png">
  <link rel="icon" type="image/png" sizes="64x64" href="assets/images/favicon-64.png">
  <link rel="apple-touch-icon" sizes="192x192" href="assets/images/favicon-192.png">
  <link rel="shortcut icon" href="favicon.ico">
  <link rel="manifest" href="site.webmanifest">

  <!-- Typography: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
            mono: ['"Plus Jakarta Sans"', 'monospace']
          }
        }
      }
    }
  </script>

  <script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>

  <style>
    :root {
      --tararent-warm-cream: #EFE7DA;
      --tararent-card-top: #7D5124;
      --tararent-card-mid: #563815;
      --tararent-card-bot: #3A220B;
      --tararent-text-light: #FFFDF8;
      --tararent-text-cream: #F5EFEB;
      --tararent-text-muted: #EFE7DA;
    }

    *, *::before, *::after {
      box-sizing: border-box;
    }

    body {
      font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
      background-color: var(--tararent-warm-cream);
      background-image: 
        radial-gradient(circle at 50% 20%, rgba(245, 222, 179, 0.45) 0%, rgba(239, 231, 218, 0.85) 55%, #DFCEB0 100%);
      color: #FFFDF8;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0;
      padding: 24px 16px;
      position: relative;
      overflow-x: hidden;
      -webkit-font-smoothing: antialiased;
    }

    /* Ambient Background Glows */
    .ambient-glow-1 {
      position: absolute;
      top: -80px;
      left: -80px;
      width: 380px;
      height: 380px;
      background: rgba(184, 130, 53, 0.22);
      border-radius: 50%;
      filter: blur(80px);
      pointer-events: none;
    }

    .ambient-glow-2 {
      position: absolute;
      bottom: -80px;
      right: -80px;
      width: 440px;
      height: 440px;
      background: rgba(125, 81, 36, 0.25);
      border-radius: 50%;
      filter: blur(90px);
      pointer-events: none;
    }

    /* Signature Brown Claymorphic Card */
    .tararent-brown-card {
      width: 100%;
      max-width: 450px;
      background: linear-gradient(180deg, #7D5124 0%, #563815 48%, #3A220B 100%);
      border: 1.5px solid rgba(220, 195, 155, 0.3);
      border-radius: 24px;
      padding: 34px 32px 30px;
      box-shadow: 
        0 24px 56px rgba(35, 20, 8, 0.4),
        0 8px 20px rgba(35, 20, 8, 0.25),
        inset 0 1px 1px rgba(255, 255, 255, 0.22);
      position: relative;
      z-index: 10;
      transition: all 0.3s ease;
    }

    /* Reference Unit Card Clean Divider */
    .unit-card-divider {
      height: 2px;
      background: rgba(245, 239, 235, 0.65);
      border-radius: 2px;
      margin: 16px 0 22px;
      width: 100%;
    }

    /* Tactile Dark Inset Inputs */
    .tararent-input {
      width: 100%;
      background: rgba(24, 13, 5, 0.55);
      border: 1.5px solid rgba(212, 175, 55, 0.35);
      border-radius: 14px;
      color: #FFFDF8;
      font-weight: 600;
      font-size: 14px;
      padding: 12px 16px;
      box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.4);
      transition: all 0.2s ease;
    }

    .tararent-input:focus {
      outline: none;
      border-color: #E5B263;
      background: rgba(20, 10, 4, 0.7);
      box-shadow: 0 0 0 3.5px rgba(229, 178, 99, 0.28), inset 0 2px 5px rgba(0, 0, 0, 0.45);
    }

    .tararent-input::placeholder {
      color: rgba(239, 231, 218, 0.45);
      font-weight: 500;
    }

    /* 6-Digit Monospace Tactile Code Entry */
    .tararent-code-input {
      font-family: 'Plus Jakarta Sans', monospace;
      letter-spacing: 0.45em;
      text-align: center;
      font-size: 1.7rem;
      font-weight: 800;
      padding: 13px 16px;
      background: rgba(18, 9, 3, 0.65);
      border: 2px solid rgba(212, 175, 55, 0.45);
      border-radius: 16px;
      color: #FFFDF8;
      box-shadow: inset 0 3px 8px rgba(0, 0, 0, 0.5);
    }

    .tararent-code-input:focus {
      border-color: #F3CA7E;
      box-shadow: 0 0 0 4px rgba(243, 202, 126, 0.28), inset 0 3px 8px rgba(0, 0, 0, 0.55);
    }

    /* Pill-Shaped Claymorphic Action Buttons */
    .tararent-pill-btn {
      width: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: linear-gradient(180deg, #C29B63 0%, #9E7438 50%, #6E4D1D 100%);
      color: #FFFDF8;
      border: 1px solid rgba(255, 240, 210, 0.4);
      border-radius: 9999px;
      padding: 13px 24px;
      font-size: 14px;
      font-weight: 800;
      letter-spacing: 0.02em;
      cursor: pointer;
      box-shadow: 
        0 4px 14px rgba(25, 15, 6, 0.35),
        inset 0 1px 1px rgba(255, 255, 255, 0.25);
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      text-decoration: none;
    }

    .tararent-pill-btn:hover:not(:disabled) {
      background: linear-gradient(180deg, #CEAA73 0%, #AA8042 50%, #7A5623 100%);
      transform: translateY(-1.5px);
      box-shadow: 
        0 6px 18px rgba(25, 15, 6, 0.45),
        inset 0 1px 1px rgba(255, 255, 255, 0.3);
    }

    .tararent-pill-btn:active:not(:disabled) {
      transform: translateY(0.5px);
      box-shadow: 0 2px 8px rgba(25, 15, 6, 0.3);
    }

    .tararent-pill-btn:disabled {
      opacity: 0.65;
      cursor: not-allowed;
      transform: none;
    }

    /* Red Occupied-style badge for urgency ("Valid for 5 mins") */
    .tararent-red-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      background: #D35454;
      border: 1px solid rgba(255, 255, 255, 0.25);
      color: #FFFDF8;
      font-size: 11px;
      font-weight: 800;
      padding: 3px 10px;
      border-radius: 9999px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
    }

    /* Warm Alert Box */
    .tararent-alert {
      padding: 12px 14px;
      border-radius: 14px;
      font-size: 12.5px;
      font-weight: 600;
      line-height: 1.4;
      display: flex;
      align-items: flex-start;
      gap: 8px;
      margin-bottom: 18px;
    }

    .tararent-alert-error {
      background: rgba(185, 28, 28, 0.35);
      border: 1px solid rgba(248, 113, 113, 0.4);
      color: #FEE2E2;
    }

    .tararent-alert-success {
      background: rgba(16, 185, 129, 0.3);
      border: 1px solid rgba(110, 231, 183, 0.45);
      color: #D1FAE5;
    }

    /* Loading Spinner */
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .btn-spinner {
      display: inline-block;
      width: 1rem;
      height: 1rem;
      border: 2px solid rgba(255, 255, 255, 0.35);
      border-top-color: #ffffff;
      border-radius: 9999px;
      animation: spin 0.75s linear infinite;
      margin-right: 0.5rem;
      vertical-align: middle;
    }

    /* Screen Fade Animation */
    .screen-fade {
      animation: screenFadeIn 0.22s ease-out;
    }
    @keyframes screenFadeIn {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body>

  <!-- Ambient Glow Elements -->
  <div class="ambient-glow-1"></div>
  <div class="ambient-glow-2"></div>

  <!-- Main Signature Brown Claymorphic Card -->
  <div class="tararent-brown-card">

    <!-- Brand Header -->
    <div class="text-center mb-5">
      <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/10 border border-white/20 shadow-inner text-xs font-bold text-[#FFFDF8]">
        <img src="assets/images/tararent-logo.png" alt="TaraRent Logo" style="width: 18px; height: 18px; object-fit: contain;">
        <span>TaraRent</span>
      </div>
    </div>

    <!-- =====================================================================
         SCREEN 1 – FORGOT PASSWORD (REGISTERED EMAIL)
         ===================================================================== -->
    <div id="screen1View" class="screen-fade <?= $initialScreen === 1 ? '' : 'hidden' ?>">
      <h1 class="text-2xl font-black text-white text-center m-0 tracking-tight">
        Forgot Password
      </h1>
      <p class="text-xs text-[#F5EFEB] text-center mt-1.5 mb-0 leading-relaxed font-medium">
        Enter your registered email address to receive a 6-digit verification code.
      </p>

      <!-- Signature Reference Card Divider -->
      <div class="unit-card-divider"></div>

      <div id="screen1Alert" class="tararent-alert tararent-alert-error hidden">
        <i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0 mt-0.5 text-[#FCA5A5]"></i>
        <span id="screen1AlertText"></span>
      </div>

      <form id="formScreen1" onsubmit="handleSendCode(event)" class="space-y-4">
        <div>
          <label for="screen1Email" class="block text-xs font-extrabold text-[#EFE7DA] mb-1.5 uppercase tracking-wide flex items-center gap-1.5">
            <i data-lucide="mail" class="w-3.5 h-3.5 text-[#D4AF37]"></i>
            <span>Registered Email Address</span>
          </label>
          <input 
            type="email" 
            id="screen1Email" 
            name="email" 
            class="tararent-input" 
            placeholder="e.g. name@example.com" 
            value="<?= htmlspecialchars($initialEmail) ?>"
            required 
            autofocus
          >
        </div>

        <button 
          type="submit" 
          id="btnSendCode" 
          class="tararent-pill-btn"
        >
          <i data-lucide="send" class="w-4 h-4"></i>
          <span>Send Verification Code</span>
        </button>
      </form>

      <div class="text-center mt-6 pt-2">
        <a href="login.php" class="text-xs font-bold text-[#EFE7DA] hover:text-white transition-colors inline-flex items-center gap-1.5">
          <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
          <span>Back to Login</span>
        </a>
      </div>
    </div>

    <!-- =====================================================================
         SCREEN 2 – VERIFY YOUR EMAIL (6-DIGIT CODE)
         ===================================================================== -->
    <div id="screen2View" class="screen-fade <?= $initialScreen === 2 ? '' : 'hidden' ?>">
      <h1 class="text-2xl font-black text-white text-center m-0 tracking-tight">
        Verify Your Email
      </h1>
      <p class="text-xs text-[#F5EFEB] text-center mt-1.5 mb-0 leading-relaxed font-medium">
        Enter the 6-digit verification code sent to your registered email.
      </p>

      <!-- Signature Reference Card Divider -->
      <div class="unit-card-divider"></div>

      <!-- Registered Email Pill with Change Option -->
      <div class="bg-black/25 border border-white/15 rounded-xl px-3.5 py-2.5 mb-4 flex items-center justify-between gap-2 text-xs text-white">
        <div class="flex items-center gap-2 overflow-hidden truncate">
          <i data-lucide="mail-check" class="w-4 h-4 text-[#D4AF37] flex-shrink-0"></i>
          <span class="font-bold truncate text-[#FFFDF8]" id="screen2EmailDisplay"><?= htmlspecialchars($initialEmail ?: 'email@example.com') ?></span>
        </div>
        <button type="button" onclick="goToScreen(1)" class="text-xs font-extrabold text-[#F3CA7E] hover:text-white underline flex-shrink-0 cursor-pointer">
          Change
        </button>
      </div>

      <div id="screen2Alert" class="tararent-alert tararent-alert-error hidden">
        <i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0 mt-0.5 text-[#FCA5A5]"></i>
        <span id="screen2AlertText"></span>
      </div>

      <form id="formScreen2" onsubmit="handleVerifyCode(event)" class="space-y-4">
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label for="screen2Code" class="text-xs font-extrabold text-[#EFE7DA] uppercase tracking-wide flex items-center gap-1.5">
              <i data-lucide="hash" class="w-3.5 h-3.5 text-[#D4AF37]"></i>
              <span>Verification Code</span>
            </label>
            <span class="tararent-red-badge">
              Valid for 5 mins
            </span>
          </div>
          <input 
            type="text" 
            id="screen2Code" 
            name="code" 
            class="tararent-input tararent-code-input" 
            placeholder="••••••" 
            maxlength="6" 
            pattern="[0-9]{6}" 
            inputmode="numeric" 
            autocomplete="one-time-code"
            required 
          >
        </div>

        <button 
          type="submit" 
          id="btnVerifyCode" 
          class="tararent-pill-btn"
        >
          <i data-lucide="shield-check" class="w-4 h-4"></i>
          <span>Verify Code</span>
        </button>
      </form>

      <div class="flex items-center justify-between mt-6 pt-2 text-xs">
        <button 
          type="button" 
          id="btnResendCode" 
          onclick="handleResendCode()" 
          class="font-extrabold text-[#F3CA7E] hover:text-white transition-colors inline-flex items-center gap-1.5 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
        >
          <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
          <span id="btnResendText">Resend Code</span>
        </button>
        <a href="forgot_password.php?abandon=1" class="font-bold text-[#EFE7DA] hover:text-white transition-colors inline-flex items-center gap-1.5">
          <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
          <span>Back to Login</span>
        </a>
      </div>
    </div>

    <!-- =====================================================================
         SCREEN 3 – CHANGE PASSWORD
         ===================================================================== -->
    <div id="screen3View" class="screen-fade <?= $initialScreen === 3 ? '' : 'hidden' ?>">
      <h1 class="text-2xl font-black text-white text-center m-0 tracking-tight">
        Change Password
      </h1>
      <p class="text-xs text-[#F5EFEB] text-center mt-1.5 mb-0 leading-relaxed font-medium">
        Email verified successfully! Please enter your new account password.
      </p>

      <!-- Signature Reference Card Divider -->
      <div class="unit-card-divider"></div>

      <div id="screen3Alert" class="tararent-alert tararent-alert-error hidden">
        <i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0 mt-0.5 text-[#FCA5A5]"></i>
        <span id="screen3AlertText"></span>
      </div>

      <form id="formScreen3" onsubmit="handleResetPassword(event)" class="space-y-4">
        <input type="hidden" id="screen3ResetToken" name="reset_token" value="<?= htmlspecialchars($initialResetToken) ?>">

        <div>
          <label for="screen3Password" class="block text-xs font-extrabold text-[#EFE7DA] mb-1.5 uppercase tracking-wide flex items-center gap-1.5">
            <i data-lucide="lock" class="w-3.5 h-3.5 text-[#D4AF37]"></i>
            <span>New Password</span>
          </label>
          <div class="relative">
            <input 
              type="password" 
              id="screen3Password" 
              name="new_password" 
              class="tararent-input pr-10" 
              placeholder="Enter at least 6 characters" 
              required 
              minlength="6"
              autofocus
            >
            <button 
              type="button" 
              class="absolute right-3 top-1/2 -translate-y-1/2 text-[#EFE7DA]/70 hover:text-white p-1 text-sm cursor-pointer inline-flex items-center justify-center transition-colors" 
              onclick="togglePasswordVisibility('screen3Password', this)" 
              title="Show / Hide Password"
              aria-label="Show or Hide Password"
            >
              <i data-lucide="eye" class="w-4 h-4"></i>
            </button>
          </div>
        </div>

        <div>
          <label for="screen3ConfirmPassword" class="block text-xs font-extrabold text-[#EFE7DA] mb-1.5 uppercase tracking-wide flex items-center gap-1.5">
            <i data-lucide="shield" class="w-3.5 h-3.5 text-[#D4AF37]"></i>
            <span>Confirm Password</span>
          </label>
          <div class="relative">
            <input 
              type="password" 
              id="screen3ConfirmPassword" 
              name="confirm_password" 
              class="tararent-input pr-10" 
              placeholder="Re-type your new password" 
              required 
              minlength="6"
            >
            <button 
              type="button" 
              class="absolute right-3 top-1/2 -translate-y-1/2 text-[#EFE7DA]/70 hover:text-white p-1 text-sm cursor-pointer inline-flex items-center justify-center transition-colors" 
              onclick="togglePasswordVisibility('screen3ConfirmPassword', this)" 
              title="Show / Hide Password"
              aria-label="Show or Hide Password"
            >
              <i data-lucide="eye" class="w-4 h-4"></i>
            </button>
          </div>
        </div>

        <button 
          type="submit" 
          id="btnResetPassword" 
          class="tararent-pill-btn"
        >
          <i data-lucide="check" class="w-4 h-4"></i>
          <span>Save New Password</span>
        </button>
      </form>

      <div class="text-center mt-6 pt-2">
        <a href="forgot_password.php?abandon=1" class="text-xs font-bold text-[#EFE7DA] hover:text-white transition-colors inline-flex items-center gap-1.5">
          <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
          <span>Back to Login</span>
        </a>
      </div>
    </div>

    <!-- =====================================================================
         SCREEN 4 – PASSWORD RESET SUCCESSFUL
         ===================================================================== -->
    <div id="screen4View" class="screen-fade hidden text-center py-2">
      <!-- Animated Success Checkmark Badge -->
      <div style="width: 72px; height: 72px; margin: 0 auto 16px; border-radius: 50%; background: linear-gradient(135deg, #10B981 0%, #059669 100%); color: #FFFFFF; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 24px rgba(5, 150, 105, 0.4); border: 2px solid rgba(110, 231, 183, 0.4);">
        <i data-lucide="check" class="w-9 h-9" style="stroke-width: 3;"></i>
      </div>

      <h1 class="text-2xl font-black text-white tracking-tight mb-2">
        Password Reset Successful
      </h1>
      <p id="screen4SuccessText" class="text-sm font-bold text-[#A7F3D0] mb-2">
        Password successfully updated! You can now log in.
      </p>
      <p class="text-xs text-[#F5EFEB] leading-relaxed mb-7 max-w-xs mx-auto font-medium">
        You can now sign in to your TaraRent account using your new credentials.
      </p>

      <a 
        href="login.php" 
        class="tararent-pill-btn text-center"
      >
        <i data-lucide="log-in" class="w-4 h-4"></i>
        <span>Proceed to Sign In</span>
      </a>
    </div>

  </div>

  <script>
    // State management
    let currentScreen = <?= (int)$initialScreen ?>;
    let registeredEmail = <?= json_encode($initialEmail) ?>;
    let verifiedResetToken = <?= json_encode($initialResetToken) ?>;
    let resendCooldownTimer = null;
    let resendSecondsRemaining = 0;

    // Initialize Lucide icons on DOM ready
    document.addEventListener('DOMContentLoaded', () => {
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    });

    // Setup History State & Intercept Browser Back Button
    window.history.replaceState({ screen: currentScreen }, '', window.location.href);

    window.addEventListener('popstate', function (event) {
      if (event.state && event.state.screen) {
        goToScreen(event.state.screen, false);
      } else {
        window.location.href = 'login.php';
      }
    });

    // =========================================================================
    // SCREEN DISPLAY & TOGGLES
    // =========================================================================
    function goToScreen(screenNum, pushState = true) {
      currentScreen = screenNum;

      // Update Screen Visibility
      document.getElementById('screen1View').classList.toggle('hidden', screenNum !== 1);
      document.getElementById('screen2View').classList.toggle('hidden', screenNum !== 2);
      document.getElementById('screen3View').classList.toggle('hidden', screenNum !== 3);
      document.getElementById('screen4View').classList.toggle('hidden', screenNum !== 4);

      if (pushState) {
        window.history.pushState({ screen: screenNum }, '', window.location.href);
      }

      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }

      // Focus fields
      if (screenNum === 1) {
        setTimeout(() => document.getElementById('screen1Email').focus(), 150);
      } else if (screenNum === 2) {
        document.getElementById('screen2EmailDisplay').textContent = registeredEmail || 'your email';
        setTimeout(() => document.getElementById('screen2Code').focus(), 150);
      } else if (screenNum === 3) {
        setTimeout(() => document.getElementById('screen3Password').focus(), 150);
      }
    }

    function togglePasswordVisibility(fieldId, btn) {
      const input = document.getElementById(fieldId);
      if (!input) return;
      if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<i data-lucide="eye-off" class="w-4 h-4"></i>';
        btn.title = 'Hide Password';
      } else {
        input.type = 'password';
        btn.innerHTML = '<i data-lucide="eye" class="w-4 h-4"></i>';
        btn.title = 'Show Password';
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    function showScreenAlert(screenNum, msg, type = 'error') {
      const alertBox = document.getElementById(`screen${screenNum}Alert`);
      const alertText = document.getElementById(`screen${screenNum}AlertText`);
      if (!alertBox || !alertText) return;

      alertBox.className = `tararent-alert ${
        type === 'success' 
          ? 'tararent-alert-success' 
          : 'tararent-alert-error'
      }`;
      alertText.textContent = msg;
      alertBox.classList.remove('hidden');
    }

    function hideScreenAlert(screenNum) {
      const alertBox = document.getElementById(`screen${screenNum}Alert`);
      if (alertBox) alertBox.classList.add('hidden');
    }

    // =========================================================================
    // SCREEN 1: SEND VERIFICATION CODE
    // =========================================================================
    async function handleSendCode(e) {
      e.preventDefault();
      hideScreenAlert(1);

      const emailInput = document.getElementById('screen1Email');
      const email = emailInput.value.trim();
      if (!email) {
        showScreenAlert(1, 'Please enter your registered email address.');
        return;
      }

      const btn = document.getElementById('btnSendCode');
      const originalBtnHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="btn-spinner"></span> Sending Verification Code...';

      try {
        const res = await fetch('forgot_password.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'send_code',
            email: email
          })
        });

        const data = await res.json();

        if (res.ok && data.status === 'success') {
          registeredEmail = data.email || email;
          startResendCooldown(45);
          goToScreen(2);
        } else {
          showScreenAlert(1, data.message || 'Unable to send verification code. Please check your email and try again.');
        }
      } catch (err) {
        console.error('[Send Code Error]:', err);
        showScreenAlert(1, 'Network or server error while sending verification code. Please check your connection.');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      }
    }

    // =========================================================================
    // SCREEN 2: VERIFY CODE
    // =========================================================================
    async function handleVerifyCode(e) {
      e.preventDefault();
      hideScreenAlert(2);

      const codeInput = document.getElementById('screen2Code');
      const code = codeInput.value.trim();

      if (!code || !/^[0-9]{6}$/.test(code)) {
        showScreenAlert(2, 'Please enter the full 6-digit numeric verification code.');
        codeInput.focus();
        return;
      }

      const btn = document.getElementById('btnVerifyCode');
      const originalBtnHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="btn-spinner"></span> Verifying Code...';

      try {
        const res = await fetch('forgot_password.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'verify_code',
            email: registeredEmail,
            code: code
          })
        });

        const data = await res.json();

        if (res.ok && data.status === 'success') {
          verifiedResetToken = data.reset_token || '';
          document.getElementById('screen3ResetToken').value = verifiedResetToken;
          goToScreen(3);
        } else {
          showScreenAlert(2, data.message || 'Invalid verification code. Please check your email and try again.');
          codeInput.select();
        }
      } catch (err) {
        console.error('[Verify Code Error]:', err);
        showScreenAlert(2, 'Network or server error verifying code. Please try again.');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      }
    }

    // Resend Code Action on Screen 2
    async function handleResendCode() {
      if (resendSecondsRemaining > 0) return;
      hideScreenAlert(2);

      const btn = document.getElementById('btnResendCode');
      const textEl = document.getElementById('btnResendText');
      btn.disabled = true;
      textEl.textContent = 'Sending...';

      try {
        const res = await fetch('forgot_password.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'resend_code',
            email: registeredEmail
          })
        });

        const data = await res.json();

        if (res.ok && data.status === 'success') {
          showScreenAlert(2, data.message || 'A fresh verification code has been dispatched to your email.', 'success');
          startResendCooldown(45);
          document.getElementById('screen2Code').value = '';
          document.getElementById('screen2Code').focus();
        } else {
          showScreenAlert(2, data.message || 'Unable to resend code at this time.');
          if (data.cooldown_remaining) {
            startResendCooldown(data.cooldown_remaining);
          } else {
            btn.disabled = false;
            textEl.textContent = 'Resend Code';
          }
        }
      } catch (err) {
        console.error('[Resend Error]:', err);
        showScreenAlert(2, 'Network error requesting new code.');
        btn.disabled = false;
        textEl.textContent = 'Resend Code';
      } finally {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      }
    }

    function startResendCooldown(seconds) {
      if (resendCooldownTimer) clearInterval(resendCooldownTimer);
      resendSecondsRemaining = seconds;

      const btn = document.getElementById('btnResendCode');
      const textEl = document.getElementById('btnResendText');
      btn.disabled = true;

      const updateText = () => {
        if (resendSecondsRemaining > 0) {
          textEl.textContent = `Resend in ${resendSecondsRemaining}s`;
          resendSecondsRemaining--;
        } else {
          clearInterval(resendCooldownTimer);
          btn.disabled = false;
          textEl.textContent = 'Resend Code';
        }
      };

      updateText();
      resendCooldownTimer = setInterval(updateText, 1000);
    }

    // =========================================================================
    // SCREEN 3: RESET PASSWORD
    // =========================================================================
    async function handleResetPassword(e) {
      e.preventDefault();
      hideScreenAlert(3);

      const p1 = document.getElementById('screen3Password').value;
      const p2 = document.getElementById('screen3ConfirmPassword').value;

      if (!p1 || p1.length < 6) {
        showScreenAlert(3, 'Password must be at least 6 characters long.');
        document.getElementById('screen3Password').focus();
        return;
      }

      if (p1 !== p2) {
        showScreenAlert(3, 'Passwords do not match.');
        document.getElementById('screen3ConfirmPassword').focus();
        return;
      }

      const tokenVal = document.getElementById('screen3ResetToken').value || verifiedResetToken;
      const btn = document.getElementById('btnResetPassword');
      const originalBtnHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="btn-spinner"></span> Saving New Password...';

      try {
        const res = await fetch('forgot_password.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'reset_password',
            new_password: p1,
            confirm_password: p2,
            reset_token: tokenVal
          })
        });

        let data;
        const rawText = await res.text();
        try {
          data = JSON.parse(rawText);
        } catch (jsonErr) {
          console.error('[Reset Password JSON Parse Error]:', jsonErr, 'Raw response:', rawText);
          showScreenAlert(3, 'Unable to reset password. Please try again later.');
          return;
        }

        const isSuccess = Boolean(data && (data.status === 'success' || data.success === true));

        if (isSuccess) {
          const successEl = document.getElementById('screen4SuccessText');
          if (successEl && data.message) {
            successEl.textContent = data.message;
          }
          goToScreen(4);
        } else {
          if (data && data.screen && data.screen !== 3) {
            goToScreen(data.screen);
            showScreenAlert(data.screen, data.message || 'Session expired. Please start over.');
          } else {
            showScreenAlert(3, (data && data.message) ? data.message : 'Unable to reset password. Please try again later.');
          }
        }
      } catch (err) {
        console.error('[Reset Password Error]:', err);
        showScreenAlert(3, 'Network or server error updating password. Please try again.');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnHtml;
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
          window.lucide.createIcons();
        }
      }
    }
  </script>

</body>
</html>
