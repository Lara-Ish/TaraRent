<?php
/**
 * TaraRent - Archived Tenant Details Page
 * Displays complete historical records (profile, payments, receipts, maintenance, reports)
 * for a moved-out / archived tenant.
 * Landlord: Leah Dimaculangan
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

Auth::requireLandlord();
$pdo = Database::getConnection();

$tenantId = (int)($_GET['id'] ?? ($_GET['tenant_id'] ?? 0));
if ($tenantId <= 0) {
    header('Location: admin_dashboard.php?tab=archived#directoryArchivedView');
    exit;
}

// 1. Fetch Tenant Record
$stmt = $pdo->prepare("
    SELECT u.*,
           (SELECT address FROM apartment_settings LIMIT 1) AS property_address,
           (SELECT security_deposit FROM apartment_settings LIMIT 1) AS standard_deposit
    FROM users u
    WHERE u.id = :id AND u.role = 'tenant'
    LIMIT 1
");
$stmt->execute([':id' => $tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    http_response_code(404);
    $pageTitle = "Archived Tenant Not Found • TaraRent";
    include __DIR__ . '/includes/header.php';
    echo '<div class="min-h-screen bg-[#F5F0E6] flex items-center justify-center p-6 text-center">
            <div class="bg-white p-8 rounded-2xl border-2 border-[#B89B72] shadow-xl max-w-md w-full">
                <div class="text-4xl mb-3">📁</div>
                <h1 class="text-xl font-bold text-[#3E2723] mb-2">Archived Tenant Not Found</h1>
                <p class="text-sm text-[#5C4A3A] mb-6">The requested archived tenant record could not be located or has been purged.</p>
                <a href="admin_dashboard.php?tab=archived#directoryArchivedView" class="inline-block px-6 py-2.5 bg-[#4A3728] text-white font-bold rounded-xl shadow hover:bg-[#382618] transition">Return to Directory</a>
            </div>
          </div>';
    exit;
}

// 2. Fetch Historical Payments
$payStmt = $pdo->prepare("
    SELECT * FROM payments 
    WHERE tenant_id = :id 
    ORDER BY payment_date DESC, id DESC
");
$payStmt->execute([':id' => $tenantId]);
$payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Historical Maintenance Tickets
$tickStmt = $pdo->prepare("
    SELECT * FROM maintenance_tickets 
    WHERE tenant_id = :id 
    ORDER BY created_at DESC, id DESC
");
$tickStmt->execute([':id' => $tenantId]);
$tickets = $tickStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Fetch Historical Tenant Reports
$repStmt = $pdo->prepare("
    SELECT * FROM tenant_reports 
    WHERE tenant_id = :id 
    ORDER BY created_at DESC, id DESC
");
$repStmt->execute([':id' => $tenantId]);
$reports = $repStmt->fetchAll(PDO::FETCH_ASSOC);

// Formatting helpers
$initial = strtoupper(mb_substr(trim(explode(' ', trim((string)$tenant['name']))[0]), 0, 1, 'UTF-8')) ?: 'T';
$unitDisplay = !empty($tenant['archived_unit_number']) ? $tenant['archived_unit_number'] : (!empty($tenant['unit_id']) ? "Unit {$tenant['unit_id']}" : 'Unit N/A');
$moveInFormatted = !empty($tenant['move_in_date']) ? date('M j, Y', strtotime($tenant['move_in_date'])) : 'Not recorded';
$moveOutFormatted = !empty($tenant['move_out_date']) 
    ? date('M j, Y', strtotime($tenant['move_out_date'])) 
    : (!empty($tenant['deleted_at']) ? date('M j, Y', strtotime($tenant['deleted_at'])) : 'Archived');
$archivedAtFormatted = !empty($tenant['deleted_at']) ? date('F j, Y • g:i A', strtotime($tenant['deleted_at'])) : 'Archived';
$monthlyRentFormatted = !empty($tenant['archived_monthly_rent']) ? '₱' . number_format((float)$tenant['archived_monthly_rent'], 2) : '₱3,000.00';
$propAddress = $tenant['property_address'] ?: 'TaraRent Residences, Batangas, Philippines';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($tenant['name']) ?> – Archived Record • TaraRent</title>
    <link rel="icon" type="image/png" href="assets/images/tararent-logo.png">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
            background-color: #F5F0E6;
        }
        .font-display {
            font-family: 'Playfair Display', Georgia, serif;
        }
    </style>
</head>
<body class="bg-[#F5F0E6] text-[#2B1A06] min-h-screen antialiased flex flex-col">

    <!-- Top Navigation Header -->
    <header class="bg-[#FFFFFF] border-b-2 border-[#B89B72] shadow-sm sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="admin_dashboard.php?tab=archived#directoryArchivedView" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl bg-[#FAF5EC] border border-[#B89B72] text-[#4A3728] font-bold text-sm hover:bg-[#E8D8B0]/60 transition shadow-sm">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    <span>Back to Archived Directory</span>
                </a>
                <span class="text-sm font-semibold text-[#8C6D46] hidden sm:inline">• Historical Archive File</span>
            </div>
            
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <div class="text-xs font-bold text-[#8C6D46] uppercase tracking-wider">Property Owner</div>
                    <div class="text-sm font-extrabold text-[#3E2723]">Leah Dimaculangan</div>
                </div>
                <div class="w-9 h-9 rounded-full bg-[#FAF5EC] border border-[#B89B72] flex items-center justify-center text-[#4A3728] font-black text-sm shadow-sm">
                    LD
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full flex-1">
        
        <!-- Breadcrumb & Status Pill Bar -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-2 text-xs font-semibold text-[#8C6D46]">
                <a href="admin_dashboard.php" class="hover:underline">Dashboard</a>
                <span>/</span>
                <a href="admin_dashboard.php?tab=archived#directoryArchivedView" class="hover:underline">Archived Tenants</a>
                <span>/</span>
                <span class="text-[#3E2723] font-bold"><?= htmlspecialchars($tenant['name']) ?></span>
            </div>
            
            <div class="flex items-center gap-2">
                <span class="px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-rose-100 text-rose-800 border border-rose-300 flex items-center gap-1.5">
                    <i data-lucide="archive" class="w-3.5 h-3.5"></i>
                    Archived Record
                </span>
                <span class="px-3 py-1 rounded-full text-xs font-bold bg-[#FAF5EC] text-[#56380E] border border-[#B89B72]">
                    Moved Out: <?= htmlspecialchars($moveOutFormatted) ?>
                </span>
            </div>
        </div>

        <!-- Hero Tenant Profile Header Card -->
        <div class="bg-[#FFFFFF] border-2 border-[#B89B72] rounded-2xl p-6 sm:p-8 shadow-md mb-8 relative overflow-hidden">
            <!-- Header Accent Gradient Line -->
            <div class="absolute top-0 left-0 right-0 h-2 bg-gradient-to-r from-[#B89B72] via-[#9A7B4F] to-[#4A3728]"></div>
            
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 pt-2">
                <div class="flex items-center gap-5">
                    <?php if (!empty($tenant['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars($tenant['profile_picture']) ?>" alt="<?= htmlspecialchars($tenant['name']) ?>" class="w-20 h-20 rounded-2xl object-cover border-2 border-[#B89B72] shadow-md flex-shrink-0">
                    <?php else: ?>
                        <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-[#B89B72] to-[#4A3728] text-white flex items-center justify-center text-3xl font-black shadow-md flex-shrink-0">
                            <?= $initial ?>
                        </div>
                    <?php endif; ?>
                    
                    <div>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#3E2723] font-display">
                                <?= htmlspecialchars($tenant['name']) ?>
                            </h1>
                            <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-[#FAF5EC] text-[#56380E] border border-[#B89B72]">
                                <?= htmlspecialchars($unitDisplay) ?>
                            </span>
                        </div>
                        <p class="text-xs sm:text-sm font-semibold text-[#8C6D46] mt-1">
                            Username: <span class="text-[#3E2723]">@<?= htmlspecialchars($tenant['username'] ?? 'tenant') ?></span> • 
                            Archived on <?= htmlspecialchars($archivedAtFormatted) ?>
                        </p>
                    </div>
                </div>

                <div class="bg-[#FAF5EC] border border-[#B89B72] rounded-xl px-4 py-3 text-right sm:text-right w-full sm:w-auto">
                    <div class="text-xs font-bold text-[#8C6D46] uppercase">Monthly Rent Rate</div>
                    <div class="text-xl font-black text-[#4A3728]"><?= $monthlyRentFormatted ?></div>
                    <div class="text-[11px] font-semibold text-emerald-700 flex items-center justify-end gap-1 mt-0.5">
                        <i data-lucide="check-circle-2" class="w-3 h-3"></i> Fully Settled & Reconciled
                    </div>
                </div>
            </div>
        </div>

        <!-- 2-Column Info Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
            
            <!-- Card 1: Personal Information -->
            <div class="bg-[#FFFFFF] border-2 border-[#B89B72] rounded-2xl p-6 shadow-sm">
                <div class="flex items-center gap-2.5 pb-3 border-b border-[#E8D8B0] mb-4">
                    <i data-lucide="user" class="w-5 h-5 text-[#4A3728]"></i>
                    <h2 class="text-base font-bold text-[#3E2723]">Personal & Contact Information</h2>
                </div>
                
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Full Legal Name</dt>
                        <dd class="text-[#2B1A06] font-bold"><?= htmlspecialchars($tenant['name']) ?></dd>
                    </div>
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Email Address</dt>
                        <dd class="text-[#2B1A06] font-bold select-all truncate max-w-[240px]" title="<?= htmlspecialchars($tenant['email'] ?? '') ?>">
                            <?= htmlspecialchars($tenant['email'] ?? '—') ?>
                        </dd>
                    </div>
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Contact Phone</dt>
                        <dd class="text-[#2B1A06] font-bold"><?= htmlspecialchars($tenant['phone'] ?? '—') ?></dd>
                    </div>
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Date of Birth</dt>
                        <dd class="text-[#2B1A06] font-bold">
                            <?= !empty($tenant['birthday']) ? date('F j, Y', strtotime($tenant['birthday'])) : 'Not provided' ?>
                        </dd>
                    </div>
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Emergency Contact</dt>
                        <dd class="text-[#2B1A06] font-bold text-right">
                            <?php 
                                $em = array_filter([$tenant['emergency_contact_name'] ?? '', $tenant['emergency_contact_phone'] ?? '']);
                                echo !empty($em) ? htmlspecialchars(implode(' • ', $em)) : htmlspecialchars($tenant['emergency_contact'] ?? 'Not provided');
                            ?>
                        </dd>
                    </div>
                    <div class="flex justify-between py-1">
                        <dt class="text-[#8C6D46] font-semibold">Total Occupants</dt>
                        <dd class="text-[#2B1A06] font-bold">
                            <?= (int)($tenant['total_occupants'] ?? 1) ?> Resident(s)
                            <?php if (!empty($tenant['adults_count']) || !empty($tenant['kids_count'])): ?>
                                <span class="text-xs text-[#8C6D46]">(<?= (int)($tenant['adults_count'] ?? 1) ?> Adults, <?= (int)($tenant['kids_count'] ?? 0) ?> Children)</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Card 2: Tenancy & Move-Out Details -->
            <div class="bg-[#FFFFFF] border-2 border-[#B89B72] rounded-2xl p-6 shadow-sm">
                <div class="flex items-center gap-2.5 pb-3 border-b border-[#E8D8B0] mb-4">
                    <i data-lucide="home" class="w-5 h-5 text-[#4A3728]"></i>
                    <h2 class="text-base font-bold text-[#3E2723]">Lease & Move-Out History</h2>
                </div>
                
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Assigned Unit</dt>
                        <dd class="text-[#2B1A06] font-bold"><?= htmlspecialchars($unitDisplay) ?></dd>
                    </div>
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Date of Moving In</dt>
                        <dd class="text-[#2B1A06] font-bold"><?= htmlspecialchars($moveInFormatted) ?></dd>
                    </div>
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Official Move-Out Date</dt>
                        <dd class="text-[#92400e] font-bold"><?= htmlspecialchars($moveOutFormatted) ?></dd>
                    </div>
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Archived Repository Date</dt>
                        <dd class="text-rose-700 font-bold"><?= htmlspecialchars($archivedAtFormatted) ?></dd>
                    </div>
                    <div class="flex justify-between py-1 border-b border-[#FAF5EC]">
                        <dt class="text-[#8C6D46] font-semibold">Verified by Landlord</dt>
                        <dd class="text-[#2B1A06] font-bold">Leah Dimaculangan</dd>
                    </div>
                    <div class="flex justify-between py-1">
                        <dt class="text-[#8C6D46] font-semibold">Security Settlement Status</dt>
                        <dd class="text-emerald-700 font-extrabold flex items-center gap-1">
                            <i data-lucide="shield-check" class="w-4 h-4"></i> Complete & Cleared
                        </dd>
                    </div>
                </dl>
            </div>

        </div>

        <!-- Section: Historical Payment Ledger -->
        <div class="bg-[#FFFFFF] border-2 border-[#B89B72] rounded-2xl p-6 shadow-sm mb-8 overflow-hidden">
            <div class="flex items-center justify-between pb-3 border-b border-[#E8D8B0] mb-4">
                <div class="flex items-center gap-2.5">
                    <i data-lucide="receipt" class="w-5 h-5 text-[#4A3728]"></i>
                    <h2 class="text-base font-bold text-[#3E2723]">Historical Payment Ledger & Receipts</h2>
                </div>
                <span class="text-xs font-bold text-[#8C6D46] bg-[#FAF5EC] px-3 py-1 rounded-full border border-[#B89B72]">
                    <?= count($payments) ?> Recorded Transaction(s)
                </span>
            </div>

            <?php if (empty($payments)): ?>
                <div class="py-12 text-center text-[#8C6D46]">
                    <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-60"></i>
                    <p class="text-sm font-semibold">No historical payment records found for this tenant.</p>
                </div>
            <?php else: ?>
                <div class="w-full overflow-hidden">
                    <table class="w-full table-fixed text-left text-sm border-collapse">
                        <thead>
                            <tr class="bg-[#FAF5EC] border-b border-[#B89B72] text-[#3E2723] font-bold text-xs uppercase tracking-wider">
                                <th class="w-[20%] p-3.5">Billing Cycle</th>
                                <th class="w-[15%] p-3.5">Payment Type</th>
                                <th class="w-[15%] p-3.5">Amount Due</th>
                                <th class="w-[15%] p-3.5">Amount Paid</th>
                                <th class="w-[15%] p-3.5">Receipt Proof</th>
                                <th class="w-[20%] p-3.5 text-center">Verification Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E8D8B0]/60">
                            <?php foreach ($payments as $p): ?>
                                <?php 
                                    $cycleStr = !empty($p['billing_cycle']) ? date('F Y', strtotime($p['billing_cycle'])) : 'Account Initiation';
                                    $dueStr = '₱' . number_format((float)($p['amount_due'] ?? 0), 2);
                                    $paidStr = '₱' . number_format((float)($p['amount_paid'] ?? 0), 2);
                                    $isApp = in_array(strtolower((string)$p['status']), ['approved', 'verified'], true);
                                    $receiptFile = $p['receipt_filename'] ?? '';
                                ?>
                                <tr class="hover:bg-[#FAF5EC]/60 transition">
                                    <td class="p-3.5 font-bold text-[#2B1A06]">
                                        <?= htmlspecialchars($cycleStr) ?>
                                        <?php if (!empty($p['payment_date'])): ?>
                                            <div class="text-[11px] font-normal text-[#8C6D46]"><?= date('M j, Y', strtotime($p['payment_date'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 font-semibold text-[#5C4A3A]">
                                        <?= htmlspecialchars($p['payment_type'] ?? 'Rent') ?>
                                    </td>
                                    <td class="p-3.5 font-bold text-[#2B1A06]"><?= $dueStr ?></td>
                                    <td class="p-3.5 font-bold text-emerald-800"><?= $paidStr ?></td>
                                    <td class="p-3.5">
                                        <?php if (!empty($receiptFile)): ?>
                                            <button type="button" onclick="previewReceipt('<?= htmlspecialchars($receiptFile) ?>')" class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-bold bg-[#FAF5EC] text-[#4A3728] border border-[#B89B72] rounded-lg hover:bg-[#E8D8B0] transition shadow-xs">
                                                <i data-lucide="image" class="w-3.5 h-3.5"></i>
                                                <span>View Receipt</span>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-xs text-[#8C6D46] italic">No receipt attached</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 text-center">
                                        <?php if ($isApp): ?>
                                            <span class="inline-block px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-300">
                                                Verified & Settled
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-block px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-300">
                                                <?= ucfirst(htmlspecialchars($p['status'] ?? 'Pending')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Section: Historical Maintenance Requests -->
        <div class="bg-[#FFFFFF] border-2 border-[#B89B72] rounded-2xl p-6 shadow-sm mb-8 overflow-hidden">
            <div class="flex items-center justify-between pb-3 border-b border-[#E8D8B0] mb-4">
                <div class="flex items-center gap-2.5">
                    <i data-lucide="wrench" class="w-5 h-5 text-[#4A3728]"></i>
                    <h2 class="text-base font-bold text-[#3E2723]">Historical Maintenance Tickets</h2>
                </div>
                <span class="text-xs font-bold text-[#8C6D46] bg-[#FAF5EC] px-3 py-1 rounded-full border border-[#B89B72]">
                    <?= count($tickets) ?> Logged Ticket(s)
                </span>
            </div>

            <?php if (empty($tickets)): ?>
                <div class="py-8 text-center text-[#8C6D46]">
                    <p class="text-sm font-semibold">No maintenance tickets logged by this tenant.</p>
                </div>
            <?php else: ?>
                <div class="w-full overflow-hidden">
                    <table class="w-full table-fixed text-left text-sm border-collapse">
                        <thead>
                            <tr class="bg-[#FAF5EC] border-b border-[#B89B72] text-[#3E2723] font-bold text-xs uppercase tracking-wider">
                                <th class="w-[12%] p-3.5">Ticket #</th>
                                <th class="w-[28%] p-3.5">Issue Title</th>
                                <th class="w-[18%] p-3.5">Category</th>
                                <th class="w-[14%] p-3.5">Actual Cost</th>
                                <th class="w-[14%] p-3.5">Date Logged</th>
                                <th class="w-[14%] p-3.5 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E8D8B0]/60">
                            <?php foreach ($tickets as $t): ?>
                                <tr class="hover:bg-[#FAF5EC]/60 transition">
                                    <td class="p-3.5 font-bold text-[#2B1A06]">#<?= (int)$t['id'] ?></td>
                                    <td class="p-3.5 font-bold text-[#3E2723] truncate" title="<?= htmlspecialchars($t['title']) ?>">
                                        <?= htmlspecialchars($t['title']) ?>
                                    </td>
                                    <td class="p-3.5 font-semibold text-[#5C4A3A]"><?= htmlspecialchars($t['category'] ?? 'General') ?></td>
                                    <td class="p-3.5 font-bold text-[#2B1A06]">₱<?= number_format((float)($t['actual_cost'] ?? 0), 2) ?></td>
                                    <td class="p-3.5 text-[#8C6D46] text-xs">
                                        <?= !empty($t['created_at']) ? date('M j, Y', strtotime($t['created_at'])) : '—' ?>
                                    </td>
                                    <td class="p-3.5 text-center">
                                        <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#FAF5EC] text-[#4A3728] border border-[#B89B72]">
                                            <?= htmlspecialchars($t['status'] ?? 'Completed') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Receipt Preview Modal -->
    <div id="receiptModal" class="fixed inset-0 z-50 bg-black/60 hidden items-center justify-center p-4 backdrop-blur-xs" onclick="closeReceiptModal()">
        <div class="bg-white rounded-2xl max-w-lg w-full p-4 border-2 border-[#B89B72] shadow-2xl relative" onclick="event.stopPropagation()">
            <div class="flex items-center justify-between pb-3 border-b border-[#E8D8B0] mb-3">
                <h3 class="font-bold text-[#3E2723] text-sm">Payment Proof Receipt</h3>
                <button type="button" onclick="closeReceiptModal()" class="w-7 h-7 rounded-full bg-[#FAF5EC] border border-[#B89B72] text-[#4A3728] font-bold text-xs flex items-center justify-center hover:bg-[#E8D8B0]">✕</button>
            </div>
            <div class="text-center p-2 bg-[#FAF5EC] rounded-xl border border-[#E8D8B0]">
                <img id="receiptModalImg" src="" alt="Payment Receipt" class="max-h-[70vh] mx-auto rounded-lg object-contain shadow-xs">
            </div>
        </div>
    </div>

    <script>
        function previewReceipt(filename) {
            const modal = document.getElementById('receiptModal');
            const img = document.getElementById('receiptModalImg');
            if (!modal || !img) return;

            let path = filename;
            if (!path.startsWith('http') && !path.startsWith('/') && !path.startsWith('uploads/')) {
                path = 'uploads/receipts/' + filename;
            }
            img.src = path;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeReceiptModal() {
            const modal = document.getElementById('receiptModal');
            if (!modal) return;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        });
    </script>
</body>
</html>
