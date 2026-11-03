<?php
/**
 * AutoCare - Customer Management & Profiles
 * Strict RBAC & IDOR Ownership Protection
 */
require_once __DIR__ . '/config.php';
require_auth();

$user = current_user();
$pdo = db();
$error = '';
$flash = get_flash();
$theme = current_theme();

// ===================================================
// IDOR / ACCESS CONTROL CHECK:
// Customers can only see their own customer profile
// ===================================================
$isStaff = in_array($user['role'], ['admin', 'employee'], true);
$myCustomerId = 0;

if (!$isStaff) {
    require_permission('view_own_data');
    // Resolve logged in customer's ID
    $cStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = :uid LIMIT 1");
    $cStmt->execute([':uid' => $user['id']]);
    $myCustomerId = (int)$cStmt->fetchColumn();
    if ($myCustomerId === 0) {
        die("لم يتم العثور على ملف العميل الخاص بحسابك.");
    }
} else {
    require_permission('manage_customers');
}

// ===================================================
// Handle POST Actions (Create / Update / Delete)
// ===================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. APPROVE SELF-REGISTERED CUSTOMER ACCOUNT
    if ($action === 'approve_account') {
        if ($user['role'] !== 'admin') { http_response_code(403); die('غير مصرح لك بالموافقة على حسابات العملاء.'); }
        require_permission('manage_customers');
        $targetId = (int)($_POST['customer_id'] ?? 0);
        if ($targetId > 0) {
            $stmt = $pdo->prepare("
                UPDATE users u
                JOIN customers c ON c.user_id = u.id
                SET u.status = 'active'
                WHERE c.id = :cid AND u.role = 'customer' AND u.status = 'pending'
            ");
            $stmt->execute([':cid' => $targetId]);
            if ($stmt->rowCount() > 0) {
                log_audit('approve', 'users', $targetId, 'الموافقة على حساب العميل');
                set_flash('success', 'تمت الموافقة على حساب العميل ويمكنه تسجيل الدخول الآن.');
            }
            header('Location: customers.php');
            exit;
        }
    }

    // 2. ADD CUSTOMER
    elseif ($action === 'add') {
        require_permission('manage_customers');
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $address  = trim($_POST['address'] ?? '');

        if (empty($fullName) || empty($phone)) {
            $error = 'الاسم الكامل ورقم الهاتف حقول إلزامية.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (full_name, phone, email, address)
                    VALUES (:name, :phone, :email, :address)
                ");
                $stmt->execute([
                    ':name'    => $fullName,
                    ':phone'   => $phone,
                    ':email'   => $email ?: null,
                    ':address' => $address ?: null,
                ]);
                $newCustId = (int)$pdo->lastInsertId();
                log_audit('add', 'customers', $newCustId, "تسجيل عميل جديد: {$fullName} ({$phone})");
                set_flash('success', 'تم تسجيل العميل بنجاح.');
                header('Location: customers.php');
                exit;
            } catch (PDOException $e) {
                $error = 'خطأ في حفظ العميل: ' . $e->getMessage();
            }
        }
    }

    // 2. EDIT CUSTOMER (Staff with manage_customers or Customer editing self)
    elseif ($action === 'edit') {
        $targetId = (int)($_POST['customer_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $address  = trim($_POST['address'] ?? '');

        // IDOR Check: Customer can only edit targetId if targetId == myCustomerId
        if (!$isStaff) {
            require_permission('view_own_data');
            if ($targetId !== $myCustomerId) {
                http_response_code(403);
                die("غير مصرح لك بتعديل بيانات عميل آخر.");
            }
        } else {
            require_permission('manage_customers');
        }

        if (empty($fullName) || empty($phone) || $targetId <= 0) {
            $error = 'يرجى تعبئة الحقول الأساسية بشكل صحيح.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET full_name = :name, phone = :phone, email = :email, address = :address
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name'    => $fullName,
                    ':phone'   => $phone,
                    ':email'   => $email ?: null,
                    ':address' => $address ?: null,
                    ':id'      => $targetId
                ]);
                log_audit('edit', 'customers', $targetId, "تعديل بيانات العميل: {$fullName}");
                set_flash('success', 'تم تحديث بيانات العميل بنجاح.');
                header('Location: customers.php' . ($isStaff ? '' : '?view=' . $targetId));
                exit;
            } catch (PDOException $e) {
                $error = 'خطأ في التحديث: ' . $e->getMessage();
            }
        }
    }

    // 4. DELETE CUSTOMER ACCOUNT
    elseif ($action === 'delete') {
        $targetId = (int)($_POST['customer_id'] ?? 0);
        $isSelfDelete = (!$isStaff && $targetId === $myCustomerId);

        if (!$isSelfDelete) {
            require_permission('delete_records');
        }

        if ($targetId > 0) {
            try {
                $cStmt = $pdo->prepare("SELECT id, user_id, full_name FROM customers WHERE id = :id LIMIT 1");
                $cStmt->execute([':id' => $targetId]);
                $target = $cStmt->fetch();

                if (!$target) {
                    $error = 'العميل غير موجود.';
                } elseif ($isSelfDelete) {
                    if (!empty($target['user_id']) && (int)$target['user_id'] === (int)$user['id']) {
                        $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'customer'")->execute([':id' => $user['id']]);
                        force_logout('تم حذف حساب العميل الخاص بك بنجاح.');
                    } else {
                        $error = 'لا يمكن حذف هذا الحساب من خلال هذه الجلسة.';
                    }
                } else {
                    $targetUserId = (int)($target['user_id'] ?? 0);
                    if ($targetUserId > 0) {
                        if (!move_user_to_recycle_bin($targetUserId, $target['full_name'], 'customer')) {
                            throw new Exception('تعذر نقل العميل إلى سلة المحذوفات.');
                        }
                    } else {
                        $pdo->prepare("DELETE FROM customers WHERE id = :id")->execute([':id' => $targetId]);
                    }
                    log_audit('delete', 'customers', $targetId, "نقل العميل إلى سلة المحذوفات: {$target['full_name']}");
                    set_flash('success', 'تم نقل العميل إلى سلة المحذوفات وإنهاء حسابه.');
                    header('Location: customers.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'لا يمكن حذف العميل لوجود أوامر صيانة أو فواتير مرتبطة به.';
            }
        }
    }

    // 4. REQUEST ACCOUNT STATEMENT
    elseif ($action === 'request_statement') {
        $reqCustId = (int)($_POST['customer_id'] ?? 0);
        if (!$isStaff && $reqCustId !== $myCustomerId) {
            http_response_code(403);
            die("غير مصرح لك بطلب كشف حساب لعميل آخر.");
        }
        try {
            $notes = trim($_POST['notes'] ?? 'طلب كشف حساب عبر البوابة الإلكترونية');
            $stStmt = $pdo->prepare("
                INSERT INTO account_statement_requests (customer_id, status, notes)
                VALUES (:cid, 'generated', :notes)
            ");
            $stStmt->execute([':cid' => $reqCustId, ':notes' => $notes]);
            set_flash('success', 'تم إنشاء كشف الحساب وتحديث البيانات بنجاح.');
            header('Location: customers.php?view=' . $reqCustId . '&statement=1');
            exit;
        } catch (PDOException $e) {
            $error = 'خطأ في تسجيل طلب كشف الحساب: ' . $e->getMessage();
        }
    }
}

// ===================================================
// Fetch View Details or Customer List
// ===================================================
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if (!$isStaff) {
    $viewId = $myCustomerId; // Force self view for customer
}

$viewCustomer = null;
$customerVehicles = [];
$customerOrders = [];
$customerInvoices = [];

if ($viewId > 0) {
    if (!$isStaff && $viewId !== $myCustomerId) {
        http_response_code(403);
        die("غير مصرح لك باستعراض بيانات عميل آخر.");
    }

    $cQuery = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
    $cQuery->execute([':id' => $viewId]);
    $viewCustomer = $cQuery->fetch();

    if ($viewCustomer) {
        // Vehicles
        $vQ = $pdo->prepare("SELECT * FROM vehicles WHERE customer_id = :cid ORDER BY id DESC");
        $vQ->execute([':cid' => $viewId]);
        $customerVehicles = $vQ->fetchAll();

        // Work Orders
        $oQ = $pdo->prepare("
            SELECT wo.*, v.plate_number, v.make, v.model, s.name as service_name
            FROM work_orders wo
            JOIN vehicles v ON wo.vehicle_id = v.id
            LEFT JOIN services s ON wo.service_id = s.id
            WHERE wo.customer_id = :cid
            ORDER BY wo.id DESC
        ");
        $oQ->execute([':cid' => $viewId]);
        $customerOrders = $oQ->fetchAll();

        // Invoices
        $iQ = $pdo->prepare("
            SELECT i.*, v.plate_number, v.make, v.model
            FROM invoices i
            JOIN vehicles v ON i.vehicle_id = v.id
            WHERE i.customer_id = :cid
            ORDER BY i.id DESC
        ");
        $iQ->execute([':cid' => $viewId]);
        $customerInvoices = $iQ->fetchAll();
    }
}

// Search & List for Staff
$search = trim($_GET['q'] ?? '');
$customers = [];
if ($isStaff) {
    if (!empty($search)) {
        $stmt = $pdo->prepare("
            SELECT c.*, u.status AS account_status,
                   (SELECT COUNT(*) FROM vehicles WHERE customer_id = c.id) AS total_vehicles,
                   (SELECT COUNT(*) FROM work_orders WHERE customer_id = c.id) AS total_orders
            FROM customers c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE (u.status IS NULL OR u.status <> 'deleted') AND (c.full_name LIKE :s1 OR c.phone LIKE :s2 OR c.email LIKE :s3)
            ORDER BY c.id DESC
        ");
        $term = "%{$search}%";
        $stmt->execute([':s1' => $term, ':s2' => $term, ':s3' => $term]);
        $customers = $stmt->fetchAll();
    } else {
        $customers = $pdo->query("
            SELECT c.*, u.status AS account_status,
                   (SELECT COUNT(*) FROM vehicles WHERE customer_id = c.id) AS total_vehicles,
                   (SELECT COUNT(*) FROM work_orders WHERE customer_id = c.id) AS total_orders
            FROM customers c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE (u.status IS NULL OR u.status <> 'deleted')
            ORDER BY c.id DESC
        ")->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - <?= $isStaff ? 'إدارة العملاء' : 'ملفي الشخصي' ?></title>
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
                <?php if ($isStaff): ?>
                    <a href="customers.php" class="nav-item active"><span class="icon">👤</span> إدارة العملاء</a>
                <?php endif; ?>
                <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> <?= $isStaff ? 'إدارة السيارات' : 'سياراتي' ?></a>
                <?php if ($isStaff): ?>
                    <a href="services.php" class="nav-item"><span class="icon">🛠️</span> قائمة الخدمات</a>
                <?php endif; ?>
                <a href="work_orders.php" class="nav-item"><span class="icon">📋</span> أوامر الصيانة</a>
                <a href="invoices.php" class="nav-item"><span class="icon">🧾</span> الفواتير</a>
                <?php if ($user['role'] === 'admin' || has_permission('view_reports')): ?>
                    <a href="reports.php" class="nav-item"><span class="icon">📈</span> التقارير</a>
                <?php endif; ?>
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
    <a class="btn btn-outline" href="recycle_bin.php">🗑️ سلة المحذوفات</a>
                <div class="topbar-left">
                    <button type="button" class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                    <h2 class="page-title"><?= $isStaff ? 'سجل العملاء' : 'بيانات العميل' ?></h2>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                        <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                        
                    </button>
                    <?php if (has_permission('manage_customers')): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addCustomerModal')">➕ تسجيل عميل جديد</button>
                    <?php endif; ?>
                </div>
            </header>

            <div class="content-container">
                <?php if ($flash): ?>
                    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <!-- Customer Details Profile View -->
                <?php if ($viewCustomer): ?>
                    <?php
                        $totalInvoiced = 0;
                        $totalDiscount = 0;
                        $totalPaid = 0;
                        foreach ($customerInvoices as $inv) {
                            $totalInvoiced += (float)$inv['total_amount'];
                            $totalDiscount += (float)($inv['discount'] ?? 0);
                            $totalPaid += (float)$inv['paid_amount'];
                        }
                        $totalDue = $totalInvoiced - $totalPaid;
                    ?>
                    <div class="card mb-4">
                        <div class="card-header flex-between">
                            <div>
                                <h3>ملف وكشف حساب العميل: <?= htmlspecialchars($viewCustomer['full_name']) ?></h3>
                                <span class="text-muted small">رقم العميل: #<?= $viewCustomer['id'] ?></span>
                            </div>
                            <div class="flex-gap">
                                <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">🖨️ طباعة كشف الحساب</button>
                                <?php if (!$isStaff): ?>
                                    <form method="POST" action="customers.php" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="request_statement">
                                        <input type="hidden" name="customer_id" value="<?= $viewCustomer['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary">🔄 تحديث طلب كشف الحساب</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isStaff): ?>
                                    <a href="customers.php" class="btn btn-sm btn-outline">← العودة لقائمة العملاء</a>
                                <?php endif; ?>
                                <?php if (!$isStaff && (int)$viewCustomer['id'] === $myCustomerId): ?>
                                    <form method="POST" action="customers.php" style="display:inline;" onsubmit="return confirm('سيتم حذف حسابك نهائياً. هل أنت متأكد؟');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="customer_id" value="<?= $myCustomerId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">🗑️ حذف حسابي</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="grid grid-3">
                                <div><strong>الهاتف:</strong> <?= htmlspecialchars($viewCustomer['phone']) ?></div>
                                <div><strong>البريد:</strong> <?= htmlspecialchars($viewCustomer['email'] ?: 'غير مسجل') ?></div>
                                <div><strong>العنوان:</strong> <?= htmlspecialchars($viewCustomer['address'] ?: 'غير مسجل') ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Summary Cards for Account Statement -->
                    <div class="stats-grid mb-4">
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">إجمالي الفواتير الصادرة</span>
                                <span class="stat-val text-primary"><?= format_money($totalInvoiced) ?></span>
                            </div>
                            <div class="stat-card-icon icon-purple">🧾</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">إجمالي الخصومات الممنوحة</span>
                                <span class="stat-val text-info"><?= format_money($totalDiscount) ?></span>
                            </div>
                            <div class="stat-card-icon icon-teal">🏷️</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">المبالغ المسددة</span>
                                <span class="stat-val text-success"><?= format_money($totalPaid) ?></span>
                            </div>
                            <div class="stat-card-icon icon-green">💰</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <span class="stat-label">الرصيد المتبقي المستحق</span>
                                <span class="stat-val <?= $totalDue > 0 ? 'text-danger' : 'text-success' ?>"><?= format_money($totalDue) ?></span>
                            </div>
                            <div class="stat-card-icon icon-red">💳</div>
                        </div>
                    </div>

                    <!-- Customer's Vehicles -->
                    <div class="card mb-4">
                        <div class="card-header flex-between">
                            <h4>السيارات المسجلة (<?= count($customerVehicles) ?>)</h4>
                            <a href="vehicles.php" class="btn btn-sm btn-primary">➕ إضافة سيارة</a>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>المركبة</th>
                                        <th>رقم اللوحة</th>
                                        <th>الموديل</th>
                                        <th>اللون</th>
                                        <th>رقم الهيكل (VIN)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($customerVehicles)): ?>
                                        <?php foreach ($customerVehicles as $veh): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($veh['make'] . ' ' . $veh['model']) ?></strong></td>
                                                <td><?= htmlspecialchars($veh['plate_number'] . ' ' . ($veh['plate_letters'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars($veh['year']) ?></td>
                                                <td><?= htmlspecialchars($veh['color'] ?: '-') ?></td>
                                                <td><code><?= htmlspecialchars($veh['vin'] ?: '-') ?></code></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">لا توجد سيارات مسجلة لهذا العميل.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Customer's Work Orders History -->
                    <div class="card mb-4">
                        <div class="card-header flex-between">
                            <h4>سجل أوامر الصيانة (<?= count($customerOrders) ?>)</h4>
                            <a href="work_orders.php" class="btn btn-sm btn-outline">أمر صيانة جديد</a>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>رقم الأمر</th>
                                        <th>التاريخ</th>
                                        <th>السيارة</th>
                                        <th>الخدمة / المشكلة</th>
                                        <th>الحالة</th>
                                        <th>التكلفة الفعلية</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($customerOrders)): ?>
                                        <?php foreach ($customerOrders as $ord): ?>
                                            <tr>
                                                <td><strong>#<?= htmlspecialchars($ord['order_number']) ?></strong></td>
                                                <td><?= htmlspecialchars(substr($ord['created_at'], 0, 10)) ?></td>
                                                <td><?= htmlspecialchars($ord['make'] . ' ' . $ord['model'] . ' (' . $ord['plate_number'] . ')') ?></td>
                                                <td><?= htmlspecialchars($ord['service_name'] ?: $ord['issue_description']) ?></td>
                                                <td><?= status_badge($ord['status']) ?></td>
                                                <td><strong><?= $ord['actual_cost'] > 0 ? format_money($ord['actual_cost']) : 'قيد التحديد' ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-center text-muted py-3">لا توجد أوامر صيانة مسجلة لهذا العميل.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Customer's Invoices & Payments History -->
                    <div class="card mb-4">
                        <div class="card-header flex-between">
                            <h4>سجل الفواتير والمبالغ المالية (<?= count($customerInvoices) ?>)</h4>
                            <a href="invoices.php" class="btn btn-sm btn-outline">استعراض الفواتير</a>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>رقم الفاتورة</th>
                                        <th>التاريخ</th>
                                        <th>السيارة</th>
                                        <th>المبلغ الإجمالي</th>
                                        <th>الخصم</th>
                                        <th>القيمة بعد الخصم</th>
                                        <th>المسدد</th>
                                        <th>المتبقي</th>
                                        <th>حالة الفاتورة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($customerInvoices)): ?>
                                        <?php foreach ($customerInvoices as $inv): ?>
                                            <?php 
                                                $valAfterDisc = (float)$inv['total_amount'];
                                                $remaining = (float)$inv['total_amount'] - (float)$inv['paid_amount'];
                                            ?>
                                            <tr>
                                                <td><strong>#<?= htmlspecialchars($inv['invoice_number']) ?></strong></td>
                                                <td><?= htmlspecialchars(substr($inv['created_at'], 0, 10)) ?></td>
                                                <td><?= htmlspecialchars($inv['make'] . ' ' . $inv['model'] . ' (' . $inv['plate_number'] . ')') ?></td>
                                                <td><?= format_money((float)$inv['subtotal'] > 0 ? $inv['subtotal'] : ((float)$inv['total_amount'] + (float)($inv['discount'] ?? 0))) ?></td>
                                                <td><?= (float)($inv['discount'] ?? 0) > 0 ? '<span class="text-danger">-' . format_money($inv['discount']) . '</span>' : '0.00 ر.س' ?></td>
                                                <td><strong><?= format_money($valAfterDisc) ?></strong></td>
                                                <td class="text-success"><?= format_money($inv['paid_amount']) ?></td>
                                                <td class="<?= $remaining > 0 ? 'text-danger font-bold' : 'text-muted' ?>"><?= format_money($remaining) ?></td>
                                                <td><?= status_badge($inv['status']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="9" class="text-center text-muted py-3">لا توجد فواتير مسجلة لهذا العميل.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Staff Customer List & Search -->
                <?php if ($isStaff && !$viewCustomer): ?>
                    <div class="card">
                        <div class="card-header flex-between">
                            <h3>قائمة العملاء (<?= count($customers) ?>)</h3>
                            <form method="GET" action="customers.php" class="search-form">
                                <input type="text" name="q" class="form-control form-control-sm" placeholder="بحث بالاسم أو الهاتف..." value="<?= clean($search) ?>">
                                <button type="submit" class="btn btn-sm btn-secondary">بحث</button>
                                <?php if (!empty($search)): ?>
                                    <a href="customers.php" class="btn btn-sm btn-outline">إلغاء</a>
                                <?php endif; ?>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>اسم العميل</th>
                                        <th>رقم الهاتف</th>
                                        <th>البريد الإلكتروني</th>
                                        <th>السيارات</th>
                                        <th>أوامر الصيانة</th>
                                        <th>تاريخ التسجيل</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($customers)): ?>
                                        <?php foreach ($customers as $c): ?>
                                            <tr>
                                                <td><?= $c['id'] ?></td>
                                                <td><strong><?= htmlspecialchars($c['full_name']) ?></strong></td>
                                                <td><?= htmlspecialchars($c['phone']) ?></td>
                                                <td><?= htmlspecialchars($c['email'] ?: '-') ?></td>
                                                <td><span class="badge badge-info"><?= $c['total_vehicles'] ?> سيارات</span></td>
                                                <td><span class="badge badge-primary"><?= $c['total_orders'] ?> أوامر</span></td>
                                                <td><?= !empty($c['account_status']) ? status_badge($c['account_status']) : '<span class="text-muted">بدون حساب دخول</span>' ?></td>
                                                <td><?= htmlspecialchars(substr($c['created_at'], 0, 10)) ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if (($c['account_status'] ?? '') === 'pending' && $user['role'] === 'admin'): ?>
                                                            <form method="POST" action="customers.php" style="display:inline;">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="approve_account">
                                                                <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-success">✓ موافقة</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <a href="customers.php?view=<?= $c['id'] ?>" class="btn btn-sm btn-outline">استعراض</a>
                                                        <?php if (has_permission('manage_customers')): ?>
                                                            <button type="button" class="btn btn-sm btn-primary btn-icon" onclick="openEditCustModal(<?= htmlspecialchars(json_encode($c)) ?>)" title="تعديل" aria-label="تعديل">✏️</button>
                                                        <?php endif; ?>
                                                        <?php if (has_permission('delete_records')): ?>
                                                            <form method="POST" action="customers.php" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا العميل؟ سيتم حذف بياناته وسجلاته');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="حذف" aria-label="حذف">🗑️</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <?= !empty($search) ? 'لا توجد نتائج مطابقة لبحثك.' : 'لا يوجد عملاء مسجلون حالياً.' ?>
                                            </td>
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

    <!-- Modal: Add Customer -->
    <?php if ($user['role'] === 'admin'): ?>
        <div class="modal" id="addCustomerModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>تسجيل عميل جديد</h3>
                    <button type="button" class="modal-close" onclick="closeModal('addCustomerModal')">✕</button>
                </div>
                <form method="POST" action="customers.php" class="modal-body form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">

                    <div class="form-group">
                        <label for="c_name">اسم العميل بالكامل *</label>
                        <input type="text" id="c_name" name="full_name" class="form-control" required placeholder="مثال: فهد عبد الله">
                    </div>

                    <div class="form-group">
                        <label for="c_phone">رقم الهاتف الجوال *</label>
                        <input type="tel" id="c_phone" name="phone" class="form-control" required placeholder="05xxxxxxxx">
                    </div>

                    <div class="form-group">
                        <label for="c_email">البريد الإلكتروني (اختياري)</label>
                        <input type="email" id="c_email" name="email" class="form-control" placeholder="fahad@example.com">
                    </div>

                    <div class="form-group">
                        <label for="c_addr">العنوان / الحي (اختياري)</label>
                        <input type="text" id="c_addr" name="address" class="form-control" placeholder="الرياض، حي العليا">
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addCustomerModal')">إلغاء</button>
                        <button type="submit" class="btn btn-primary">حفظ العميل</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal: Edit Customer (Admin or Staff) -->
    <div class="modal" id="editCustomerModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>تعديل بيانات العميل</h3>
                <button type="button" class="modal-close" onclick="closeModal('editCustomerModal')">✕</button>
            </div>
            <form method="POST" action="customers.php" class="modal-body form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="customer_id" id="edit_cust_id">

                <div class="form-group">
                    <label for="edit_c_name">اسم العميل بالكامل *</label>
                    <input type="text" id="edit_c_name" name="full_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_c_phone">رقم الهاتف الجوال *</label>
                    <input type="tel" id="edit_c_phone" name="phone" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_c_email">البريد الإلكتروني</label>
                    <input type="email" id="edit_c_email" name="email" class="form-control">
                </div>

                <div class="form-group">
                    <label for="edit_c_addr">العنوان / الحي</label>
                    <input type="text" id="edit_c_addr" name="address" class="form-control">
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editCustomerModal')">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>

    <script src="app.js"></script>
    <script>
        function openEditCustModal(c) {
            document.getElementById('edit_cust_id').value = c.id;
            document.getElementById('edit_c_name').value = c.full_name;
            document.getElementById('edit_c_phone').value = c.phone;
            document.getElementById('edit_c_email').value = c.email || '';
            document.getElementById('edit_c_addr').value = c.address || '';
            openModal('editCustomerModal');
        }
    </script>
</body>
</html>
