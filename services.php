<?php
/**
 * AutoCare - Services Catalog Management
 */
require_once __DIR__ . '/config.php';
require_auth();
require_permission('manage_services');

$user = current_user();
$pdo = db();
$isAdmin = ($user['role'] === 'admin');
$error = '';
$flash = get_flash();
$theme = current_theme();

// ==========================================
// Handle POST Actions
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. ADD SERVICE
    if ($action === 'add') {
        require_permission('manage_services');
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);

        if (empty($name) || $price <= 0) {
            $error = 'اسم الخدمة والسعر الإيجابي حقول إلزامية.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO services (name, description, price, status)
                    VALUES (:name, :desc, :price, 'active')
                ");
                $stmt->execute([
                    ':name'  => $name,
                    ':desc'  => $description ?: null,
                    ':price' => $price
                ]);
                $newSrvId = (int)$pdo->lastInsertId();
                log_audit('add', 'services', $newSrvId, "إضافة خدمة جديدة: {$name} بسعر {$price} ر.س");
                set_flash('success', 'تمت إضافة خدمة الصيانة بنجاح.');
                header('Location: services.php');
                exit;
            } catch (PDOException $e) {
                $error = 'خطأ في إضافة الخدمة: ' . $e->getMessage();
            }
        }
    }

    // 2. TOGGLE STATUS
    elseif ($action === 'toggle_status') {
        require_permission('manage_services');
        $srvId = (int)($_POST['service_id'] ?? 0);
        if ($srvId > 0) {
            $cur = $pdo->prepare("SELECT name, status FROM services WHERE id = :id");
            $cur->execute([':id' => $srvId]);
            $srv = $cur->fetch();

            if ($srv) {
                $new = ($srv['status'] === 'active') ? 'inactive' : 'active';
                $upd = $pdo->prepare("UPDATE services SET status = :status WHERE id = :id");
                $upd->execute([':status' => $new, ':id' => $srvId]);
                log_audit('toggle_status', 'services', $srvId, "تغيير حالة الخدمة {$srv['name']} إلى " . ($new === 'active' ? 'نشط' : 'معطل'));
                set_flash('success', 'تم تعديل حالة الخدمة بنجاح.');
                header('Location: services.php');
                exit;
            }
        }
    }

    // 3. EDIT SERVICE
    elseif ($action === 'edit') {
        require_permission('manage_services');
        $srvId       = (int)($_POST['service_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if (empty($name) || $price <= 0 || $srvId <= 0) {
            $error = 'يرجى إدخال اسم وسعر الخدمة بشكل صحيح.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE services
                    SET name = :name, description = :desc, price = :price, status = :status
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name'   => $name,
                    ':desc'   => $description ?: null,
                    ':price'  => $price,
                    ':status' => $status,
                    ':id'     => $srvId
                ]);
                log_audit('edit', 'services', $srvId, "تعديل الخدمة: {$name} (السعر: {$price} ر.س)");
                set_flash('success', 'تم تحديث بيانات الخدمة بنجاح.');
                header('Location: services.php');
                exit;
            } catch (PDOException $e) {
                $error = 'خطأ في التحديث: ' . $e->getMessage();
            }
        }
    }

    // 4. DELETE SERVICE (Requires delete_records permission)
    elseif ($action === 'delete') {
        require_permission('delete_records');
        $srvId = (int)($_POST['service_id'] ?? 0);
        if ($srvId > 0) {
            try {
                $srvStmt = $pdo->prepare("SELECT name FROM services WHERE id = :id");
                $srvStmt->execute([':id' => $srvId]);
                $srvName = $srvStmt->fetchColumn();

                if ($srvName) {
                    $pdo->prepare("UPDATE work_orders SET service_id = NULL WHERE service_id = :id")->execute([':id' => $srvId]);
                    
                    $del = $pdo->prepare("DELETE FROM services WHERE id = :id");
                    $del->execute([':id' => $srvId]);

                    log_audit('delete', 'services', $srvId, "حذف الخدمة: {$srvName}");
                    set_flash('success', 'تم حذف الخدمة بنجاح دون التأثير على سجلات أوامر الصيانة السابقة.');
                    header('Location: services.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'خطأ أثناء حذف الخدمة: ' . $e->getMessage();
            }
        }
    }
}


// Fetch all services with usage count
$services = $pdo->query("
    SELECT s.*, 
           (SELECT COUNT(*) FROM work_orders WHERE service_id = s.id) AS usage_count
    FROM services s 
    ORDER BY s.id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - خدمات الصيانة</title>
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
                <?php if (has_permission('manage_customers')): ?>
                    <a href="customers.php" class="nav-item"><span class="icon">👤</span> إدارة العملاء</a>
                <?php endif; ?>
                <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> إدارة السيارات</a>
                <a href="services.php" class="nav-item active"><span class="icon">🛠️</span> قائمة الخدمات</a>
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
                    <h2 class="page-title">دليل خدمات الصيانة والأسعار</h2>
                </div>
                <div class="topbar-right">
                    <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                        <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                        
                    </button>
                    <?php if (has_permission('manage_services')): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addServiceModal')">➕ إضافة خدمة جديدة</button>
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
                        <h3>قائمة الخدمات المتوفرة (<?= count($services) ?>)</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>اسم الخدمة</th>
                                    <th>الوصف</th>
                                    <th>السعر الأساسي</th>
                                    <th>مرات التنفيذ</th>
                                    <th>الحالة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($services)): ?>
                                    <?php foreach ($services as $srv): ?>
                                        <tr>
                                            <td><?= $srv['id'] ?></td>
                                            <td><strong><?= htmlspecialchars($srv['name']) ?></strong></td>
                                            <td><?= htmlspecialchars($srv['description'] ?: 'فحص وصيانة شاملة') ?></td>
                                            <td><strong class="text-success"><?= format_money($srv['price']) ?></strong></td>
                                            <td><span class="badge badge-info"><?= (int)$srv['usage_count'] ?> أمر صيانة</span></td>
                                            <td><?= status_badge($srv['status']) ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button" class="btn btn-sm btn-outline" onclick="openViewServiceModal(<?= htmlspecialchars(json_encode($srv)) ?>)">👁️ عرض</button>
                                                    <?php if (has_permission('manage_services')): ?>
                                                        <button type="button" class="btn btn-sm btn-primary btn-icon" onclick="openEditServiceModal(<?= htmlspecialchars(json_encode($srv)) ?>)" title="تعديل" aria-label="تعديل">✏️</button>
                                                        <form method="POST" action="services.php" style="display:inline;">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_status">
                                                            <input type="hidden" name="service_id" value="<?= $srv['id'] ?>">
                                                            <button type="submit" class="btn btn-sm <?= $srv['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                                                <?= $srv['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if (has_permission('delete_records')): ?>
                                                        <form method="POST" action="services.php" style="display:inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذه الخدمة؟ لن تتأثر أوامر الصيانة السابقة.');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="service_id" value="<?= $srv['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="حذف" aria-label="حذف">🗑️</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            لا توجد خدمات مضافة في قاعدة البيانات حتى الآن.
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

    <!-- Modal: View Service -->
    <div class="modal" id="viewServiceModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>تفاصيل خدمة الصيانة</h3>
                <button type="button" class="modal-close" onclick="closeModal('viewServiceModal')">✕</button>
            </div>
            <div class="modal-body form-grid">
                <div class="form-group">
                    <label>اسم الخدمة</label>
                    <div id="view_srv_name" class="form-control" style="background:#202b3c; font-weight:bold;"></div>
                </div>
                <div class="form-group">
                    <label>السعر الأساسي المعتمد</label>
                    <div id="view_srv_price" class="form-control text-success" style="background:#202b3c; font-weight:bold;"></div>
                </div>
                <div class="form-group">
                    <label>الحالة الحالية</label>
                    <div id="view_srv_status" class="form-control" style="background:#202b3c;"></div>
                </div>
                <div class="form-group">
                    <label>عدد أوامر الصيانة المنفذة بهذه الخدمة</label>
                    <div id="view_srv_usage" class="form-control" style="background:#202b3c;"></div>
                </div>
                <div class="form-group">
                    <label>الوصف والبنود المشمولة</label>
                    <div id="view_srv_desc" class="form-control" style="background:#202b3c; min-height:80px; white-space:pre-line;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('viewServiceModal')">إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Add Service -->
    <?php if ($isAdmin): ?>
        <div class="modal" id="addServiceModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>إضافة خدمة صيانة جديدة</h3>
                    <button type="button" class="modal-close" onclick="closeModal('addServiceModal')">✕</button>
                </div>
                <form method="POST" action="services.php" class="modal-body form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">

                    <div class="form-group">
                        <label for="s_name">اسم الخدمة *</label>
                        <input type="text" id="s_name" name="name" class="form-control" required placeholder="مثال: فحص كمبيوتر كامل وبرمجة">
                    </div>

                    <div class="form-group">
                        <label for="s_price">السعر الافتراضي المعتمد (ر.س) *</label>
                        <input type="number" step="0.01" id="s_price" name="price" class="form-control" required placeholder="150.00">
                    </div>

                    <div class="form-group">
                        <label for="s_desc">وصف تفصيلي للخدمة</label>
                        <textarea id="s_desc" name="description" class="form-control" rows="3" placeholder="تفاصيل ما تشمله الخدمة..."></textarea>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addServiceModal')">إلغاء</button>
                        <button type="submit" class="btn btn-primary">حفظ الخدمة</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: Edit Service -->
        <div class="modal" id="editServiceModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>تعديل بيانات الخدمة</h3>
                    <button type="button" class="modal-close" onclick="closeModal('editServiceModal')">✕</button>
                </div>
                <form method="POST" action="services.php" class="modal-body form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="service_id" id="edit_srv_id">

                    <div class="form-group">
                        <label for="edit_s_name">اسم الخدمة *</label>
                        <input type="text" id="edit_s_name" name="name" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_s_price">السعر الافتراضي المعتمد (ر.س) *</label>
                        <input type="number" step="0.01" id="edit_s_price" name="price" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_s_status">حالة الخدمة *</label>
                        <select id="edit_s_status" name="status" class="form-control" required>
                            <option value="active">نشط (متاحة للاختيار)</option>
                            <option value="inactive">معطل (غير متاحة للاختيار)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit_s_desc">وصف تفصيلي للخدمة</label>
                        <textarea id="edit_s_desc" name="description" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('editServiceModal')">إلغاء</button>
                        <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script src="app.js"></script>
    <script>
        function openViewServiceModal(srv) {
            document.getElementById('view_srv_name').textContent = srv.name;
            document.getElementById('view_srv_price').textContent = Number(srv.price).toFixed(2) + ' ر.س';
            document.getElementById('view_srv_status').textContent = srv.status === 'active' ? 'نشط (متاح)' : 'معطل';
            document.getElementById('view_srv_usage').textContent = (srv.usage_count || 0) + ' أمر صيانة';
            document.getElementById('view_srv_desc').textContent = srv.description || 'فحص وصيانة عامة';
            openModal('viewServiceModal');
        }

        function openEditServiceModal(srv) {
            document.getElementById('edit_srv_id').value = srv.id;
            document.getElementById('edit_s_name').value = srv.name;
            document.getElementById('edit_s_price').value = srv.price;
            document.getElementById('edit_s_status').value = srv.status;
            document.getElementById('edit_s_desc').value = srv.description || '';
            openModal('editServiceModal');
        }
    </script>
</body>
</html>
