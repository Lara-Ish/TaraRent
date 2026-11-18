<?php
/**
 * TaraRent - Tenant Payment Proof Upload Handler (upload_payment.php)
 *
 * Uploads payment receipt directly to Supabase Cloud Storage bucket ('receipts')
 * and stores the absolute public URL in the Supabase PostgreSQL database.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/storage_service.php';
require_once __DIR__ . '/includes/notification_service.php';

// 1. Session Authentication Check
if (!class_exists('Auth') || !Auth::check()) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(401);
    }
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$currentUser = Auth::user();
$tenantId = (int)($currentUser['id'] ?? 0);
$unitId = !empty($currentUser['unit_id']) ? (int)$currentUser['unit_id'] : null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: tenant_portal.php?section=tenantPayments');
    exit;
}

$pdo = Database::getConnection();

// 2. Validate Receipt File
if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    header('Location: tenant_portal.php?section=tenantPayments&error=no_file');
    exit;
}

$file = $_FILES['receipt'];
$maxBytes = 10 * 1024 * 1024; // 10MB limit

if ($file['size'] > $maxBytes) {
    header('Location: tenant_portal.php?section=tenantPayments&error=file_too_large');
    exit;
}

$origExt = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
$allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
if (!in_array($origExt, $allowedExts, true)) {
    header('Location: tenant_portal.php?section=tenantPayments&error=invalid_file_type');
    exit;
}

// MIME Type Check
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!in_array($mime, $allowedMimes, true)) {
            header('Location: tenant_portal.php?section=tenantPayments&error=invalid_file_type');
            exit;
        }
    }
}

// 3. Upload Directly to Supabase Storage via REST API
$timestamp = time();
$safeName = "receipt_{$tenantId}_{$timestamp}_" . bin2hex(random_bytes(4)) . ".{$origExt}";
$publicReceiptUrl = uploadToSupabaseStorage($file['tmp_name'], $safeName, 'receipts');

if ($publicReceiptUrl === false) {
    error_log("[upload_payment.php] Failed to upload receipt to Supabase Storage for tenant {$tenantId}");
    header('Location: tenant_portal.php?section=tenantPayments&error=upload_failed');
    exit;
}

// 4. Capture Form Parameters
$paymentType = trim((string)($_POST['payment_type'] ?? 'Monthly Rent'));
if ($paymentType === '') {
    $paymentType = 'Monthly Rent';
}
$amountPaid = (float)($_POST['amount_paid'] ?? 3000.00);
$billingCycle = trim((string)($_POST['billing_cycle'] ?? date('Y-m-01')));
$paymentMethod = trim((string)($_POST['payment_method'] ?? 'GCash / Bank Transfer'));
$refNum = trim((string)($_POST['reference_number'] ?? ''));
if (!empty($refNum) && stripos($paymentMethod, $refNum) === false) {
    $paymentMethod = $paymentMethod . ' (Ref: ' . $refNum . ')';
}

// Resolve Due Date & Rent Amount from Unit
$baseRent = 3000.00;
if ($unitId !== null && $unitId > 0) {
    $unitStmt = $pdo->prepare("SELECT monthly_rent FROM units WHERE id = :uid LIMIT 1");
    $unitStmt->execute([':uid' => $unitId]);
    $uRent = $unitStmt->fetchColumn();
    if ($uRent !== false && (float)$uRent > 0) {
        $baseRent = (float)$uRent;
    }
}

// 5. Insert Record with Absolute Public Supabase URL
$insertStmt = $pdo->prepare("
    INSERT INTO payments (
        tenant_id,
        unit_id,
        payment_type,
        billing_cycle,
        due_date,
        base_rent_amount,
        additional_space_fee,
        amount_due,
        amount_paid,
        payment_date,
        status,
        payment_method,
        receipt_filename,
        verified_by_landlord,
        created_at
    ) VALUES (
        :tenant_id,
        :unit_id,
        :payment_type,
        :billing_cycle,
        :due_date,
        :base_rent_amount,
        0.00,
        :amount_due,
        :amount_paid,
        CURRENT_DATE,
        'pending',
        :payment_method,
        :receipt_filename,
        FALSE,
        NOW()
    )
");

$insertStmt->execute([
    ':tenant_id'         => $tenantId,
    ':unit_id'           => $unitId,
    ':payment_type'      => $paymentType,
    ':billing_cycle'     => $billingCycle,
    ':due_date'          => $billingCycle,
    ':base_rent_amount'  => $baseRent,
    ':amount_due'        => $baseRent,
    ':amount_paid'       => $amountPaid,
    ':payment_method'    => $paymentMethod,
    ':receipt_filename'  => $publicReceiptUrl
]);

// 6. Notify Landlord
try {
    if (class_exists('NotificationService')) {
        $tName = $currentUser['name'] ?? 'Resident';
        NotificationService::create(
            $pdo,
            'landlord',
            null,
            'New Payment Submitted',
            "{$tName} submitted payment proof for {$paymentType} (₱" . number_format($amountPaid, 2) . ").",
            'payment',
            'admin_dashboard.php?section=sectionPayments'
        );
    }
} catch (\Throwable $ne) {
    error_log('[upload_payment.php] Notification failed: ' . $ne->getMessage());
}

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'success',
        'message' => 'Payment proof uploaded successfully to Supabase Storage.',
        'receipt_url' => $publicReceiptUrl
    ]);
    exit;
}

header('Location: tenant_portal.php?section=tenantPayments&status=payment_submitted');
exit;
