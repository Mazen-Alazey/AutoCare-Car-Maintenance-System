<?php
/**
 * AutoCare - Employee Self Registration
 * Employee accounts require administrator approval before login.
 */
require_once __DIR__ . '/config.php';
if (is_logged_in()) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$name || !$username || !$email || !$phone || !$password) {
        $error = 'يرجى تعبئة جميع الحقول.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'البريد الإلكتروني غير صالح.';
    } elseif (strlen($password) < 6) {
        $error = 'يجب ألا تقل كلمة المرور عن 6 خانات.';
    } elseif ($password !== $confirm) {
        $error = 'كلمة المرور وتأكيدها غير متطابقين.';
    } else {
        try {
            $pdo = db();
            $check = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1");
            $check->execute([':u'=>$username, ':e'=>$email]);
            if ($check->fetch()) {
                $error = 'اسم المستخدم أو البريد الإلكتروني مسجل مسبقاً.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (name, username, email, phone, password, role, status, is_primary_admin, deletion_requested)
                    VALUES (:name, :username, :email, :phone, :password, 'employee', 'pending', 0, 0)
                ");
                $stmt->execute([
                    ':name'=>$name, ':username'=>$username, ':email'=>$email,
                    ':phone'=>$phone, ':password'=>password_hash($password, PASSWORD_DEFAULT)
                ]);
                set_flash('success', 'تم إرسال طلب تسجيل الموظف. لا يمكن تسجيل الدخول حتى تتم موافقة الأدمن.');
                header('Location: login.php'); exit;
            }
        } catch (PDOException $e) {
            $error = 'حدث خطأ أثناء إرسال طلب التسجيل.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars(current_theme()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AutoCare - تسجيل موظف</title>
<script>(function(){var s=localStorage.getItem('autocare_theme');if(s==='light'||s==='dark')document.documentElement.setAttribute('data-theme',s);})();</script>
<link rel="stylesheet" href="style.css">
</head>
<body class="auth-body">
<div class="auth-container"><div class="auth-card">
<div class="auth-header"><a href="index.php" class="brand-badge">AutoCare Pro</a><h2>طلب تسجيل موظف</h2><p>سيتم تفعيل الحساب بعد موافقة الأدمن.</p></div>
<?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="POST" class="form-grid"><?= csrf_field() ?>
<div class="form-group"><label>الاسم الكامل *</label><input name="name" class="form-control" required value="<?= clean($_POST['name'] ?? '') ?>"></div>
<div class="form-group"><label>اسم المستخدم *</label><input name="username" class="form-control" required value="<?= clean($_POST['username'] ?? '') ?>"></div>
<div class="form-group"><label>البريد الإلكتروني *</label><input type="email" name="email" class="form-control" required value="<?= clean($_POST['email'] ?? '') ?>"></div>
<div class="form-group"><label>رقم الهاتف *</label><input name="phone" class="form-control" required value="<?= clean($_POST['phone'] ?? '') ?>"></div>
<div class="form-group"><label>كلمة المرور *</label><input type="password" name="password" class="form-control" minlength="6" required></div>
<div class="form-group"><label>تأكيد كلمة المرور *</label><input type="password" name="confirm_password" class="form-control" minlength="6" required></div>
<div class="form-actions"><button class="btn btn-primary btn-block">إرسال طلب التسجيل</button></div>
</form>
<div class="auth-footer"><p><a href="login.php">العودة لتسجيل الدخول</a> · <a href="register.php">تسجيل عميل</a></p></div>
</div></div>
<script src="app.js"></script>
</body></html>
