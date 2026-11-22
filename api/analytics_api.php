<?php
/**
 * TaraRent - Business Intelligence Analytics API
 * Executes Descriptive & Diagnostic Analytics for Apartment Property
 * Tracks verified receipts & manual payment approvals in ₱
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getConnection();

    // 1. Units & Occupancy Metrics
    $totalUnits = (int)$pdo->query("SELECT COUNT(*) FROM units")->fetchColumn();

    // A unit is occupied if it has an active non-archived tenant or status is 'occupied'
    $occupiedUnits = (int)$pdo->query("
        SELECT COUNT(DISTINCT u.id) 
        FROM units u 
        JOIN users usr ON usr.unit_id = u.id 
        WHERE usr.role = 'tenant' 
          AND usr.is_active = TRUE 
          AND (usr.is_archived = FALSE OR usr.is_archived IS NULL)
          AND usr.deleted_at IS NULL
    ")->fetchColumn();
    $availableUnits = max(0, $totalUnits - $occupiedUnits);
    $occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0.0;

    // 2. Tenants Counts (Active & Archived)
    $activeTenants = (int)$pdo->query("
        SELECT COUNT(*) FROM users 
        WHERE role = 'tenant' 
          AND is_active = TRUE 
          AND (is_archived = FALSE OR is_archived IS NULL)
          AND deleted_at IS NULL
    ")->fetchColumn();

    $archivedTenants = (int)$pdo->query("
        SELECT COUNT(*) FROM users 
        WHERE role = 'tenant' 
          AND (is_archived = TRUE OR deleted_at IS NOT NULL)
    ")->fetchColumn();

    // 3. Financial & Revenue Metrics (Payments)
    $finStmt = $pdo->query("
        SELECT 
            COALESCE(SUM(CASE WHEN status != 'pending_setup' THEN amount_due ELSE 0 END), 0) AS total_receivables_billed,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN amount_paid ELSE 0 END), 0) AS total_revenue_collected,
            COALESCE(SUM(CASE WHEN status NOT IN ('approved', 'pending_setup') AND amount_due IS NOT NULL THEN (amount_due - COALESCE(amount_paid, 0)) ELSE 0 END), 0) AS total_outstanding_balance,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) AS paid_payments_count,
            COUNT(CASE WHEN status NOT IN ('approved', 'pending_setup') THEN 1 END) AS unpaid_payments_count,
            COUNT(CASE WHEN status = 'pending' AND receipt_filename IS NOT NULL AND receipt_filename != '' THEN 1 END) AS pending_receipts_count
        FROM payments
    ");
    $finKpis = $finStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalBilled = (float)($finKpis['total_receivables_billed'] ?? 0);
    $totalCollected = (float)($finKpis['total_revenue_collected'] ?? 0);
    $totalOutstanding = (float)($finKpis['total_outstanding_balance'] ?? 0);
    $overallEfficiency = $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100, 2) : 0.0;
    $paidPaymentsCount = (int)($finKpis['paid_payments_count'] ?? 0);
    $unpaidPaymentsCount = (int)($finKpis['unpaid_payments_count'] ?? 0);
    $pendingReceiptsCount = (int)($finKpis['pending_receipts_count'] ?? 0);

    // 4. Maintenance Expense & Ticket Metrics
    $maintKpiStmt = $pdo->query("
        SELECT 
            COALESCE(SUM(actual_cost), 0) AS total_maintenance_expenditure,
            COUNT(id) AS total_tickets_logged,
            SUM(CASE WHEN LOWER(status) IN ('open', 'pending', 'in_progress', 'on-going', 'ongoing') THEN 1 ELSE 0 END) AS active_tickets,
            SUM(CASE WHEN LOWER(status) IN ('resolved', 'closed', 'done') THEN 1 ELSE 0 END) AS resolved_tickets
        FROM maintenance_tickets
    ");
    $maintKpis = $maintKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalMaintAll = (float)($maintKpis['total_maintenance_expenditure'] ?? 0);
    $totalTicketsLogged = (int)($maintKpis['total_tickets_logged'] ?? 0);
    $activeTickets = (int)($maintKpis['active_tickets'] ?? 0);
    $resolvedTickets = (int)($maintKpis['resolved_tickets'] ?? 0);

    // 5. Tenant Reports Metrics
    $reportsKpiStmt = $pdo->query("
        SELECT 
            COUNT(id) AS total_reports,
            SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending_reports,
            SUM(CASE WHEN LOWER(status) != 'pending' THEN 1 ELSE 0 END) AS reviewed_reports
        FROM tenant_reports
    ");
    $reportsKpis = $reportsKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalReports = (int)($reportsKpis['total_reports'] ?? 0);
    $pendingReports = (int)($reportsKpis['pending_reports'] ?? 0);
    $reviewedReports = (int)($reportsKpis['reviewed_reports'] ?? 0);

    // 6. Monthly Collection Efficiency Timeline Chart
    $monthlyStmt = $pdo->query("
        SELECT 
            TO_CHAR(billing_cycle::date, 'YYYY-MM') AS month_key,
            ROUND(SUM(amount_due)::numeric, 2) AS billed_rent,
            ROUND(SUM(CASE WHEN status = 'approved' THEN amount_paid ELSE 0 END)::numeric, 2) AS collected_rent,
            ROUND(SUM(CASE WHEN status != 'approved' THEN (amount_due - COALESCE(amount_paid, 0)) ELSE 0 END)::numeric, 2) AS outstanding_balance,
            CASE 
                WHEN SUM(amount_due) > 0 
                THEN ROUND(((SUM(CASE WHEN status = 'approved' THEN amount_paid ELSE 0 END) / SUM(amount_due)) * 100)::numeric, 2) 
                ELSE 0.00 
            END AS collection_efficiency_percentage
        FROM payments
        WHERE billing_cycle IS NOT NULL AND status != 'pending_setup'
        GROUP BY TO_CHAR(billing_cycle::date, 'YYYY-MM')
        ORDER BY month_key ASC
    ");
    $monthlyCollections = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($monthlyCollections as &$row) {
        $dateObj = DateTime::createFromFormat('!Y-m', $row['month_key']);
        $row['month_label'] = $dateObj ? $dateObj->format('M Y') : $row['month_key'];
        $row['billed_rent'] = (float)$row['billed_rent'];
        $row['collected_rent'] = (float)$row['collected_rent'];
        $row['outstanding_balance'] = (float)$row['outstanding_balance'];
        $row['collection_efficiency_percentage'] = (float)$row['collection_efficiency_percentage'];
    }
    unset($row);

    // 7. Maintenance Expenses per Unit
    $unitMaintStmt = $pdo->query("
        SELECT 
            u.id AS unit_id,
            u.unit_number,
            u.monthly_rent,
            COUNT(mt.id) AS ticket_count,
            COALESCE(ROUND(SUM(mt.actual_cost)::numeric, 2), 0.00) AS total_cost,
            CASE 
                WHEN COUNT(mt.id) > 0 THEN ROUND((COALESCE(SUM(mt.actual_cost), 0) / COUNT(mt.id))::numeric, 2) 
                ELSE 0.00 
            END AS avg_cost_per_ticket
        FROM units u
        LEFT JOIN maintenance_tickets mt ON mt.unit_id = u.id
        GROUP BY u.id, u.unit_number, u.monthly_rent
        ORDER BY u.unit_number ASC
    ");
    $unitMaintenance = $unitMaintStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unitMaintenance as &$um) {
        $um['total_cost'] = (float)$um['total_cost'];
        $um['ticket_count'] = (int)$um['ticket_count'];
        $um['avg_cost_per_ticket'] = (float)$um['avg_cost_per_ticket'];
    }
    unset($um);

    // 8. Repair Category Categorization & Share
    $catStmt = $pdo->query("
        SELECT 
            COALESCE(category, 'General') AS category,
            COUNT(id) AS ticket_count,
            COALESCE(ROUND(SUM(actual_cost)::numeric, 2), 0.00) AS total_cost,
            CASE 
                WHEN COUNT(id) > 0 THEN ROUND((COALESCE(SUM(actual_cost), 0) / COUNT(id))::numeric, 2) 
                ELSE 0.00 
            END AS avg_cost
        FROM maintenance_tickets
        GROUP BY category
        ORDER BY total_cost DESC, ticket_count DESC
    ");
    $categoriesRaw = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    $categoryAnalytics = [];
    foreach ($categoriesRaw as $c) {
        $cost = (float)$c['total_cost'];
        $share = $totalMaintAll > 0 ? round(($cost / $totalMaintAll) * 100, 1) : 0.0;
        $categoryAnalytics[] = [
            'category' => $c['category'],
            'ticket_count' => (int)$c['ticket_count'],
            'total_cost' => $cost,
            'avg_cost' => (float)$c['avg_cost'],
            'cost_share_percentage' => $share
        ];
    }

    // 9. Diagnostic Operational Alerts
    $diagnosticAlerts = [];
    if (!empty($categoryAnalytics) && $totalMaintAll > 0) {
        $topCat = $categoryAnalytics[0];
        if ($topCat['total_cost'] > 0) {
            $topCatFormattedCost = number_format((float)$topCat['total_cost'], 2);
            $diagnosticAlerts[] = [
                'type' => 'category_driver',
                'severity' => 'warning',
                'title' => "Primary Expense Category: {$topCat['category']}",
                'message' => "{$topCat['category']} represents {$topCat['cost_share_percentage']}% of all repair expenditures across units (₱{$topCatFormattedCost})."
            ];
        }
    }

    $overdueStmt = $pdo->query("
        SELECT p.billing_cycle, p.due_date, p.amount_due, p.amount_paid, p.status, 
               (p.amount_due - COALESCE(p.amount_paid, 0)) AS balance,
               u.unit_number, usr.name AS tenant_name
        FROM payments p
        JOIN units u ON p.unit_id = u.id
        JOIN users usr ON p.tenant_id = usr.id
        WHERE (usr.is_archived = FALSE OR usr.is_archived IS NULL)
          AND (
            p.status IN ('rejected', 'overdue') 
            OR (p.status = 'pending' AND p.due_date < CURRENT_DATE AND COALESCE(p.amount_paid, 0) < p.amount_due)
            OR (p.status = 'unpaid' AND p.due_date < CURRENT_DATE)
          )
        ORDER BY p.billing_cycle DESC
    ");
    $overdues = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($overdues as $ov) {
        $dateObj = DateTime::createFromFormat('!Y-m-d', (string)$ov['billing_cycle']);
        $cycle = $dateObj ? $dateObj->format('M Y') : $ov['billing_cycle'];
        $formattedBalance = number_format((float)$ov['balance'], 2);
        $statusLabel = strtoupper((string)$ov['status']);
        $diagnosticAlerts[] = [
            'type' => 'arrears',
            'severity' => 'error',
            'title' => "{$ov['unit_number']} Overdue Balance: {$cycle} ({$statusLabel})",
            'message' => "{$ov['tenant_name']} has an outstanding balance of ₱{$formattedBalance} for {$cycle} (Due: {$ov['due_date']})."
        ];
    }

    $response = [
        'status' => 'success',
        'generated_at' => date('c'),
        'kpis' => [
            'units' => [
                'total_units' => $totalUnits,
                'occupied_units' => $occupiedUnits,
                'available_units' => $availableUnits,
                'occupancy_rate_pct' => $occupancyRate,
                'total_active_tenants' => $activeTenants,
                'total_archived_tenants' => $archivedTenants,
                'total_tenants' => $activeTenants + $archivedTenants
            ],
            'financials' => [
                'total_receivables_billed' => $totalBilled,
                'total_revenue_collected' => $totalCollected,
                'total_outstanding_balance' => $totalOutstanding,
                'overall_collection_efficiency_pct' => $overallEfficiency,
                'paid_payments_count' => $paidPaymentsCount,
                'unpaid_payments_count' => $unpaidPaymentsCount
            ],
            'maintenance' => [
                'total_expenditure' => $totalMaintAll,
                'total_tickets_logged' => $totalTicketsLogged,
                'active_tickets' => $activeTickets,
                'resolved_tickets' => $resolvedTickets
            ],
            'reports' => [
                'total_reports' => $totalReports,
                'pending_reports' => $pendingReports,
                'reviewed_reports' => $reviewedReports
            ],
            'manual_verification' => [
                'pending_receipts_count' => $pendingReceiptsCount
            ]
        ],
        'charts' => [
            'monthly_collection_efficiency' => $monthlyCollections,
            'maintenance_by_unit' => $unitMaintenance,
            'maintenance_by_category' => $categoryAnalytics
        ],
        'diagnostic_alerts' => $diagnosticAlerts
    ];

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Analytics query error: ' . $e->getMessage()
    ]);
}
