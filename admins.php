<?php
/**
 * AutoCare - Primary Admin Management
 * Only the original/primary admin can manage additional admin accounts.
 */
require_once __DIR__ . '/config.php';
require_auth(['admin']);
require_primary_admin();

$pdo = db();
$user = current_user();
$error = '';
$flash = get_flash();
$theme = current_theme();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$name || !$username || !$email || !$phone || !$password) {
            $error = 'جميع الحقول مطلوبة لإضافة مدير.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'البريد الإلكتروني غير صالح.';
        } elseif (strlen($password) < 6) {
            $error = 'يجب ألا تقل كلمة المرور عن 6 خانات.';
        } else {
            try {
                $check = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1");
                $check->execute([':u' => $username, ':e' => $email]);
                if ($check->fetch()) {
                    $error = 'اسم المستخدم أو البريد الإلكتروني مسجل مسبقاً.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, username, email, phone, password, role, status, is_primary_admin, deletion_requested)
                        VALUES (:name, :username, :email, :phone, :password, 'admin', 'active', 0, 0)
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':username' => $username,
                        ':email' => $email,
                        ':phone' => $phone,
                        ':password' => password_hash($password, PASSWORD_DEFAULT)
                    ]);
                    $id = (int)$pdo->lastInsertId();
                    log_audit('add', 'users', $id, "إضافة مدير جديد: {$name}");
                    set_flash('success', 'تمت إضافة المدير الجديد بصلاحيات المدير التشغيلية.');
                    header('Location: admins.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'حدث خطأ أثناء إضافة المدير.';
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['admin_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($id <= 0 || $id === $user['id']) {
            $error = 'لا يمكن تعديل حساب المدير الرئيسي من هذه الشاشة.';
        } elseif (!$name || !$email || !$phone || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'يرجى إدخال بيانات المدير بشكل صحيح.';
        } else {
            try {
                $sql = "UPDATE users SET name = :name, email = :email, phone = :phone";
                $params = [':name'=>$name, ':email'=>$email, ':phone'=>$phone, ':id'=>$id];
                if ($password !== '') {
                    if (strlen($password) < 6) {
                        throw new Exception('يجب ألا تقل كلمة المرور عن 6 خانات.');
                    }
                    $sql .= ", password = :password";
                    $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= " WHERE id = :id AND role = 'admin' AND is_primary_admin = 0";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                set_flash('success', 'تم تحديث بيانات المدير.');
                header('Location: admins.php');
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['admin_id'] ?? 0);
        if ($id > 0 && $id !== $user['id']) {
            $stmt = $pdo->prepare("SELECT status, name FROM users WHERE id = :id AND role = 'admin' AND is_primary_admin = 0");
            $stmt->execute([':id'=>$id]);
            $target = $stmt->fetch();
            if ($target) {
                $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE users SET status = :status WHERE id = :id AND role = 'admin' AND is_primary_admin = 0")
                    ->execute([':status'=>$newStatus, ':id'=>$id]);
                log_audit('toggle_status','users',$id,"تغيير حالة المدير {$target['name']} إلى {$newStatus}");
                set_flash('success', 'تم تغيير حالة المدير.');
            }
        }
        header('Location: admins.php');
        exit;
    } elseif ($action === 'delete') {
        $id = (int)($_POST['admin_id'] ?? 0);
        if ($id > 0 && $id !== $user['id']) {
            try {
                $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id AND role = 'admin' AND is_primary_admin = 0");
                $stmt->execute([':id'=>$id]);
                $name = $stmt->fetchColumn();
                if ($name) {
                    if (move_user_to_recycle_bin($id, $name, 'admin')) {
                        log_audit('delete','users',$id,"نقل المدير إلى سلة المحذوفات: {$name}");
                        set_flash('success','تم نقل المدير إلى سلة المحذوفات وإنهاء جلسته.');
                    } else {
                        $error = 'تعذر نقل المدير إلى سلة المحذوفات.';
                    }
                }
            } catch (PDOException $e) {
                $error = 'تعذر حذف المدير.';
            }
        }
        header('Location: admins.php');
        exit;
    }
}

$admins = $pdo->query("
    SELECT id, name, username, email, phone, status, created_at, is_primary_admin
    FROM users WHERE role = 'admin' AND status <> 'deleted'
    ORDER BY is_primary_admin DESC, id ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AutoCare - إدارة المدراء</title>
<script>
(function(){var saved=localStorage.getItem('autocare_theme');if(!saved){var m=document.cookie.match(new RegExp('(^| )autocare_theme=([^;]+)'));if(m)saved=m[2];}if(saved==='light'||saved==='dark')document.documentElement.setAttribute('data-theme',saved);})();
</script>
<link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-body">
<div class="dashboard-wrapper">
<aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand"><a href="dashboard.php" class="brand-link"><span class="brand-icon">⚡</span><span class="brand-title">AutoCare</span></a><button type="button" class="mobile-sidebar-close" onclick="toggleSidebar()">✕</button></div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item"><span class="icon">📊</span> لوحة المؤشرات</a>
        <a href="employees.php" class="nav-item"><span class="icon">👥</span> إدارة الموظفين</a>
        <a href="customers.php" class="nav-item"><span class="icon">👤</span> إدارة العملاء</a>
        <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> إدارة السيارات</a>
        <a href="services.php" class="nav-item"><span class="icon">🛠️</span> قائمة الخدمات</a>
        <a href="work_orders.php" class="nav-item"><span class="icon">📋</span> أوامر الصيانة</a>
        <a href="invoices.php" class="nav-item"><span class="icon">🧾</span> الفواتير والمدفوعات</a>
        <a href="reports.php" class="nav-item"><span class="icon">📈</span> التقارير المالية</a>
        <a href="admins.php" class="nav-item active"><span class="icon">👑</span> إدارة المدراء</a>
        <a href="permissions.php" class="nav-item"><span class="icon">🛡️</span> مصفوفة الصلاحيات</a>
        <a href="profile.php" class="nav-item"><span class="icon">⚙️</span> حسابي الشخصي</a>
        <a href="logout.php" class="nav-item logout-link"><span class="icon">🚪</span> تسجيل الخروج</a>
    </nav>
</aside>
<main class="main-content">
<header class="topbar">
    <a class="btn btn-outline" href="recycle_bin.php">🗑️ سلة المحذوفات</a>
    <div class="topbar-left"><button type="button" class="mobile-toggle" onclick="toggleSidebar()">☰</button><h2 class="page-title">إدارة المدراء</h2></div>
    <div class="topbar-right"><button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر"><span class="theme-icon"><?= $theme === 'dark' ? '☀️' : '🌙' ?></span></button><button type="button" class="btn btn-primary btn-sm" onclick="openModal('addAdminModal')">➕ إضافة مدير</button></div>
</header>
<div class="content-container">
<?php if($flash): ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card">
<div class="card-header"><h3>حسابات المدراء</h3></div>
<div class="table-responsive"><table class="data-table"><thead><tr><th>#</th><th>الاسم</th><th>اسم المستخدم</th><th>البريد</th><th>الهاتف</th><th>الحالة</th><th>تاريخ الإضافة</th><th>الإجراءات</th></tr></thead><tbody>
<?php foreach($admins as $a): ?>
<tr>
<td><?= (int)$a['id'] ?></td>
<td><strong><?= htmlspecialchars($a['name']) ?></strong> <?php if($a['is_primary_admin']): ?><span class="badge badge-primary">المدير الرئيسي</span><?php endif; ?></td>
<td><code><?= htmlspecialchars($a['username']) ?></code></td>
<td><?= htmlspecialchars($a['email']) ?></td><td><?= htmlspecialchars($a['phone']) ?></td><td><?= status_badge($a['status']) ?></td>
<td><?= htmlspecialchars(substr($a['created_at'],0,10)) ?></td>
<td><div class="action-buttons">
<?php if(!$a['is_primary_admin']): ?>
<button type="button" class="btn btn-sm btn-primary btn-icon" onclick="openEditAdminModal(<?= htmlspecialchars(json_encode($a),ENT_QUOTES,'UTF-8') ?>)" title="تعديل">✏️</button>
<form method="POST" style="display:inline" onsubmit="return confirm('هل أنت متأكد من تغيير حالة المدير؟');"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm <?= $a['status']==='active'?'btn-outline-warning':'btn-outline-success' ?>"><?= $a['status']==='active'?'تعطيل':'تفعيل' ?></button></form>
<form method="POST" style="display:inline" onsubmit="return confirm('سيتم حذف المدير وإنهاء حسابه. هل أنت متأكد؟');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-outline-danger btn-icon" title="حذف">🗑️</button></form>
<?php else: ?><span class="text-muted">محمي</span><?php endif; ?>
</div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
</main></div>

<div class="modal" id="addAdminModal"><div class="modal-content"><div class="modal-header"><h3>إضافة مدير جديد</h3><button type="button" class="modal-close" onclick="closeModal('addAdminModal')">✕</button></div>
<form method="POST" class="modal-body form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="add">
<div class="form-group"><label>الاسم الكامل *</label><input name="name" class="form-control" required></div>
<div class="form-group"><label>اسم المستخدم *</label><input name="username" class="form-control" required></div>
<div class="form-group"><label>البريد الإلكتروني *</label><input type="email" name="email" class="form-control" required></div>
<div class="form-group"><label>رقم الهاتف *</label><input name="phone" class="form-control" required></div>
<div class="form-group"><label>كلمة المرور *</label><input type="password" name="password" class="form-control" minlength="6" required></div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('addAdminModal')">إلغاء</button><button class="btn btn-primary">حفظ المدير</button></div>
</form></div></div>

<div class="modal" id="editAdminModal"><div class="modal-content"><div class="modal-header"><h3>تعديل مدير</h3><button type="button" class="modal-close" onclick="closeModal('editAdminModal')">✕</button></div>
<form method="POST" class="modal-body form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="admin_id" id="edit_admin_id">
<div class="form-group"><label>الاسم الكامل *</label><input name="name" id="edit_admin_name" class="form-control" required></div>
<div class="form-group"><label>البريد الإلكتروني *</label><input type="email" name="email" id="edit_admin_email" class="form-control" required></div>
<div class="form-group"><label>رقم الهاتف *</label><input name="phone" id="edit_admin_phone" class="form-control" required></div>
<div class="form-group"><label>كلمة المرور الجديدة (اختياري)</label><input type="password" name="password" class="form-control" minlength="6"></div>
<div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('editAdminModal')">إلغاء</button><button class="btn btn-primary">حفظ التعديلات</button></div>
</form></div></div>

<script src="app.js"></script>
<script>
function openEditAdminModal(a){document.getElementById('edit_admin_id').value=a.id;document.getElementById('edit_admin_name').value=a.name;document.getElementById('edit_admin_email').value=a.email;document.getElementById('edit_admin_phone').value=a.phone;openModal('editAdminModal');}
</script>
</body></html>
