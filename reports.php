<?php
/**
 * AutoCare - Financial & Operational Reports
 * Strictly Admin Only - Real Aggregated MySQL Metrics
 */
require_once __DIR__ . '/config.php';
require_auth();
require_permission('view_reports');

$pdo = db();
$user = current_user();
$theme = current_theme();

// Date range filters (default to current month)
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate   = $_GET['to_date']   ?? date('Y-m-d');

// SQL with date range parameters
$fromDateTime = $fromDate . ' 00:00:00';
$toDateTime   = $toDate . ' 23:59:59';

// 1. Financial totals in date range
$finStmt = $pdo->prepare("
    SELECT 
        COUNT(id) AS total_invoices,
        COALESCE(SUM(total_amount), 0) AS total_revenue,
        COALESCE(SUM(paid_amount), 0) AS total_collected,
        COALESCE(SUM(discount), 0) AS total_discounts
    FROM invoices
    WHERE created_at BETWEEN :f AND :t
");
$finStmt->execute([':f' => $fromDateTime, ':t' => $toDateTime]);
$fin = $finStmt->fetch();
$fin['outstanding'] = max(0, $fin['total_revenue'] - $fin['total_collected']);

// 2. Work Orders summary in date range
$woStmt = $pdo->prepare("
    SELECT 
        COUNT(id) AS total_orders,
        SUM(CASE WHEN status IN ('new', 'inspecting', 'in_progress') THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN status IN ('completed', 'delivered') THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders
    FROM work_orders
    WHERE created_at BETWEEN :f AND :t
");
$woStmt->execute([':f' => $fromDateTime, ':t' => $toDateTime]);
$woStats = $woStmt->fetch();

// 3. Payments breakdown by method
$payStmt = $pdo->prepare("
    SELECT 
        payment_method,
        COUNT(id) AS count,
        COALESCE(SUM(amount), 0) AS total
    FROM payments
    WHERE created_at BETWEEN :f AND :t
    GROUP BY payment_method
");
$payStmt->execute([':f' => $fromDateTime, ':t' => $toDateTime]);
$paymentMethods = $payStmt->fetchAll();

// 4. Most requested services in date range
$srvStmt = $pdo->prepare("
    SELECT 
        s.name AS service_name,
        COUNT(wo.id) AS requests_count,
        COALESCE(SUM(wo.actual_cost), 0) AS generated_revenue
    FROM work_orders wo
    JOIN services s ON wo.service_id = s.id
    WHERE wo.created_at BETWEEN :f AND :t
    GROUP BY s.id, s.name
    ORDER BY requests_count DESC
    LIMIT 5
");
$srvStmt->execute([':f' => $fromDateTime, ':t' => $toDateTime]);
$topServices = $srvStmt->fetchAll();

// 5. Audit logs in date range
ensure_audit_logs_table();
$auditStmt = $pdo->prepare("
    SELECT * 
    FROM audit_logs 
    WHERE created_at BETWEEN :f AND :t 
    ORDER BY id DESC 
    LIMIT 50
");
$auditStmt->execute([':f' => $fromDateTime, ':t' => $toDateTime]);
$recentAuditLogs = $auditStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - التقارير الإدارية والمالية</title>
    <script>
    (function() {
        var saved = localStorage.getItem('autocare_theme');
        if (!saved) {
            var match = document.cookie.match(new RegExp('(^| )autocare_theme=([^;]+)'));
            if (match) saved = match[2];
        }
        if (saved === 'light' || saved === 'dark') {
            document.documentElement.setAttribute('data-theme', saved);
        }
    })();
    </script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-body">
    <div class="dashboard-wrapper">
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="appSidebar">
            <div class="sidebar-brand">
                <a href="dashboard.php" class="brand-link">
                    <span class="brand-icon">⚡</span>
                    <span class="brand-title">AutoCare</span>
                </a>
                <button type="button" class="mobile-sidebar-close" onclick="toggleSidebar()">✕</button>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item"><span class="icon">📊</span> لوحة المؤشرات</a>
                <?php if ($user['role'] === 'admin' || has_permission('manage_employees')): ?>
                    <a href="employees.php" class="nav-item"><span class="icon">👥</span> إدارة الموظفين</a>
                <?php endif; ?>
                <?php if (has_permission('manage_customers')): ?>
                    <a href="customers.php" class="nav-item"><span class="icon">👤</span> إدارة العملاء</a>
                <?php endif; ?>
                <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> إدارة السيارات</a>
                <?php if (has_permission('manage_services')): ?>
                    <a href="services.php" class="nav-item"><span class="icon">🛠️</span> قائمة الخدمات</a>
                <?php endif; ?>
                <a href="work_orders.php" class="nav-item"><span class="icon">📋</span> أوامر الصيانة</a>
                <a href="invoices.php" class="nav-item"><span class="icon">🧾</span> الفواتير</a>
                <a href="reports.php" class="nav-item active"><span class="icon">📈</span> التقارير المالية</a>
                <?php if (has_permission('manage_users')): ?>
                    <?php if (has_permission('manage_admins')): ?>
                    <a href="admins.php" class="nav-item"><span class="icon">👑</span> إدارة المدراء</a>
                <?php endif; ?>

                <a href="permissions.php" class="nav-item"><span class="icon">🛡️</span> مصفوفة الصلاحيات</a>
                <?php if (has_permission('manage_password_reset_requests')): ?><a href="password_requests.php" class="nav-item"><span class="icon">🔐</span> طلبات استعادة كلمة المرور</a><?php endif; ?>
                <?php endif; ?>
                <a href="profile.php" class="nav-item"><span class="icon">⚙️</span> حسابي الشخصي</a>
                <a href="logout.php" class="nav-item logout-link"><span class="icon">🚪</span> خروج</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <button type="button" class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                    <h2 class="page-title">التقارير التحليلية والمالية</h2>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                        <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                        
                    </button>
                    <button onclick="window.print()" class="btn btn-outline btn-sm">🖨️ طباعة التقرير</button>
                </div>
            </header>

            <div class="content-container">
                <!-- Date Filter Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h3>تحديد الفترة الزمنية للتقرير</h3>
                    </div>
                    <form method="GET" action="reports.php" class="card-body form-grid">
                        <div class="grid grid-3">
                            <div class="form-group">
                                <label for="from_date">من تاريخ</label>
                                <input type="date" id="from_date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>">
                            </div>
                            <div class="form-group">
                                <label for="to_date">إلى تاريخ</label>
                                <input type="date" id="to_date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>">
                            </div>
                            <div class="form-group flex-align-end">
                                <button type="submit" class="btn btn-primary btn-block">توليد التقرير</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Financial Summary Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-info">
                            <span class="stat-label">إجمالي المبيعات (الفواتير)</span>
                            <span class="stat-val text-success"><?= format_money($fin['total_revenue']) ?></span>
                        </div>
                        <div class="stat-card-icon icon-green">💰</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <span class="stat-label">المبالغ المحصلة</span>
                            <span class="stat-val text-info"><?= format_money($fin['total_collected']) ?></span>
                        </div>
                        <div class="stat-card-icon icon-teal">💳</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <span class="stat-label">الذمم والديون المتبقية</span>
                            <span class="stat-val text-danger"><?= format_money($fin['outstanding']) ?></span>
                        </div>
                        <div class="stat-card-icon icon-red">⏳</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-info">
                            <span class="stat-label">عدد الفواتير الصادرة</span>
                            <span class="stat-val"><?= $fin['total_invoices'] ?></span>
                        </div>
                        <div class="stat-card-icon icon-purple">🧾</div>
                    </div>
                </div>

                <!-- Operations Breakdown Grid -->
                <div class="grid grid-2 mt-4">
                    <!-- Work Orders in Period -->
                    <div class="card">
                        <div class="card-header">
                            <h4>أداء أوامر الصيانة خلال الفترة</h4>
                        </div>
                        <div class="card-body">
                            <div class="report-metric-row">
                                <span>إجمالي الأوامر المستلمة:</span>
                                <strong><?= (int)$woStats['total_orders'] ?></strong>
                            </div>
                            <div class="report-metric-row">
                                <span>أوامر نشطة قيد العمل:</span>
                                <strong class="text-warning"><?= (int)$woStats['active_orders'] ?></strong>
                            </div>
                            <div class="report-metric-row">
                                <span>أوامر تم إنجازها وتسليمها:</span>
                                <strong class="text-success"><?= (int)$woStats['completed_orders'] ?></strong>
                            </div>
                            <div class="report-metric-row">
                                <span>أوامر ملغاة:</span>
                                <strong class="text-danger"><?= (int)$woStats['cancelled_orders'] ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Payment methods breakdown -->
                    <div class="card">
                        <div class="card-header">
                            <h4>طرق الدفع والتحصيل</h4>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($paymentMethods)): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>طريقة الدفع</th>
                                            <th>عدد العمليات</th>
                                            <th>المجموع</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paymentMethods as $pm): ?>
                                            <tr>
                                                <td><strong><?= $pm['payment_method'] === 'cash' ? '💵 نقدي (Cash)' : ($pm['payment_method'] === 'card' ? '💳 مدى / بطاقة (Card)' : '🏦 تحويل بنكي') ?></strong></td>
                                                <td><?= $pm['count'] ?></td>
                                                <td><strong class="text-success"><?= format_money($pm['total']) ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p class="text-muted text-center py-4">لم يتم تسجيل مقبوضات خلال هذه الفترة.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Requested Services -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h4>أكثر خدمات الصيانة طلباً خلال الفترة</h4>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>اسم الخدمة</th>
                                    <th>عدد مرات الطلب</th>
                                    <th>العائد المالي المحقق</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($topServices)): ?>
                                    <?php foreach ($topServices as $ts): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($ts['service_name']) ?></strong></td>
                                            <td><span class="badge badge-info"><?= $ts['requests_count'] ?> مرات</span></td>
                                            <td><strong class="text-success"><?= format_money($ts['generated_revenue']) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center py-4 text-muted">لا توجد بيانات خدمات مسجلة في هذا النطاق الزمني.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Audit Logs (سجل تدقيق ومراقبة العمليات) -->
                <div class="card mt-4">
                    <div class="card-header flex-between">
                        <h4>سجل تدقيق ومراقبة العمليات (Audit Logs)</h4>
                        <span class="badge badge-secondary">آخر <?= count($recentAuditLogs) ?> عملية</span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>الوقت والتاريخ</th>
                                    <th>المستخدم</th>
                                    <th>الدور</th>
                                    <th>العملية</th>
                                    <th>الجدول</th>
                                    <th>التفاصيل والبيان</th>
                                    <th>عنوان IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentAuditLogs)): ?>
                                    <?php foreach ($recentAuditLogs as $log): ?>
                                        <tr>
                                            <td><small class="text-muted"><?= htmlspecialchars($log['created_at']) ?></small></td>
                                            <td><strong><?= htmlspecialchars($log['user_name'] ?: 'النظام') ?></strong></td>
                                            <td><span class="badge badge-secondary"><?= htmlspecialchars($log['user_role'] ?: '-') ?></span></td>
                                            <td><span class="badge badge-primary"><?= htmlspecialchars($log['action']) ?></span></td>
                                            <td><code><?= htmlspecialchars($log['table_name']) ?></code></td>
                                            <td><?= htmlspecialchars($log['description'] ?? '-') ?></td>
                                            <td><small><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1') ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">لا توجد سجلات تدقيق مسجلة في هذا النطاق الزمني.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="app.js"></script>
</body>
</html>
