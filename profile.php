<?php
/**
 * AutoCare - User Profile & Password Management
 */
require_once __DIR__ . '/config.php';
require_auth();

$user = current_user();
$pdo = db();
$error = '';
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. REQUEST ACCOUNT DELETION (Employee requires administrator approval; customer may delete directly)
    if ($action === 'request_delete') {
        if ($user['role'] === 'employee') {
            try {
                $stmt = $pdo->prepare("UPDATE users SET deletion_requested = 1, deletion_requested_at = CURRENT_TIMESTAMP WHERE id = :id AND role = 'employee'");
                $stmt->execute([':id' => $user['id']]);
                set_flash('success', 'تم إرسال طلب حذف حسابك إلى الأدمن للموافقة.');
                header('Location: profile.php');
                exit;
            } catch (PDOException $e) {
                $error = 'تعذر إرسال طلب الحذف.';
            }
        } elseif ($user['role'] === 'customer') {
            try {
                $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'customer'")->execute([':id' => $user['id']]);
                force_logout('تم حذف حسابك بنجاح.');
            } catch (PDOException $e) {
                $error = 'تعذر حذف الحساب حالياً.';
            }
        } else {
            $error = 'لا يمكن للمدير حذف حسابه من النظام.';
        }
    }

    // 2. UPDATE BASIC INFO
    elseif ($action === 'update_info') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($name) || empty($email) || empty($phone)) {
            $error = 'جميع الحقول مطلوبة.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'البريد الإلكتروني غير صالح.';
        } else {
            try {
                // Check email uniqueness among other users
                $check = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
                $check->execute([':email' => $email, ':id' => $user['id']]);
                if ($check->fetch()) {
                    $error = 'البريد الإلكتروني مستخدم بالفعل من قبل حساب آخر.';
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = :name, email = :email, phone = :phone WHERE id = :id");
                    $stmt->execute([':name' => $name, ':email' => $email, ':phone' => $phone, ':id' => $user['id']]);

                    // Update session
                    $_SESSION['user_name']  = $name;
                    $_SESSION['user_email'] = $email;

                    // If customer, sync customer profile too
                    if ($user['role'] === 'customer') {
                        $custUpd = $pdo->prepare("UPDATE customers SET full_name = :name, email = :email, phone = :phone WHERE user_id = :uid");
                        $custUpd->execute([':name' => $name, ':email' => $email, ':phone' => $phone, ':uid' => $user['id']]);
                    }

                    set_flash('success', 'تم تحديث بياناتك الشخصية بنجاح.');
                    header('Location: profile.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'خطأ أثناء التحديث: ' . $e->getMessage();
            }
        }
    }

    // 2. CHANGE PASSWORD
    elseif ($action === 'change_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass     = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
            $error = 'يرجى تعبئة كافة حقول كلمة المرور.';
        } elseif (strlen($newPass) < 6) {
            $error = 'يجب ألا تقل كلمة المرور الجديدة عن 6 خانات.';
        } elseif ($newPass !== $confirmPass) {
            $error = 'كلمة المرور الجديدة وتأكيدها غير متطابقين.';
        } else {
            try {
                $passStmt = $pdo->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
                $passStmt->execute([':id' => $user['id']]);
                $hash = $passStmt->fetchColumn();

                if (!password_verify($currentPass, $hash)) {
                    $error = 'كلمة المرور الحالية غير صحيحة.';
                } else {
                    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                    $updPass = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
                    $updPass->execute([':p' => $newHash, ':id' => $user['id']]);

                    set_flash('success', 'تم تغيير كلمة المرور بنجاح.');
                    header('Location: profile.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'خطأ أثناء تحديث كلمة المرور.';
            }
        }
    }
}

// Fetch latest user details from DB
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$uStmt->execute([':id' => $user['id']]);
$userData = $uStmt->fetch();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars(current_theme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - الملف الشخصي</title>
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
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="employees.php" class="nav-item"><span class="icon">👥</span> إدارة الموظفين</a>
                <?php endif; ?>
                <?php if ($user['role'] === 'admin' || $user['role'] === 'employee'): ?>
                    <a href="customers.php" class="nav-item"><span class="icon">👤</span> إدارة العملاء</a>
                <?php endif; ?>
                <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> <?= $user['role'] === 'customer' ? 'سياراتي' : 'إدارة السيارات' ?></a>
                <?php if ($user['role'] === 'admin' || $user['role'] === 'employee'): ?>
                    <a href="services.php" class="nav-item"><span class="icon">🛠️</span> قائمة الخدمات</a>
                <?php endif; ?>
                <a href="work_orders.php" class="nav-item"><span class="icon">📋</span> أوامر الصيانة</a>
                <a href="invoices.php" class="nav-item"><span class="icon">🧾</span> الفواتير</a>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="reports.php" class="nav-item"><span class="icon">📈</span> التقارير</a>
                <?php endif; ?>
                <a href="profile.php" class="nav-item active"><span class="icon">⚙️</span> حسابي الشخصي</a>
                <a href="logout.php" class="nav-item logout-link"><span class="icon">🚪</span> خروج</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <button type="button" class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                    <h2 class="page-title">إعدادات الحساب والملف الشخصي</h2>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                        <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                        
                    </button>
                </div>
            </header>

            <div class="content-container">
                <?php if ($flash): ?>
                    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="grid grid-2">
                    <!-- Update Info Form -->
                    <div class="card">
                        <div class="card-header">
                            <h3>المعلومات الشخصية</h3>
                        </div>
                        <form method="POST" action="profile.php" class="card-body form-grid">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_info">

                            <div class="form-group">
                                <label for="p_name">الاسم الكامل *</label>
                                <input type="text" id="p_name" name="name" class="form-control" required value="<?= htmlspecialchars($userData['name'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label for="p_uname">اسم المستخدم (لا يمكن تعديله)</label>
                                <input type="text" id="p_uname" class="form-control" readonly disabled value="<?= htmlspecialchars($userData['username'] ?? '') ?>" style="background:#202b3c;">
                            </div>

                            <div class="form-group">
                                <label for="p_email">البريد الإلكتروني *</label>
                                <input type="email" id="p_email" name="email" class="form-control" required value="<?= htmlspecialchars($userData['email'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label for="p_phone">رقم الهاتف *</label>
                                <input type="tel" id="p_phone" name="phone" class="form-control" required value="<?= htmlspecialchars($userData['phone'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>نوع الحساب / الصلاحية</label>
                                <input type="text" class="form-control" readonly disabled value="<?= $user['role'] === 'admin' ? 'مدير عام النظام' : ($user['role'] === 'employee' ? 'موظف صيانة' : 'عميل مسجل') ?>" style="background:#202b3c;">
                            </div>

                            <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                        </form>
                    </div>

                    <!-- Change Password Form -->
                    <div class="card">
                        <div class="card-header">
                            <h3>تغيير كلمة المرور</h3>
                        </div>
                        <form method="POST" action="profile.php" class="card-body form-grid">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="change_password">

                            <div class="form-group">
                                <label for="curr_pass">كلمة المرور الحالية *</label>
                                <input type="password" id="curr_pass" name="current_password" class="form-control" required placeholder="••••••••">
                            </div>

                            <div class="form-group">
                                <label for="new_pass">كلمة المرور الجديدة *</label>
                                <input type="password" id="new_pass" name="new_password" class="form-control" required minlength="6" placeholder="••••••••">
                            </div>

                            <div class="form-group">
                                <label for="conf_pass">تأكيد كلمة المرور الجديدة *</label>
                                <input type="password" id="conf_pass" name="confirm_password" class="form-control" required minlength="6" placeholder="••••••••">
                            </div>

                            <button type="submit" class="btn btn-secondary">تحديث كلمة المرور</button>
                        </form>
                    </div>
                </div>

                <?php if ($user['role'] === 'employee'): ?>
                    <div class="card account-danger-zone">
                        <div class="card-header"><h3>إدارة الحساب</h3></div>
                        <div class="card-body flex-between">
                            <div>
                                <strong>طلب حذف حساب الموظف</strong>
                                <p class="text-muted small">سيتم إرسال الطلب إلى الأدمن، ولن يتم حذف الحساب إلا بعد موافقته.</p>
                            </div>
                            <?php if (!empty($userData['deletion_requested'])): ?>
                                <span class="badge badge-warning">طلب الحذف قيد المراجعة</span>
                            <?php else: ?>
                                <form method="POST" action="profile.php" onsubmit="return confirm('سيتم إرسال طلب حذف حسابك إلى الأدمن. هل تريد المتابعة؟');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_delete">
                                    <button type="submit" class="btn btn-outline-danger">🗑️ طلب حذف الحساب</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($user['role'] === 'customer'): ?>
                    <div class="card account-danger-zone">
                        <div class="card-header"><h3>إدارة الحساب</h3></div>
                        <div class="card-body flex-between">
                            <div>
                                <strong>حذف حسابي</strong>
                                <p class="text-muted small">سيتم حذف حساب الدخول الخاص بك وتسجيل خروجك مباشرة.</p>
                            </div>
                            <form method="POST" action="profile.php" onsubmit="return confirm('سيتم حذف حسابك نهائياً. هل أنت متأكد؟');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="request_delete">
                                <button type="submit" class="btn btn-outline-danger">🗑️ حذف حسابي</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="app.js"></script>
</body>
</html>
