<?php
/**
 * RentConnect - Tenant Management & Automatic Archiving Service
 * Handles tenant move-out status, settlement auditing, and automatic archiving of fully-settled moved-out tenants.
 */

declare(strict_types=1);

class TenantService
{
    private static function normalizeDate(?string $dateStr): ?string
    {
        if ($dateStr === null) {
            return null;
        }
        $trimmed = trim($dateStr);
        if ($trimmed === '') {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $trimmed);
        if ($dt && $dt->format('Y-m-d') === $trimmed) {
            return $trimmed;
        }
        return null;
    }

    /**
     * Audit whether a tenant has fully settled all financial obligations.
     * Conditions for full settlement:
     * 1. No unpaid bills remain (unpaid, overdue, pending_setup, rejected).
     * 2. No outstanding rental balances remain (amount_due > amount_paid).
     * 3. No pending payment verification requests remain (status = 'pending').
     *
     * @param PDO $pdo
     * @param int $tenantId
     * @return array Audit result including is_settled, pending_count, unpaid_count, unpaid_balance, unsettled_items, reasons
     */
    public static function checkTenantSettlement(PDO $pdo, int $tenantId): array
    {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_records,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status IN ('unpaid', 'overdue', 'pending_setup', 'rejected') THEN 1 ELSE 0 END) AS unpaid_count,
                SUM(CASE WHEN (COALESCE(amount_due, 0) - COALESCE(amount_paid, 0)) > 0.01 THEN (amount_due - amount_paid) ELSE 0 END) AS total_unpaid_balance,
                SUM(COALESCE(amount_due, 0)) AS total_amount_due,
                SUM(COALESCE(amount_paid, 0)) AS total_amount_paid
            FROM payments 
            WHERE tenant_id = :tid
        ");
        $stmt->execute([':tid' => $tenantId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalRecords  = (int)($res['total_records'] ?? 0);
        $pendingCount  = (int)($res['pending_count'] ?? 0);
        $unpaidCount   = (int)($res['unpaid_count'] ?? 0);
        $unpaidBalance = round((float)($res['total_unpaid_balance'] ?? 0.0), 2);
        $totalDue      = round((float)($res['total_amount_due'] ?? 0.0), 2);
        $totalPaid     = round((float)($res['total_amount_paid'] ?? 0.0), 2);

        $itemStmt = $pdo->prepare("
            SELECT id, payment_type, billing_cycle, due_date, amount_due, amount_paid, status, receipt_filename
            FROM payments
            WHERE tenant_id = :tid
              AND (status != 'approved' OR (COALESCE(amount_due, 0) - COALESCE(amount_paid, 0)) > 0.01)
            ORDER BY billing_cycle DESC, id DESC
        ");
        $itemStmt->execute([':tid' => $tenantId]);
        $rawItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $statusLabels = [
            'pending'       => 'Pending Verification',
            'unpaid'        => 'Unpaid Bill',
            'overdue'       => 'Overdue Bill',
            'pending_setup' => 'Awaiting Setup',
            'rejected'      => 'Rejected Receipt',
            'approved'      => 'Balance Remaining',
        ];

        $unsettledItems = [];
        foreach ($rawItems as $item) {
            $due  = round((float)($item['amount_due'] ?? 0), 2);
            $paid = round((float)($item['amount_paid'] ?? 0), 2);
            $bal  = round(max(0.0, $due - $paid), 2);
            $st   = (string)($item['status'] ?? 'unpaid');
            $bc   = $item['billing_cycle'] ?? null;
            $dd   = $item['due_date'] ?? null;

            $unsettledItems[] = [
                'id'                      => (int)$item['id'],
                'payment_type'            => $item['payment_type'] ?: 'Rent',
                'billing_cycle'           => $bc,
                'billing_cycle_formatted' => $bc ? date('F Y', strtotime($bc)) : '—',
                'due_date'                => $dd,
                'due_date_formatted'      => $dd ? date('M d, Y', strtotime($dd)) : '—',
                'amount_due'              => $due,
                'amount_due_formatted'    => '₱' . number_format($due, 2),
                'amount_paid'             => $paid,
                'amount_paid_formatted'   => '₱' . number_format($paid, 2),
                'balance'                 => $bal,
                'balance_formatted'       => '₱' . number_format($bal, 2),
                'status'                  => $st,
                'status_label'            => $statusLabels[$st] ?? ucfirst(str_replace('_', ' ', $st)),
                'has_receipt'             => !empty($item['receipt_filename']),
            ];
        }

        $isSettled = ($pendingCount === 0 && $unpaidCount === 0 && $unpaidBalance <= 0.01);

        $reasons = [];
        if ($pendingCount > 0) {
            $reasons[] = "{$pendingCount} payment receipt" . ($pendingCount === 1 ? '' : 's') . " awaiting verification";
        }
        if ($unpaidCount > 0) {
            $reasons[] = "{$unpaidCount} unpaid or overdue bill" . ($unpaidCount === 1 ? '' : 's');
        }
        if ($unpaidBalance > 0.01) {
            $reasons[] = "outstanding balance of ₱" . number_format($unpaidBalance, 2);
        }

        return [
            'is_settled'               => $isSettled,
            'total_records'            => $totalRecords,
            'pending_count'            => $pendingCount,
            'unpaid_count'             => $unpaidCount,
            'unpaid_balance'           => $unpaidBalance,
            'unpaid_balance_formatted' => '₱' . number_format($unpaidBalance, 2),
            'total_amount_due'         => $totalDue,
            'total_amount_paid'        => $totalPaid,
            'unsettled_items'          => $unsettledItems,
            'reasons'                  => $reasons,
            'reason_text'              => !empty($reasons) ? implode(', ', $reasons) : 'All bills and charges are fully settled'
        ];
    }

    /**
     * Mark a tenant as Moved Out and automatically archive if all obligations are settled.
     * Runs inside a database transaction with row-level locking to guarantee consistency and
     * prevent double execution or bypassing unsettled payment obligations.
     *
     * @param PDO $pdo
     * @param int $tenantId
     * @param string|null $moveOutDate Optional official move-out date (YYYY-MM-DD)
     * @param bool $confirmSettlement Explicit owner/admin confirmation that bills were checked
     * @return array Action result
     */
    public static function markMovedOut(PDO $pdo, int $tenantId, ?string $moveOutDate = null, bool $confirmSettlement = true): array
    {
        if (!$confirmSettlement) {
            return [
                'status'  => 'error',
                'code'    => 'confirmation_required',
                'message' => 'Please confirm whether all outstanding bills and pending payments have been settled.'
            ];
        }

        $explicitDate = self::normalizeDate($moveOutDate);
        if ($moveOutDate !== null && trim($moveOutDate) !== '' && $explicitDate === null) {
            return [
                'status'  => 'error',
                'code'    => 'invalid_date',
                'message' => 'Invalid move-out date format. Expected YYYY-MM-DD.'
            ];
        }

        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            // PostgreSQL: FOR UPDATE cannot be applied to the nullable side of a LEFT JOIN.
            // Lock the users row first, then fetch unit_number separately.
            $stmt = $pdo->prepare("
                SELECT u.*
                FROM users u
                WHERE u.id = :id AND u.role = 'tenant'
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':id' => $tenantId]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

            // Fetch unit_number separately if needed
            if ($tenant && !empty($tenant['unit_id'])) {
                $uStmt = $pdo->prepare("SELECT unit_number FROM units WHERE id = :uid LIMIT 1");
                $uStmt->execute([':uid' => $tenant['unit_id']]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                $tenant['unit_number'] = $uRow['unit_number'] ?? null;
            } else if ($tenant) {
                $tenant['unit_number'] = null;
            }

            if (!$tenant) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
                return [
                    'status'  => 'error',
                    'code'    => 'not_found',
                    'message' => 'Tenant account not found.'
                ];
            }

            $isArchived = filter_var($tenant['is_archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $isActive = filter_var($tenant['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isArchived || !$isActive || !empty($tenant['deleted_at'])) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
                return [
                    'status'  => 'error',
                    'code'    => 'already_archived',
                    'message' => 'Tenant account has already been archived.'
                ];
            }

            $resolvedMoveOutDate = $explicitDate ?? (!empty($tenant['move_out_date']) ? (string)$tenant['move_out_date'] : date('Y-m-d'));

            // Lock tenant payment rows inside the transaction before auditing settlement
            $lockPaymentsStmt = $pdo->prepare("SELECT id FROM payments WHERE tenant_id = :tid FOR UPDATE");
            $lockPaymentsStmt->execute([':tid' => $tenantId]);
            $lockPaymentsStmt->fetchAll(PDO::FETCH_COLUMN);

            // Backend validation: audit all billing & verification records
            $settlement = self::checkTenantSettlement($pdo, $tenantId);

            $unitId = (int)($tenant['unit_id'] ?? 0);
            $unitNumber = $tenant['unit_number'] ?? ($tenant['archived_unit_number'] ?? 'Unit');
            $monthlyRent = (float)($tenant['monthly_rent'] ?? ($tenant['archived_monthly_rent'] ?? 0));

            // Capture assigned additional rental spaces for permanent record
            $spacesStmt = $pdo->prepare("
                SELECT space_number, space_name, monthly_fee 
                FROM additional_spaces 
                WHERE assigned_tenant_id = :tid 
                ORDER BY space_number ASC
            ");
            $spacesStmt->execute([':tid' => $tenantId]);
            $spacesRows = $spacesStmt->fetchAll(PDO::FETCH_ASSOC);
            $spacesParts = [];
            foreach ($spacesRows as $sr) {
                $spacesParts[] = "{$sr['space_name']} (₱" . number_format((float)$sr['monthly_fee'], 2) . "/mo)";
            }
            $archivedSpacesText = !empty($spacesParts) ? implode(', ', $spacesParts) : ($tenant['archived_spaces_text'] ?? null);

            // 1. Decoupled Unit Vacancy Logic: Immediately make the physical unit "Available" for new tenants
            if ($unitId > 0) {
                $pdo->prepare("
                    UPDATE units 
                    SET status = 'available', 
                        occupant_id = NULL 
                    WHERE id = :uid
                ")->execute([':uid' => $unitId]);
            }

            // Also release any rented additional spaces so they become available
            $pdo->prepare("
                UPDATE additional_spaces 
                SET status = 'available', 
                    assigned_tenant_id = NULL, 
                    assigned_unit_id = NULL, 
                    rented_at = NULL 
                WHERE assigned_tenant_id = :id
            ")->execute([':id' => $tenantId]);

            // Release apartment_settings active_tenant_id if set
            $pdo->prepare("UPDATE apartment_settings SET active_tenant_id = NULL WHERE active_tenant_id = :id")->execute([':id' => $tenantId]);

            // CASE 1: Tenant has an UNPAID balance (Outstanding bills or pending payment verification)
            // Decoupled Archival: Do NOT archive tenant. Their account must remain active in the system until balance is fully settled.
            if (!$settlement['is_settled']) {
                $pdo->prepare("
                    UPDATE users 
                    SET rental_status = 'moved_out', 
                        move_out_date = :move_out_date,
                        archived_unit_number = COALESCE(archived_unit_number, :a_unit_num),
                        archived_monthly_rent = COALESCE(archived_monthly_rent, :a_rent),
                        archived_spaces_text = COALESCE(archived_spaces_text, :a_spaces),
                        unit_id = NULL,
                        is_active = TRUE,
                        is_archived = FALSE,
                        updated_at = NOW() 
                    WHERE id = :id
                ")->execute([
                    ':move_out_date' => $resolvedMoveOutDate,
                    ':a_unit_num'    => $unitNumber,
                    ':a_rent'        => $monthlyRent,
                    ':a_spaces'      => $archivedSpacesText,
                    ':id'            => $tenantId
                ]);

                if ($startedTransaction) {
                    $pdo->commit();
                }

                $balanceFormatted = number_format((float)($settlement['outstanding_balance'] ?? 0), 2);
                return [
                    'status'                   => 'success',
                    'archived'                 => false,
                    'is_unpaid'                => true,
                    'tenant_id'                => $tenantId,
                    'tenant_name'              => $tenant['name'],
                    'unit_id'                  => $unitId,
                    'unit_number'              => $unitNumber,
                    'unit_available'           => true,
                    'rental_status'            => 'moved_out',
                    'move_out_date'            => $resolvedMoveOutDate,
                    'move_out_date_formatted'  => date('M d, Y', strtotime($resolvedMoveOutDate)),
                    'settlement'               => $settlement,
                    'message'                  => "Tenant '{$tenant['name']}' has been marked as Moved Out. Unit {$unitNumber} is now Available for new occupants. Because an outstanding balance remains (₱{$balanceFormatted}), the account remains active with restricted access until all dues are paid."
                ];
            }

            // CASE 2: Fully settled tenant — transfer to Archived Tenants immediately
            $pdo->prepare("
                UPDATE users 
                SET rental_status = 'moved_out', 
                    move_out_date = :move_out_date,
                    updated_at = NOW() 
                WHERE id = :id
            ")->execute([
                ':move_out_date' => $resolvedMoveOutDate,
                ':id'            => $tenantId
            ]);
            $tenant['move_out_date'] = $resolvedMoveOutDate;
            $tenant['rental_status'] = 'moved_out';

            $archivedOk = self::archiveSettledTenant($pdo, $tenantId, $tenant, $resolvedMoveOutDate);
            if (!$archivedOk) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'status'  => 'error',
                    'code'    => 'archive_failed',
                    'message' => 'Unable to complete automatic archiving. Please refresh and try again.'
                ];
            }

            if ($startedTransaction) {
                $pdo->commit();
            }

            return [
                'status'                   => 'success',
                'archived'                 => true,
                'is_unpaid'                => false,
                'tenant_id'                => $tenantId,
                'tenant_name'              => $tenant['name'],
                'unit_id'                  => $unitId,
                'unit_number'              => $unitNumber,
                'unit_available'           => true,
                'rental_status'            => 'moved_out',
                'move_out_date'            => $resolvedMoveOutDate,
                'move_out_date_formatted'  => date('M d, Y', strtotime($resolvedMoveOutDate)),
                'deleted_at_formatted'     => date('M d, Y h:i A'),
                'settlement'               => $settlement,
                'message'                  => "Tenant '{$tenant['name']}' has officially moved out and all bills are fully settled. Unit {$unitNumber} is now Available, and the account has been transferred to Archived Tenants."
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[TenantService] markMovedOut failed: ' . $e->getMessage());
            return [
                'status'  => 'error',
                'code'    => 'transaction_error',
                'message' => 'Failed to process move-out and archiving. Please try again.'
            ];
        }
    }

    /**
     * Archive a fully settled tenant.
     * Safely preserves complete personal info, billing ledger, receipts, and maintenance tickets in MySQL.
     *
     * @param PDO $pdo
     * @param int $tenantId
     * @param array|null $target
     * @param string|null $moveOutDate
     * @return bool
     */
    public static function archiveSettledTenant(PDO $pdo, int $tenantId, ?array $target = null, ?string $moveOutDate = null): bool
    {
        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            if (!$target) {
                $check = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'tenant' LIMIT 1 FOR UPDATE");
                $check->execute([':id' => $tenantId]);
                $target = $check->fetch(PDO::FETCH_ASSOC);
                if (!$target) {
                    if ($startedTransaction) $pdo->rollBack();
                    return false;
                }
            }

            $isArchived = filter_var($target['is_archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $isActive = filter_var($target['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
            if ($isArchived || !$isActive || !empty($target['deleted_at'])) {
                if ($startedTransaction) $pdo->rollBack();
                return false;
            }

            $unitId = (int)($target['unit_id'] ?? 0);
            $explicitDate = self::normalizeDate($moveOutDate);

            // 1. Capture previous rental details for permanent historical audit
            $archivedUnitNum = $target['archived_unit_number'] ?? null;
            $archivedRent = (float)($target['archived_monthly_rent'] ?? 3000.00);
            if ($unitId > 0) {
                $uStmt = $pdo->prepare("SELECT unit_number, monthly_rent FROM units WHERE id = :uid LIMIT 1");
                $uStmt->execute([':uid' => $unitId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    $archivedUnitNum = $uRow['unit_number'];
                    $archivedRent = (float)$uRow['monthly_rent'];
                }
            }

            // 2. Capture assigned additional rental spaces for permanent historical audit
            $spacesStmt = $pdo->prepare("
                SELECT space_number, space_name, monthly_fee 
                FROM additional_spaces 
                WHERE assigned_tenant_id = :tid 
                ORDER BY space_number ASC
            ");
            $spacesStmt->execute([':tid' => $tenantId]);
            $spacesRows = $spacesStmt->fetchAll(PDO::FETCH_ASSOC);
            $spacesParts = [];
            foreach ($spacesRows as $sr) {
                $spacesParts[] = "{$sr['space_name']} (₱" . number_format((float)$sr['monthly_fee'], 2) . "/mo)";
            }
            $archivedSpacesText = !empty($spacesParts) ? implode(', ', $spacesParts) : ($target['archived_spaces_text'] ?? null);

            // 3. Release any assigned additional rental spaces
            $pdo->prepare("
                UPDATE additional_spaces 
                SET status = 'available', 
                    assigned_tenant_id = NULL, 
                    assigned_unit_id = NULL, 
                    rented_at = NULL 
                WHERE assigned_tenant_id = :id
            ")->execute([':id' => $tenantId]);

            // 4. Archive Tenant Account (Soft-deletion with permanent record preservation, guarding against double execution)
            $upd = $pdo->prepare("
                UPDATE users 
                SET is_active = FALSE,
                    is_archived = TRUE,
                    rental_status = 'moved_out',
                    deleted_at = COALESCE(deleted_at, NOW()),
                    move_out_date = COALESCE(:move_out_date, move_out_date, CURRENT_DATE),
                    archived_unit_number = :a_unit_num,
                    archived_monthly_rent = :a_rent,
                    archived_spaces_text = :a_spaces,
                    unit_id = NULL,
                    updated_at = NOW()
                WHERE id = :id
                  AND is_active = TRUE
                  AND (is_archived = FALSE OR is_archived IS NULL)
            ");
            $upd->execute([
                ':move_out_date' => $explicitDate,
                ':a_unit_num'    => $archivedUnitNum,
                ':a_rent'        => $archivedRent,
                ':a_spaces'      => $archivedSpacesText,
                ':id'            => $tenantId
            ]);

            if ($upd->rowCount() === 0) {
                if ($startedTransaction) $pdo->rollBack();
                return false;
            }

            // 5. Release physical unit in units table so it is available
            if ($unitId > 0) {
                $pdo->prepare("UPDATE units SET status = 'available', occupant_id = NULL WHERE id = :uid")->execute([':uid' => $unitId]);
            }

            // 6. Release apartment_settings active_tenant_id if set
            $pdo->prepare("UPDATE apartment_settings SET active_tenant_id = NULL WHERE active_tenant_id = :id")->execute([':id' => $tenantId]);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return true;
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[TenantService] Failed to archive tenant: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check all active tenants marked 'moved_out' and automatically archive any whose bills are fully settled.
     * Called upon payment verification, ledger updates, or dashboard sync.
     *
     * @param PDO $pdo
     * @return int Number of tenants automatically archived
     */
    public static function syncMovedOutSettlements(PDO $pdo): int
    {
        $stmt = $pdo->query("
            SELECT * FROM users 
            WHERE role = 'tenant' 
              AND is_active = TRUE 
              AND (is_archived = FALSE OR is_archived IS NULL)
              AND rental_status = 'moved_out'
        ");
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $archivedCount = 0;

        foreach ($candidates as $cand) {
            $settlement = self::checkTenantSettlement($pdo, (int)$cand['id']);
            if ($settlement['is_settled']) {
                if (self::archiveSettledTenant($pdo, (int)$cand['id'], $cand)) {
                    $archivedCount++;
                }
            }
        }

        return $archivedCount;
    }

    /**
     * Current DateTimeImmutable in Asia/Manila timezone.
     *
     * @return DateTimeImmutable
     */
    public static function getManilaNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    }

    /**
     * Resolve a tenant's monthly billing day (1..31) from move_in_date, existing Rent due dates, or current Manila date.
     *
     * @param string|null $moveInDate
     * @param array|null $payments
     * @param DateTimeImmutable|null $referenceNow
     * @return int
     */
    public static function resolveBillingDay(?string $moveInDate, ?array $payments = null, ?DateTimeImmutable $referenceNow = null): int
    {
        $tz = new DateTimeZone('Asia/Manila');
        $trimmedMoveIn = trim((string)($moveInDate ?? ''));
        if ($trimmedMoveIn !== '') {
            $ts = strtotime($trimmedMoveIn);
            if ($ts !== false) {
                return (int)date('j', $ts);
            }
        }

        if (is_array($payments)) {
            foreach ($payments as $p) {
                $pType = strtolower((string)($p['payment_type'] ?? 'rent'));
                if ($pType === 'rent' && !empty($p['due_date'])) {
                    $ts = strtotime((string)$p['due_date']);
                    if ($ts !== false) {
                        return (int)date('j', $ts);
                    }
                }
            }
        }

        $now = $referenceNow ?: new DateTimeImmutable('now', $tz);
        return (int)$now->format('j');
    }

    /**
     * Compute a valid calendar due date (YYYY-MM-DD) for a billing cycle month and billing day,
     * clamping the day to the month's length (e.g. day 31 in Feb -> Feb 28/29).
     *
     * @param string $billingCycle
     * @param int $billingDay
     * @return string
     */
    public static function computeDueDateForCycle(string $billingCycle, int $billingDay): string
    {
        $tz = new DateTimeZone('Asia/Manila');
        $ts = strtotime($billingCycle);
        $base = $ts !== false
            ? (new DateTimeImmutable('@' . $ts))->setTimezone($tz)
            : self::getManilaNow();

        $firstOfMonth = $base->modify('first day of this month');
        $daysInMonth = (int)$firstOfMonth->format('t');
        $clampedDay = max(1, min($billingDay, $daysInMonth));
        return $firstOfMonth->setDate(
            (int)$firstOfMonth->format('Y'),
            (int)$firstOfMonth->format('n'),
            $clampedDay
        )->format('Y-m-d');
    }

    /**
     * Compute the tenant's real-time billing cycle and Next Due Date in Asia/Manila.
     * Uses unpaid obligations first, and once paid & verified automatically advances
     * to the following month's billing cycle using the tenant's move-in/billing day.
     *
     * @param array $tenantUser
     * @param array $allPayments
     * @param DateTimeImmutable|null $referenceNow
     * @return array{
     *   next_due_date: string,
     *   next_billing_cycle: string,
     *   next_due_display: string,
     *   short_due_display: string,
     *   billing_day: int,
     *   is_paid_and_advanced: bool
     * }
     */
    public static function resolveTenantBillingState(
        array $tenantUser,
        array $allPayments,
        ?DateTimeImmutable $referenceNow = null
    ): array {
        $tz = new DateTimeZone('Asia/Manila');
        $now = $referenceNow ?: self::getManilaNow();
        $todayStr = $now->format('Y-m-d');
        $billingDay = self::resolveBillingDay($tenantUser['move_in_date'] ?? null, $allPayments, $now);

        $earliestDueDate = null;
        $earliestCycle = null;
        $latestSettledRentCycle = null;
        $approvedRentDueDates = [];

        foreach ($allPayments as $p) {
            $pType = strtolower(trim((string)($p['payment_type'] ?? 'rent')));
            $pStatus = strtolower(trim((string)($p['status'] ?? '')));
            $amtDue = (float)($p['amount_due'] ?? 0.0);
            $amtPaid = (float)($p['amount_paid'] ?? 0.0);
            $remaining = max(0.0, $amtDue - $amtPaid);

            if ($pType === 'rent' && $pStatus === 'approved' && $remaining <= 0.01) {
                if (!empty($p['due_date'])) {
                    $approvedRentDueDates[] = date('Y-m-d', strtotime((string)$p['due_date']));
                }
                if (!empty($p['billing_cycle'])) {
                    $normCycle = date('Y-m-01', strtotime((string)$p['billing_cycle']));
                    if ($latestSettledRentCycle === null || $normCycle > $latestSettledRentCycle) {
                        $latestSettledRentCycle = $normCycle;
                    }
                } elseif (!empty($p['due_date'])) {
                    $normCycle = date('Y-m-01', strtotime((string)$p['due_date']));
                    if ($latestSettledRentCycle === null || $normCycle > $latestSettledRentCycle) {
                        $latestSettledRentCycle = $normCycle;
                    }
                }
            }

            if (in_array($pStatus, ['unpaid', 'overdue', 'rejected', 'pending_setup', 'pending'], true) || $remaining > 0.01) {
                if (!empty($p['due_date'])) {
                    $dNorm = date('Y-m-d', strtotime((string)$p['due_date']));
                    if ($earliestDueDate === null || $dNorm < $earliestDueDate) {
                        $earliestDueDate = $dNorm;
                        $earliestCycle = !empty($p['billing_cycle'])
                            ? date('Y-m-01', strtotime((string)$p['billing_cycle']))
                            : date('Y-m-01', strtotime($dNorm));
                    }
                }
            }
        }

        $isPaidAndAdvanced = false;
        $nextDueDate = null;
        $nextBillingCycle = null;

        if ($earliestDueDate !== null) {
            $nextDueDate = $earliestDueDate;
            $nextBillingCycle = $earliestCycle ?: date('Y-m-01', strtotime($earliestDueDate));
        } elseif (!empty($tenantUser['next_due_date']) && strtotime((string)$tenantUser['next_due_date']) !== false) {
            $storedNext = date('Y-m-d', strtotime((string)$tenantUser['next_due_date']));
            if (!in_array($storedNext, $approvedRentDueDates, true) && $storedNext >= $todayStr) {
                $nextDueDate = $storedNext;
                $nextBillingCycle = date('Y-m-01', strtotime($storedNext));
                $isPaidAndAdvanced = true;
            }
        }

        if ($nextDueDate === null) {
            if ($latestSettledRentCycle !== null) {
                // Paid and verified: automatically advance to the following month's billing cycle
                $isPaidAndAdvanced = true;
                $cursor = DateTimeImmutable::createFromFormat('Y-m-d', $latestSettledRentCycle, $tz) ?: $now->modify('first day of this month');
                $cursor = $cursor->modify('first day of next month');
                $candidateDue = self::computeDueDateForCycle($cursor->format('Y-m-d'), $billingDay);

                // Ensure upcoming due date isn't stuck in an already-elapsed month
                $guard = 0;
                while ($candidateDue < $todayStr && $guard < 36) {
                    $cursor = $cursor->modify('first day of next month');
                    $candidateDue = self::computeDueDateForCycle($cursor->format('Y-m-d'), $billingDay);
                    $guard++;
                }

                $nextBillingCycle = $cursor->format('Y-m-01');
                $nextDueDate = $candidateDue;
            } else {
                // Brand-new tenant with no ledger bills yet: derive dynamically from move_in_date or today
                $moveInStr = trim((string)($tenantUser['move_in_date'] ?? ''));
                $anchor = ($moveInStr !== '' && strtotime($moveInStr) !== false)
                    ? (new DateTimeImmutable('@' . strtotime($moveInStr)))->setTimezone($tz)
                    : $now;
                $cursor = $anchor->modify('first day of this month');
                $candidateDue = self::computeDueDateForCycle($cursor->format('Y-m-d'), $billingDay);
                if ($candidateDue < $todayStr && $moveInStr === '') {
                    $cursor = $cursor->modify('first day of next month');
                    $candidateDue = self::computeDueDateForCycle($cursor->format('Y-m-d'), $billingDay);
                }
                $nextBillingCycle = $cursor->format('Y-m-01');
                $nextDueDate = $candidateDue;
            }
        }

        $dueTs = strtotime($nextDueDate);
        return [
            'next_due_date' => $nextDueDate,
            'next_billing_cycle' => $nextBillingCycle,
            'next_due_display' => date('M d, Y', $dueTs),
            'short_due_display' => date('F j', $dueTs),
            'billing_day' => $billingDay,
            'is_paid_and_advanced' => $isPaidAndAdvanced,
        ];
    }
}
