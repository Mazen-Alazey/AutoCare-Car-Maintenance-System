<?php
/**
 * AutoCare - Vehicle Management
 * Strict Customer Ownership & RBAC
 */
require_once __DIR__ . '/config.php';
require_auth();

$user = current_user();
$pdo = db();
$error = '';
$flash = get_flash();
$theme = current_theme();

$isStaff = in_array($user['role'], ['admin', 'employee'], true);
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
    require_permission('manage_vehicles');
}

// ==========================================
// Handle POST Actions
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. ADD VEHICLE
    if ($action === 'add') {
        if ($isStaff) {
            require_permission('manage_vehicles');
        } else {
            require_permission('view_own_data');
        }
        $customerId   = ($user['role'] === 'admin') ? (int)($_POST['customer_id'] ?? 0) : $myCustomerId;
        $plateNumber  = trim($_POST['plate_number'] ?? '');
        $plateLetters = trim($_POST['plate_letters'] ?? '');
        $make         = trim($_POST['make'] ?? '');
        $model        = trim($_POST['model'] ?? '');
        $year         = (int)($_POST['year'] ?? 0);
        $color        = trim($_POST['color'] ?? '');
        $vin          = trim($_POST['vin'] ?? '');
        $notes        = trim($_POST['notes'] ?? '');

        if ($customerId <= 0 || empty($plateNumber) || empty($make) || empty($model) || $year < 1980) {
            $error = 'يرجى تعبئة بيانات السيارة الأساسية بشكل صحيح.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles (customer_id, plate_number, plate_letters, make, model, year, color, vin, notes)
                    VALUES (:cid, :pnum, :plet, :make, :model, :year, :color, :vin, :notes)
                ");
                $stmt->execute([
                    ':cid'   => $customerId,
                    ':pnum'  => $plateNumber,
                    ':plet'  => $plateLetters ?: null,
                    ':make'  => $make,
                    ':model' => $model,
                    ':year'  => $year,
                    ':color' => $color ?: null,
                    ':vin'   => $vin ?: null,
                    ':notes' => $notes ?: null
                ]);
                $newVehId = (int)$pdo->lastInsertId();
                log_audit('add', 'vehicles', $newVehId, "إضافة سيارة جديدة: {$make} {$model} ({$plateNumber})");
                set_flash('success', 'تمت إضافة السيارة بنجاح.');
                header('Location: vehicles.php');
                exit;
            } catch (PDOException $e) {
                $error = 'خطأ في إضافة السيارة: ' . $e->getMessage();
            }
        }
    }

    // 2. EDIT VEHICLE
    elseif ($action === 'edit') {
        if ($isStaff) {
            require_permission('manage_vehicles');
        } else {
            require_permission('view_own_data');
        }
        $vId          = (int)($_POST['vehicle_id'] ?? 0);
        $plateNumber  = trim($_POST['plate_number'] ?? '');
        $plateLetters = trim($_POST['plate_letters'] ?? '');
        $make         = trim($_POST['make'] ?? '');
        $model        = trim($_POST['model'] ?? '');
        $year         = (int)($_POST['year'] ?? 0);
        $color        = trim($_POST['color'] ?? '');
        $vin          = trim($_POST['vin'] ?? '');
        $notes        = trim($_POST['notes'] ?? '');

        if ($vId <= 0 || empty($plateNumber) || empty($make) || empty($model) || $year < 1980) {
            $error = 'يرجى تعبئة بيانات السيارة الأساسية بشكل صحيح.';
        } else {
            try {
                if ($user['role'] === 'admin') {
                    $stmt = $pdo->prepare("
                        UPDATE vehicles
                        SET plate_number = :pnum, plate_letters = :plet, make = :make, model = :model, year = :year, color = :color, vin = :vin, notes = :notes
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':pnum'  => $plateNumber,
                        ':plet'  => $plateLetters ?: null,
                        ':make'  => $make,
                        ':model' => $model,
                        ':year'  => $year,
                        ':color' => $color ?: null,
                        ':vin'   => $vin ?: null,
                        ':notes' => $notes ?: null,
                        ':id'    => $vId
                    ]);
                } else {
                    // IDOR check for customer
                    $stmt = $pdo->prepare("
                        UPDATE vehicles
                        SET plate_number = :pnum, plate_letters = :plet, make = :make, model = :model, year = :year, color = :color, vin = :vin, notes = :notes
                        WHERE id = :id AND customer_id = :cid
                    ");
                    $stmt->execute([
                        ':pnum'  => $plateNumber,
                        ':plet'  => $plateLetters ?: null,
                        ':make'  => $make,
                        ':model' => $model,
                        ':year'  => $year,
                        ':color' => $color ?: null,
                        ':vin'   => $vin ?: null,
                        ':notes' => $notes ?: null,
                        ':id'    => $vId,
                        ':cid'   => $myCustomerId
                    ]);
                }
                log_audit('edit', 'vehicles', $vId, "تعديل بيانات السيارة: {$make} {$model} ({$plateNumber})");
                set_flash('success', 'تم تحديث بيانات السيارة بنجاح.');
                header('Location: vehicles.php');
                exit;
            } catch (PDOException $e) {
                $error = 'خطأ في تحديث السيارة: ' . $e->getMessage();
            }
        }
    }

    // 3. DELETE VEHICLE
    elseif ($action === 'delete') {
        if ($isStaff) {
            require_permission('delete_records');
        } else {
            require_permission('view_own_data');
        }
        $vId = (int)($_POST['vehicle_id'] ?? 0);
        if ($vId > 0) {
            try {
                // Fetch info for audit log
                $vInfoStmt = $pdo->prepare("SELECT make, model, plate_number FROM vehicles WHERE id = :id");
                $vInfoStmt->execute([':id' => $vId]);
                $vInfo = $vInfoStmt->fetch();

                if ($user['role'] === 'admin') {
                    $stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = :id");
                    $stmt->execute([':id' => $vId]);
                } else {
                    // Customer ownership check
                    $stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = :id AND customer_id = :cid");
                    $stmt->execute([':id' => $vId, ':cid' => $myCustomerId]);
                }
                if ($vInfo) {
                    log_audit('delete', 'vehicles', $vId, "حذف السيارة: {$vInfo['make']} {$vInfo['model']} ({$vInfo['plate_number']})");
                }
                set_flash('success', 'تم حذف السيارة بنجاح.');
                header('Location: vehicles.php');
                exit;
            } catch (PDOException $e) {
                $error = 'لا يمكن حذف السيارة لوجود أوامر صيانة مسجلة عليها.';
            }
        }
    }
}


// ==========================================
// Query Vehicles
// ==========================================
$search = trim($_GET['q'] ?? '');
if ($isStaff) {
    if (!empty($search)) {
        $stmt = $pdo->prepare("
            SELECT v.*, c.full_name AS customer_name, c.phone AS customer_phone
            FROM vehicles v
            JOIN customers c ON v.customer_id = c.id
            WHERE v.plate_number LIKE :s1 OR v.make LIKE :s2 OR v.model LIKE :s3 OR c.full_name LIKE :s4
            ORDER BY v.id DESC
        ");
        $term = "%{$search}%";
        $stmt->execute([':s1' => $term, ':s2' => $term, ':s3' => $term, ':s4' => $term]);
        $vehicles = $stmt->fetchAll();
    } else {
        $vehicles = $pdo->query("
            SELECT v.*, c.full_name AS customer_name, c.phone AS customer_phone
            FROM vehicles v
            JOIN customers c ON v.customer_id = c.id
            ORDER BY v.id DESC
        ")->fetchAll();
    }
    // Fetch customers list for modal dropdown
    $customersList = $pdo->query("SELECT id, full_name, phone FROM customers ORDER BY full_name ASC")->fetchAll();
} else {
    // Customer vehicles only
    $stmt = $pdo->prepare("
        SELECT v.*, c.full_name AS customer_name
        FROM vehicles v
        JOIN customers c ON v.customer_id = c.id
        WHERE v.customer_id = :cid
        ORDER BY v.id DESC
    ");
    $stmt->execute([':cid' => $myCustomerId]);
    $vehicles = $stmt->fetchAll();
    $customersList = [];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - <?= $isStaff ? 'إدارة السيارات' : 'سياراتي' ?></title>
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
                    <a href="customers.php" class="nav-item"><span class="icon">👤</span> إدارة العملاء</a>
                <?php endif; ?>
                <a href="vehicles.php" class="nav-item active"><span class="icon">🚗</span> <?= $isStaff ? 'إدارة السيارات' : 'سياراتي' ?></a>
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
                <div class="topbar-left">
                    <button type="button" class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                    <h2 class="page-title"><?= $isStaff ? 'إدارة السيارات والمركبات' : 'سياراتي المسجلة' ?></h2>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                        <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                        
                    </button>
                    <?php if (has_permission('manage_vehicles') || has_permission('view_own_data')): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addVehicleModal')">➕ إضافة سيارة جديدة</button>
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

                <div class="card">
                    <div class="card-header flex-between">
                        <h3>قائمة السيارات (<?= count($vehicles) ?>)</h3>
                        <?php if ($isStaff): ?>
                            <form method="GET" action="vehicles.php" class="search-form">
                                <input type="text" name="q" class="form-control form-control-sm" placeholder="بحث برقم اللوحة، الماركة..." value="<?= clean($search) ?>">
                                <button type="submit" class="btn btn-sm btn-secondary">بحث</button>
                                <?php if (!empty($search)): ?>
                                    <a href="vehicles.php" class="btn btn-sm btn-outline">إلغاء</a>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <?php if ($isStaff): ?>
                                        <th>مالك السيارة</th>
                                    <?php endif; ?>
                                    <th>الشركة والموديل</th>
                                    <th>سنة الصنع</th>
                                    <th>رقم اللوحة</th>
                                    <th>اللون</th>
                                    <th>رقم الهيكل (VIN)</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($vehicles)): ?>
                                    <?php foreach ($vehicles as $v): ?>
                                        <tr>
                                            <td><?= $v['id'] ?></td>
                                            <?php if ($isStaff): ?>
                                                <td>
                                                    <strong><?= htmlspecialchars($v['customer_name']) ?></strong>
                                                    <div class="text-muted small"><?= htmlspecialchars($v['customer_phone'] ?? '') ?></div>
                                                </td>
                                            <?php endif; ?>
                                            <td><strong><?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?></strong></td>
                                            <td><?= htmlspecialchars($v['year']) ?></td>
                                            <td><span class="badge badge-secondary"><?= htmlspecialchars($v['plate_number'] . ' ' . ($v['plate_letters'] ?? '')) ?></span></td>
                                            <td><?= htmlspecialchars($v['color'] ?: '-') ?></td>
                                            <td><code><?= htmlspecialchars($v['vin'] ?: '-') ?></code></td>
                                            <td>
                                                <?php if (!$isStaff): ?>
                                                    <a href="work_orders.php?request_for_vehicle=<?= $v['id'] ?>" class="btn btn-sm btn-primary">طلب صيانة</a>
                                                <?php endif; ?>
                                                <?php 
                                                    $canEditVehicle = ($isStaff && has_permission('manage_vehicles')) || (!$isStaff && has_permission('view_own_data'));
                                                    $canDeleteVehicle = ($isStaff && has_permission('delete_records')) || (!$isStaff && has_permission('view_own_data'));
                                                ?>
                                                <?php if ($canEditVehicle): ?>
                                                    <button type="button" class="btn btn-sm btn-outline btn-icon" onclick="openEditVehicleModal(<?= htmlspecialchars(json_encode($v)) ?>)" title="تعديل" aria-label="تعديل">✏️</button>
                                                <?php endif; ?>
                                                <?php if ($canDeleteVehicle): ?>
                                                    <form method="POST" action="vehicles.php" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذه السيارة؟');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="حذف" aria-label="حذف">🗑️</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (!$canEditVehicle && !$canDeleteVehicle && $isStaff): ?>
                                                    <span class="text-muted small">عرض فقط</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?= $isStaff ? 8 : 7 ?>" class="text-center py-4 text-muted">
                                            <?= !empty($search) ? 'لا توجد نتائج مطابقة لبحثك.' : 'لا توجد سيارات مسجلة حالياً.' ?>
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

    <!-- Modal: Add Vehicle -->
    <div class="modal" id="addVehicleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>إضافة سيارة جديدة</h3>
                <button type="button" class="modal-close" onclick="closeModal('addVehicleModal')">✕</button>
            </div>
            <form method="POST" action="vehicles.php" class="modal-body form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">

                <?php if ($isStaff): ?>
                    <div class="form-group">
                        <label for="v_cust">اختيار العميل المالك *</label>
                        <select id="v_cust" name="customer_id" class="form-control" required>
                            <option value="">-- اختر العميل --</option>
                            <?php foreach ($customersList as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['full_name'] . ' (' . $cl['phone'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="v_make">الشركة المصنعة / الماركة *</label>
                        <input type="text" id="v_make" name="make" class="form-control" required placeholder="مثال: تويوتا / Toyota">
                    </div>
                    <div class="form-group">
                        <label for="v_model">الموديل / الفئة *</label>
                        <input type="text" id="v_model" name="model" class="form-control" required placeholder="مثال: كامري / Camry">
                    </div>
                </div>

                <div class="grid grid-3">
                    <div class="form-group">
                        <label for="v_year">سنة الصنع *</label>
                        <input type="number" id="v_year" name="year" class="form-control" required min="1980" max="<?= date('Y') + 1 ?>" value="<?= date('Y') ?>">
                    </div>
                    <div class="form-group">
                        <label for="v_pnum">رقم اللوحة *</label>
                        <input type="text" id="v_pnum" name="plate_number" class="form-control" required placeholder="1234">
                    </div>
                    <div class="form-group">
                        <label for="v_plet">حروف اللوحة</label>
                        <input type="text" id="v_plet" name="plate_letters" class="form-control" placeholder="أ ب ج">
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="v_color">اللون</label>
                        <input type="text" id="v_color" name="color" class="form-control" placeholder="أبيض لؤلؤي">
                    </div>
                    <div class="form-group">
                        <label for="v_vin">رقم الهيكل VIN (اختياري)</label>
                        <input type="text" id="v_vin" name="vin" class="form-control" placeholder="17 حرف/رقم">
                    </div>
                </div>

                <div class="form-group">
                    <label for="v_notes">ملاحظات إضافية عن حالة السيارة</label>
                    <textarea id="v_notes" name="notes" class="form-control" rows="2" placeholder="أي تفاصيل أو خدوش أو صدمات سابقة..."></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addVehicleModal')">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ السيارة</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Vehicle -->
    <div class="modal" id="editVehicleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>تعديل بيانات السيارة</h3>
                <button type="button" class="modal-close" onclick="closeModal('editVehicleModal')">✕</button>
            </div>
            <form method="POST" action="vehicles.php" class="modal-body form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="vehicle_id" id="edit_veh_id">

                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="edit_v_make">الشركة المصنعة / الماركة *</label>
                        <input type="text" id="edit_v_make" name="make" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_v_model">الموديل / الفئة *</label>
                        <input type="text" id="edit_v_model" name="model" class="form-control" required>
                    </div>
                </div>

                <div class="grid grid-3">
                    <div class="form-group">
                        <label for="edit_v_year">سنة الصنع *</label>
                        <input type="number" id="edit_v_year" name="year" class="form-control" required min="1980" max="<?= date('Y') + 1 ?>">
                    </div>
                    <div class="form-group">
                        <label for="edit_v_pnum">رقم اللوحة *</label>
                        <input type="text" id="edit_v_pnum" name="plate_number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_v_plet">حروف اللوحة</label>
                        <input type="text" id="edit_v_plet" name="plate_letters" class="form-control">
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="edit_v_color">اللون</label>
                        <input type="text" id="edit_v_color" name="color" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="edit_v_vin">رقم الهيكل VIN</label>
                        <input type="text" id="edit_v_vin" name="vin" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit_v_notes">ملاحظات إضافية</label>
                    <textarea id="edit_v_notes" name="notes" class="form-control" rows="2"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editVehicleModal')">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>

    <script src="app.js"></script>
    <script>
        function openEditVehicleModal(v) {
            document.getElementById('edit_veh_id').value = v.id;
            document.getElementById('edit_v_make').value = v.make;
            document.getElementById('edit_v_model').value = v.model;
            document.getElementById('edit_v_year').value = v.year;
            document.getElementById('edit_v_pnum').value = v.plate_number;
            document.getElementById('edit_v_plet').value = v.plate_letters || '';
            document.getElementById('edit_v_color').value = v.color || '';
            document.getElementById('edit_v_vin').value = v.vin || '';
            document.getElementById('edit_v_notes').value = v.notes || '';
            openModal('editVehicleModal');
        }
    </script>
</body>
</html>
