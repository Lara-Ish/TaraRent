<?php
/**
 * RentConnect / TaraRent - Centralized Access Control & Security Guards
 * Provides owner password verification for sensitive tenant deletions and
 * real-time MySQL-backed read-only enforcement for unverified tenant accounts.
 */

declare(strict_types=1);

class AccessControl
{
    public const UNVERIFIED_FEATURE_BLOCK_MESSAGE =
        'Access Denied: Please verify your identity to submit new records.';

    /**
     * Normalize any boolean representation (PHP bool, int 0/1, Postgres 't'/'f', string 'true'/'false') to boolean.
     */
    public static function toBoolean($val): bool
    {
        if ($val === true || $val === 1 || $val === '1' || $val === 't' || $val === 'true') {
            return true;
        }
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Evaluate whether a user database row is verified for transactional actions.
     * Landlords/owners are never restricted by tenant verification status.
     * If is_verified == false (or 0), tenant is strictly unverified.
     */
    public static function isUserVerifiedRow(array $userRow): bool
    {
        if (strtolower((string)($userRow['role'] ?? '')) === 'landlord') {
            return true;
        }

        $verificationStatus = strtolower(trim((string)($userRow['verification_status'] ?? 'unverified')));
        $isVerifiedFlag = self::toBoolean($userRow['is_verified'] ?? false);

        // Strict Unverified Rule: If is_verified == false (or 0), tenant is strictly unverified
        if (!$isVerifiedFlag) {
            return false;
        }

        return ($verificationStatus === 'verified');
    }

    /**
     * Determine comprehensive tenant tenancy, move-out, balance, and RBAC mode.
     * Enforces the three strict RBAC rules:
     * 1. 'silent_lockout'  : Moved out AND outstanding balance <= 0 (completely deny access, silent redirect to login).
     * 2. 'debt_collection': Moved out AND outstanding balance > 0 (Dashboard & Upload Payment only, lock rest).
     * 3. 'unverified'     : is_verified == false / 0 (Read-only Dashboard, block payments & maintenance submission).
     * 4. 'deactivated'    : is_active == false (Account deactivated by management).
     * 5. 'active_verified': Active, verified tenant with full access.
     */
    public static function getTenantStatus(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [
                'found'               => false,
                'role'                => null,
                'is_active'           => false,
                'is_verified'         => false,
                'is_moved_out'        => false,
                'outstanding_balance' => 0.0,
                'unpaid_count'        => 0,
                'pending_count'       => 0,
                'has_debt'            => false,
                'is_settled'          => true,
                'mode'                => 'invalid',
                'user'                => null,
            ];
        }

        $stmt = $pdo->prepare("
            SELECT id, name, username, email, role, phone, unit_id,
                   is_active, is_archived, rental_status, verification_status,
                   is_verified, move_out_date, profile_picture, deleted_at
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [
                'found'               => false,
                'role'                => null,
                'is_active'           => false,
                'is_verified'         => false,
                'is_moved_out'        => false,
                'outstanding_balance' => 0.0,
                'unpaid_count'        => 0,
                'pending_count'       => 0,
                'has_debt'            => false,
                'is_settled'          => true,
                'mode'                => 'invalid',
                'user'                => null,
            ];
        }

        $role = strtolower((string)($user['role'] ?? 'tenant'));
        $isActive = self::toBoolean($user['is_active'] ?? false);
        $isArchived = self::toBoolean($user['is_archived'] ?? false);
        $rentalStatus = strtolower(trim((string)($user['rental_status'] ?? 'active')));
        $verificationStatus = strtolower(trim((string)($user['verification_status'] ?? 'unverified')));
        $isVerifiedFlag = self::toBoolean($user['is_verified'] ?? false);
        $hasDeletedAt = !empty($user['deleted_at']);

        // Strict verification: is_verified must be true AND verification_status must be 'verified'
        $isVerified = $isVerifiedFlag && ($verificationStatus === 'verified');

        // Check if marked as moved out
        $isMovedOut = in_array($rentalStatus, ['moved_out', 'moved out', 'archived'], true)
            || $isArchived
            || $hasDeletedAt;

        // Query outstanding balance and unpaid/pending bill counts from payments table
        $payStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_records,
                COALESCE(SUM(CASE WHEN (COALESCE(amount_due, 0) - COALESCE(amount_paid, 0)) > 0.01 THEN (amount_due - amount_paid) ELSE 0 END), 0) AS total_unpaid_balance,
                COALESCE(SUM(CASE WHEN status IN ('unpaid', 'overdue', 'rejected', 'pending_setup') THEN 1 ELSE 0 END), 0) AS unpaid_count,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count
            FROM payments
            WHERE tenant_id = :tid
        ");
        $payStmt->execute([':tid' => $userId]);
        $payRow = $payStmt->fetch(PDO::FETCH_ASSOC);

        $unpaidBalance = round((float)($payRow['total_unpaid_balance'] ?? 0.0), 2);
        $unpaidCount = (int)($payRow['unpaid_count'] ?? 0);
        $pendingCount = (int)($payRow['pending_count'] ?? 0);

        // Account has debt if monetary balance remaining or unpaid bills exist
        $hasDebt = ($unpaidBalance > 0.01 || $unpaidCount > 0);
        $isSettled = (!$hasDebt && $pendingCount === 0);

        // Classify strict RBAC mode
        if ($isMovedOut) {
            if ($hasDebt) {
                // Rule 3: Debt Collection Mode (Moved Out & Unpaid)
                $mode = 'debt_collection';
            } else {
                // Rule 2: Silent Lockout (Moved Out & Paid)
                $mode = 'silent_lockout';
            }
        } elseif (!$isActive) {
            $mode = 'deactivated';
        } elseif (!$isVerified) {
            // Rule 1: Unverified Restrictions
            $mode = 'unverified';
        } else {
            $mode = 'active_verified';
        }

        return [
            'found'               => true,
            'user'                => $user,
            'role'                => $role,
            'is_active'           => $isActive,
            'is_verified'         => $isVerified,
            'is_moved_out'        => $isMovedOut,
            'outstanding_balance' => $unpaidBalance,
            'unpaid_count'        => $unpaidCount,
            'pending_count'       => $pendingCount,
            'has_debt'            => $hasDebt,
            'is_settled'          => $isSettled,
            'mode'                => $mode,
        ];
    }

    /**
     * Fetch the tenant's live verification state directly from database so
     * permission upgrades/revocations take effect immediately without re-login.
     */
    public static function getUserVerificationState(PDO $pdo, int $userId): array
    {
        $status = self::getTenantStatus($pdo, $userId);
        if (!$status['found']) {
            return [
                'found'               => false,
                'is_active'           => 0,
                'is_archived'         => 0,
                'role'                => null,
                'verification_status' => 'unverified',
                'is_verified'         => 0,
                'verified'            => false,
            ];
        }

        $u = $status['user'];
        $isActive = $status['is_active'] ? 1 : 0;
        $isArchived = (!empty($u['is_archived']) || !empty($u['deleted_at']) || ($u['rental_status'] ?? '') === 'archived') ? 1 : 0;
        $isVerified = $status['is_verified'] ? 1 : 0;

        return [
            'found'               => true,
            'is_active'           => $isActive,
            'is_archived'         => $isArchived,
            'role'                => $status['role'],
            'verification_status' => (string)($u['verification_status'] ?? 'unverified'),
            'is_verified'         => $isVerified,
            'verified'            => ($status['mode'] === 'active_verified' || $status['mode'] === 'debt_collection'),
        ];
    }

    /**
     * Convenience helper for checking if a user ID currently has verified access.
     */
    public static function isTenantVerified(PDO $pdo, int $userId): bool
    {
        return self::getTenantStatus($pdo, $userId)['is_verified'];
    }

    /**
     * Enforce live verified-account check on JSON endpoints for tenant mutations.
     * Landlords bypass this check; unauthenticated or inactive users receive 401;
     * unverified/pending/rejected tenants receive 403.
     */
    public static function requireVerifiedTenantJson(PDO $pdo, ?array $currentUser = null): array
    {
        $sessionUser = $currentUser ?? Auth::user();
        $userId = (int)($sessionUser['id'] ?? 0);

        if ($userId <= 0 || !Auth::check()) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Unauthorized: Please sign in to perform this action.',
            ]);
            exit;
        }

        $status = self::getTenantStatus($pdo, $userId);
        if (!$status['found']) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Unauthorized: Account not found.',
            ]);
            exit;
        }

        if ($status['role'] === 'landlord') {
            return $status;
        }

        // Rule 2: Silent lockout for moved-out tenants with zero balance
        if ($status['mode'] === 'silent_lockout') {
            Auth::logout();
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Unauthorized.',
            ]);
            exit;
        }

        if ($status['mode'] === 'deactivated') {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Unauthorized: This account has been deactivated.',
            ]);
            exit;
        }

        // Rule 1: If unverified, block transactional write actions / abort PostgreSQL insertion
        if ($status['mode'] === 'unverified' || (!$status['is_verified'] && $status['mode'] !== 'debt_collection')) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'code' => 'ACCESS_DENIED',
                'error' => 'Access Denied',
                'verification_status' => $status['user']['verification_status'] ?? 'unverified',
                'is_verified' => 0,
                'message' => 'Access Denied: Please verify your identity to submit new records.',
            ]);
            exit;
        }

        return $status;
    }

    /**
     * Validate the currently authenticated owner/landlord's account password
     * using PHP password_verify() against MySQL. Never logs or stores plaintext.
     */
    public static function verifyOwnerPassword(PDO $pdo, string $enteredPassword): array
    {
        if (!Auth::isLandlord()) {
            return [
                'valid' => false,
                'status_code' => 403,
                'message' => 'Unauthorized: Only the property owner can delete tenant accounts.',
            ];
        }

        $ownerUser = Auth::user();
        $ownerId = (int)($ownerUser['id'] ?? 0);
        if ($ownerId <= 0) {
            return [
                'valid' => false,
                'status_code' => 401,
                'message' => 'Owner authentication session is missing or expired. Please sign in again.',
            ];
        }

        if ($enteredPassword === '') {
            return [
                'valid' => false,
                'status_code' => 422,
                'message' => 'Please enter your current owner password to confirm this deletion.',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT id, username, name, role, password_hash, is_active
            FROM users
            WHERE id = :id AND role = 'landlord' AND is_active = TRUE
            LIMIT 1
        ");
        $stmt->execute([':id' => $ownerId]);
        $ownerRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ownerRow || empty($ownerRow['password_hash'])) {
            return [
                'valid' => false,
                'status_code' => 403,
                'message' => 'Unable to verify active owner account credentials.',
            ];
        }

        if (!password_verify($enteredPassword, (string)$ownerRow['password_hash'])) {
            return [
                'valid' => false,
                'status_code' => 403,
                'message' => 'Incorrect owner password. Tenant account deletion was denied.',
            ];
        }

        return [
            'valid' => true,
            'status_code' => 200,
            'owner_id' => (int)$ownerRow['id'],
        ];
    }
}
