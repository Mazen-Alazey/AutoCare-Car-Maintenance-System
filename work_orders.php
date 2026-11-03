<?php
/**
 * AutoCare - Work Orders Management System
 * Full Lifecycle: New -> Inspecting -> In Progress -> Completed -> Delivered
 */
require_once __DIR__ . '/config.php';
require_auth();

// Ensure predefined maintenance services exist
ensure_default_services();

$user = current_user();
$pdo = db();
$error = '';
$flash = get_flash();
$theme = current_theme();

$isStaff = in_array($user['role'], ['admin', 'employee'], true);
$isAdmin = ($user['role'] === 'admin');
$isEmployee = ($user['role'] === 'employee');
$myCustomerId = 0;

if (!$isStaff) {
    require_permission('view_own_data');
    $cStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = :uid LIMIT 1");
    $cStmt->execute([':uid' => $user['id']]);
    $myCustomerId = (int)$cStmt->fetchColumn();
    if ($myCustomerId === 0) {
        die("لم يتم العثور على ملف العميل الخاص بك.");
    }
} else {
    require_permission('manage_work_orders');
}

// ==========================================
// Handle POST Actions
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. CREATE WORK ORDER
    if ($action === 'create') {
        if ($isStaff) {
            require_permission('manage_work_orders');
        } else {
            require_permission('view_own_data');
        }

        if ($isAdmin || has_permission('manage_work_orders') && $isStaff) {
            $customerId   = (int)($_POST['customer_id'] ?? 0);
            $vehicleId    = (int)($_POST['vehicle_id'] ?? 0);
            $serviceId    = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
            $employeeId   = !empty($_POST['assigned_employee_id']) ? (int)$_POST['assigned_employee_id'] : null;
            $issue        = trim($_POST['issue_description'] ?? '');
            
            // If service selected, price MUST strictly equal catalog price (cannot be modified)
            $estCost = 0.00;
            if ($serviceId) {
                $srvStmt = $pdo->prepare("SELECT price FROM services WHERE id = :sid");
                $srvStmt->execute([':sid' => $serviceId]);
                $estCost = (float)($srvStmt->fetchColumn() ?: 0);
            } else {
                $estCost = (float)($_POST['estimated_cost'] ?? 0);
            }
        } else {
            // Customer requesting maintenance for own vehicle
            $customerId   = $myCustomerId;
            $vehicleId    = (int)($_POST['vehicle_id'] ?? 0);
            $serviceId    = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
            $employeeId   = null;
            $issue        = trim($_POST['issue_description'] ?? '');
            
            // Enforce service price
            $estCost = 0.00;
            if ($serviceId) {
                $srvStmt = $pdo->prepare("SELECT price FROM services WHERE id = :sid AND status = 'active'");
                $srvStmt->execute([':sid' => $serviceId]);
                $estCost = (float)($srvStmt->fetchColumn() ?: 0);
            }

            // Verify car ownership (prevent IDOR)
            $ownCheck = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE id = :vid AND customer_id = :cid");
            $ownCheck->execute([':vid' => $vehicleId, ':cid' => $myCustomerId]);
            if ((int)$ownCheck->fetchColumn() === 0) {
                $error = 'السيارة المحددة غير مسجلة في حسابك.';
            }
        }


        if (empty($error)) {
            if ($customerId <= 0 || $vehicleId <= 0 || empty($issue)) {
                $error = 'يرجى اختيار السيارة وتحديد وصف المشكلة أو الخدمة المطلوبة.';
            } else {
                try {
                    // Generate Unique Order Number: WO-YYYYMM-XXXX
                    $prefix = 'WO-' . date('Ym') . '-';
                    $rand = str_pad((string)random_int(100, 9999), 4, '0', STR_PAD_LEFT);
                    $orderNumber = $prefix . $rand;

                    $stmt = $pdo->prepare("
                        INSERT INTO work_orders (order_number, customer_id, vehicle_id, assigned_employee_id, service_id, issue_description, estimated_cost, status)
                        VALUES (:num, :cid, :vid, :eid, :sid, :issue, :est, 'new')
                    ");
                    $stmt->execute([
                        ':num'   => $orderNumber,
                        ':cid'   => $customerId,
                        ':vid'   => $vehicleId,
                        ':eid'   => $employeeId,
                        ':sid'   => $serviceId,
                        ':issue' => $issue,
                        ':est'   => $estCost
                    ]);
                    $newOrderId = (int)$pdo->lastInsertId();

                    log_audit('create', 'work_orders', $newOrderId, "إنشاء أمر صيانة #{$orderNumber} بتكلفة تقديرية {$estCost} ر.س");
                    
                    $msg = $isStaff 
                        ? ('تم إنشاء أمر الصيانة رقم ' . $orderNumber . ' بنجاح.') 
                        : ('تم تقديم طلب الصيانة بنجاح برقم #' . $orderNumber . '، سيقوم فريق العمل بفحص سيارتك وتحديث الحالة فوراً.');
                    set_flash('success', $msg);
                    header('Location: work_orders.php');
                    exit;
                } catch (PDOException $e) {
                    $error = 'خطأ في إنشاء أمر الصيانة: ' . $e->getMessage();
                }
            }
        }
    }

    // 2. UPDATE WORK ORDER STATUS & DIAGNOSIS (Staff Only)
    elseif ($action === 'update_status' && $isStaff) {
        $orderId    = (int)($_POST['order_id'] ?? 0);
        $newStatus  = $_POST['status'] ?? 'new';
        $diagnosis  = trim($_POST['diagnosis_notes'] ?? '');

        // Fetch current order state to enforce strict business rules
        $curStmt = $pdo->prepare("
            SELECT wo.*, s.price AS catalog_service_price, inv.id AS invoice_id, inv.status AS invoice_status
            FROM work_orders wo
            LEFT JOIN services s ON wo.service_id = s.id
            LEFT JOIN invoices inv ON inv.work_order_id = wo.id
            WHERE wo.id = :id
        ");
        $curStmt->execute([':id' => $orderId]);
        $currentOrder = $curStmt->fetch();

        if (!$currentOrder) {
            $error = 'أمر الصيانة غير موجود.';
        } elseif ($user['role'] === 'employee' && !empty($currentOrder['assigned_employee_id']) && (int)$currentOrder['assigned_employee_id'] !== (int)$user['id']) {
            // Requirement: Prevent an employee from modifying a work order assigned to another technician
            http_response_code(403);
            $error = 'غير مصرح لك بتعديل أو تغيير حالة أمر صيانة مسند إلى فني آخر.';
        } elseif (!empty($currentOrder['invoice_id']) && !$isAdmin) {
            // Invoice is already issued; amounts and final stages are locked for non-admins
            $error = 'لا يمكن تعديل أمر الصيانة بعد إصدار الفاتورة إلا من قبل مدير النظام (Admin).';
        } else {
            // Status Progression Validation (تثبيت مرحلة الصيانة ومنع الرجوع للخلف لجميع المستخدمين بما فيهم المدير)
            $stageRank = [
                'new'         => 1,
                'inspecting'  => 2,
                'in_progress' => 3,
                'completed'   => 4,
                'delivered'   => 5,
                'cancelled'   => 99
            ];

            $currentRank = $stageRank[$currentOrder['status']] ?? 1;
            $newRank     = $stageRank[$newStatus] ?? 1;

            // Prevent regression backward for all users including Admin (حالة الصيانة تتحرك للأمام فقط)
            if ($currentOrder['status'] === 'delivered' && $newStatus !== 'delivered') {
                $error = 'تم تسليم السيارة للعميل بالفعل؛ لا يمكن تعديل أو إرجاع حالة أمر الصيانة بعد التسليم النهائي.';
            } elseif ($newStatus !== 'cancelled' && $newRank < $currentRank) {
                $error = "لا يمكن إرجاع حالة أمر الصيانة إلى مرحلة سابقة؛ مسار مراحل الصيانة يتحرك للأمام فقط ولا يمكن العودة للخلف.";
            } elseif ($newStatus === 'cancelled' && !$isAdmin) {
                $error = 'إلغاء أمر الصيانة مسموح لمدير النظام (Admin) فقط.';
            } elseif ($newStatus === 'cancelled' && in_array($currentOrder['status'], ['completed', 'delivered'], true)) {
                $error = 'لا يمكن إلغاء أمر الصيانة بعد اكتمال الصيانة أو تسليم السيارة.';
            } else {
                // Rule: Technician Assignment Constraint
                if ($isEmployee) {
                    $assignedEmployeeId = !empty($currentOrder['assigned_employee_id']) 
                        ? (int)$currentOrder['assigned_employee_id'] 
                        : (int)$user['id'];
                } else {
                    $assignedEmployeeId = !empty($_POST['assigned_employee_id']) ? (int)$_POST['assigned_employee_id'] : $currentOrder['assigned_employee_id'];
                }

                // Rule: Amount Protection in Final Stages (منع تعديل المبالغ لغير المدير في المراحل النهائية)
                $isFinalStage = in_array($currentOrder['status'], ['completed', 'delivered'], true) || !empty($currentOrder['invoice_id']);
                if ($isFinalStage && !$isAdmin) {
                    // Lock the actual cost to existing value for non-admins
                    $actualCost = (float)$currentOrder['actual_cost'];
                } else {
                    // If order has a linked catalog service, the price is strictly FIXED by the catalog
                    if (!empty($currentOrder['service_id']) && (float)$currentOrder['catalog_service_price'] > 0) {
                        $actualCost = (float)$currentOrder['catalog_service_price'];
                    } else {
                        $actualCost = isset($_POST['actual_cost']) && is_numeric($_POST['actual_cost']) 
                            ? (float)$_POST['actual_cost'] 
                            : (float)$currentOrder['actual_cost'];
                    }
                }

                $allowedStatuses = ['new', 'inspecting', 'in_progress', 'completed', 'delivered', 'cancelled'];
                if (!in_array($newStatus, $allowedStatuses, true)) {
                    $error = 'حالة غير صالحة لأمر الصيانة.';
                } else {
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE work_orders
                            SET status = :status,
                                diagnosis_notes = :diag,
                                actual_cost = :actual,
                                assigned_employee_id = :eid
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':status' => $newStatus,
                            ':diag'   => $diagnosis ?: null,
                            ':actual' => $actualCost,
                            ':eid'    => $assignedEmployeeId,
                            ':id'     => $orderId
                        ]);

                        log_audit('update_status', 'work_orders', $orderId, "تحديث حالة أمر الصيانة #{$currentOrder['order_number']} إلى {$newStatus} والتكلفة {$actualCost} ر.س");

                        set_flash('success', 'تم حفظ وتثبيت حالة أمر الصيانة وملاحظات الفحص بنجاح.');
                        header('Location: work_orders.php?view=' . $orderId);
                        exit;
                    } catch (PDOException $e) {
                        $error = 'خطأ في التحديث: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// ==========================================
// View Single Order Details
// ==========================================
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewOrder = null;
if ($viewId > 0) {
    $stmt = $pdo->prepare("
        SELECT wo.*, 
               c.full_name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
               v.plate_number, v.plate_letters, v.make, v.model, v.year, v.color, v.vin,
               s.name AS service_name, s.price AS service_default_price,
               u.name AS technician_name,
               inv.id AS invoice_id, inv.invoice_number, inv.status AS invoice_status
        FROM work_orders wo
        JOIN customers c ON wo.customer_id = c.id
        JOIN vehicles v ON wo.vehicle_id = v.id
        LEFT JOIN services s ON wo.service_id = s.id
        LEFT JOIN users u ON wo.assigned_employee_id = u.id
        LEFT JOIN invoices inv ON inv.work_order_id = wo.id
        WHERE wo.id = :id
    ");
    $stmt->execute([':id' => $viewId]);
    $viewOrder = $stmt->fetch();

    // Customer IDOR check
    if ($viewOrder && !$isStaff && (int)$viewOrder['customer_id'] !== $myCustomerId) {
        http_response_code(403);
        die("غير مصرح لك بمشاهدة أمر الصيانة هذا.");
    }
}

// ==========================================
// List Orders with Filter & Search
// ==========================================
$filterStatus = $_GET['status'] ?? '';
$filterMyOrders = isset($_GET['my_orders']) && $_GET['my_orders'] === '1';
$search = trim($_GET['q'] ?? '');

$query = "
    SELECT wo.*, 
           c.full_name AS customer_name, c.phone AS customer_phone,
           v.plate_number, v.make, v.model,
           s.name AS service_name, s.price AS catalog_service_price,
           u.name AS technician_name,
           inv.id AS invoice_id, inv.invoice_number
    FROM work_orders wo
    JOIN customers c ON wo.customer_id = c.id
    JOIN vehicles v ON wo.vehicle_id = v.id
    LEFT JOIN services s ON wo.service_id = s.id
    LEFT JOIN users u ON wo.assigned_employee_id = u.id
    LEFT JOIN invoices inv ON inv.work_order_id = wo.id
    WHERE 1=1
";
$params = [];

if (!$isStaff) {
    $query .= " AND wo.customer_id = :mycid";
    $params[':mycid'] = $myCustomerId;
} elseif ($isEmployee && $filterMyOrders) {
    $query .= " AND wo.assigned_employee_id = :myempid";
    $params[':myempid'] = $user['id'];
}

if (!empty($filterStatus)) {
    $query .= " AND wo.status = :status";
    $params[':status'] = $filterStatus;
}

if (!empty($search)) {
    $query .= " AND (wo.order_number LIKE :s1 OR c.full_name LIKE :s2 OR v.plate_number LIKE :s3)";
    $params[':s1'] = "%{$search}%";
    $params[':s2'] = "%{$search}%";
    $params[':s3'] = "%{$search}%";
}

$query .= " ORDER BY wo.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Dropdowns for Modal (Staff only)
$customers = $isAdmin ? $pdo->query("SELECT id, full_name, phone FROM customers ORDER BY full_name ASC")->fetchAll() : [];
$vehicles = $isAdmin ? $pdo->query("SELECT id, customer_id, make, model, year, plate_number FROM vehicles ORDER BY id DESC")->fetchAll() : [];
$servicesList = $pdo->query("SELECT id, name, price FROM services WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Technicians dropdown list:
// Admin sees all active technicians; Employee only sees themselves
$technicians = [];
if ($isAdmin) {
    $technicians = $pdo->query("SELECT id, name FROM users WHERE role = 'employee' AND status = 'active' ORDER BY name ASC")->fetchAll();
} elseif ($isEmployee) {
    $technicians = [['id' => $user['id'], 'name' => $user['name']]];
}

// If customer, fetch their registered vehicles
$myVehicles = [];
if (!$isStaff) {
    $myVehStmt = $pdo->prepare("SELECT id, make, model, year, plate_number, plate_letters FROM vehicles WHERE customer_id = :cid ORDER BY id DESC");
    $myVehStmt->execute([':cid' => $myCustomerId]);
    $myVehicles = $myVehStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - أوامر الصيانة</title>
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
                <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> <?= $isStaff ? 'إدارة السيارات' : 'سياراتي' ?></a>
                <?php if (has_permission('manage_services')): ?>
                    <a href="services.php" class="nav-item"><span class="icon">🛠️</span> قائمة الخدمات</a>
                <?php endif; ?>
                <a href="work_orders.php" class="nav-item active"><span class="icon">📋</span> أوامر الصيانة</a>
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
                <div class="topbar-left">
                    <button type="button" class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                    <h2 class="page-title"><?= $isStaff ? 'أوامر وبطاقات الصيانة' : 'طلبات الصيانة لسياراتي' ?></h2>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                        <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                        
                    </button>
                    <?php if ($isStaff && has_permission('manage_work_orders')): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addOrderModal')">➕ أمر صيانة جديد</button>
                    <?php elseif (!$isStaff && has_permission('view_own_data')): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('customerOrderModal')">➕ طلب صيانة جديد</button>
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

                <!-- Order Details View -->
                <?php if ($viewOrder): ?>
                    <?php 
                        $hasService = !empty($viewOrder['service_id']);
                        $hasInvoice = !empty($viewOrder['invoice_id']);
                        $isFinished = in_array($viewOrder['status'], ['completed', 'delivered'], true);
                    ?>
                    <div class="card mb-4">
                        <div class="card-header flex-between">
                            <div>
                                <h3>أمر صيانة: <span class="text-primary">#<?= htmlspecialchars($viewOrder['order_number']) ?></span></h3>
                                <span class="text-muted small">تاريخ الفتح: <?= htmlspecialchars($viewOrder['created_at']) ?></span>
                            </div>
                            <div class="flex-gap">
                                <?= status_badge($viewOrder['status']) ?>
                                <a href="work_orders.php" class="btn btn-sm btn-outline">← عودة للأوامر</a>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="grid grid-3 mb-4">
                                <div class="info-box">
                                    <span class="info-label">بيانات العميل</span>
                                    <strong><?= htmlspecialchars($viewOrder['customer_name']) ?></strong>
                                    <p class="text-muted small">📞 <?= htmlspecialchars($viewOrder['customer_phone']) ?></p>
                                </div>
                                <div class="info-box">
                                    <span class="info-label">بيانات السيارة</span>
                                    <strong><?= htmlspecialchars($viewOrder['make'] . ' ' . $viewOrder['model'] . ' ' . $viewOrder['year']) ?></strong>
                                    <p class="text-muted small">لوحة: <?= htmlspecialchars($viewOrder['plate_number'] . ' ' . ($viewOrder['plate_letters'] ?? '')) ?></p>
                                </div>
                                <div class="info-box">
                                    <span class="info-label">الخدمة والفني المسؤول</span>
                                    <strong><?= htmlspecialchars($viewOrder['service_name'] ?: 'صيانة عامة') ?></strong>
                                    <p class="text-muted small">الفني: 
                                        <?php if ($isEmployee): ?>
                                            <?= htmlspecialchars(!empty($viewOrder['technician_name']) ? $viewOrder['technician_name'] : $user['name']) ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($viewOrder['technician_name'] ?: 'غير معين') ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-2 mb-4">
                                <div class="card bg-darker p-3">
                                    <h4>وصف المشكلة / الطلب:</h4>
                                    <p><?= nl2br(htmlspecialchars($viewOrder['issue_description'])) ?></p>
                                </div>
                                <div class="card bg-darker p-3">
                                    <h4>تقرير الفحص والتشخيص الفني:</h4>
                                    <p><?= nl2br(htmlspecialchars($viewOrder['diagnosis_notes'] ?: 'لم يتم تسجيل ملاحظات تشخيص بعد.')) ?></p>
                                </div>
                            </div>

                            <div class="grid grid-2 mb-4 align-center">
                                <div>
                                    <p>التكلفة التقديرية المعتمدة: <strong><?= format_money($viewOrder['estimated_cost']) ?></strong></p>
                                    <p>التكلفة الفعلية النهائية: <strong class="text-success"><?= $viewOrder['actual_cost'] > 0 ? format_money($viewOrder['actual_cost']) : format_money($viewOrder['estimated_cost']) ?></strong> 
                                    <?= $hasService ? '<span class="badge badge-info small">سعر معتمد وثابت للخدمة</span>' : '' ?></p>
                                </div>
                                <div class="text-left">
                                    <?php if ($hasInvoice): ?>
                                        <a href="invoices.php?view=<?= $viewOrder['invoice_id'] ?>" class="btn btn-success">🧾 استعراض الفاتورة الصادرة</a>
                                    <?php elseif ($isStaff && $isFinished): ?>
                                        <a href="invoices.php?create_from=<?= $viewOrder['id'] ?>" class="btn btn-primary">➕ إصدار فاتورة لأمر الصيانة</a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Staff: Update Order Status Form -->
                            <?php if ($isStaff): ?>
                                <hr class="my-4">
                                <?php 
                                    $isAssignedToOther = ($isEmployee && !empty($viewOrder['assigned_employee_id']) && (int)$viewOrder['assigned_employee_id'] !== (int)$user['id']);
                                    $isDelivered = ($viewOrder['status'] === 'delivered');
                                ?>
                                <?php if ($isAssignedToOther): ?>
                                    <div class="alert alert-warning">
                                        ⚠️ أمر الصيانة هذا مسند إلى الفني <strong>(<?= htmlspecialchars($viewOrder['technician_name'] ?: 'فني آخر') ?>)</strong>. بموجب سياسة النظام، لا يمكنك تعديل بيانات هذا الأمر أو تغيير حالته.
                                    </div>
                                <?php elseif ($isDelivered): ?>
                                    <div class="alert alert-success">
                                        ✅ تم تسليم السيارة للعميل بنجاح. حالة أمر الصيانة مكتملة ومثبتة بشكل نهائي ولا يمكن تغييرها.
                                    </div>
                                <?php elseif ($hasInvoice): ?>
                                    <div class="alert alert-info">
                                        ℹ️ تم إصدار فاتورة نهائية لأمر الصيانة هذا برقم <strong>#<?= htmlspecialchars($viewOrder['invoice_number']) ?></strong>. البيانات مقفلة للعرض فقط.
                                    </div>
                                <?php else: ?>
                                    <h4>تحديث حالة وملاحظات أمر الصيانة</h4>
                                    <form method="POST" action="work_orders.php" class="form-grid mt-3">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="order_id" value="<?= $viewOrder['id'] ?>">

                                        <div class="grid grid-3">
                                            <div class="form-group">
                                                <label for="u_status">حالة الصيانة *</label>
                                                <?php
                                                    $stageRankMap = [
                                                        'new'         => ['rank' => 1, 'label' => 'جديد (New)'],
                                                        'inspecting'  => ['rank' => 2, 'label' => 'قيد الفحص (Inspecting)'],
                                                        'in_progress' => ['rank' => 3, 'label' => 'قيد الصيانة (In Progress)'],
                                                        'completed'   => ['rank' => 4, 'label' => 'مكتمل (Completed)'],
                                                        'delivered'   => ['rank' => 5, 'label' => 'تم التسليم (Delivered)'],
                                                    ];
                                                    $curRank = $stageRankMap[$viewOrder['status']]['rank'] ?? 1;
                                                ?>
                                                <select id="u_status" name="status" class="form-control" required>
                                                    <?php foreach ($stageRankMap as $stKey => $stInfo): ?>
                                                        <?php if ($stInfo['rank'] >= $curRank): ?>
                                                            <option value="<?= $stKey ?>" <?= $viewOrder['status'] === $stKey ? 'selected' : '' ?>>
                                                                <?= $stInfo['label'] ?>
                                                            </option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    <?php if ($isAdmin && !in_array($viewOrder['status'], ['completed', 'delivered'], true)): ?>
                                                        <option value="cancelled" <?= $viewOrder['status'] === 'cancelled' ? 'selected' : '' ?>>ملغي (Cancelled)</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label for="u_cost">التكلفة النهائية (ر.س) <?= $hasService ? '<span class="text-muted small">(مقفلة حسب الخدمة)</span>' : '' ?></label>
                                                <input type="number" step="0.01" id="u_cost" name="actual_cost" class="form-control" 
                                                       value="<?= $viewOrder['actual_cost'] > 0 ? $viewOrder['actual_cost'] : ($viewOrder['service_default_price'] ?: $viewOrder['estimated_cost']) ?>"
                                                       <?= $hasService ? 'readonly style="background:#1e293b; color:#10b981; font-weight:bold;"' : '' ?>>
                                            </div>

                                            <div class="form-group">
                                                <label for="u_tech">الفني المسؤول <?= $isEmployee ? '<span class="text-muted small">(الموظف الحالي)</span>' : '' ?></label>
                                                <?php if ($isEmployee): ?>
                                                    <input type="hidden" name="assigned_employee_id" value="<?= $user['id'] ?>">
                                                    <input type="text" class="form-control" readonly style="background:#1e293b;" value="<?= htmlspecialchars($user['name']) ?>">
                                                <?php else: ?>
                                                    <select id="u_tech" name="assigned_employee_id" class="form-control">
                                                        <option value="">-- بدون تعيين --</option>
                                                        <?php foreach ($technicians as $tech): ?>
                                                            <option value="<?= $tech['id'] ?>" <?= $viewOrder['assigned_employee_id'] == $tech['id'] ? 'selected' : '' ?>><?= htmlspecialchars($tech['name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="u_diag">ملاحظات الفحص والقطع المستبدلة</label>
                                            <textarea id="u_diag" name="diagnosis_notes" class="form-control" rows="3"><?= htmlspecialchars($viewOrder['diagnosis_notes'] ?? '') ?></textarea>
                                        </div>

                                        <div class="flex-between">
                                            <button type="submit" class="btn btn-primary">💾 حفظ التحديثات</button>
                                            <?php if ($isFinished): ?>
                                                <a href="invoices.php?create_from=<?= $viewOrder['id'] ?>" class="btn btn-success">➕ إصدار فاتورة الآن</a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Orders List -->
                <div class="card">
                    <div class="card-header flex-between">
                        <div class="flex-gap align-center">
                            <h3>سجل أوامر الصيانة (<?= count($orders) ?>)</h3>
                            <?php if ($isEmployee): ?>
                                <a href="work_orders.php<?= $filterMyOrders ? '' : '?my_orders=1' ?>" class="btn btn-sm <?= $filterMyOrders ? 'btn-primary' : 'btn-outline' ?>">
                                    <?= $filterMyOrders ? '✅ أوامري المكلف بها' : '📋 عرض أوامري الخاصة فقط' ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <form method="GET" action="work_orders.php" class="search-form">
                            <?php if ($filterMyOrders): ?>
                                <input type="hidden" name="my_orders" value="1">
                            <?php endif; ?>
                            <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">كل الحالات</option>
                                <option value="new" <?= $filterStatus === 'new' ? 'selected' : '' ?>>جديد</option>
                                <option value="inspecting" <?= $filterStatus === 'inspecting' ? 'selected' : '' ?>>قيد الفحص</option>
                                <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>قيد الصيانة</option>
                                <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>مكتمل</option>
                                <option value="delivered" <?= $filterStatus === 'delivered' ? 'selected' : '' ?>>تم التسليم</option>
                                <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>ملغي</option>
                            </select>
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="رقم الأمر، العميل، اللوحة..." value="<?= clean($search) ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">بحث</button>
                            <?php if (!empty($search) || !empty($filterStatus) || $filterMyOrders): ?>
                                <a href="work_orders.php" class="btn btn-sm btn-outline">إلغاء الفلترة</a>
                            <?php endif; ?>
                        </form>
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
                                    <th>التكلفة</th>
                                    <th>التاريخ</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($orders)): ?>
                                    <?php foreach ($orders as $ord): ?>
                                        <tr>
                                            <td><strong>#<?= htmlspecialchars($ord['order_number']) ?></strong></td>
                                            <td>
                                                <?= htmlspecialchars($ord['customer_name']) ?>
                                                <div class="text-muted small"><?= htmlspecialchars($ord['customer_phone']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($ord['make'] . ' ' . $ord['model'] . ' (' . $ord['plate_number'] . ')') ?></td>
                                            <td><?= htmlspecialchars($ord['service_name'] ?: 'فحص وصيانة') ?></td>
                                            <td>
                                                <?php if ($isEmployee): ?>
                                                    <!-- For Employee: strictly display technician owner name if assigned, or account owner -->
                                                    <span class="badge badge-secondary"><?= htmlspecialchars($ord['technician_name'] ?: $user['name']) ?></span>
                                                <?php else: ?>
                                                    <span><?= htmlspecialchars($ord['technician_name'] ?: 'غير معين') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= status_badge($ord['status']) ?></td>
                                            <td>
                                                <strong>
                                                    <?php 
                                                        $cost = (float)$ord['actual_cost'] > 0 ? (float)$ord['actual_cost'] : ((float)$ord['catalog_service_price'] > 0 ? (float)$ord['catalog_service_price'] : (float)$ord['estimated_cost']);
                                                        echo format_money($cost);
                                                    ?>
                                                </strong>
                                            </td>
                                            <td><?= htmlspecialchars(substr($ord['created_at'], 0, 10)) ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="work_orders.php?view=<?= $ord['id'] ?>" class="btn btn-sm btn-outline">استعراض</a>
                                                    <?php if (!empty($ord['invoice_id'])): ?>
                                                        <a href="invoices.php?view=<?= $ord['invoice_id'] ?>" class="btn btn-sm btn-success">فاتورة</a>
                                                    <?php elseif ($isStaff && in_array($ord['status'], ['completed', 'delivered'], true)): ?>
                                                        <a href="invoices.php?create_from=<?= $ord['id'] ?>" class="btn btn-sm btn-primary">إصدار فاتورة</a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            <?= (!empty($search) || !empty($filterStatus)) ? 'لا توجد أوامر صيانة مطابقة للفلتر المحدد.' : 'لا توجد أوامر صيانة مسجلة حالياً.' ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal: Create Work Order (Admin Only) -->
    <?php if ($isAdmin): ?>
        <div class="modal" id="addOrderModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>إنشاء أمر صيانة جديد</h3>
                    <button type="button" class="modal-close" onclick="closeModal('addOrderModal')">✕</button>
                </div>
                <form method="POST" action="work_orders.php" class="modal-body form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">

                    <div class="form-group">
                        <label for="wo_cust">العميل صاحب السيارة *</label>
                        <select id="wo_cust" name="customer_id" class="form-control" required onchange="filterVehiclesByCustomer(this.value)">
                            <option value="">-- اختر العميل --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name'] . ' (' . $c['phone'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="wo_veh">السيارة المراد صيانتها *</label>
                        <select id="wo_veh" name="vehicle_id" class="form-control" required>
                            <option value="">-- اختر السيارة --</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['id'] ?>" data-customer="<?= $v['customer_id'] ?>">
                                    <?= htmlspecialchars($v['make'] . ' ' . $v['model'] . ' (' . $v['plate_number'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-2">
                        <div class="form-group">
                            <label for="wo_srv">الخدمة المطلوبة</label>
                            <select id="wo_srv" name="service_id" class="form-control" onchange="onServiceSelected(this)">
                                <option value="" data-price="0">-- فحص وتحديد العطل --</option>
                                <?php foreach ($servicesList as $s): ?>
                                    <option value="<?= $s['id'] ?>" data-price="<?= $s['price'] ?>">
                                        <?= htmlspecialchars($s['name'] . ' (' . format_money($s['price']) . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="wo_tech">تعيين الفني المسؤول</label>
                            <select id="wo_tech" name="assigned_employee_id" class="form-control">
                                <option value="">-- تعيين لاحقاً --</option>
                                <?php foreach ($technicians as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="wo_est">التكلفة التقديرية (ر.س) <span id="wo_est_hint" class="text-muted small"></span></label>
                        <input type="number" step="0.01" id="wo_est" name="estimated_cost" class="form-control" placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label for="wo_issue">وصف المشكلة أو شكوى العميل بالتفصيل *</label>
                        <textarea id="wo_issue" name="issue_description" class="form-control" rows="3" required placeholder="مثال: صوت طقطقة في العجلات الأمامية عند المنعطفات، وفحص نظام الفرامل..."></textarea>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addOrderModal')">إلغاء</button>
                        <button type="submit" class="btn btn-primary">إنشاء أمر الصيانة</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal: Request Maintenance (Customer Only) -->
    <?php if (!$isStaff): ?>
        <div class="modal" id="customerOrderModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>تقديم طلب صيانة جديد</h3>
                    <button type="button" class="modal-close" onclick="closeModal('customerOrderModal')">✕</button>
                </div>
                <?php if (empty($myVehicles)): ?>
                    <div class="modal-body text-center py-4">
                        <p class="text-muted mb-3">لم تقم بإضافة أي سيارة إلى حسابك بعد.</p>
                        <a href="vehicles.php" class="btn btn-primary">➕ إضافة سيارة أولاً</a>
                    </div>
                <?php else: ?>
                    <form method="POST" action="work_orders.php" class="modal-body form-grid">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">

                        <div class="form-group">
                            <label for="c_wo_veh">اختر سيارتك المراد صيانتها *</label>
                            <select id="c_wo_veh" name="vehicle_id" class="form-control" required>
                                <option value="">-- اختر السيارة --</option>
                                <?php foreach ($myVehicles as $mv): ?>
                                    <option value="<?= $mv['id'] ?>" <?= (isset($_GET['request_for_vehicle']) && (int)$_GET['request_for_vehicle'] === (int)$mv['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mv['make'] . ' ' . $mv['model'] . ' (' . $mv['plate_number'] . ' ' . ($mv['plate_letters'] ?? '') . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="c_wo_srv">الخدمة المطلوبة (اختياري)</label>
                            <select id="c_wo_srv" name="service_id" class="form-control">
                                <option value="">-- فحص وتحديد العطل في المركز --</option>
                                <?php foreach ($servicesList as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name'] . ' (' . format_money($s['price']) . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="c_wo_issue">وصف العطل أو المشكلة أو سبب الزيارة بالتفصيل *</label>
                            <textarea id="c_wo_issue" name="issue_description" class="form-control" rows="3" required placeholder="مثال: تبديل زيت وفلتر، أو صوت في المحرك، أو فحص دوري..."></textarea>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" onclick="closeModal('customerOrderModal')">إلغاء</button>
                            <button type="submit" class="btn btn-primary">إرسال طلب الصيانة</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <script src="app.js"></script>
    <script>
        function filterVehiclesByCustomer(customerId) {
            const vehSelect = document.getElementById('wo_veh');
            if (!vehSelect) return;
            const options = vehSelect.querySelectorAll('option');
            let firstVisible = null;

            options.forEach(opt => {
                if (!opt.value) return;
                const optCustId = opt.getAttribute('data-customer');
                if (!customerId || optCustId === customerId) {
                    opt.style.display = '';
                    if (!firstVisible) firstVisible = opt.value;
                } else {
                    opt.style.display = 'none';
                }
            });
            vehSelect.value = firstVisible || '';
        }

        function onServiceSelected(selectElem) {
            const selectedOpt = selectElem.options[selectElem.selectedIndex];
            const price = selectedOpt ? parseFloat(selectedOpt.getAttribute('data-price') || 0) : 0;
            const estInput = document.getElementById('wo_est');
            const hintElem = document.getElementById('wo_est_hint');
            if (estInput) {
                if (price > 0) {
                    estInput.value = price.toFixed(2);
                    estInput.readOnly = true;
                    estInput.style.background = '#1e293b';
                    if (hintElem) hintElem.textContent = '(سعر ثابت ومعتمد للخدمة)';
                } else {
                    estInput.readOnly = false;
                    estInput.style.background = '';
                    if (hintElem) hintElem.textContent = '';
                }
            }
        }
    </script>
</body>
</html>
