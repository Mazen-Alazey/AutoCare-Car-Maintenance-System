<?php
/**
 * AutoCare - Customer Self-Registration
 */
require_once __DIR__ . '/config.php';

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Auto-generate username from email if not provided
    if (empty($username) && !empty($email)) {
        $parts = explode('@', $email);
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', $parts[0]) . '_' . rand(100, 999);
    }

    if (empty($name) || empty($phone) || empty($email) || empty($password)) {
        $error = 'يرجى تعبئة جميع الحقول الإلزامية.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'صيغة البريد الإلكتروني غير صحيحة.';
    } elseif (strlen($password) < 6) {
        $error = 'يجب ألا تقل كلمة المرور عن 6 أحرف أو أرقام.';
    } elseif ($password !== $confirm) {
        $error = 'كلمة المرور وتأكيدها غير متطابقين.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            // Check unique username and email
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email OR username = :uname LIMIT 1");
            $checkStmt->execute([':email' => $email, ':uname' => $username]);
            if ($checkStmt->fetch()) {
                $pdo->rollBack();
                $error = 'البريد الإلكتروني أو اسم المستخدم مسجل مسبقاً.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $userStmt = $pdo->prepare("
                    INSERT INTO users (name, username, email, phone, password, role, status)
                    VALUES (:name, :uname, :email, :phone, :pass, 'customer', 'pending')
                ");
                $userStmt->execute([
                    ':name'  => $name,
                    ':uname' => $username,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':pass'  => $hash
                ]);
                $userId = (int)$pdo->lastInsertId();

                // Create linked customer profile
                $custStmt = $pdo->prepare("
                    INSERT INTO customers (user_id, full_name, phone, email, address)
                    VALUES (:uid, :fname, :phone, :email, :addr)
                ");
                $custStmt->execute([
                    ':uid'   => $userId,
                    ':fname' => $name,
                    ':phone' => $phone,
                    ':email' => $email,
                    ':addr'  => $address
                ]);
                $customerId = (int)$pdo->lastInsertId();

                $pdo->commit();

                // Customer accounts require administrator approval before login.
                set_flash('success', 'تم إنشاء حسابك بنجاح، وهو الآن بانتظار موافقة الإدارة قبل تسجيل الدخول.');
                header('Location: login.php');
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'حدث خطأ أثناء التسجيل: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars(current_theme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - تسجيل حساب عميل جديد</title>
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
<body class="auth-body">
    <div style="position: absolute; top: 1.5rem; left: 1.5rem; z-index: 10;">
        <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
            <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
            
        </button>
    </div>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <a href="index.php" class="brand-badge">AutoCare Pro</a>
                <h2>تسجيل حساب عميل جديد</h2>
                <p>سجّل حسابك لمتابعة صيانة سياراتك، الفواتير، وحالة الصيانة مباشرة.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="register.php" class="form-grid">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="name">الاسم الكامل *</label>
                    <input type="text" id="name" name="name" class="form-control" required value="<?= clean($_POST['name'] ?? '') ?>" placeholder="مثال: خالد محمد">
                </div>

                <div class="form-group">
                    <label for="phone">رقم الهاتف الجوال *</label>
                    <input type="tel" id="phone" name="phone" class="form-control" required value="<?= clean($_POST['phone'] ?? '') ?>" placeholder="05xxxxxxxx">
                </div>

                <div class="form-group">
                    <label for="email">البريد الإلكتروني *</label>
                    <input type="email" id="email" name="email" class="form-control" required value="<?= clean($_POST['email'] ?? '') ?>" placeholder="name@example.com">
                </div>

                <div class="form-group">
                    <label for="address">العنوان / المدينة (اختياري)</label>
                    <input type="text" id="address" name="address" class="form-control" value="<?= clean($_POST['address'] ?? '') ?>" placeholder="الرياض، حي الملز">
                </div>

                <div class="form-group">
                    <label for="password">كلمة المرور *</label>
                    <input type="password" id="password" name="password" class="form-control" required minlength="6" placeholder="••••••••">
                </div>

                <div class="form-group">
                    <label for="confirm_password">تأكيد كلمة المرور *</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6" placeholder="••••••••">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-block">إنشاء الحساب الآن</button>
                </div>
            </form>

            <div class="auth-footer">
                <p>لديك حساب بالفعل؟ <a href="login.php">تسجيل الدخول</a></p>
                <p class="mt-2"><a href="index.php">← العودة للصفحة الرئيسية</a></p>
            </div>
        </div>
    </div>
    <script src="app.js"></script>
</body>
</html>
