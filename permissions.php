<?php
/**
 * AutoCare - Permissions Management & Permission Matrix (Admin Only)
 * Allows Admin to view the Permission Matrix and customize permissions per Employee/Customer
 */
require_once __DIR__ . '/config.php';
require_auth(['admin']);
require_primary_admin();

$pdo = db();
$user = current_user();
$flash = get_flash();
$error = '';
$theme = current_theme();

// ==========================================
// Handle Actions (POST only)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. UPDATE USER PERMISSIONS (From Modal or Form)
    if ($action === 'update_permissions') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $permissionsInput = $_POST['permissions'] ?? []; // array of pkey => 1

        if ($targetUserId > 0) {
            // The primary admin may customize employees, customers, and additional admins.
            // The primary admin account itself is never editable through the matrix.
            $uStmt = $pdo->prepare("SELECT id, name, role, is_primary_admin FROM users WHERE id = :id LIMIT 1");
            $uStmt->execute([':id' => $targetUserId]);
            $targetUser = $uStmt->fetch();

            if ($targetUser && $targetUser['role'] !== 'admin' || ($targetUser && $targetUser['role'] === 'admin' && !(int)$targetUser['is_primary_admin'])) {
                // Admin-management and permission-matrix access remain exclusive to the primary admin.
                unset($permissionsInput['manage_admins']);
                try {
                    // Update overrides for all system permissions
                    foreach (SYSTEM_PERMISSIONS as $pkey => $pInfo) {
                        $isGranted = isset($permissionsInput[$pkey]) && $permissionsInput[$pkey] == '1';
                        set_user_permission_override($targetUserId, $pkey, $isGranted);
                    }

                    log_audit('edit', 'user_permissions', $targetUserId, "تحديث مصفوفة صلاحيات المستخدم: {$targetUser['name']} ({$targetUser['role']})");
                    set_flash('success', "تم حفظ وتحديث صلاحيات المستخدم ({$targetUser['name']}) بنجاح.");
                    header('Location: permissions.php');
                    exit;
                } catch (Exception $e) {
                    $error = 'حدث خطأ أثناء حفظ الصلاحيات: ' . $e->getMessage();
                }
            } else {
                $error = 'لا يمكن تعديل صلاحيات المدير الرئيسي أو المستخدم غير موجود.';
            }
        }
    }

    // 2. TOGGLE SINGLE PERMISSION (Direct Matrix Click)
    elseif ($action === 'toggle_permission') {
        $targetUserId  = (int)($_POST['user_id'] ?? 0);
        $permissionKey = trim($_POST['permission_key'] ?? '');
        $currentState  = (int)($_POST['current_state'] ?? 0);
        $newState      = $currentState === 1 ? false : true;

        if ($targetUserId > 0 && isset(SYSTEM_PERMISSIONS[$permissionKey])) {
            $uStmt = $pdo->prepare("SELECT id, name, role, is_primary_admin FROM users WHERE id = :id LIMIT 1");
            $uStmt->execute([':id' => $targetUserId]);
            $targetUser = $uStmt->fetch();

            $isAdditionalAdmin = $targetUser && $targetUser['role'] === 'admin' && !(int)$targetUser['is_primary_admin'];
            $isEditableTarget = $targetUser && ($targetUser['role'] !== 'admin' || $isAdditionalAdmin);
            $isProtectedAdminPermission = in_array($permissionKey, ['manage_admins'], true);

            if ($isEditableTarget && !$isProtectedAdminPermission) {
                set_user_permission_override($targetUserId, $permissionKey, $newState);
                $stateLabel = $newState ? 'تفعيل' : 'تعطيل';
                log_audit('edit', 'user_permissions', $targetUserId, "{$stateLabel} صلاحية ({$permissionKey}) للمستخدم: {$targetUser['name']}");
                set_flash('success', "تم {$stateLabel} صلاحية (" . SYSTEM_PERMISSIONS[$permissionKey]['name'] . ") للمستخدم {$targetUser['name']}.");
                header('Location: permissions.php');
                exit;
            }
        }
    }

    // 3. RESET USER PERMISSIONS BACK TO ROLE DEFAULT
    elseif ($action === 'reset_permissions') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            $uStmt = $pdo->prepare("SELECT id, name, role, is_primary_admin FROM users WHERE id = :id LIMIT 1");
            $uStmt->execute([':id' => $targetUserId]);
            $targetUser = $uStmt->fetch();

            $isAdditionalAdmin = $targetUser && $targetUser['role'] === 'admin' && !(int)$targetUser['is_primary_admin'];
            $isEditableTarget = $targetUser && ($targetUser['role'] !== 'admin' || $isAdditionalAdmin);

            if ($isEditableTarget) {
                reset_user_permissions($targetUserId);
                log_audit('edit', 'user_permissions', $targetUserId, "إعادة تعيين صلاحيات المستخدم {$targetUser['name']} إلى الوضع الافتراضي للدور");
                set_flash('info', "تمت استعادة الصلاحيات الافتراضية للمستخدم ({$targetUser['name']}).");
                header('Location: permissions.php');
                exit;
            }
        }
    }
}

// ==========================================
// Fetch Users & Data for Matrix
// ==========================================
$roleFilter = $_GET['role'] ?? 'all'; // all, admin, employee, customer
$search = trim($_GET['q'] ?? '');

$query = "SELECT id, name, username, email, phone, role, status, is_primary_admin FROM users WHERE (role IN ('employee', 'customer') OR (role = 'admin' AND is_primary_admin = 0))";
$params = [];

if (in_array($roleFilter, ['admin', 'employee', 'customer'], true)) {
    if ($roleFilter === 'admin') {
        $query .= " AND role = 'admin' AND is_primary_admin = 0";
    } else {
        $query .= " AND role = :role";
        $params[':role'] = $roleFilter;
    }
}

if (!empty($search)) {
    $query .= " AND (name LIKE :q OR username LIKE :q OR phone LIKE :q)";
    $params[':q'] = "%$search%";
}

$query .= " ORDER BY role ASC, name ASC";
$uStmt = $pdo->prepare($query);
$uStmt->execute($params);
$usersList = $uStmt->fetchAll();

// Preload permissions map for all listed users
$usersPermissions = [];
foreach ($usersList as $u) {
    $usersPermissions[$u['id']] = get_user_permissions_map($u['id'], $u['role']);
}

// Group permissions by category for clear presentation
$categories = [];
foreach (SYSTEM_PERMISSIONS as $pkey => $pInfo) {
    $cat = $pInfo['category'];
    if (!isset($categories[$cat])) {
        $categories[$cat] = [];
    }
    $categories[$cat][$pkey] = $pInfo;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - مصفوفة الصلاحيات وإدارة المستخدمين</title>
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

            <div class="user-profile-badge">
                <div class="avatar-circle"><?= mb_substr($user['name'], 0, 1) ?></div>
                <div class="user-meta">
                    <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="user-role-tag">المدير العام</div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item"><span class="icon">📊</span> لوحة المؤشرات</a>
                <a href="employees.php" class="nav-item"><span class="icon">👥</span> إدارة الموظفين</a>
                <a href="customers.php" class="nav-item"><span class="icon">👤</span> إدارة العملاء</a>
                <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> إدارة السيارات</a>
                <a href="services.php" class="nav-item"><span class="icon">🛠️</span> قائمة الخدمات</a>
                <a href="work_orders.php" class="nav-item"><span class="icon">📋</span> أوامر الصيانة</a>
                <a href="invoices.php" class="nav-item"><span class="icon">🧾</span> الفواتير والمدفوعات</a>
                <a href="reports.php" class="nav-item"><span class="icon">📈</span> التقارير المالية</a>
                <a href="permissions.php" class="nav-item active"><span class="icon">🛡️</span> مصفوفة الصلاحيات</a>
                <a href="profile.php" class="nav-item"><span class="icon">⚙️</span> حسابي الشخصي</a>
                <a href="logout.php" class="nav-item logout-link"><span class="icon">🚪</span> تسجيل الخروج</a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Topbar -->
            <header class="topbar">
                <div class="topbar-left">
                    <button type="button" class="mobile-toggle" onclick="toggleSidebar()">☰</button>
                    <h2 class="page-title">🛡️ إدارة ومصفوفة الصلاحيات للمستخدمين</h2>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                        <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                        
                    </button>
                    <span class="system-time">📅 <?= date('Y-m-d') ?></span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm">خروج</a>
                </div>
            </header>

            <div class="content-container">
                <?php if ($flash): ?>
                    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <!-- Explanation Banner -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="flex-between">
                            <div>
                                <h3 style="margin-bottom: 0.3rem;">نظام الصلاحيات المتقدم (Role-Based & User-Specific RBAC)</h3>
                                <p style="margin: 0; font-size: 0.9rem;">
                                    يمكن لمدير النظام تخصيص صلاحيات كل موظف وعميل بشكل فردي ومستقل. اضغط على أي علامة في المصفوفة لتبديلها مباشرة، أو اضغط زر <strong>"تخصيص الصلاحيات"</strong> لإدارة جميع صلاحيات المستخدم دفعة واحدة.
                                </p>
                            </div>
                            <div style="display: flex; gap: 0.8rem; align-items: center; flex-wrap: wrap;">
                                <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.82rem;">
                                    <span class="perm-pill granted default-role">✓</span> صلاحية مفعلة بالدور
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.82rem;">
                                    <span class="perm-pill granted override">✓</span> مخصصة (مفعلة)
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.82rem;">
                                    <span class="perm-pill denied override">✕</span> مخصصة (معطلة)
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters & Search -->
                <div class="card mb-4">
                    <div class="card-body p-3">
                        <form method="GET" action="permissions.php" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; gap: 0.5rem; flex: 1; min-width: 250px;">
                                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control form-control-sm" placeholder="بحث بالاسم أو رقم الهاتف...">
                                <button type="submit" class="btn btn-primary btn-sm">بحث</button>
                                <?php if (!empty($search) || $roleFilter !== 'all'): ?>
                                    <a href="permissions.php" class="btn btn-outline btn-sm">إلغاء الفلتر</a>
                                <?php endif; ?>
                            </div>

                            <div style="display: flex; gap: 0.4rem;">
                                <a href="permissions.php?role=all<?= !empty($search) ? '&q='.urlencode($search) : '' ?>" class="btn <?= $roleFilter === 'all' ? 'btn-primary' : 'btn-outline' ?> btn-sm">كل المستخدمين (<?= count($usersList) ?>)</a>
                                <a href="permissions.php?role=admin<?= !empty($search) ? '&q='.urlencode($search) : '' ?>" class="btn <?= $roleFilter === 'admin' ? 'btn-primary' : 'btn-outline' ?> btn-sm">👑 المدراء فقط</a>
                                <a href="permissions.php?role=employee<?= !empty($search) ? '&q='.urlencode($search) : '' ?>" class="btn <?= $roleFilter === 'employee' ? 'btn-primary' : 'btn-outline' ?> btn-sm">👥 الموظفين فقط</a>
                                <a href="permissions.php?role=customer<?= !empty($search) ? '&q='.urlencode($search) : '' ?>" class="btn <?= $roleFilter === 'customer' ? 'btn-primary' : 'btn-outline' ?> btn-sm">👤 العملاء فقط</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Permission Matrix Table -->
                <div class="card">
                    <div class="card-header flex-between">
                        <h3 class="card-title">مصفوفة الصلاحيات التفاعلية (<?= count($usersList) ?> مستخدم)</h3>
                        <span class="badge badge-primary"><?= count(SYSTEM_PERMISSIONS) ?> وظيفة نظام خاضعة للرقابة</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($usersList)): ?>
                            <div style="padding: 2.5rem; text-align: center; color: var(--text-muted);">
                                لا توجد حسابات مطابقة لبحثك في قائمة المدراء الإضافيين أو الموظفين أو العملاء.
                            </div>
                        <?php else: ?>
                            <div class="matrix-container">
                                <table class="matrix-table">
                                    <thead>
                                        <tr>
                                            <th class="user-col" style="text-align: right;">المستخدم / الدور</th>
                                            <?php foreach (SYSTEM_PERMISSIONS as $pkey => $pInfo): ?>
                                                <th title="<?= htmlspecialchars($pInfo['description']) ?>">
                                                    <div style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 2px;"><?= htmlspecialchars($pInfo['category']) ?></div>
                                                    <div><?= htmlspecialchars($pInfo['name']) ?></div>
                                                </th>
                                            <?php endforeach; ?>
                                            <th>إجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($usersList as $u): 
                                            $uPerms = $usersPermissions[$u['id']];
                                        ?>
                                            <tr>
                                                <td class="user-col">
                                                    <div style="font-weight: 600; color: var(--heading-color);"><?= htmlspecialchars($u['name']) ?></div>
                                                    <div style="font-size: 0.8rem; display: flex; gap: 0.4rem; align-items: center; margin-top: 3px;">
                                                        <span class="badge <?= $u['role'] === 'admin' ? 'badge-danger' : ($u['role'] === 'employee' ? 'badge-primary' : 'badge-warning') ?>" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">
                                                            <?= $u['role'] === 'admin' ? 'أدمن' : ($u['role'] === 'employee' ? 'موظف' : 'عميل') ?>
                                                        </span>
                                                        <span style="color: var(--text-dim); font-size: 0.75rem;"><?= htmlspecialchars($u['phone']) ?></span>
                                                    </div>
                                                </td>

                                                <?php foreach (SYSTEM_PERMISSIONS as $pkey => $pInfo): 
                                                    $pData = $uPerms[$pkey];
                                                    $isGranted = $pData['is_granted'];
                                                    $hasOverride = $pData['has_override'];
                                                ?>
                                                    <td>
                                                        <?php $protectedForAdditionalAdmin = $u['role'] === 'admin' && in_array($pkey, ['manage_admins'], true); ?>
                                                        <?php if ($protectedForAdditionalAdmin): ?>
                                                            <span class="perm-pill denied default-role" title="هذه الصلاحية محصورة بالمدير الرئيسي ولا يمكن منحها للمدير الإضافي">🔒</span>
                                                        <?php else: ?>
                                                            <form method="POST" action="permissions.php" style="display: inline;">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="toggle_permission">
                                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                                <input type="hidden" name="permission_key" value="<?= $pkey ?>">
                                                                <input type="hidden" name="current_state" value="<?= $isGranted ? '1' : '0' ?>">
                                                                <button type="submit" 
                                                                        class="perm-pill <?= $isGranted ? 'granted' : 'denied' ?> <?= $hasOverride ? 'override' : 'default-role' ?>" 
                                                                        title="<?= $isGranted ? 'صلاحية مفعّلة - اضغط للتعطيل' : 'صلاحية معطّلة - اضغط للتفعيل' ?> (<?= htmlspecialchars($pInfo['name']) ?>)">
                                                                    <?= $isGranted ? '✓' : '✕' ?>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>

                                                <td style="white-space: nowrap;">
                                                    <button type="button" class="btn btn-outline btn-sm" onclick="openUserPermModal(<?= htmlspecialchars(json_encode([
                                                        'id' => $u['id'],
                                                        'name' => $u['name'],
                                                        'role' => $u['role'],
                                                        'perms' => $uPerms
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)) ?>)">
                                                        ⚙️ تخصيص
                                                    </button>

                                                    <form method="POST" action="permissions.php" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من استعادة الصلاحيات الافتراضية لهذا المستخدم؟');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="reset_permissions">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="btn btn-outline btn-sm" title="استعادة الصلاحيات الافتراضية للدور">🔄</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- User Permissions Customization Modal -->
    <div class="modal" id="userPermModal">
        <div class="modal-dialog" style="max-width: 680px;">
            <div class="modal-content">
                <form method="POST" action="permissions.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_permissions">
                    <input type="hidden" name="user_id" id="modalUserId" value="0">

                    <div class="modal-header">
                        <h3 class="modal-title" id="modalUserTitle">تخصيص صلاحيات المستخدم</h3>
                        <button type="button" class="modal-close" onclick="closeModal('userPermModal')">✕</button>
                    </div>

                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                        <div style="margin-bottom: 1.2rem; display: flex; justify-content: space-between; align-items: center; background: var(--bg-input); padding: 0.8rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                            <div>
                                <span id="modalUserRoleBadge" class="badge badge-primary"></span>
                                <span id="modalUserName" style="font-weight: bold; margin-right: 0.5rem;"></span>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <button type="button" class="btn btn-outline btn-sm" onclick="setAllModalPerms(true)">تفعيل الكل</button>
                                <button type="button" class="btn btn-outline btn-sm" onclick="setAllModalPerms(false)">تعطيل الكل</button>
                            </div>
                        </div>

                        <?php foreach ($categories as $catName => $catPerms): ?>
                            <div style="margin-bottom: 1.2rem;">
                                <h4 style="font-size: 0.95rem; color: var(--primary); margin-bottom: 0.6rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.3rem;">
                                    📂 <?= htmlspecialchars($catName) ?>
                                </h4>

                                <?php foreach ($catPerms as $pkey => $pInfo): ?>
                                    <div class="perm-item-card">
                                        <div>
                                            <div class="perm-info-title"><?= htmlspecialchars($pInfo['name']) ?></div>
                                            <div class="perm-info-desc"><?= htmlspecialchars($pInfo['description']) ?></div>
                                        </div>
                                        <div>
                                            <label class="perm-switch">
                                                <input type="checkbox" name="permissions[<?= $pkey ?>]" value="1" id="perm_input_<?= $pkey ?>" class="modal-perm-checkbox">
                                                <span class="perm-slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">💾 حفظ الصلاحيات المخصصة</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('userPermModal')">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="app.js"></script>
    <script>
        function openUserPermModal(data) {
            document.getElementById('modalUserId').value = data.id;
            document.getElementById('modalUserTitle').innerText = 'تخصيص صلاحيات: ' + data.name;
            document.getElementById('modalUserName').innerText = data.name;
            
            const badge = document.getElementById('modalUserRoleBadge');
            badge.innerText = data.role === 'admin' ? 'حساب أدمن' : (data.role === 'employee' ? 'حساب موظف' : 'حساب عميل');
            badge.className = 'badge ' + (data.role === 'admin' ? 'badge-danger' : (data.role === 'employee' ? 'badge-primary' : 'badge-warning'));

            // Uncheck all first
            document.querySelectorAll('.modal-perm-checkbox').forEach(cb => {
                cb.checked = false;
            });

            // Set checkboxes based on user perms
            if (data.perms) {
                for (const key in data.perms) {
                    const cb = document.getElementById('perm_input_' + key);
                    if (cb) {
                        cb.checked = data.perms[key].is_granted;
                    }
                }
            }

            openModal('userPermModal');
        }

        function setAllModalPerms(status) {
            document.querySelectorAll('.modal-perm-checkbox').forEach(cb => {
                cb.checked = status;
            });
        }
    </script>
</body>
</html>
