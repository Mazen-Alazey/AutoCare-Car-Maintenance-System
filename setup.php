<?php
/**
 * AutoCare - Initial System Setup (First & Only Admin Creation)
 */
require_once __DIR__ . '/config.php';

// Check if an Admin already exists
if (has_admin()) {
    set_flash('warning', 'تم إعداد المدير مسبقاً لهذا النظام. يرجى تسجيل الدخول.');
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name     = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($username) || empty($email) || empty($phone) || empty($password)) {
        $error = 'جميع الحقول مطلوبة لإنشاء حساب المدير.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'البريد الإلكتروني المدخل غير صالح.';
    } elseif (strlen($password) < 6) {
        $error = 'يجب ألا تقل كلمة المرور عن 6 خانات.';
    } elseif ($password !== $confirm) {
        $error = 'كلمة المرور وتأكيد كلمة المرور غير متطابقين.';
    } else {
        try {
            // Double check inside transaction that no admin was created concurrently
            $pdo = db();
            $pdo->beginTransaction();

            $check = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ((int)$check > 0) {
                $pdo->rollBack();
                $error = 'تم إنشاء حساب مدير للنظام مسبقاً!';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (name, username, email, phone, password, role, status, is_primary_admin)
                    VALUES (:name, :username, :email, :phone, :password, 'admin', 'active', 1)
                ");
                $stmt->execute([
                    ':name'     => $name,
                    ':username' => $username,
                    ':email'    => $email,
                    ':phone'    => $phone,
                    ':password' => $hash
                ]);
                $pdo->commit();

                // Initialize tables and default maintenance services
                ensure_permissions_table();
                ensure_notifications_table();
                ensure_default_services();

                set_flash('success', 'تم إنشاء حساب المدير وتهيئة خدمات الصيانة بنجاح! يمكنك الآن تسجيل الدخول.');
                header('Location: login.php');
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() == 23000) {
                $error = 'اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل.';
            } else {
                $error = 'حدث خطأ أثناء إنشاء الحساب: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - الإعداد الأولي للنظام (إنشاء المدير)</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-body">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="brand-badge">AutoCare Pro</div>
                <h2>الإعداد الأولي للنظام</h2>
                <p>مرحباً بك! هذه هي المرة الأولى لتشغيل النظام. يرجى إنشاء حساب المدير الرئيسي (Admin).</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="setup.php" class="form-grid">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="name">الاسم الكامل للمدير</label>
                    <input type="text" id="name" name="name" class="form-control" required value="<?= clean($_POST['name'] ?? '') ?>" placeholder="مثال: م. أحمد السعيد">
                </div>

                <div class="form-group">
                    <label for="username">اسم المستخدم (Username)</label>
                    <input type="text" id="username" name="username" class="form-control" required value="<?= clean($_POST['username'] ?? '') ?>" placeholder="admin">
                </div>

                <div class="form-group">
                    <label for="email">البريد الإلكتروني</label>
                    <input type="email" id="email" name="email" class="form-control" required value="<?= clean($_POST['email'] ?? '') ?>" placeholder="admin@autocare.com">
                </div>

                <div class="form-group">
                    <label for="phone">رقم الهاتف</label>
                    <input type="tel" id="phone" name="phone" class="form-control" required value="<?= clean($_POST['phone'] ?? '') ?>" placeholder="05xxxxxxxx">
                </div>

                <div class="form-group">
                    <label for="password">كلمة المرور</label>
                    <input type="password" id="password" name="password" class="form-control" required minlength="6" placeholder="••••••••">
                </div>

                <div class="form-group">
                    <label for="confirm_password">تأكيد كلمة المرور</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6" placeholder="••••••••">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-block">حفظ وإنشاء حساب المدير</button>
                </div>
            </form>
            <div class="auth-footer">
                <p>ملاحظة: هذا الحساب هو المسؤول الوحيد للنظام ولا يمكن إنشاء مدير ثانٍ.</p>
            </div>
        </div>
    </div>
</body>
</html>
