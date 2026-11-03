<?php
/**
 * AutoCare - Main System Dashboard (Role-Based: Admin / Employee / Customer)
 */
require_once __DIR__ . '/config.php';
require_auth();

$user = current_user();
$pdo = db();
$flash = get_flash();

// ==========================================
// 1. ADMIN DASHBOARD DATA
// ==========================================
if ($user['role'] === 'admin') {
    // Exact SQL aggregation (Zero dummy data)
    $stats = [
        'customers'   => (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
        'employees'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'employee' AND status <> 'deleted'")->fetchColumn(),
        'vehicles'    => (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn(),
        'orders'      => (int)$pdo->query("SELECT COUNT(*) FROM work_orders")->fetchColumn(),
        'open_orders' => (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE status IN ('new','inspecting','in_progress')")->fetchColumn(),
        'done_orders' => (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE status IN ('completed','delivered')")->fetchColumn(),
        'invoices'    => (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn(),
        'revenue'     => (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices")->fetchColumn(),
        'paid'        => (float)$pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM invoices")->fetchColumn(),
    ];
    $stats['pending_balance'] = max(0, $stats['revenue'] - $stats['paid']);

    // Recent work orders
    $recentOrders = $pdo->query("
        SELECT wo.*, c.full_name AS customer_name, v.plate_number, v.make, v.model, s.name AS service_name, u.name AS tech_name
        FROM work_orders wo
        JOIN customers c ON wo.customer_id = c.id
        JOIN vehicles v ON wo.vehicle_id = v.id
        LEFT JOIN services s ON wo.service_id = s.id
        LEFT JOIN users u ON wo.assigned_employee_id = u.id
        ORDER BY wo.id DESC LIMIT 5
    ")->fetchAll();

    // Recent notifications
    $recentNotifications = [];
    try {
        ensure_notifications_table();
        $ns = $pdo->prepare("SELECT * FROM notifications WHERE is_read=0 AND (target_role='all_staff' OR target_role=:role) AND (user_id IS NULL OR user_id=:uid) ORDER BY id DESC LIMIT 5");
        $ns->execute([':role'=>'admin', ':uid'=>$user['id']]);
        $recentNotifications = $ns->fetchAll();
    } catch (Exception $e) {}
}

// ==========================================
// 2. EMPLOYEE DASHBOARD DATA
// ==========================================
elseif ($user['role'] === 'employee') {
    $empId = $user['id'];
    $stats = [
        'my_orders'     => (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE assigned_employee_id = $empId")->fetchColumn(),
        'my_active'     => (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE assigned_employee_id = $empId AND status IN ('new','inspecting','in_progress')")->fetchColumn(),
        'my_completed'  => (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE assigned_employee_id = $empId AND status IN ('completed','delivered')")->fetchColumn(),
        'all_completed' => (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE status IN ('completed','delivered')")->fetchColumn(),
        'total_vehicles'=> (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn(),
    ];

    // Work orders for employee: assigned to him OR recently completed by the center
    $recentOrders = $pdo->prepare("
        SELECT wo.*, c.full_name AS customer_name, v.plate_number, v.make, v.model, s.name AS service_name, u.name AS tech_name
        FROM work_orders wo
        JOIN customers c ON wo.customer_id = c.id
        JOIN vehicles v ON wo.vehicle_id = v.id
        LEFT JOIN services s ON wo.service_id = s.id
        LEFT JOIN users u ON wo.assigned_employee_id = u.id
        WHERE wo.assigned_employee_id = :empid OR wo.status IN ('completed', 'delivered')
        ORDER BY wo.id DESC LIMIT 10
    ");
    $recentOrders->execute([':empid' => $empId]);
    $recentOrders = $recentOrders->fetchAll();

    $recentNotifications = [];
    try {
        ensure_notifications_table();
        $ns = $pdo->prepare("SELECT * FROM notifications WHERE is_read=0 AND (target_role IN ('employee','all_staff')) AND (user_id IS NULL OR user_id=:uid) ORDER BY id DESC LIMIT 5");
        $ns->execute([':uid'=>$user['id']]);
        $recentNotifications = $ns->fetchAll();
    } catch (Exception $e) {}
}

// ==========================================
// 3. CUSTOMER DASHBOARD DATA
// ==========================================
else {
    $userId = $user['id'];
    // Resolve customer ID
    $cStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = :uid LIMIT 1");
    $cStmt->execute([':uid' => $userId]);
    $custId = (int)$cStmt->fetchColumn();

    $stats = [
        'my_vehicles'   => 0,
        'active_orders' => 0,
        'total_invoices'=> 0,
        'unpaid_balance'=> 0.00
    ];

    $myVehicles = [];
    $myOrders = [];
    $myInvoices = [];

    if ($custId > 0) {
        $stats['my_vehicles']   = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE customer_id = $custId")->fetchColumn();
        $stats['active_orders'] = (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE customer_id = $custId AND status IN ('new','inspecting','in_progress')")->fetchColumn();
        $stats['total_invoices']= (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE customer_id = $custId")->fetchColumn();
        $due = (float)$pdo->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM invoices WHERE customer_id = $custId")->fetchColumn();
        $stats['unpaid_balance'] = max(0, $due);

        // Fetch user vehicles
        $vStmt = $pdo->prepare("SELECT * FROM vehicles WHERE customer_id = :cid ORDER BY id DESC");
        $vStmt->execute([':cid' => $custId]);
        $myVehicles = $vStmt->fetchAll();

        // Fetch user work orders
        $oStmt = $pdo->prepare("
            SELECT wo.*, v.plate_number, v.make, v.model, s.name AS service_name
            FROM work_orders wo
            JOIN vehicles v ON wo.vehicle_id = v.id
            LEFT JOIN services s ON wo.service_id = s.id
            WHERE wo.customer_id = :cid
            ORDER BY wo.id DESC
        ");
        $oStmt->execute([':cid' => $custId]);
        $myOrders = $oStmt->fetchAll();

        // Fetch customer invoices
        $iStmt = $pdo->prepare("
            SELECT i.*, v.plate_number, v.make, v.model
            FROM invoices i
            JOIN vehicles v ON i.vehicle_id = v.id
            WHERE i.customer_id = :cid
            ORDER BY i.id DESC LIMIT 5
        ");
        $iStmt->execute([':cid' => $custId]);
        $myInvoices = $iStmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars(current_theme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - لوحة التحكم</title>
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

            <div class="user-profile-badge">
                <div class="avatar-circle"><?= mb_substr($user['name'], 0, 1) ?></div>
                <div class="user-meta">
                    <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="user-role-tag"><?= $user['role'] === 'admin' ? 'المدير العام' : ($user['role'] === 'employee' ? 'موظف / فني' : 'عميل المركز') ?></div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item active"><span class="icon">📊</span> لوحة المؤشرات</a>
                
                <?php if ($user['role'] === 'admin' || has_permission('manage_employees')): ?>
                    <a href="employees.php" class="nav-item"><span class="icon">👥</span> إدارة الموظفين</a>
                <?php endif; ?>

                <?php if ($user['role'] === 'admin' || has_permission('manage_customers')): ?>
                    <a href="customers.php" class="nav-item"><span class="icon">👤</span> إدارة العملاء</a>
                <?php endif; ?>

                <?php if (has_permission('manage_vehicles') || has_permission('view_own_data')): ?>
                    <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> <?= $user['role'] === 'customer' ? 'سياراتي' : 'إدارة السيارات' ?></a>
                <?php endif; ?>
                
                <?php if ($user['role'] === 'admin' || has_permission('manage_services')): ?>
                    <a href="services.php" class="nav-item"><span class="icon">🛠️</span> قائمة الخدمات</a>
                <?php endif; ?>

                <?php if (has_permission('view_work_orders') || has_permission('create_work_orders')): ?>
                    <a href="work_orders.php" class="nav-item"><span class="icon">📋</span> <?= $user['role'] === 'customer' ? 'طلبات الصيانة' : 'أوامر الصيانة' ?></a>
                <?php endif; ?>

                <?php if (has_permission('manage_invoices') || has_permission('view_own_data')): ?>
                    <a href="invoices.php" class="nav-item"><span class="icon">🧾</span> <?= $user['role'] === 'customer' ? 'فواتيري' : 'الفواتير والمدفوعات' ?></a>
                <?php endif; ?>

                <?php if ($user['role'] === 'admin' || has_permission('view_reports')): ?>
                    <a href="reports.php" class="nav-item"><span class="icon">📈</span> التقارير المالية</a>
                <?php endif; ?>
                <?php if (has_permission('manage_appointments')): ?>
                    <a href="appointments.php" class="nav-item"><span class="icon">📅</span> المواعيد</a>
                <?php endif; ?>
                <?php if (has_permission('manage_inventory')): ?>
                    <a href="inventory.php" class="nav-item"><span class="icon">🔧</span> قطع الغيار والمخزون</a>
                <?php endif; ?>
                <?php if (has_permission('manage_backups')): ?>
                    <a href="backup.php" class="nav-item"><span class="icon">💾</span> النسخ الاحتياطي</a>
                <?php endif; ?>
                <?php if ($user['role'] === 'admin'): ?><a href="recycle_bin.php" class="nav-item"><span class="icon">🗑️</span> سلة المحذوفات</a><?php endif; ?>

                <?php if (has_permission('manage_admins')): ?>
                    <a href="admins.php" class="nav-item"><span class="icon">👑</span> إدارة المدراء</a>
                <?php endif; ?>
                <?php if ($user['role'] === 'admin' && current_is_primary_admin()): ?>
                    <a href="permissions.php" class="nav-item"><span class="icon">🛡️</span> مصفوفة الصلاحيات</a>
                <?php endif; ?>
                <?php if (has_permission('manage_password_reset_requests')): ?><a href="password_requests.php" class="nav-item"><span class="icon">🔐</span> طلبات استعادة كلمة المرور</a><?php endif; ?>

                <a href="profile.php" class="nav-item"><span class="icon">⚙️</span> حسابي الشخصي</a>
                <a href="logout.php" class="nav-item logout-link"><span class="icon">🚪</span> تسجيل الخروج</a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Topbar -->
            <header class="topbar">
                <div class="topbar-left">
                    <button type="button" class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                    <h2 class="page-title">
                        <?php if ($user['role'] === 'admin'): ?>
                            لوحة الإدارة العامة
                        <?php elseif ($user['role'] === 'employee'): ?>
                            لوحة متابعة مهام الصيانة
                        <?php else: ?>
                            لوحة حساب العميل
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                        <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                        
                    </button>
                    <span class="system-time">📅 <?= date('Y-m-d') ?></span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm">خروج</a>
                </div>
            </header>

            <div class="content-container">
                <?php if ($flash): ?>
                    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
                <?php endif; ?>

                <!-- ================= ADMIN DASHBOARD ================= -->
                <?php if ($user['role'] === 'admin'): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">إجمالي العملاء</span>
                                <span class="stat-val"><?= $stats['customers'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-blue">👤</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">طاقم الموظفين</span>
                                <span class="stat-val"><?= $stats['employees'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-purple">👥</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">السيارات المسجلة</span>
                                <span class="stat-val"><?= $stats['vehicles'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-amber">🚗</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">أوامر الصيانة الحالية</span>
                                <span class="stat-val"><?= $stats['open_orders'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-cyan">📋</div>
                        </div>
                    </div>

                    <div class="stats-grid mt-3">
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">إجمالي الإيرادات</span>
                                <span class="stat-val text-success"><?= format_money($stats['revenue']) ?></span>
                            </div>
                            <div class="stat-card-icon icon-green">💰</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">المبالغ المحصلة</span>
                                <span class="stat-val text-info"><?= format_money($stats['paid']) ?></span>
                            </div>
                            <div class="stat-card-icon icon-teal">💳</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">المبالغ المتبقية</span>
                                <span class="stat-val text-danger"><?= format_money($stats['pending_balance']) ?></span>
                            </div>
                            <div class="stat-card-icon icon-red">⏳</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">أوامر مكتملة ومسلمة</span>
                                <span class="stat-val"><?= $stats['done_orders'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-emerald">✅</div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="quick-actions-bar mt-4">
                        <a href="employees.php" class="btn btn-secondary">➕ إضافة موظف جديد</a>
                        <a href="customers.php" class="btn btn-secondary">➕ تسجيل عميل</a>
                        <a href="vehicles.php" class="btn btn-secondary">➕ إضافة سيارة</a>
                        <a href="work_orders.php" class="btn btn-primary">➕ أمر صيانة جديد</a>
                        <a href="services.php" class="btn btn-secondary">➕ خدمة صيانة</a>
                    </div>

                    <!-- Recent Orders Table -->
                    <div class="card mt-4">
                        <div class="card-header flex-between">
                            <h3>أحدث أوامر الصيانة</h3>
                            <a href="work_orders.php" class="btn btn-outline btn-sm">عرض كل الأوامر</a>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>رقم الأمر</th>
                                        <th>العميل</th>
                                        <th>السيارة</th>
                                        <th>الخدمة المطلوبة</th>
                                        <th>الفني المسؤول</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                        <th>الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentOrders)): ?>
                                        <?php foreach ($recentOrders as $order): ?>
                                            <tr>
                                                <td><strong>#<?= htmlspecialchars($order['order_number']) ?></strong></td>
                                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                                <td><?= htmlspecialchars($order['make'] . ' ' . $order['model'] . ' (' . $order['plate_number'] . ')') ?></td>
                                                <td><?= htmlspecialchars($order['service_name'] ?: 'فحص عام وتصليح') ?></td>
                                                <td><?= htmlspecialchars($order['tech_name'] ?: 'غير معين') ?></td>
                                                <td><?= status_badge($order['status']) ?></td>
                                                <td><?= htmlspecialchars(substr($order['created_at'], 0, 10)) ?></td>
                                                <td>
                                                    <a href="work_orders.php?view=<?= $order['id'] ?>" class="btn btn-sm btn-outline">تفاصيل</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">لا توجد أوامر صيانة مسجلة حتى الآن.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if (!empty($recentNotifications)): ?>
                        <!-- Admin Notifications -->
                        <div class="card mt-4">
                            <div class="card-header flex-between">
                                <h3>🔔 أحدث إشعارات المدفوعات والعمليات</h3>
                                <span class="badge badge-info"><?= count($recentNotifications) ?> إشعار</span>
                            </div>
                            <div class="card-body">
                                <ul class="payment-receipt-list">
                                    <?php foreach ($recentNotifications as $notif): ?>
                                        <li class="py-2" style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                            <div>
                                                <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                                <div class="text-muted small"><?= htmlspecialchars($notif['message']) ?></div>
                                            </div>
                                            <span class="text-muted small"><?= htmlspecialchars(substr($notif['created_at'], 0, 16)) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                <!-- ================= EMPLOYEE DASHBOARD ================= -->
                <?php elseif ($user['role'] === 'employee'): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">أوامري المسندة</span>
                                <span class="stat-val"><?= $stats['my_orders'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-blue">📋</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">قيد الصيانة والفحص</span>
                                <span class="stat-val text-warning"><?= $stats['my_active'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-amber">⚙️</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">صيانات أنجزتها</span>
                                <span class="stat-val text-success"><?= $stats['my_completed'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-green">✅</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">إجمالي السيارات المنجزة بالمركز</span>
                                <span class="stat-val text-primary"><?= $stats['all_completed'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-green">🚗</div>
                        </div>
                    </div>

                    <div class="card mt-4">
                        <div class="card-header flex-between">
                            <h3>أوامر العمل الخاصة بي وحالات الصيانة المكتملة</h3>
                            <a href="work_orders.php" class="btn btn-outline btn-sm">جميع أوامر الصيانة</a>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>رقم الأمر</th>
                                        <th>العميل</th>
                                        <th>السيارة</th>
                                        <th>الفني المسؤول</th>
                                        <th>وصف العطل</th>
                                        <th>الحالة</th>
                                        <th>الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentOrders)): ?>
                                        <?php foreach ($recentOrders as $order): ?>
                                            <tr>
                                                <td><strong>#<?= htmlspecialchars($order['order_number']) ?></strong></td>
                                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                                <td><?= htmlspecialchars($order['make'] . ' ' . $order['model'] . ' (' . $order['plate_number'] . ')') ?></td>
                                                <td><span class="badge badge-secondary"><?= htmlspecialchars($order['tech_name'] ?: $user['name']) ?></span></td>
                                                <td><?= htmlspecialchars(mb_strimwidth($order['issue_description'], 0, 40, '...')) ?></td>
                                                <td>
                                                    <?php if (in_array($order['status'], ['completed', 'delivered'], true)): ?>
                                                        <span class="badge badge-success">✅ تم إنجاز وصيانة السيارة</span>
                                                    <?php else: ?>
                                                        <?= status_badge($order['status']) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="work_orders.php?view=<?= $order['id'] ?>" class="btn btn-sm <?= in_array($order['status'], ['completed', 'delivered'], true) ? 'btn-outline' : 'btn-primary' ?>">
                                                        <?= in_array($order['status'], ['completed', 'delivered'], true) ? 'عرض التفاصيل' : 'تحديث الحالة' ?>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">لا توجد أوامر صيانة مسندة لك أو مكتملة حالياً.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if (!empty($recentNotifications)): ?>
                        <!-- Employee Notifications -->
                        <div class="card mt-4">
                            <div class="card-header flex-between">
                                <h3>🔔 أحدث إشعارات المدفوعات والعمليات</h3>
                                <span class="badge badge-info"><?= count($recentNotifications) ?> إشعار</span>
                            </div>
                            <div class="card-body">
                                <ul class="payment-receipt-list">
                                    <?php foreach ($recentNotifications as $notif): ?>
                                        <li class="py-2" style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                            <div>
                                                <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                                <div class="text-muted small"><?= htmlspecialchars($notif['message']) ?></div>
                                            </div>
                                            <span class="text-muted small"><?= htmlspecialchars(substr($notif['created_at'], 0, 16)) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                <!-- ================= CUSTOMER DASHBOARD ================= -->
                <?php else: ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">سياراتي المسجلة</span>
                                <span class="stat-val"><?= $stats['my_vehicles'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-blue">🚗</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">سيارات قيد الصيانة</span>
                                <span class="stat-val text-warning"><?= $stats['active_orders'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-amber">🔧</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">إجمالي فواتيري</span>
                                <span class="stat-val"><?= $stats['total_invoices'] ?></span>
                            </div>
                            <div class="stat-card-icon icon-purple">🧾</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">الرصيد المستحق</span>
                                <span class="stat-val <?= $stats['unpaid_balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= format_money($stats['unpaid_balance']) ?></span>
                            </div>
                            <div class="stat-card-icon icon-green">💳</div>
                        </div>
                    </div>

                    <div class="grid grid-2 mt-4">
                        <!-- Customer Vehicles -->
                        <div class="card">
                            <div class="card-header flex-between">
                                <h3>سياراتي</h3>
                                <a href="vehicles.php" class="btn btn-sm btn-primary">➕ إضافة سيارة</a>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($myVehicles)): ?>
                                    <div class="vehicle-list">
                                        <?php foreach ($myVehicles as $v): ?>
                                            <div class="vehicle-item">
                                                <div class="v-icon">🚘</div>
                                                <div class="v-details">
                                                    <strong><?= htmlspecialchars($v['make'] . ' ' . $v['model'] . ' (' . $v['year'] . ')') ?></strong>
                                                    <span class="v-plate">لوحة: <?= htmlspecialchars($v['plate_number'] . ' ' . ($v['plate_letters'] ?? '')) ?></span>
                                                </div>
                                                <span class="badge badge-secondary"><?= htmlspecialchars($v['color'] ?: 'لون غير محدد') ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">لم تقم بإضافة أي سيارة حتى الآن. <a href="vehicles.php">أضف سيارتك الأولى الآن</a>.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Customer Invoices -->
                        <div class="card">
                            <div class="card-header flex-between">
                                <h3>فواتيري الأخيرة</h3>
                                <a href="invoices.php" class="btn btn-sm btn-outline">جميع الفواتير</a>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($myInvoices)): ?>
                                    <div class="invoice-list">
                                        <?php foreach ($myInvoices as $inv): ?>
                                            <div class="invoice-row-mini">
                                                <div>
                                                    <strong>#<?= htmlspecialchars($inv['invoice_number']) ?></strong>
                                                    <div class="text-muted small"><?= htmlspecialchars($inv['make'] . ' ' . $inv['model']) ?></div>
                                                </div>
                                                <div class="text-left">
                                                    <div><strong><?= format_money($inv['total_amount']) ?></strong></div>
                                                    <?= status_badge($inv['status']) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">لا توجد فواتير مصدرة لحسابك حتى الآن.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Customer Active Work Orders -->
                    <div class="card mt-4">
                        <div class="card-header flex-between">
                            <h3>سجل صيانة سياراتي</h3>
                            <div class="flex-gap">
                                <a href="work_orders.php" class="btn btn-sm btn-primary">➕ طلب صيانة جديد</a>
                                <a href="work_orders.php" class="btn btn-sm btn-outline">عرض السجل الكامل</a>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>رقم الطلب</th>
                                        <th>السيارة</th>
                                        <th>الخدمة / المشكلة</th>
                                        <th>حالة الصيانة</th>
                                        <th>التكلفة الفعلية</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($myOrders)): ?>
                                        <?php foreach ($myOrders as $ord): ?>
                                            <tr>
                                                <td><strong>#<?= htmlspecialchars($ord['order_number']) ?></strong></td>
                                                <td><?= htmlspecialchars($ord['make'] . ' ' . $ord['model'] . ' (' . $ord['plate_number'] . ')') ?></td>
                                                <td><?= htmlspecialchars($ord['service_name'] ?: $ord['issue_description']) ?></td>
                                                <td><?= status_badge($ord['status']) ?></td>
                                                <td><?= $ord['actual_cost'] > 0 ? format_money($ord['actual_cost']) : 'قيد التحديد' ?></td>
                                                <td><?= htmlspecialchars(substr($ord['created_at'], 0, 10)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">لا توجد طلبات صيانة حالية لسياراتك.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="app.js"></script>
</body>
</html>
