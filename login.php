<?php
/**
 * AutoCare - User Login (Admin / Employee / Customer)
 */
require_once __DIR__ . '/config.php';

// Check if system has an admin yet
if (!has_admin()) {
    header('Location: setup.php');
    exit;
}

// If already logged in, go straight to dashboard
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $login_input = trim($_POST['login_input'] ?? '');
    $password    = $_POST['password'] ?? '';

    if (empty($login_input) || empty($password)) {
        $error = 'يرجى إدخال اسم المستخدم أو البريد الإلكتروني وكلمة المرور.';
    } else {
        try {
            $stmt = db()->prepare("
                SELECT id, name, username, email, password, role, status
                FROM users
                WHERE username = :login1 OR email = :login2
                LIMIT 1
            ");
            $stmt->execute([':login1' => $login_input, ':login2' => $login_input]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'pending') {
                    $error = 'تم إنشاء حسابك، لكنه بانتظار موافقة الأدمن قبل السماح بتسجيل الدخول.';
                } elseif ($user['status'] === 'pending_delete') {
                    $error = 'تم إرسال طلب حذف حسابك وهو بانتظار موافقة الأدمن.';
                } elseif ($user['status'] !== 'active') {
                    $error = 'حسابك معطل حالياً. يرجى التواصل مع إدارة المركز.';
                } else {
                    // Session regeneration for security
                    session_regenerate_id(true);

                    $_SESSION['user_id']       = (int)$user['id'];
                    $_SESSION['user_name']     = $user['name'];
                    $_SESSION['user_username'] = $user['username'];
                    $_SESSION['user_email']    = $user['email'];
                    $_SESSION['user_role']     = $user['role'];
                    $_SESSION['last_activity'] = time();

                    // If user is a customer, link customer_id in session
                    if ($user['role'] === 'customer') {
                        $custStmt = db()->prepare("SELECT id FROM customers WHERE user_id = :uid LIMIT 1");
                        $custStmt->execute([':uid' => $user['id']]);
                        $cust = $custStmt->fetch();
                        if ($cust) {
                            $_SESSION['customer_id'] = (int)$cust['id'];
                        }
                    }

                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = 'بيانات الدخول غير صحيحة. يرجى التأكد من اسم المستخدم وكلمة المرور.';
            }
        } catch (PDOException $e) {
            $error = 'حدث خطأ في النظام. يرجى المحاولة لاحقاً.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars(current_theme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - تسجيل الدخول</title>
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
                <a href="index.php" class="brand-badge">AutoCare System</a>
                <h2>تسجيل الدخول للنظام</h2>
                <p>مرحباً بك مجدداً! أدخل بيانات حسابك للمتابعة.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="form-grid">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="login_input">اسم المستخدم أو البريد الإلكتروني</label>
                    <input type="text" id="login_input" name="login_input" class="form-control" required value="<?= clean($_POST['login_input'] ?? '') ?>" placeholder="admin أو username@mail.com">
                </div>

                <div class="form-group">
                    <label for="password">كلمة المرور</label>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-block">تسجيل الدخول</button>
                </div>
            </form>

            <div class="auth-footer">
                <p>عميل جديد وتريد متابعة صيانة سيارتك؟ <a href="register.php">إنشاء حساب عميل</a></p>
                <p class="mt-2">موظف جديد؟ <a href="register_employee.php">إرسال طلب تسجيل موظف</a></p>
                <p class="mt-2"><a href="forgot_password.php">نسيت كلمة المرور؟</a></p>
                <p class="mt-2"><a href="index.php">← العودة للصفحة الرئيسية</a></p>
            </div>
        </div>
    </div>
    <script src="app.js"></script>
</body>
</html>
