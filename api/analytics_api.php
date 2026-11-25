<?php
/**
 * TaraRent - Business Intelligence Analytics API
 * Implements the 4 Core Pillars of Business Analytics:
 *   1. Descriptive (NOI, OER, DSO / Collection Velocity, Revenue & Occupancy KPIs)
 *   2. Diagnostic (Pareto Maintenance Cost Breakdown & Unit Health Index)
 *   3. Predictive (Next-Month Cash Flow Forecast & Tenant Delinquency Risk Scoring)
 *   4. Prescriptive (Rule-Based Smart Business Insights Engine)
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

    // =========================================================================
    // 1. UNITS & OCCUPANCY METRICS
    // =========================================================================
    $totalUnits = (int)$pdo->query("SELECT COUNT(*) FROM units")->fetchColumn();

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

    // =========================================================================
    // 2. TENANTS COUNTS (ACTIVE & ARCHIVED)
    // =========================================================================
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

    // =========================================================================
    // 3. FINANCIAL & REVENUE METRICS (PAYMENTS)
    // =========================================================================
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

    // =========================================================================
    // 4. MAINTENANCE REQUEST VOLUME METRICS (COUNTS ONLY — NO EXPENSE DEDUCTIONS)
    // =========================================================================
    $maintKpiStmt = $pdo->query("
        SELECT 
            COUNT(id) AS total_tickets_logged,
            SUM(CASE WHEN LOWER(status) IN ('open', 'pending', 'in_progress', 'on-going', 'ongoing') THEN 1 ELSE 0 END) AS active_tickets,
            SUM(CASE WHEN LOWER(status) IN ('resolved', 'closed', 'done') THEN 1 ELSE 0 END) AS resolved_tickets
        FROM maintenance_tickets
    ");
    $maintKpis = $maintKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalTicketsLogged = (int)($maintKpis['total_tickets_logged'] ?? 0);
    $activeTickets = (int)($maintKpis['active_tickets'] ?? 0);
    $resolvedTickets = (int)($maintKpis['resolved_tickets'] ?? 0);

    // =========================================================================
    // 5. TENANT REPORTS METRICS
    // =========================================================================
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

    // =========================================================================
    // PILLAR 1: DESCRIPTIVE BA METRICS (PURE RENTAL COLLECTIONS — NO EXPENSE DEDUCTIONS)
    // =========================================================================
    // DSO / Payment Speed: Average days from bill generation (created_at::date) to collection (payment_date / verified_at)
    $dsoStmt = $pdo->query("
        SELECT 
            COUNT(*) AS settled_count,
            COALESCE(ROUND(AVG(GREATEST(0, (COALESCE(payment_date, verified_at::date) - created_at::date)))::numeric, 1), 0) AS avg_days_to_collect,
            COUNT(CASE WHEN due_date IS NOT NULL THEN 1 END) AS due_dated_count,
            COUNT(CASE WHEN due_date IS NOT NULL AND COALESCE(payment_date, verified_at::date) <= due_date THEN 1 END) AS on_time_count
        FROM payments
        WHERE status = 'approved'
          AND (payment_date IS NOT NULL OR verified_at IS NOT NULL)
    ");
    $dsoRow = $dsoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $settledCount = (int)($dsoRow['settled_count'] ?? 0);
    $dsoDays = (float)($dsoRow['avg_days_to_collect'] ?? 0.0);
    $dueDatedCount = (int)($dsoRow['due_dated_count'] ?? 0);
    $onTimeCount = (int)($dsoRow['on_time_count'] ?? 0);
    $onTimeRatePct = $dueDatedCount > 0 ? round(($onTimeCount / $dueDatedCount) * 100, 1) : ($settledCount > 0 ? 100.0 : 0.0);

    $descriptiveBa = [
        'gross_collected_rent' => $totalCollected,
        'total_receivables_billed' => $totalBilled,
        'total_outstanding_balance' => $totalOutstanding,
        'collection_rate_pct' => $overallEfficiency,
        'dso_days' => $dsoDays,
        'has_dso_data' => $settledCount > 0,
        'settled_payments_count' => $settledCount,
        'on_time_collection_rate_pct' => $onTimeRatePct
    ];

    // =========================================================================
    // 6. MONTHLY COLLECTION EFFICIENCY TIMELINE CHART
    // =========================================================================
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

    // =========================================================================
    // PILLAR 2: DIAGNOSTIC ANALYTICS (UNIT PERFORMANCE & REQUEST BREAKDOWN BY COUNT — NO COST FIGURES)
    // =========================================================================
    $unitMaintStmt = $pdo->query("
        SELECT 
            u.id AS unit_id,
            u.unit_number,
            u.monthly_rent,
            u.status AS unit_status,
            COUNT(mt.id) AS ticket_count,
            COALESCE(SUM(CASE WHEN LOWER(mt.status) IN ('open', 'pending', 'in_progress', 'on-going', 'ongoing') THEN 1 ELSE 0 END), 0) AS open_tickets,
            COALESCE((
                SELECT ROUND(SUM(p.amount_paid)::numeric, 2)
                FROM payments p
                WHERE p.unit_id = u.id AND p.status = 'approved'
            ), 0.00) AS unit_collected_rent,
            COALESCE((
                SELECT ROUND(SUM(GREATEST(0, p.amount_due - COALESCE(p.amount_paid, 0)))::numeric, 2)
                FROM payments p
                WHERE p.unit_id = u.id AND p.status NOT IN ('approved', 'pending_setup') AND p.amount_due IS NOT NULL
            ), 0.00) AS unit_unpaid_rent
        FROM units u
        LEFT JOIN maintenance_tickets mt ON mt.unit_id = u.id
        GROUP BY u.id, u.unit_number, u.monthly_rent, u.status
        ORDER BY open_tickets DESC, ticket_count DESC, u.unit_number ASC
    ");
    $unitMaintenance = $unitMaintStmt->fetchAll(PDO::FETCH_ASSOC);

    $unitHealthIndex = [];
    foreach ($unitMaintenance as &$um) {
        $unitCollected = (float)$um['unit_collected_rent'];
        $unitUnpaid = (float)$um['unit_unpaid_rent'];
        $ticketCount = (int)$um['ticket_count'];
        $openUnitTickets = (int)$um['open_tickets'];

        $um['ticket_count'] = $ticketCount;
        $um['open_tickets'] = $openUnitTickets;
        $um['unit_collected_rent'] = $unitCollected;
        $um['unit_unpaid_rent'] = $unitUnpaid;

        $requestSharePct = $totalTicketsLogged > 0 ? round(($ticketCount / $totalTicketsLogged) * 100, 1) : 0.0;

        // Compute Unit Health Score (100 = healthy, reduced by open/repeat maintenance requests & unpaid rent)
        $penalty = ($openUnitTickets * 15) + ($requestSharePct * 0.25) + ($unitUnpaid > 0 ? 15 : 0);
        $healthScore = (int)max(0, min(100, round(100 - $penalty)));

        if ($openUnitTickets >= 3 || $healthScore < 55) {
            $healthTier = 'problematic';
        } elseif ($openUnitTickets >= 1 || $ticketCount >= 2 || $unitUnpaid > 0 || $healthScore < 80) {
            $healthTier = 'monitor';
        } else {
            $healthTier = 'healthy';
        }

        $um['request_share_pct'] = $requestSharePct;

        $unitHealthIndex[] = [
            'unit_id' => (int)$um['unit_id'],
            'unit_number' => $um['unit_number'],
            'monthly_rent' => (float)$um['monthly_rent'],
            'unit_status' => $um['unit_status'],
            'ticket_count' => $ticketCount,
            'open_tickets' => $openUnitTickets,
            'unit_collected_rent' => $unitCollected,
            'unit_unpaid_rent' => $unitUnpaid,
            'request_share_pct' => $requestSharePct,
            'health_score' => $healthScore,
            'health_tier' => $healthTier
        ];
    }
    unset($um);

    // 8. Maintenance Request Category Breakdown by Volume (Plumbing, Electrical, Structural, Carpentry, General)
    $catStmt = $pdo->query("
        SELECT 
            COALESCE(NULLIF(TRIM(category), ''), 'General') AS category,
            COUNT(id) AS ticket_count
        FROM maintenance_tickets
        GROUP BY COALESCE(NULLIF(TRIM(category), ''), 'General')
        ORDER BY ticket_count DESC
    ");
    $categoriesRaw = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    $standardTrades = ['Plumbing', 'Electrical', 'Structural', 'Carpentry', 'General'];
    $tradeBuckets = [];
    foreach ($standardTrades as $trade) {
        $tradeBuckets[$trade] = [
            'category' => $trade,
            'ticket_count' => 0
        ];
    }

    foreach ($categoriesRaw as $c) {
        $rawName = trim((string)$c['category']);
        $matchedTrade = null;
        foreach ($standardTrades as $trade) {
            if (strcasecmp($rawName, $trade) === 0) {
                $matchedTrade = $trade;
                break;
            }
        }
        $targetKey = $matchedTrade ?? ucwords(strtolower($rawName));
        if (!isset($tradeBuckets[$targetKey])) {
            $tradeBuckets[$targetKey] = [
                'category' => $targetKey,
                'ticket_count' => 0
            ];
        }
        $tradeBuckets[$targetKey]['ticket_count'] += (int)$c['ticket_count'];
    }

    // Sort descending by ticket_count for Pareto volume ordering
    $paretoList = array_values($tradeBuckets);
    usort($paretoList, static function (array $a, array $b): int {
        if ($a['ticket_count'] !== $b['ticket_count']) {
            return $b['ticket_count'] <=> $a['ticket_count'];
        }
        return strcmp($a['category'], $b['category']);
    });

    $categoryAnalytics = [];
    $paretoMaintenance = [];
    $runningCount = 0;
    foreach ($paretoList as $item) {
        $ticketsCnt = (int)$item['ticket_count'];
        $share = $totalTicketsLogged > 0 ? round(($ticketsCnt / $totalTicketsLogged) * 100, 1) : 0.0;
        $runningCount += $ticketsCnt;
        $cumPct = $totalTicketsLogged > 0 ? min(100.0, round(($runningCount / $totalTicketsLogged) * 100, 1)) : 0.0;

        $paretoMaintenance[] = [
            'category' => $item['category'],
            'ticket_count' => $ticketsCnt,
            'request_share_percentage' => $share,
            'cumulative_pct' => $cumPct
        ];

        if ($ticketsCnt > 0) {
            $categoryAnalytics[] = [
                'category' => $item['category'],
                'ticket_count' => $ticketsCnt,
                'request_share_percentage' => $share
            ];
        }
    }

    // =========================================================================
    // PILLAR 3: PREDICTIVE ANALYTICS
    //   A) NEXT-MONTH REVENUE CASH FLOW FORECAST
    //   B) TENANT DELINQUENCY / DEFAULT RISK SCORING
    // =========================================================================

    // A) Next-Month Cash Flow Forecast based on current occupancy, parking add-ons, and historical collection rate
    $occRentStmt = $pdo->query("
        SELECT 
            COALESCE(SUM(COALESCE(usr.monthly_rent, u.monthly_rent)), 0) AS occupied_rent_potential
        FROM units u
        JOIN users usr ON usr.unit_id = u.id
        WHERE usr.role = 'tenant'
          AND usr.is_active = TRUE
          AND (usr.is_archived = FALSE OR usr.is_archived IS NULL)
          AND usr.deleted_at IS NULL
    ");
    $occupiedRentPotential = (float)($occRentStmt->fetchColumn() ?: 0.0);

    $vacantRentStmt = $pdo->query("
        SELECT COALESCE(SUM(u.monthly_rent), 0)
        FROM units u
        WHERE NOT EXISTS (
            SELECT 1 FROM users usr
            WHERE usr.unit_id = u.id
              AND usr.role = 'tenant'
              AND usr.is_active = TRUE
              AND (usr.is_archived = FALSE OR usr.is_archived IS NULL)
              AND usr.deleted_at IS NULL
        )
    ");
    $vacantUnitOpportunity = (float)($vacantRentStmt->fetchColumn() ?: 0.0);

    $parkingOccupiedPotential = 0.0;
    $parkingVacantOpportunity = 0.0;
    try {
        $spaceStmt = $pdo->query("
            SELECT 
                COALESCE(SUM(CASE WHEN status = 'occupied' AND assigned_tenant_id IS NOT NULL THEN monthly_fee ELSE 0 END), 0) AS occupied_addons,
                COALESCE(SUM(CASE WHEN status = 'available' OR assigned_tenant_id IS NULL THEN monthly_fee ELSE 0 END), 0) AS available_addons
            FROM additional_spaces
        ");
        $spaceRow = $spaceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $parkingOccupiedPotential = (float)($spaceRow['occupied_addons'] ?? 0.0);
        $parkingVacantOpportunity = (float)($spaceRow['available_addons'] ?? 0.0);
    } catch (Throwable $e) {
        // Table may not exist in older schemas; default to 0
    }

    $grossScheduledInflow = round($occupiedRentPotential + $parkingOccupiedPotential, 2);

    // Weighted historical collection realization rate (recent 3 months weighted higher if available)
    $hasHistoricalBaseline = count($monthlyCollections) > 0 && $totalBilled > 0;
    if ($hasHistoricalBaseline) {
        $recentSlice = array_slice($monthlyCollections, -3);
        $weightSum = 0.0;
        $weightedEffSum = 0.0;
        $collectedSum = 0.0;
        foreach ($recentSlice as $idx => $m) {
            $w = (float)($idx + 1); // recency weights 1, 2, 3
            $weightedEffSum += ((float)$m['collection_efficiency_percentage']) * $w;
            $weightSum += $w;
            $collectedSum += (float)$m['collected_rent'];
        }
        $trendEfficiency = $weightSum > 0 ? ($weightedEffSum / $weightSum) : $overallEfficiency;
        $historicalCollectionFactorPct = round(($trendEfficiency * 0.65) + ($onTimeRatePct * 0.35), 1);
        $historicalCollectionFactorPct = max(0.0, min(100.0, $historicalCollectionFactorPct));
        $historicalAvgCollected = round($collectedSum / count($recentSlice), 2);
    } else {
        $historicalCollectionFactorPct = $grossScheduledInflow > 0 ? 100.0 : 0.0;
        $historicalAvgCollected = 0.0;
    }

    $expectedNextMonthInflow = round($grossScheduledInflow * ($historicalCollectionFactorPct / 100), 2);
    $conservativeFactorPct = $hasHistoricalBaseline
        ? round(max(0.0, min($historicalCollectionFactorPct, $onTimeRatePct > 0 ? $onTimeRatePct : $historicalCollectionFactorPct * 0.9)), 1)
        : 0.0;
    $conservativeNextMonthInflow = $hasHistoricalBaseline
        ? round($grossScheduledInflow * ($conservativeFactorPct / 100), 2)
        : $expectedNextMonthInflow;

    $nextMonthObj = new DateTime('first day of next month');
    $forecastMonthLabel = $nextMonthObj->format('M Y');

    $cashFlowForecast = [
        'forecast_month_label' => $forecastMonthLabel,
        'occupied_unit_rent_potential' => round($occupiedRentPotential, 2),
        'parking_addons_potential' => round($parkingOccupiedPotential, 2),
        'gross_scheduled_inflow' => $grossScheduledInflow,
        'vacant_unit_opportunity' => round($vacantUnitOpportunity, 2),
        'vacant_parking_opportunity' => round($parkingVacantOpportunity, 2),
        'historical_collection_factor_pct' => $historicalCollectionFactorPct,
        'conservative_factor_pct' => $conservativeFactorPct,
        'historical_avg_collected' => $historicalAvgCollected,
        'expected_cash_inflow' => $expectedNextMonthInflow,
        'conservative_cash_inflow' => $conservativeNextMonthInflow,
        'optimistic_cash_inflow' => $grossScheduledInflow,
        'has_historical_baseline' => $hasHistoricalBaseline
    ];

    // B) Tenant Delinquency / Default Risk Scoring (0-100% & Low/Medium/High Tier)
    $riskStmt = $pdo->query("
        SELECT 
            usr.id AS tenant_id,
            usr.name AS tenant_name,
            COALESCE(u.unit_number, 'Unassigned') AS unit_number,
            COALESCE(usr.monthly_rent, u.monthly_rent, 3000.00) AS monthly_rent,
            COUNT(CASE WHEN p.status != 'pending_setup' THEN 1 END) AS total_bills,
            COALESCE(SUM(CASE WHEN p.status NOT IN ('approved', 'pending_setup') AND p.amount_due IS NOT NULL THEN GREATEST(0, p.amount_due - COALESCE(p.amount_paid, 0)) ELSE 0 END), 0) AS unpaid_balance,
            COUNT(CASE WHEN p.status = 'overdue' OR (p.status IN ('unpaid', 'pending', 'rejected') AND p.due_date IS NOT NULL AND p.due_date < CURRENT_DATE) THEN 1 END) AS overdue_bills_count,
            COUNT(CASE WHEN p.due_date IS NOT NULL AND (
                (p.status = 'approved' AND COALESCE(p.payment_date, p.verified_at::date) > p.due_date)
                OR p.status = 'overdue'
                OR (p.status IN ('unpaid', 'pending', 'rejected') AND p.due_date < CURRENT_DATE)
            ) THEN 1 END) AS late_payments_count,
            COALESCE(ROUND(AVG(CASE 
                WHEN p.due_date IS NOT NULL AND p.status = 'approved' AND COALESCE(p.payment_date, p.verified_at::date) > p.due_date
                    THEN (COALESCE(p.payment_date, p.verified_at::date) - p.due_date)
                WHEN p.due_date IS NOT NULL AND p.status IN ('unpaid', 'pending', 'rejected', 'overdue') AND p.due_date < CURRENT_DATE
                    THEN (CURRENT_DATE - p.due_date)
                ELSE NULL
            END)::numeric, 1), 0) AS avg_days_late,
            COUNT(CASE WHEN p.status = 'rejected' THEN 1 END) AS rejected_receipts_count,
            COUNT(CASE WHEN p.receipt_filename IS NOT NULL AND p.receipt_filename != '' THEN 1 END) AS submitted_receipts_count
        FROM users usr
        LEFT JOIN units u ON usr.unit_id = u.id
        LEFT JOIN payments p ON p.tenant_id = usr.id
        WHERE usr.role = 'tenant'
          AND usr.is_active = TRUE
          AND (usr.is_archived = FALSE OR usr.is_archived IS NULL)
          AND usr.deleted_at IS NULL
        GROUP BY usr.id, usr.name, u.unit_number, usr.monthly_rent, u.monthly_rent
        ORDER BY usr.name ASC
    ");
    $tenantRows = $riskStmt->fetchAll(PDO::FETCH_ASSOC);

    $tenantRiskScores = [];
    $riskSummary = [
        'high_count' => 0,
        'medium_count' => 0,
        'low_count' => 0,
        'total_scored' => 0
    ];

    foreach ($tenantRows as $tr) {
        $monthlyRent = max(1.0, (float)$tr['monthly_rent']);
        $totalBills = (int)$tr['total_bills'];
        $unpaidBalance = (float)$tr['unpaid_balance'];
        $overdueCount = (int)$tr['overdue_bills_count'];
        $lateCount = (int)$tr['late_payments_count'];
        $avgDaysLate = (float)$tr['avg_days_late'];
        $rejectedCount = (int)$tr['rejected_receipts_count'];
        $submittedCount = (int)$tr['submitted_receipts_count'];

        // Weighted Risk Model (0 - 100):
        //   1. Unpaid / Overdue Balance component (up to 40 pts)
        $unpaidScore = $unpaidBalance > 0
            ? min(25.0, ($unpaidBalance / $monthlyRent) * 20.0) + ($overdueCount > 0 ? min(15.0, $overdueCount * 7.5) : 0.0)
            : 0.0;

        //   2. Historical Payment Delay component (up to 35 pts)
        $lateRatio = $totalBills > 0 ? ($lateCount / $totalBills) : 0.0;
        $delayScore = min(35.0, ($lateRatio * 25.0) + min(10.0, $avgDaysLate * 0.75));

        //   3. Rejected Receipt Ratio component (up to 25 pts)
        $denomReceipts = max($submittedCount, $rejectedCount, 1);
        $rejectedRatio = $rejectedCount > 0 ? ($rejectedCount / $denomReceipts) : 0.0;
        $rejectScore = min(25.0, $rejectedRatio * 25.0);

        $riskScore = (int)max(0, min(100, round($unpaidScore + $delayScore + $rejectScore)));

        if ($riskScore >= 60) {
            $riskTier = 'High';
            $riskSummary['high_count']++;
        } elseif ($riskScore >= 25) {
            $riskTier = 'Medium';
            $riskSummary['medium_count']++;
        } else {
            $riskTier = 'Low';
            $riskSummary['low_count']++;
        }
        $riskSummary['total_scored']++;

        $tenantRiskScores[] = [
            'tenant_id' => (int)$tr['tenant_id'],
            'tenant_name' => $tr['tenant_name'],
            'unit_number' => $tr['unit_number'],
            'monthly_rent' => (float)$tr['monthly_rent'],
            'total_bills' => $totalBills,
            'unpaid_balance' => round($unpaidBalance, 2),
            'overdue_bills_count' => $overdueCount,
            'late_payments_count' => $lateCount,
            'avg_days_late' => $avgDaysLate,
            'rejected_receipts_count' => $rejectedCount,
            'rejected_ratio_pct' => round($rejectedRatio * 100, 1),
            'risk_score' => $riskScore,
            'risk_tier' => $riskTier
        ];
    }

    // Sort highest risk tenants to the top
    usort($tenantRiskScores, static function (array $a, array $b): int {
        if ($a['risk_score'] !== $b['risk_score']) {
            return $b['risk_score'] <=> $a['risk_score'];
        }
        return $b['unpaid_balance'] <=> $a['unpaid_balance'];
    });

    // =========================================================================
    // PILLAR 4: PRESCRIPTIVE ANALYTICS ("RECOMMENDED ACTIONS FOR TODAY" ENGINE)
    // =========================================================================
    $smartInsights = [];

    // Rule 1: High-Risk / Overdue Tenants -> Friendly early reminder suggestion
    $highRiskTenants = array_values(array_filter($tenantRiskScores, static fn($t) => $t['risk_tier'] === 'High' || $t['overdue_bills_count'] > 0));
    if (count($highRiskTenants) > 0) {
        $topRisk = $highRiskTenants[0];
        $balText = number_format((float)$topRisk['unpaid_balance'], 2);
        $isHighTier = ($topRisk['risk_tier'] === 'High');
        $smartInsights[] = [
            'id' => 'high_risk_sms',
            'pillar' => 'Payment Reliability Risk',
            'severity' => $isHighTier ? 'urgent' : 'warning',
            'badge' => $isHighTier ? 'High Risk Reminder' : 'Overdue Rent Reminder',
            'title' => "Send a friendly payment reminder to {$topRisk['tenant_name']} ({$topRisk['unit_number']})",
            'recommendation' => "{$topRisk['tenant_name']} is in the {$topRisk['risk_tier']} Risk tier ({$topRisk['risk_score']}%) with ₱{$balText} unpaid. Send an early reminder before the next due date so rent doesn't fall further behind.",
            'metric_highlight' => "{$topRisk['risk_score']}% Risk",
            'action_label' => 'Check Payments',
            'target_section' => 'sectionPayments',
            'target_nav' => 'navPaymentVerification'
        ];
    }

    // Rule 2: Pending Payments Waiting for Approval -> Improve Average Payment Speed (DSO)
    if ($pendingReceiptsCount > 0) {
        $smartInsights[] = [
            'id' => 'pending_receipts_dso',
            'pillar' => 'Average Payment Speed',
            'severity' => 'warning',
            'badge' => 'Waiting for Approval',
            'title' => "Approve {$pendingReceiptsCount} pending payment slip" . ($pendingReceiptsCount === 1 ? '' : 's') . " today",
            'recommendation' => "Tenants already uploaded payment proof. Reviewing and approving them updates your Total Rent Collected and keeps Average Payment Speed (DSO) fast.",
            'metric_highlight' => "{$pendingReceiptsCount} To Approve",
            'action_label' => 'Approve Payments',
            'target_section' => 'sectionPayments',
            'target_nav' => 'navPaymentVerification'
        ];
    }

    // Rule 3: Unit Maintenance Watchlist Inspection Suggestion (by open/frequent request count only)
    $problemUnits = array_values(array_filter($unitHealthIndex, static fn($uh) => $uh['open_tickets'] > 0 || $uh['health_tier'] === 'problematic'));
    if (count($problemUnits) > 0) {
        $pu = $problemUnits[0];
        $smartInsights[] = [
            'id' => 'preventive_unit_maint',
            'pillar' => 'Unit Maintenance Watchlist',
            'severity' => $pu['health_tier'] === 'problematic' ? 'urgent' : 'warning',
            'badge' => 'Inspection Suggested',
            'title' => "Inspect {$pu['unit_number']} — {$pu['open_tickets']} open fix" . ($pu['open_tickets'] === 1 ? '' : 'es') . " needed",
            'recommendation' => "{$pu['unit_number']} has {$pu['open_tickets']} open maintenance request" . ($pu['open_tickets'] === 1 ? '' : 's') . " ({$pu['ticket_count']} total logged). Follow up on repairs to keep your tenant satisfied.",
            'metric_highlight' => "{$pu['open_tickets']} Open",
            'action_label' => 'Open Requests',
            'target_section' => 'sectionTickets',
            'target_nav' => 'navMaintenanceTickets'
        ];
    }

    // Rule 4: Vacant Units & Extra Spaces Revenue Opportunity
    $totalUnrealizedOpp = $vacantUnitOpportunity + $parkingVacantOpportunity;
    if ($availableUnits > 0 || $parkingVacantOpportunity > 0) {
        $oppFormatted = number_format($totalUnrealizedOpp, 2);
        $smartInsights[] = [
            'id' => 'vacancy_revenue_opportunity',
            'pillar' => 'Next-Month Cash Flow',
            'severity' => 'opportunity',
            'badge' => 'Income Opportunity',
            'title' => "Fill vacant units/spaces to add +₱{$oppFormatted}/mo",
            'recommendation' => ($availableUnits > 0
                ? "{$availableUnits} of {$totalUnits} units " . ($availableUnits === 1 ? 'is' : 'are') . " still available"
                : "All residential units are rented")
                . ($parkingVacantOpportunity > 0 ? " plus ₱" . number_format($parkingVacantOpportunity, 2) . "/mo in open parking/add-on spaces" : "")
                . ". Renting them out raises next month's full cash flow potential to ₱" . number_format($grossScheduledInflow + $totalUnrealizedOpp, 2) . ".",
            'metric_highlight' => "+₱{$oppFormatted}/mo",
            'action_label' => 'View Units',
            'target_section' => 'sectionDirectory',
            'target_nav' => 'navTenantDirectory'
        ];
    }

    // Rule 5: Healthy Portfolio Positive Insight
    if (empty($smartInsights) || ($overallEfficiency >= 80.0 && count($highRiskTenants) === 0 && $availableUnits === 0)) {
        $collectedFmt = number_format($totalCollected, 2);
        $forecastFmt = number_format($expectedNextMonthInflow, 2);
        $smartInsights[] = [
            'id' => 'optimal_portfolio_status',
            'pillar' => 'Everything Looks Healthy',
            'severity' => 'positive',
            'badge' => 'On Track',
            'title' => "Your rental units are running smoothly (Collected: ₱{$collectedFmt})",
            'recommendation' => "Collection rate is strong at {$overallEfficiency}% with no high-risk tenants. Expected cash flow for {$forecastMonthLabel} is ₱{$forecastFmt}.",
            'metric_highlight' => "{$overallEfficiency}% Collected",
            'action_label' => 'View Units',
            'target_section' => 'sectionDirectory',
            'target_nav' => 'navTenantDirectory'
        ];
    }

    // =========================================================================
    // DIAGNOSTIC ALERTS (ARREARS & REQUEST VOLUME ONLY)
    // =========================================================================
    $diagnosticAlerts = [];

    $overdueStmt = $pdo->query("
        SELECT DISTINCT p.id, p.billing_cycle, p.due_date, p.amount_due, p.amount_paid, p.status, 
               (p.amount_due - COALESCE(p.amount_paid, 0)) AS balance,
               u.unit_number, usr.name AS tenant_name
        FROM payments p
        JOIN units u ON p.unit_id = u.id
        JOIN users usr ON p.tenant_id = usr.id
        WHERE usr.is_active = TRUE
          AND (usr.is_archived = FALSE OR usr.is_archived IS NULL)
          AND usr.deleted_at IS NULL
          AND (
            p.status IN ('rejected', 'overdue') 
            OR (p.status = 'pending' AND p.due_date < CURRENT_DATE AND COALESCE(p.amount_paid, 0) < p.amount_due)
            OR (p.status = 'unpaid' AND p.due_date < CURRENT_DATE)
          )
        ORDER BY p.billing_cycle DESC, p.id DESC
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

    // =========================================================================
    // RESPONSE PAYLOAD
    // =========================================================================
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
        'business_analytics' => [
            'descriptive' => $descriptiveBa,
            'diagnostic' => [
                'pareto_maintenance' => $paretoMaintenance,
                'unit_health_index' => $unitHealthIndex
            ],
            'predictive' => [
                'cash_flow_forecast' => $cashFlowForecast,
                'tenant_risk_scores' => $tenantRiskScores,
                'risk_summary' => $riskSummary
            ],
            'prescriptive' => [
                'smart_insights' => $smartInsights
            ]
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
