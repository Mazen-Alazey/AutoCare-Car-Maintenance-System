<?php
/**
 * AutoCare Car Maintenance Management System
 * Global Configuration & Security Helper File
 * PHP 8+ / PDO / MySQL
 */

// 1. Session Configuration (Secure Session Settings)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// 2. Database Connection Constants
// عدّل هذه البيانات حسب استضافتك (XAMPP أو InfinityFree)
define('DB_HOST', 'sql113.infinityfree.com');
define('DB_NAME', 'if0_42724589_autocare_db');
define('DB_USER', 'if0_42724589');
define('DB_PASS', 'MazenM11');
define('DB_CHARSET', 'utf8mb4');

// 3. Dynamic Base URL Detection
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$baseUrl = rtrim($protocol . $host . ($scriptDir === '/' ? '' : $scriptDir), '/');
define('BASE_URL', $baseUrl);

// 4. PDO Database Singleton Connection
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("خطأ في الاتصال بقاعدة البيانات: يرجى التحقق من ملف config.php وتأكيد استيراد database.sql.");
        }
    }
    return $pdo;
}

// 5. Check if System Has Admin Created
function has_admin(): bool {
    try {
        $stmt = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// 6. Security: CSRF Protection
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            die("طلب غير مصرح به (فشل التحقق من رمز الحماية CSRF). يرجى العودة وإعادة المحاولة.");
        }
    }
}

// 7. Security: Sanitization & Escaping
function clean(mixed $data): string {
    if (is_array($data)) {
        return '';
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

// 8. Flash Messages (Success & Errors)
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message
    ];
}

function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// 9. Authentication & Authorization Helpers
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'       => (int)$_SESSION['user_id'],
        'name'     => $_SESSION['user_name'] ?? '',
        'username' => $_SESSION['user_username'] ?? '',
        'email'    => $_SESSION['user_email'] ?? '',
        'role'     => $_SESSION['user_role'] ?? '',
    ];
}

function force_logout(string $message = ''): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    if ($message !== '') {
        session_start();
        set_flash('danger', $message);
    }
    header('Location: login.php');
    exit;
}

function is_primary_admin(int $userId): bool {
    try {
        $stmt = db()->prepare("SELECT is_primary_admin FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
        $stmt->execute([':id' => $userId]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (Exception $e) {
        try {
            $stmt = db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
            return (int)$stmt->fetchColumn() === $userId;
        } catch (Exception $e2) {
            return false;
        }
    }
}

function current_is_primary_admin(): bool {
    $user = current_user();
    return $user ? is_primary_admin($user['id']) : false;
}

function require_primary_admin(): void {
    if (!is_logged_in()) {
        set_flash('danger', 'يرجى تسجيل الدخول أولاً.');
        header('Location: login.php');
        exit;
    }
    if (($_SESSION['user_role'] ?? '') !== 'admin' || !current_is_primary_admin()) {
        http_response_code(403);
        die("غير مصرح لك بالوصول إلى إدارة المدراء.");
    }
}

function require_auth(array $allowed_roles = []): void {
    if (!is_logged_in()) {
        set_flash('danger', 'يرجى تسجيل الدخول أولاً للوصول إلى هذه الصفحة.');
        header("Location: login.php");
        exit;
    }

    // Automatic inactivity timeout: 5 minutes.
    ensure_user_account_schema();
    ensure_extended_schema();
    $timeoutSeconds = 300;
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) >= $timeoutSeconds) {
        force_logout('انتهت الجلسة بسبب عدم استخدام النظام لمدة 5 دقائق. يرجى تسجيل الدخول من جديد.');
    }
    $_SESSION['last_activity'] = time();

    try {
        $stmt = db()->prepare("SELECT id, name, username, email, role, status FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $dbUser = $stmt->fetch();

        if (!$dbUser || $dbUser['status'] !== 'active') {
            force_logout($dbUser ? 'تم إيقاف حسابك أو لم تتم الموافقة عليه بعد.' : 'تم حذف حسابك من النظام.');
        }

        if (($dbUser['role'] ?? '') !== ($_SESSION['user_role'] ?? '')) {
            force_logout('تم تغيير صلاحيات حسابك. يرجى تسجيل الدخول من جديد.');
        }

        $_SESSION['user_name'] = $dbUser['name'];
        $_SESSION['user_username'] = $dbUser['username'];
        $_SESSION['user_email'] = $dbUser['email'];
    } catch (Exception $e) {
        force_logout('تعذر التحقق من حسابك. يرجى تسجيل الدخول من جديد.');
    }

    $userRole = $_SESSION['user_role'] ?? '';
    if (!empty($allowed_roles) && !in_array($userRole, $allowed_roles, true)) {
        http_response_code(403);
        die("غير مصرح لك بالوصول إلى هذه الصفحة.");
    }
}

// 10. System Permissions Definitions & Management
const SYSTEM_PERMISSIONS = [
    'manage_users' => [
        'name'          => 'إدارة المستخدمين',
        'category'      => 'المستخدمون والأمان',
        'description'   => 'إدارة حسابات المستخدمين وفق الصلاحيات الممنوحة لهذا المدير',
        'default_roles' => ['admin']
    ],
    'manage_employees' => [
        'name'          => 'إدارة الموظفين',
        'category'      => 'المستخدمون والأمان',
        'description'   => 'إضافة وتعديل وحذف وتعطيل وتفعيل حسابات الموظفين',
        'default_roles' => ['admin']
    ],
    'manage_admins' => [
         'name'          => 'إدارة المدراء',
         'category'      => 'المستخدمون والأمان',
         'description'   => 'إضافة وتعديل وتفعيل وحذف واستعراض حسابات المدراء الآخرين',
         'default_roles' => ['admin']
     ],
    'manage_customers' => [
        'name'          => 'إدارة العملاء',
        'category'      => 'العملاء والسيارات',
        'description'   => 'إضافة وتعديل وحذف وتصفح سجلات العملاء بالمركز',
        'default_roles' => ['admin', 'employee']
    ],
    'manage_vehicles' => [
        'name'          => 'إدارة سيارات العملاء',
        'category'      => 'العملاء والسيارات',
        'description'   => 'تسجيل وتعديل وحذف سيارات العملاء وبياناتها',
        'default_roles' => ['admin', 'employee', 'customer']
    ],
    'manage_services' => [
        'name'          => 'إدارة الخدمات والأسعار',
        'category'      => 'خدمات الصيانة',
        'description'   => 'إضافة وتعديل وحذف وتفعيل أسعار وقائمة الخدمات',
        'default_roles' => ['admin', 'employee']
    ],
    'manage_work_orders' => [
        'name'          => 'إدارة أوامر الصيانة الشاملة',
        'category'      => 'أوامر الصيانة',
        'description'   => 'إدارة ومتابعة وتحديث جميع بطاقات وأوامر الصيانة وتعيين الفنيين',
        'default_roles' => ['admin', 'employee']
    ],
    'create_work_orders' => [
        'name'          => 'إنشاء طلب/أمر صيانة',
        'category'      => 'أوامر الصيانة',
        'description'   => 'فتح كارت أو طلب صيانة جديد للمركبة وتحديد العطل',
        'default_roles' => ['admin', 'employee', 'customer']
    ],
    'view_work_orders' => [
        'name'          => 'عرض طلبات الصيانة',
        'category'      => 'أوامر الصيانة',
        'description'   => 'تصفح واستعراض قائمة أوامر الصيانة وتفاصيلها',
        'default_roles' => ['admin', 'employee', 'customer']
    ],
    'edit_work_orders' => [
        'name'          => 'تعديل طلبات الصيانة',
        'category'      => 'أوامر الصيانة',
        'description'   => 'تعديل بيانات وفحص وتشخيص كروت الصيانة والتكاليف',
        'default_roles' => ['admin', 'employee']
    ],
    'delete_work_orders' => [
        'name'          => 'حذف طلبات الصيانة',
        'category'      => 'أوامر الصيانة',
        'description'   => 'حذف وإلغاء كروت الصيانة من النظام',
        'default_roles' => ['admin']
    ],
    'delete_records' => [
        'name'          => 'حذف السجلات والبيانات',
        'category'      => 'الأمان والحذف',
        'description'   => 'حذف سجلات العملاء والسيارات وخدمات الصيانة بشكل نهائي من النظام',
        'default_roles' => ['admin']
    ],
    'update_work_order_status' => [
        'name'          => 'تحديث حالة الصيانة',
        'category'      => 'أوامر الصيانة',
        'description'   => 'تغيير مرحلة العمل (فحص، صيانة، اكتمال، تسليم)',
        'default_roles' => ['admin', 'employee']
    ],
    'manage_invoices' => [
        'name'          => 'إدارة الفواتير والمدفوعات',
        'category'      => 'المالية والفواتير',
        'description'   => 'إصدار الفواتير، تسجيل المدفوعات، وطباعة سندات القبض',
        'default_roles' => ['admin', 'employee']
    ],
    'give_discount' => [
        'name'          => 'صلاحية إعطاء الخصم للموظف',
        'category'      => 'المالية والفواتير',
        'description'   => 'صلاحية تمكين الموظف من إعطاء وتطبيق الخصم المالي للعميل عند إنشاء الفاتورة أو الدفع',
        'default_roles' => ['admin'] // Admin only by default; granted explicitly by admin in permissions matrix
    ],
    'view_reports' => [
        'name'          => 'عرض التقارير وسجلات العمليات',
        'category'      => 'التقارير والرقابة',
        'description'   => 'الاطلاع على الإيرادات والأرباح وسجل تدقيق العمليات',
        'default_roles' => ['admin']
    ],
    'request_account_statement' => [
        'name'          => 'طلب كشف الحساب المالي',
        'category'      => 'المالية والفواتير',
        'description'   => 'طلب وتصدير كشف حساب المعاملات والمدفوعات',
        'default_roles' => ['admin', 'customer']
    ],
    'view_own_data' => [
        'name'          => 'عرض البيانات الخاصة',
        'category'      => 'الحساب الشخصي',
        'description'   => 'عرض الحساب الشخصي وسيارات وفواتير المستخدم الخاصة',
        'default_roles' => ['admin', 'employee', 'customer']
    ],
    'manage_password_reset_requests' => [
        'name'          => 'إدارة طلبات استعادة كلمة المرور',
        'category'      => 'المستخدمون والأمان',
        'description'   => 'استعراض واعتماد طلبات استعادة كلمة المرور للمستخدمين',
        'default_roles' => []
    ],
    'manage_inventory' => [
        'name'          => 'إدارة قطع الغيار والمخزون',
        'category'      => 'المخزون',
        'description'   => 'إضافة وتعديل وحذف قطع الغيار ومتابعة الكميات والأسعار',
        'default_roles' => ['admin', 'employee']
    ],
    'manage_appointments' => [
        'name'          => 'إدارة المواعيد',
        'category'      => 'المواعيد',
        'description'   => 'إنشاء وتعديل وإلغاء ومتابعة مواعيد الصيانة',
        'default_roles' => ['admin', 'employee', 'customer']
    ],
    'view_vehicle_history' => [
        'name'          => 'عرض سجل صيانة السيارة',
        'category'      => 'أوامر الصيانة',
        'description'   => 'عرض التاريخ الكامل لأوامر صيانة السيارة وفواتيرها',
        'default_roles' => ['admin', 'employee', 'customer']
    ],
    'manage_backups' => [
        'name'          => 'النسخ الاحتياطي لقاعدة البيانات',
        'category'      => 'الأمان والرقابة',
        'description'   => 'إنشاء نسخة SQL احتياطية من بيانات النظام',
        'default_roles' => ['admin']
    ]
];

// Ensure user_permissions table exists in DB automatically
function ensure_permissions_table(): void {
    static $checked = false;
    if ($checked) return;
    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `user_permissions` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT UNSIGNED NOT NULL,
              `permission_key` VARCHAR(50) NOT NULL,
              `is_granted` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_user_permission` (`user_id`, `permission_key`),
              CONSTRAINT `fk_perm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $checked = true;
    } catch (Exception $e) {
        // Table may already exist or DB restricted
    }
}

// Check permission for a specific user ID
function user_has_permission(int $userId, string $permissionKey, ?string $userRole = null): bool {
    if (!isset(SYSTEM_PERMISSIONS[$permissionKey])) {
        return false;
    }

    $pdo = db();
    if ($userRole === null) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $userRole = $stmt->fetchColumn() ?: '';
    }

    // The primary admin has all permissions. Additional admins cannot manage
    // admins or the permission matrix, but their other operational permissions
    // are controlled by the primary admin through user_permissions.
    if ($userRole === 'admin') {
        if (is_primary_admin($userId)) {
            return true;
        }

        // These two capabilities are permanently reserved for the primary admin.
        if (in_array($permissionKey, ['manage_admins', 'manage_backups'], true)) {
            return false;
        }
    }

    ensure_permissions_table();

    try {
        // Check for custom override in user_permissions table
        $stmt = $pdo->prepare("SELECT is_granted FROM user_permissions WHERE user_id = :uid AND permission_key = :pkey LIMIT 1");
        $stmt->execute([':uid' => $userId, ':pkey' => $permissionKey]);
        $row = $stmt->fetch();

        if ($row !== false) {
            return (bool)$row['is_granted'];
        }
    } catch (PDOException $e) {
        // Fallback to default role check
    }

    // Default fallback based on role
    $defaultRoles = SYSTEM_PERMISSIONS[$permissionKey]['default_roles'] ?? [];
    return in_array($userRole, $defaultRoles, true);
}

// Check permission for current logged-in user
function has_permission(string $permissionKey): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    return user_has_permission($user['id'], $permissionKey, $user['role']);
}

// Enforce permission backend protection
function require_permission(string $permissionKey): void {
    if (!is_logged_in()) {
        set_flash('danger', 'يرجى تسجيل الدخول أولاً للوصول إلى هذه الصفحة.');
        header("Location: login.php");
        exit;
    }

    if (!has_permission($permissionKey)) {
        http_response_code(403);
        die("
            <div style='font-family: sans-serif; direction: rtl; text-align: center; padding: 50px 20px; background: #0f172a; color: #f8fafc; min-height: 100vh;'>
                <div style='max-width: 500px; margin: 0 auto; background: #1e293b; padding: 30px; border-radius: 12px; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.5);'>
                    <div style='font-size: 50px; margin-bottom: 15px;'>🚫</div>
                    <h2 style='color: #ef4444; margin-bottom: 10px;'>غير مصرح لك بالوصول!</h2>
                    <p style='color: #94a3b8; margin-bottom: 25px;'>ليس لديك الصلاحية الكافية (" . htmlspecialchars(SYSTEM_PERMISSIONS[$permissionKey]['name'] ?? $permissionKey) . ") لإجراء هذه العملية أو تصفح هذه الصفحة.</p>
                    <a href='dashboard.php' style='display: inline-block; background: #3b82f6; color: #fff; padding: 10px 24px; border-radius: 8px; text-decoration: none; font-weight: bold;'>العودة للوحة المؤشرات</a>
                </div>
            </div>
        ");
    }
}

// Fetch all permissions status for a specific user
function get_user_permissions_map(int $userId, string $userRole): array {
    ensure_permissions_table();
    $result = [];
    $pdo = db();

    $customOverrides = [];
    try {
        $stmt = $pdo->prepare("SELECT permission_key, is_granted FROM user_permissions WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        while ($row = $stmt->fetch()) {
            $customOverrides[$row['permission_key']] = (bool)$row['is_granted'];
        }
    } catch (Exception $e) {}

    foreach (SYSTEM_PERMISSIONS as $key => $perm) {
        $isDefault = in_array($userRole, $perm['default_roles'], true);
        $hasOverride = array_key_exists($key, $customOverrides);

        if ($userRole === 'admin') {
            if (is_primary_admin($userId)) {
                $isGranted = true;
            } elseif (in_array($key, ['manage_admins'], true)) {
                $isGranted = false;
                $hasOverride = false;
            } else {
                $isGranted = $hasOverride ? $customOverrides[$key] : true;
            }
        } else {
            $isGranted = $hasOverride ? $customOverrides[$key] : $isDefault;
        }

        $result[$key] = [
            'key'          => $key,
            'name'         => $perm['name'],
            'category'     => $perm['category'],
            'description'  => $perm['description'],
            'default_role' => $isDefault,
            'has_override' => $hasOverride,
            'is_granted'   => $isGranted
        ];
    }
    return $result;
}

// Set custom permission for a user
function set_user_permission_override(int $userId, string $permissionKey, bool $isGranted): bool {
    if (!isset(SYSTEM_PERMISSIONS[$permissionKey])) {
        return false;
    }
    ensure_permissions_table();
    try {
        $stmt = db()->prepare("
            INSERT INTO user_permissions (user_id, permission_key, is_granted)
            VALUES (:uid, :pkey, :granted)
            ON DUPLICATE KEY UPDATE is_granted = :granted_upd, updated_at = CURRENT_TIMESTAMP
        ");
        return $stmt->execute([
            ':uid'         => $userId,
            ':pkey'        => $permissionKey,
            ':granted'     => $isGranted ? 1 : 0,
            ':granted_upd' => $isGranted ? 1 : 0
        ]);
    } catch (PDOException $e) {
        return false;
    }
}

// Reset all custom permissions for a user back to default
function reset_user_permissions(int $userId): bool {
    ensure_permissions_table();
    try {
        $stmt = db()->prepare("DELETE FROM user_permissions WHERE user_id = :uid");
        return $stmt->execute([':uid' => $userId]);
    } catch (PDOException $e) {
        return false;
    }
}


// Recycle Bin schema for administrator-deleted accounts.
function ensure_recycle_bin_table(): void {
    static $checked = false;
    if ($checked) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS recycle_bin (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            record_id INT UNSIGNED NOT NULL,
            record_name VARCHAR(150) NULL,
            record_role VARCHAR(30) NULL,
            deleted_by INT UNSIGNED NULL,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            restored_at TIMESTAMP NULL DEFAULT NULL,
            restored_by INT UNSIGNED NULL,
            status ENUM('deleted','restored') NOT NULL DEFAULT 'deleted',
            INDEX idx_rb_status(status), INDEX idx_rb_type(entity_type), INDEX idx_rb_record(record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $checked = true;
    } catch (Exception $e) {
        error_log('AutoCare recycle bin schema error: ' . $e->getMessage());
    }
}

function move_user_to_recycle_bin(int $userId, string $name, string $role): bool {
    ensure_recycle_bin_table();
    try {
        $pdo = db();
        $u = current_user();
        $stmt = $pdo->prepare("INSERT INTO recycle_bin (entity_type, record_id, record_name, record_role, deleted_by) VALUES ('user', :rid, :name, :role, :by)");
        $stmt->execute([':rid'=>$userId, ':name'=>$name, ':role'=>$role, ':by'=>$u['id'] ?? null]);
        $del = $pdo->prepare("UPDATE users SET status='deleted', deletion_requested=0, deletion_requested_at=NULL WHERE id=:id AND is_primary_admin=0");
        $del->execute([':id'=>$userId]);
        return $del->rowCount() > 0;
    } catch (Exception $e) {
        error_log('AutoCare recycle bin delete error: ' . $e->getMessage());
        return false;
    }
}

// Additional operational tables / security schema
function ensure_extended_schema(): void {
    ensure_recycle_bin_table();
    static $checked = false;
    if ($checked) return;
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            token_expires_at TIMESTAMP NULL DEFAULT NULL,
            status ENUM('pending','approved','used','rejected','expired') NOT NULL DEFAULT 'pending',
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL DEFAULT NULL,
            approved_by INT UNSIGNED NULL,
            used_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_pr_status(status), INDEX idx_pr_user(user_id),
            CONSTRAINT fk_pr_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_pr_approver FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try {
            $prCols = $pdo->query("SHOW COLUMNS FROM password_reset_requests")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!in_array('token_expires_at', $prCols, true)) {
                $pdo->exec("ALTER TABLE password_reset_requests ADD COLUMN token_expires_at TIMESTAMP NULL DEFAULT NULL AFTER token_hash");
            }
        } catch (Exception $e) {
            error_log('AutoCare password reset schema migration error: ' . $e->getMessage());
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_parts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL, part_number VARCHAR(80) NULL,
            quantity INT NOT NULL DEFAULT 0, min_quantity INT NOT NULL DEFAULT 0,
            purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0, sale_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_inv_name(name), INDEX idx_inv_status(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS appointments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL, vehicle_id INT UNSIGNED NOT NULL,
            appointment_date DATE NOT NULL, appointment_time TIME NOT NULL,
            service_id INT UNSIGNED NULL, notes VARCHAR(500) NULL,
            status ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
            created_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_appt_date(appointment_date), INDEX idx_appt_customer(customer_id),
            CONSTRAINT fk_appt_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            CONSTRAINT fk_appt_vehicle FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            CONSTRAINT fk_appt_service FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL,
            CONSTRAINT fk_appt_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $cols=$pdo->query("SHOW COLUMNS FROM audit_logs")->fetchAll(PDO::FETCH_COLUMN,0);
        if (!in_array('old_value',$cols,true)) $pdo->exec("ALTER TABLE audit_logs ADD COLUMN old_value TEXT NULL AFTER description");
        if (!in_array('new_value',$cols,true)) $pdo->exec("ALTER TABLE audit_logs ADD COLUMN new_value TEXT NULL AFTER old_value");
        $checked=true;
    } catch (Exception $e) {}
}

function create_password_reset_request(int $userId): bool {
    ensure_extended_schema();
    ensure_notifications_table();
    try {
        $pdo=db();
        $pdo->exec("UPDATE password_reset_requests SET status='expired' WHERE user_id=".(int)$userId." AND status='pending'");
        $raw=bin2hex(random_bytes(32));
        $hash=hash('sha256',$raw);
        $st=$pdo->prepare("INSERT INTO password_reset_requests(user_id,token_hash) VALUES(:uid,:hash)");
        $st->execute([':uid'=>$userId,':hash'=>$hash]);
        $reqId=(int)$pdo->lastInsertId();
        $u=$pdo->prepare("SELECT name, username FROM users WHERE id=:id"); $u->execute([':id'=>$userId]); $usr=$u->fetch();
        $primaryId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' AND is_primary_admin=1 LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO notifications(user_id,target_role,title,message) VALUES(:uid,'admin','طلب استعادة كلمة مرور',:msg)")
            ->execute([':uid'=>$primaryId ?: null, ':msg'=>'طلب المستخدم '.($usr['name']??'').' ('.($usr['username']??'').') استعادة كلمة المرور. رقم الطلب: '.$reqId]);
        return true;
    } catch(Exception $e){ error_log('AutoCare password reset request error: ' . $e->getMessage()); return false; }
}

function log_audit_change(string $action,string $tableName,?int $recordId,string $description='',?string $oldValue=null,?string $newValue=null): void {
    try { ensure_extended_schema(); $u=current_user(); $st=db()->prepare("INSERT INTO audit_logs(user_id,user_name,user_role,action,table_name,record_id,description,old_value,new_value,ip_address) VALUES(:uid,:un,:ur,:a,:t,:r,:d,:o,:n,:ip)"); $st->execute([':uid'=>$u['id']??null,':un'=>$u['name']??'System',':ur'=>$u['role']??'system',':a'=>$action,':t'=>$tableName,':r'=>$recordId,':d'=>$description,':o'=>$oldValue,':n'=>$newValue,':ip'=>$_SERVER['REMOTE_ADDR']??'127.0.0.1']); } catch(Exception $e) {}
}

// 11. Theme Helper
function current_theme(): string {
    if (!empty($_COOKIE['autocare_theme']) && in_array($_COOKIE['autocare_theme'], ['light', 'dark'], true)) {
        return $_COOKIE['autocare_theme'];
    }
    return 'dark'; // Default theme
}

// 11. Ensure Notifications Table Exists
function ensure_notifications_table(): void {
    static $checked = false;
    if ($checked) return;
    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS `notifications` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT UNSIGNED NULL,
              `target_role` ENUM('admin', 'employee', 'all_staff', 'customer') NOT NULL DEFAULT 'all_staff',
              `title` VARCHAR(150) NOT NULL,
              `message` TEXT NOT NULL,
              `customer_name` VARCHAR(120) NULL,
              `customer_phone` VARCHAR(30) NULL,
              `amount` DECIMAL(10, 2) NULL,
              `payment_method` VARCHAR(50) NULL,
              `invoice_id` INT UNSIGNED NULL,
              `is_read` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_notif_role` (`target_role`),
              INDEX `idx_notif_read` (`is_read`),
              INDEX `idx_notif_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $checked = true;
    } catch (Exception $e) {
        // Table may already exist or DB restricted
    }
}

// 12. Ensure Default Maintenance Services (12 Standard Services with Fixed Catalog Prices)
function ensure_default_services(): void {
    static $checked = false;
    if ($checked) return;
    try {
        $pdo = db();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
        if ($count < 10) {
            $defaultServices = [
                ['فحص شامل للسيارة والكمبيوتر', 'فحص كامل لجميع الحساسات وكمبيوتر المحرك والقير والكهرباء', 150.00],
                ['تغيير زيت المحرك مع الفلتر الأصلي', 'تبديل زيت المحرك التخليقي بالكامل مع فلتر وكالة أصلي', 220.00],
                ['صيانة وتبديل فحمات الفرامل الأمامية', 'استبدال فحمات الفرامل الأمامية وفحص مستوى زيت الفرامل', 180.00],
                ['صيانة وتبديل فحمات الفرامل الخلفية', 'استبدال فحمات الفرامل الخلفية وفحص جلب الكليبرات', 160.00],
                ['خرط هوبات الفرامل وميزان دقيق', 'خرط الهوبات الأمامية والخلفية بمكينة ليزر دقيقة لمنع الرجة', 120.00],
                ['فحص وتعبئة غاز مكيف السيارة مع تنظيف الفلتر', 'تفريغ وتعبئة فريون أصلي وفحص التسريب مع تنظيف فلتر المكيف', 200.00],
                ['صيانة دورية 10,000 كم (بواجي، فلاتر، سوائل)', 'صيانة دورية شاملة تشمل البواجي، فلتر الهواء، وفحص كافة السوائل', 350.00],
                ['وزن أذرعة وميزان ليزر للعجلات', 'ضبط زوايا العجلات والمقصات والأذرعة بجهاز الليزر ثلاثي الأبعاد', 100.00],
                ['تغيير سائل ناقل الحركة (زيت القير) مع الفلتر', 'استبدال زيت القير الأوتوماتيكي مع فلتر القير وتنظيف الكارتير', 400.00],
                ['تبديل بطارية السيارة مع فحص الدينامو', 'تركيب بطارية جديدة وفحص كفاءة شحن الدينامو والكهرباء', 320.00],
                ['تنظيف بخاخات الوقود وغرفة الاحتراق', 'تنظيف البخاخات بجهاز الموجات الصوتية وتنظيف بوابة الهواء', 250.00],
                ['فحص وتغيير سائل تبريد الرديتر', 'تفريغ دورة التبريد وتعبئة ماء رديتر أصلي مانع للصدأ والغليان', 140.00]
            ];

            $ins = $pdo->prepare("
                INSERT INTO services (name, description, price, status)
                VALUES (:name, :desc, :price, 'active')
            ");

            foreach ($defaultServices as $srv) {
                // Check if already exists by name
                $chk = $pdo->prepare("SELECT id FROM services WHERE name = :name LIMIT 1");
                $chk->execute([':name' => $srv[0]]);
                if (!$chk->fetchColumn()) {
                    $ins->execute([
                        ':name'  => $srv[0],
                        ':desc'  => $srv[1],
                        ':price' => $srv[2]
                    ]);
                }
            }
        }
        $checked = true;
    } catch (Exception $e) {}
}

// 13. Notifications Helpers
function add_payment_notification(string $customerName, string $customerPhone, float $amount, string $paymentMethod, ?int $invoiceId = null): bool {
    ensure_notifications_table();
    try {
        $pdo = db();
        $title = "عملية دفع جديدة من العميل ({$customerName})";
        $methodLabel = match($paymentMethod) {
            'card' => 'مدى / بطاقة ائتمان',
            'bank_transfer' => 'تحويل بنكي',
            default => 'نقداً'
        };
        $message = "قام العميل {$customerName} (جوال: {$customerPhone}) بسداد مبلغ " . format_money($amount) . " عبر {$methodLabel}.";

        $stmt = $pdo->prepare("
            INSERT INTO notifications (target_role, title, message, customer_name, customer_phone, amount, payment_method, invoice_id, is_read)
            VALUES ('all_staff', :title, :msg, :cname, :cphone, :amt, :meth, :invid, 0)
        ");
        return $stmt->execute([
            ':title'  => $title,
            ':msg'    => $message,
            ':cname'  => $customerName,
            ':cphone' => $customerPhone,
            ':amt'    => $amount,
            ':meth'   => $methodLabel,
            ':invid'  => $invoiceId
        ]);
    } catch (Exception $e) {
        return false;
    }
}

function get_unread_notifications(?int $userId = null, string $role = 'admin'): array {
    ensure_notifications_table();
    try {
        $pdo = db();
        if ($role === 'customer') {
            return []; // Notifications are for staff/admin when customer pays
        }
        $stmt = $pdo->prepare("
            SELECT * FROM notifications
            WHERE is_read = 0
              AND (target_role = 'all_staff' OR target_role = :role)
              AND (user_id IS NULL OR user_id = :uid)
            ORDER BY id DESC LIMIT 15
        ");
        $stmt->execute([':role' => $role, ':uid' => $userId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function mark_notification_read(int $notifId): bool {
    ensure_notifications_table();
    try {
        $u = current_user();
        $stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND (user_id IS NULL OR user_id = :uid)");
        return $stmt->execute([':id' => $notifId, ':uid' => $u['id'] ?? 0]);
    } catch (Exception $e) {
        return false;
    }
}

// 14. Format Currency & Status
function format_money(float|int|string|null $amount): string {
    return number_format((float)($amount ?? 0), 2) . ' ر.س';
}

function status_badge(string $status): string {
    $badges = [
        'new'         => ['class' => 'badge-info',    'label' => 'جديد'],
        'inspecting'  => ['class' => 'badge-warning', 'label' => 'قيد الفحص'],
        'in_progress' => ['class' => 'badge-primary', 'label' => 'قيد الصيانة'],
        'completed'   => ['class' => 'badge-success', 'label' => 'مكتمل'],
        'delivered'   => ['class' => 'badge-dark',    'label' => 'تم التسليم'],
        'cancelled'   => ['class' => 'badge-danger',  'label' => 'ملغي'],
        'active'      => ['class' => 'badge-success', 'label' => 'نشط'],
        'inactive'    => ['class' => 'badge-danger',  'label' => 'معطل'],
        'pending'     => ['class' => 'badge-warning', 'label' => 'بانتظار الموافقة'],
        'pending_delete' => ['class' => 'badge-warning', 'label' => 'طلب حذف'],
        'deleted'    => ['class' => 'badge-secondary', 'label' => 'في سلة المحذوفات'],
        'paid'        => ['class' => 'badge-success', 'label' => 'مدفوع بالكامل'],
        'partial'     => ['class' => 'badge-warning', 'label' => 'تم تسليم جزء من المبلغ'],
        'unpaid'      => ['class' => 'badge-danger',  'label' => 'غير مدفوع'],
    ];

    $item = $badges[$status] ?? ['class' => 'badge-secondary', 'label' => $status];
    return '<span class="badge ' . $item['class'] . '">' . htmlspecialchars($item['label']) . '</span>';
}

// 15. Ensure Audit Logs Table and Schema Compatibility
function ensure_audit_logs_table(): void {
    static $checked = false;
    if ($checked) return;
    try {
        $pdo = db();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `audit_logs` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT UNSIGNED NULL,
              `user_name` VARCHAR(100) NULL,
              `user_role` VARCHAR(50) NULL,
              `action` VARCHAR(50) NOT NULL,
              `table_name` VARCHAR(50) NOT NULL,
              `record_id` INT UNSIGNED NULL,
              `description` TEXT NULL,
              `ip_address` VARCHAR(45) NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_audit_table` (`table_name`),
              INDEX `idx_audit_action` (`action`),
              INDEX `idx_audit_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        // Ensure action column supports all action types (modify from old ENUM to VARCHAR(50) seamlessly if needed)
        try {
            $pdo->exec("ALTER TABLE `audit_logs` MODIFY COLUMN `action` VARCHAR(50) NOT NULL");
        } catch (Exception $alterEx) {}
        $checked = true;
    } catch (Exception $e) {
        // Table may already exist or DB restricted
    }
}

// 16. Audit Logging Helper
function log_audit(string $action, string $tableName, ?int $recordId = null, string $description = ''): void {
    try {
        ensure_audit_logs_table();
        $pdo = db();
        $user = current_user();
        $userId = $user ? $user['id'] : null;
        $userName = $user ? $user['name'] : 'System';
        $userRole = $user ? $user['role'] : 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, user_name, user_role, action, table_name, record_id, description, ip_address)
            VALUES (:uid, :uname, :urole, :act, :tbl, :rec, :desc, :ip)
        ");
        $stmt->execute([
            ':uid'   => $userId,
            ':uname' => $userName,
            ':urole' => $userRole,
            ':act'   => $action,
            ':tbl'   => $tableName,
            ':rec'   => $recordId,
            ':desc'  => $description,
            ':ip'    => $ip
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}


function ensure_user_account_schema(): void {
    static $checked = false;
    if ($checked) return;
    try {
        $pdo = db();
        $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('is_primary_admin', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_primary_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
        }
        if (!in_array('deletion_requested', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN deletion_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        }
        if (!in_array('deletion_requested_at', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN deletion_requested_at TIMESTAMP NULL DEFAULT NULL AFTER deletion_requested");
        }
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','pending','pending_delete','deleted') NOT NULL DEFAULT 'active'");
        } catch (Exception $e) {}
        // Preserve the original/oldest admin as the primary admin if this is an existing database.
        $pdo->exec("UPDATE users SET is_primary_admin = 1 WHERE role = 'admin' AND id = (SELECT min_id FROM (SELECT MIN(id) AS min_id FROM users WHERE role = 'admin') x) AND is_primary_admin = 0");
        $checked = true;
    } catch (Exception $e) {
        // The SQL package contains the same schema for fresh installations.
    }
}

ensure_user_account_schema();

