<?php
/**
 * AutoCare - Employees Management (Strictly Admin / Authorized Only)
 */
require_once __DIR__ . '/config.php';
require_auth();
require_permission('manage_employees');

$pdo = db();
$user = current_user();
$error = '';
$flash = get_flash();
$theme = current_theme();


// ==========================================
// Handle Actions (All POST only)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. ADD EMPLOYEE
    if ($action === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($username) || empty($email) || empty($phone) || empty($password)) {
            $error = 'جميع الحقول مطلوبة لإضافة الموظف.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'البريد الإلكتروني غير صالح.';
        } elseif (strlen($password) < 6) {
            $error = 'يجب ألا تقل كلمة المرور عن 6 خانات.';
        } else {
            try {
                // Check username or email uniqueness
                $check = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1");
                $check->execute([':u' => $username, ':e' => $email]);
                if ($check->fetch()) {
                    $error = 'اسم المستخدم أو البريد الإلكتروني مسجل مسبقاً.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, username, email, phone, password, role, status)
                        VALUES (:name, :username, :email, :phone, :password, 'employee', 'active')
                    ");
                    $stmt->execute([
                        ':name'     => $name,
                        ':username' => $username,
                        ':email'    => $email,
                        ':phone'    => $phone,
                        ':password' => $hash
                    ]);
                    $empNewId = (int)$pdo->lastInsertId();
                    log_audit('add', 'users', $empNewId, "إضافة موظف جديد: {$name} ({$username})");
                    set_flash('success', 'تمت إضافة الموظف بنجاح.');
                    header('Location: employees.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'حدث خطأ أثناء إضافة الموظف: ' . $e->getMessage();
            }
        }
    }

    // 2. APPROVE SELF-REGISTERED EMPLOYEE
    elseif ($action === 'approve') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        if ($empId > 0) {
            $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id AND role = 'employee' AND status = 'pending'");
            $stmt->execute([':id' => $empId]);
            if ($stmt->rowCount() > 0) {
                log_audit('approve', 'users', $empId, 'الموافقة على تسجيل الموظف');
                set_flash('success', 'تمت الموافقة على حساب الموظف ويمكنه تسجيل الدخول الآن.');
            }
            header('Location: employees.php');
            exit;
        }
    }

    // 3. APPROVE EMPLOYEE SELF-DELETION REQUEST
    elseif ($action === 'approve_delete') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        if ($empId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id AND role = 'employee' AND deletion_requested = 1");
                $stmt->execute([':id' => $empId]);
                $empName = $stmt->fetchColumn();
                if ($empName) {
                    $pdo->prepare("UPDATE work_orders SET assigned_employee_id = NULL WHERE assigned_employee_id = :id")->execute([':id' => $empId]);
                    if (move_user_to_recycle_bin($empId, $empName, 'employee')) {
                        log_audit('delete', 'users', $empId, "الموافقة على نقل الموظف إلى سلة المحذوفات: {$empName}");
                    }
                    set_flash('success', 'تم حذف الموظف وإنهاء حسابه.');
                }
            } catch (PDOException $e) {
                $error = 'خطأ في الموافقة على حذف الموظف.';
            }
            header('Location: employees.php');
            exit;
        }
    }

    // 4. TOGGLE STATUS (Active / Inactive)
    elseif ($action === 'toggle_status') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        if ($empId > 0) {
            // Ensure target is strictly an employee (cannot toggle admin)
            $stmt = $pdo->prepare("SELECT name, status FROM users WHERE id = :id AND role = 'employee'");
            $stmt->execute([':id' => $empId]);
            $currentEmp = $stmt->fetch();

            if ($currentEmp) {
                $newStatus = $currentEmp['status'] === 'active' ? 'inactive' : 'active';
                $upd = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id AND role = 'employee'");
                $upd->execute([':status' => $newStatus, ':id' => $empId]);
                log_audit('toggle_status', 'users', $empId, "تغيير حالة الموظف {$currentEmp['name']} إلى " . ($newStatus === 'active' ? 'نشط' : 'معطل'));
                set_flash('success', 'تم تغيير حالة حساب الموظف بنجاح.');
                header('Location: employees.php');
                exit;
            }
        }
    }

    // 3. EDIT EMPLOYEE
    elseif ($action === 'edit') {
        $empId    = (int)($_POST['employee_id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $status   = $_POST['status'] === 'inactive' ? 'inactive' : 'active';

        if (empty($name) || empty($email) || empty($phone) || $empId <= 0) {
            $error = 'يرجى تعبئة الحقول الأساسية للموظف.';
        } else {
            try {
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET name = :name, email = :email, phone = :phone, password = :pass, status = :status
                        WHERE id = :id AND role = 'employee'
                    ");
                    $stmt->execute([
                        ':name'   => $name,
                        ':email'  => $email,
                        ':phone'  => $phone,
                        ':pass'   => $hash,
                        ':status' => $status,
                        ':id'     => $empId
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET name = :name, email = :email, phone = :phone, status = :status
                        WHERE id = :id AND role = 'employee'
                    ");
                    $stmt->execute([
                        ':name'   => $name,
                        ':email'  => $email,
                        ':phone'  => $phone,
                        ':status' => $status,
                        ':id'     => $empId
                    ]);
                }
                log_audit('edit', 'users', $empId, "تعديل بيانات الموظف: {$name}");
                set_flash('success', 'تم تحديث بيانات الموظف بنجاح.');
                header('Location: employees.php');
                exit;
            } catch (PDOException $e) {
                $error = 'خطأ في التحديث: ' . $e->getMessage();
            }
        }
    }
    // 4. DELETE EMPLOYEE (Admin Only)
    elseif ($action === 'delete') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        if ($empId > 0) {
            try {
                // Ensure target is strictly an employee
                $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id AND role = 'employee'");
                $stmt->execute([':id' => $empId]);
                $empName = $stmt->fetchColumn();

                if ($empName) {
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE assigned_employee_id = :id");
                    $cntStmt->execute([':id' => $empId]);
                    $hasOrders = (int)$cntStmt->fetchColumn();

                    if ($hasOrders > 0) {
                        $pdo->prepare("UPDATE work_orders SET assigned_employee_id = NULL WHERE assigned_employee_id = :id")->execute([':id' => $empId]);
                    }

                    $delStmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'employee'");
                    $delStmt->execute([':id' => $empId]);

                    log_audit('delete', 'users', $empId, "حذف الموظف: {$empName}");
                    set_flash('success', 'تم حذف الموظف نهائياً بنجاح.');
                    header('Location: employees.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'خطأ في حذف الموظف: ' . $e->getMessage();
            }
        }
    }
}
$employees = $pdo->query("
    SELECT id, name, username, email, phone, status, deletion_requested, created_at,
           (SELECT COUNT(*) FROM work_orders WHERE assigned_employee_id = users.id) AS total_orders
    FROM users 
    WHERE role = 'employee' AND status <> 'deleted'
    ORDER BY id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - إدارة الموظفين والفنيين</title>
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
                <a href="employees.php" class="nav-item active"><span class="icon">👥</span> إدارة الموظفين</a>
                <a href="customers.php" class="nav-item"><span class="icon">👤</span> إدارة العملاء</a>
                <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> إدارة السيارات</a>
                <a href="services.php" class="nav-item"><span class="icon">🛠️</span> قائمة الخدمات</a>
                <a href="work_orders.php" class="nav-item"><span class="icon">📋</span> أوامر الصيانة</a>
                <a href="invoices.php" class="nav-item"><span class="icon">🧾</span> الفواتير والمدفوعات</a>
                <?php if (has_permission('manage_appointments')): ?><a href="appointments.php" class="nav-item"><span class="icon">📅</span> المواعيد</a><?php endif; ?>
                <?php if (has_permission('manage_inventory')): ?><a href="inventory.php" class="nav-item"><span class="icon">🔧</span> المخزون</a><?php endif; ?>
                <a href="reports.php" class="nav-item"><span class="icon">📈</span> التقارير المالية</a>
                <?php if (has_permission('manage_admins')): ?>
                    <a href="admins.php" class="nav-item"><span class="icon">👑</span> إدارة المدراء</a>
                <?php endif; ?>
                <a href="permissions.php" class="nav-item"><span class="icon">🛡️</span> مصفوفة الصلاحيات</a>
                <?php if (has_permission('manage_password_reset_requests')): ?><a href="password_requests.php" class="nav-item"><span class="icon">🔐</span> طلبات استعادة كلمة المرور</a><?php endif; ?>
                <a href="profile.php" class="nav-item"><span class="icon">⚙️</span> حسابي الشخصي</a>
                <a href="logout.php" class="nav-item logout-link"><span class="icon">🚪</span> تسجيل الخروج</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
    <a class="btn btn-outline" href="recycle_bin.php">🗑️ سلة المحذوفات</a>
                <div class="topbar-left">
                    <button type="button" class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                    <h2 class="page-title">إدارة طاقم الموظفين والفنيين</h2>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                        <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                        
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addEmployeeModal')">➕ إضافة موظف جديد</button>
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
                        <h3>قائمة الموظفين (<?= count($employees) ?>)</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الاسم</th>
                                    <th>اسم المستخدم</th>
                                    <th>البريد الإلكتروني</th>
                                    <th>الهاتف</th>
                                    <th>المهام المسندة</th>
                                    <th>الحالة</th>
                                    <th>تاريخ الإضافة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($employees)): ?>
                                    <?php foreach ($employees as $emp): ?>
                                        <tr>
                                            <td><?= $emp['id'] ?></td>
                                            <td><strong><?= htmlspecialchars($emp['name']) ?></strong></td>
                                            <td><code><?= htmlspecialchars($emp['username']) ?></code></td>
                                            <td><?= htmlspecialchars($emp['email']) ?></td>
                                            <td><?= htmlspecialchars($emp['phone']) ?></td>
                                            <td><span class="badge badge-info"><?= $emp['total_orders'] ?> أوامر</span></td>
                                            <td><?= status_badge($emp['status']) ?></td>
                                            <td><?= htmlspecialchars(substr($emp['created_at'], 0, 10)) ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if ($emp['status'] === 'pending'): ?>
                                                        <form method="POST" action="employees.php" style="display:inline;">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="approve">
                                                            <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-success">✓ موافقة</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (!empty($emp['deletion_requested'])): ?>
                                                        <form method="POST" action="employees.php" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من الموافقة على حذف هذا الموظف؟');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="approve_delete">
                                                            <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">تأكيد الحذف</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <!-- Edit Button -->
                                                    <button type="button" class="btn btn-sm btn-primary btn-icon" onclick="openEditEmpModal(<?= htmlspecialchars(json_encode($emp)) ?>)" title="تعديل" aria-label="تعديل">
                                                        ✏️
                                                    </button>

                                                    <!-- Toggle Status Form (POST) -->
                                                    <form method="POST" action="employees.php" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من تغيير حالة الموظف؟');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                                                        <button type="submit" class="btn btn-sm <?= $emp['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                                            <?= $emp['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?>
                                                        </button>
                                                    </form>

                                                    <!-- Delete Employee Form (POST) -->
                                                    <form method="POST" action="employees.php" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا الموظف نهائياً؟');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="حذف" aria-label="حذف">
                                                            🗑️
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">لا يوجد موظفون مضافون حالياً. يمكنك إضافة موظف جديد من الزر أعلاه.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal: Add Employee -->
    <div class="modal" id="addEmployeeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>إضافة موظف / فني جديد</h3>
                <button type="button" class="modal-close" onclick="closeModal('addEmployeeModal')">✕</button>
            </div>
            <form method="POST" action="employees.php" class="modal-body form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">

                <div class="form-group">
                    <label for="emp_name">الاسم الكامل للموظف *</label>
                    <input type="text" id="emp_name" name="name" class="form-control" required placeholder="مثال: فني ميكانيك أحمد">
                </div>

                <div class="form-group">
                    <label for="emp_uname">اسم المستخدم للولوج (Username) *</label>
                    <input type="text" id="emp_uname" name="username" class="form-control" required placeholder="emp_ahmed">
                </div>

                <div class="form-group">
                    <label for="emp_email">البريد الإلكتروني *</label>
                    <input type="email" id="emp_email" name="email" class="form-control" required placeholder="ahmed@autocare.com">
                </div>

                <div class="form-group">
                    <label for="emp_phone">رقم الهاتف *</label>
                    <input type="tel" id="emp_phone" name="phone" class="form-control" required placeholder="05xxxxxxxx">
                </div>

                <div class="form-group">
                    <label for="emp_pass">كلمة المرور *</label>
                    <input type="password" id="emp_pass" name="password" class="form-control" required minlength="6" placeholder="••••••••">
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addEmployeeModal')">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ الموظف</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Employee -->
    <div class="modal" id="editEmployeeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>تعديل بيانات الموظف</h3>
                <button type="button" class="modal-close" onclick="closeModal('editEmployeeModal')">✕</button>
            </div>
            <form method="POST" action="employees.php" class="modal-body form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="employee_id" id="edit_emp_id">

                <div class="form-group">
                    <label for="edit_emp_name">الاسم الكامل للموظف *</label>
                    <input type="text" id="edit_emp_name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_emp_uname">اسم المستخدم (للعرض فقط)</label>
                    <input type="text" id="edit_emp_uname" class="form-control" disabled style="opacity: 0.7;">
                </div>

                <div class="form-group">
                    <label for="edit_emp_email">البريد الإلكتروني *</label>
                    <input type="email" id="edit_emp_email" name="email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_emp_phone">رقم الهاتف *</label>
                    <input type="tel" id="edit_emp_phone" name="phone" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_emp_status">حالة الحساب *</label>
                    <select id="edit_emp_status" name="status" class="form-control" required>
                        <option value="active">نشط (مفعل)</option>
                        <option value="inactive">معطل (غير نشط)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_emp_pass">تغيير كلمة المرور (اتركه فارغاً إذا لم ترغب في التغيير)</label>
                    <input type="password" id="edit_emp_pass" name="password" class="form-control" placeholder="••••••••" minlength="6">
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editEmployeeModal')">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>

    <script src="app.js"></script>
    <script>
        function openEditEmpModal(emp) {
            document.getElementById('edit_emp_id').value = emp.id;
            document.getElementById('edit_emp_name').value = emp.name;
            document.getElementById('edit_emp_uname').value = emp.username;
            document.getElementById('edit_emp_email').value = emp.email;
            document.getElementById('edit_emp_phone').value = emp.phone;
            document.getElementById('edit_emp_status').value = emp.status;
            document.getElementById('edit_emp_pass').value = '';
            openModal('editEmployeeModal');
        }
    </script>
</body>
</html>
