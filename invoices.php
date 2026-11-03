<?php
/**
 * AutoCare - Invoices & Payments Management
 * Automatic Math Calculations, Payments Recording & Printable Invoice
 */
require_once __DIR__ . '/config.php';
require_auth();

$user = current_user();
$pdo = db();
$error = '';
$flash = get_flash();
$theme = current_theme();

$isStaff = in_array($user['role'], ['admin', 'employee'], true);
$isAdmin = ($user['role'] === 'admin');
$canGrantDiscount = $isAdmin || has_permission('give_discount');
$myCustomerId = 0;

if (!$isStaff) {
    require_permission('view_own_data');
    $cStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = :uid LIMIT 1");
    $cStmt->execute([':uid' => $user['id']]);
    $myCustomerId = (int)$cStmt->fetchColumn();
    if ($myCustomerId === 0) {
        die("لم يتم العثور على ملف العميل الخاص بك.");
    }
} else {
    require_permission('manage_invoices');
}

// ==========================================
// Handle POST Actions
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. CREATE INVOICE (Staff with manage_invoices only)
    if ($action === 'create' && $isStaff) {
        require_permission('manage_invoices');
        $workOrderId = (int)($_POST['work_order_id'] ?? 0);
        $subtotal    = (float)($_POST['subtotal'] ?? 0);
        
        // Strict discount rule: Discounts can ONLY be granted by Admin or Employees with give_discount permission
        $requestedDiscount = (float)($_POST['discount'] ?? 0);
        if ($requestedDiscount > 0 && !$canGrantDiscount) {
            $error = 'ليس لديك صلاحية لإعطاء خصم. منح وتطبيق الخصم مقيد بمدير النظام أو الموظفين الحاصلين على صلاحية إعطاء الخصم.';
            $discount = 0.00;
        } else {
            $discount = $canGrantDiscount ? max(0, $requestedDiscount) : 0.00;
        }
        
        $payMethod   = $_POST['payment_method'] ?? 'cash';
        $initialPay  = (float)($_POST['initial_paid'] ?? 0);

        if ($workOrderId <= 0 || $subtotal <= 0) {
            $error = 'يرجى إدخال مبلغ صحيح للفاتورة وتحديد أمر الصيانة.';
        } elseif (empty($error)) {
            try {
                // Fetch work order details
                $woStmt = $pdo->prepare("
                    SELECT wo.customer_id, wo.vehicle_id, wo.order_number, wo.service_id, wo.status AS wo_status, s.price AS service_price
                    FROM work_orders wo
                    LEFT JOIN services s ON wo.service_id = s.id
                    WHERE wo.id = :id
                ");
                $woStmt->execute([':id' => $workOrderId]);
                $wo = $woStmt->fetch();

                if (!$wo) {
                    $error = 'أمر الصيانة غير موجود.';
                } else {
                    // If work order had a catalog service attached, enforce the fixed catalog service price as subtotal
                    if (!empty($wo['service_id']) && (float)$wo['service_price'] > 0) {
                        $subtotal = (float)$wo['service_price'];
                    }

                    // Check if invoice already created for this work order
                    $chk = $pdo->prepare("SELECT id FROM invoices WHERE work_order_id = :woid");
                    $chk->execute([':woid' => $workOrderId]);
                    if ($chk->fetch()) {
                        $error = 'تم إصدار فاتورة مسبقاً لأمر الصيانة هذا!';
                    } else {
                        $pdo->beginTransaction();

                        // Calculate totals automatically: Total = Subtotal - Discount
                        $total = max(0, $subtotal - $discount);
                        $paid = min($total, max(0, $initialPay));
                        
                        $status = 'unpaid';
                        if ($paid >= $total && $total > 0) {
                            $status = 'paid';
                        } elseif ($paid > 0) {
                            $status = 'partial';
                        }

                        // Unique invoice number: INV-YYYYMM-XXXX
                        $invNumber = 'INV-' . date('Ym') . '-' . str_pad((string)random_int(100, 9999), 4, '0', STR_PAD_LEFT);

                        $insStmt = $pdo->prepare("
                            INSERT INTO invoices (invoice_number, work_order_id, customer_id, vehicle_id, subtotal, discount, total_amount, paid_amount, payment_method, status)
                            VALUES (:num, :woid, :cid, :vid, :sub, :disc, :tot, :paid, :meth, :status)
                        ");
                        $insStmt->execute([
                            ':num'    => $invNumber,
                            ':woid'   => $workOrderId,
                            ':cid'    => $wo['customer_id'],
                            ':vid'    => $wo['vehicle_id'],
                            ':sub'    => $subtotal,
                            ':disc'   => $discount,
                            ':tot'    => $total,
                            ':paid'   => $paid,
                            ':meth'   => $payMethod,
                            ':status' => $status
                        ]);
                        $invoiceId = (int)$pdo->lastInsertId();

                        // Record first payment if made
                        if ($paid > 0) {
                            $payStmt = $pdo->prepare("
                                INSERT INTO payments (invoice_id, amount, payment_method, notes)
                                VALUES (:invid, :amt, :meth, 'دفعة أولى عند إصدار الفاتورة')
                            ");
                            $payStmt->execute([
                                ':invid' => $invoiceId,
                                ':amt'   => $paid,
                                ':meth'  => $payMethod
                            ]);
                            $newPayId = (int)$pdo->lastInsertId();
                            log_audit('add_payment', 'payments', $newPayId, "دفعة أولى للفاتورة #{$invNumber} بمبلغ {$paid} ر.س");
                        }

                        // Maintain permanent vehicle stage (do not regress delivered status)
                        $curWoStatus = $wo['wo_status'] ?? 'completed';
                        $targetWoStatus = ($curWoStatus === 'delivered') ? 'delivered' : 'completed';

                        $updWo = $pdo->prepare("UPDATE work_orders SET status = :st, actual_cost = :cost WHERE id = :woid");
                        $updWo->execute([':st' => $targetWoStatus, ':cost' => $total, ':woid' => $workOrderId]);

                        $pdo->commit();

                        log_audit('create', 'invoices', $invoiceId, "إصدار فاتورة جديدة #{$invNumber} لأمر صيانة #{$wo['order_number']} بقيمة إجمالية {$subtotal} ر.س وخصم {$discount} ر.س (الصافي: {$total} ر.س)");

                        set_flash('success', 'تم إصدار الفاتورة رقم ' . $invNumber . ' بنجاح.');
                        header('Location: invoices.php?view=' . $invoiceId);
                        exit;
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'خطأ في إصدار الفاتورة: ' . $e->getMessage();
            }
        }
    }

    // 2. ADD PAYMENT TO EXISTING INVOICE (Staff Only)
    elseif ($action === 'add_payment' && $isStaff) {
        require_permission('manage_invoices');
        $invoiceId  = (int)($_POST['invoice_id'] ?? 0);
        $amount     = (float)($_POST['amount'] ?? 0);
        $payMethod  = $_POST['payment_method'] ?? 'cash';
        $notes      = trim($_POST['notes'] ?? '');

        if ($invoiceId <= 0 || $amount <= 0) {
            $error = 'يرجى إدخال مبلغ دفع صالح.';
        } else {
            try {
                $pdo->beginTransaction();

                $invStmt = $pdo->prepare("
                    SELECT i.*, c.full_name AS customer_name, c.phone AS customer_phone 
                    FROM invoices i 
                    JOIN customers c ON i.customer_id = c.id
                    WHERE i.id = :id FOR UPDATE
                ");
                $invStmt->execute([':id' => $invoiceId]);
                $inv = $invStmt->fetch();

                if (!$inv) {
                    $pdo->rollBack();
                    $error = 'الفاتورة غير موجودة.';
                } else {
                    $remaining = $inv['total_amount'] - $inv['paid_amount'];
                    if ($amount > $remaining) {
                        $pdo->rollBack();
                        $error = 'المبلغ المدخل (' . $amount . ') أكبر من الرصيد المتبقي (' . $remaining . ').';
                    } else {
                        // Insert payment record
                        $payStmt = $pdo->prepare("
                            INSERT INTO payments (invoice_id, amount, payment_method, notes)
                            VALUES (:invid, :amt, :meth, :notes)
                        ");
                        $payStmt->execute([
                            ':invid' => $invoiceId,
                            ':amt'   => $amount,
                            ':meth'  => $payMethod,
                            ':notes' => $notes ?: 'سداد دفعة'
                        ]);
                        $payId = (int)$pdo->lastInsertId();

                        // Update invoice totals and status
                        $newPaid = $inv['paid_amount'] + $amount;
                        $newStatus = ($newPaid >= $inv['total_amount']) ? 'paid' : 'partial';

                        $updInv = $pdo->prepare("UPDATE invoices SET paid_amount = :paid, status = :status WHERE id = :id");
                        $updInv->execute([':paid' => $newPaid, ':status' => $newStatus, ':id' => $invoiceId]);

                        $pdo->commit();

                        log_audit('add_payment', 'payments', $payId, "تسجيل سند قبض ودفعة بمبلغ {$amount} ر.س للفاتورة #{$inv['invoice_number']}");

                        set_flash('success', 'تم تسجيل سند القبض والدفعة بنجاح.');
                        header('Location: invoices.php?view=' . $invoiceId);
                        exit;
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'خطأ في معالجة الدفع: ' . $e->getMessage();
            }
        }
    }

    // 3. CUSTOMER SELF-PAYMENT (Customer Portal)
    elseif ($action === 'customer_pay' && !$isStaff) {
        $invoiceId  = (int)($_POST['invoice_id'] ?? 0);
        $amount     = (float)($_POST['amount'] ?? 0);
        $payMethod  = $_POST['payment_method'] ?? 'card';
        $notes      = trim($_POST['notes'] ?? 'سداد إلكتروني من حساب العميل');

        if ($invoiceId <= 0 || $amount <= 0) {
            $error = 'يرجى إدخال مبلغ دفع صالح.';
        } else {
            try {
                $pdo->beginTransaction();

                $invStmt = $pdo->prepare("
                    SELECT i.*, c.full_name AS customer_name, c.phone AS customer_phone 
                    FROM invoices i
                    JOIN customers c ON i.customer_id = c.id
                    WHERE i.id = :id AND i.customer_id = :cid FOR UPDATE
                ");
                $invStmt->execute([':id' => $invoiceId, ':cid' => $myCustomerId]);
                $inv = $invStmt->fetch();

                if (!$inv) {
                    $pdo->rollBack();
                    $error = 'الفاتورة غير موجودة أو غير مسجلة بحسابك.';
                } else {
                    $remaining = max(0, (float)$inv['total_amount'] - (float)$inv['paid_amount']);
                    if ($remaining <= 0) {
                        $pdo->rollBack();
                        $error = 'هذه الفاتورة مسددة بالكامل بالفعل.';
                    } elseif ($amount > $remaining) {
                        $pdo->rollBack();
                        $error = 'المبلغ المدخل (' . $amount . ' ر.س) أكبر من الرصيد المتبقي المستحق (' . $remaining . ' ر.س).';
                    } else {
                        // Record payment
                        $payStmt = $pdo->prepare("
                            INSERT INTO payments (invoice_id, amount, payment_method, notes)
                            VALUES (:invid, :amt, :meth, :notes)
                        ");
                        $payStmt->execute([
                            ':invid' => $invoiceId,
                            ':amt'   => $amount,
                            ':meth'  => $payMethod,
                            ':notes' => $notes
                        ]);
                        $payId = (int)$pdo->lastInsertId();

                        // Update invoice status and paid amount
                        $newPaid = (float)$inv['paid_amount'] + $amount;
                        $newStatus = ($newPaid >= (float)$inv['total_amount']) ? 'paid' : 'partial';

                        $updInv = $pdo->prepare("UPDATE invoices SET paid_amount = :paid, status = :status WHERE id = :id");
                        $updInv->execute([':paid' => $newPaid, ':status' => $newStatus, ':id' => $invoiceId]);

                        $pdo->commit();

                        // Send Real-time Notification to Staff & Admin with (Customer Name, Phone, Date, Method, Amount)
                        add_payment_notification(
                            $inv['customer_name'],
                            $inv['customer_phone'] ?: 'غير مسجل',
                            $amount,
                            $payMethod,
                            $invoiceId
                        );

                        log_audit('add_payment', 'payments', $payId, "سداد إلكتروني ناجح من العميل {$inv['customer_name']} بمبلغ {$amount} ر.س للفاتورة #{$inv['invoice_number']}");

                        $msg = ($newStatus === 'paid') 
                            ? 'تم سداد الفاتورة بالكامل بنجاح!' 
                            : 'تم تسليم جزء من المبلغ بنجاح، والمتبقي هو ' . format_money(max(0, (float)$inv['total_amount'] - $newPaid)) . '.';

                        set_flash('success', $msg);
                        header('Location: invoices.php?view=' . $invoiceId);
                        exit;
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'خطأ في معالجة الدفع: ' . $e->getMessage();
            }
        }
    }
}

// ==========================================
// View Single Invoice Details / Print View
// ==========================================
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : (isset($_GET['print']) ? (int)$_GET['print'] : 0);
$isPrintMode = isset($_GET['print']);
$viewInvoice = null;
$paymentsHistory = [];

if ($viewId > 0) {
    $stmt = $pdo->prepare("
        SELECT i.*, 
               c.full_name AS customer_name, c.phone AS customer_phone, c.email AS customer_email, c.address AS customer_address,
               v.plate_number, v.plate_letters, v.make, v.model, v.year, v.color, v.vin,
               wo.order_number, wo.issue_description, wo.diagnosis_notes,
               s.name AS service_name
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        JOIN vehicles v ON i.vehicle_id = v.id
        JOIN work_orders wo ON i.work_order_id = wo.id
        LEFT JOIN services s ON wo.service_id = s.id
        WHERE i.id = :id
    ");
    $stmt->execute([':id' => $viewId]);
    $viewInvoice = $stmt->fetch();

    // IDOR check for customers
    if ($viewInvoice && !$isStaff && (int)$viewInvoice['customer_id'] !== $myCustomerId) {
        http_response_code(403);
        die("غير مصرح لك بمشاهدة أو طباعة هذه الفاتورة.");
    }

    if ($viewInvoice) {
        $pStmt = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = :id ORDER BY id ASC");
        $pStmt->execute([':id' => $viewId]);
        $paymentsHistory = $pStmt->fetchAll();
    }
}

// ==========================================
// Check if redirected from work order with `create_from`
// ==========================================
$createFromWo = null;
if ($isStaff && isset($_GET['create_from'])) {
    $woId = (int)$_GET['create_from'];
    $chkStmt = $pdo->prepare("
        SELECT wo.*, c.full_name AS customer_name, v.make, v.model, v.plate_number, s.price AS service_price
        FROM work_orders wo
        JOIN customers c ON wo.customer_id = c.id
        JOIN vehicles v ON wo.vehicle_id = v.id
        LEFT JOIN services s ON wo.service_id = s.id
        WHERE wo.id = :id
    ");
    $chkStmt->execute([':id' => $woId]);
    $createFromWo = $chkStmt->fetch();
}

// ==========================================
// List Invoices
// ==========================================
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$listQuery = "
    SELECT i.*, 
           c.full_name AS customer_name, c.phone AS customer_phone,
           v.plate_number, v.make, v.model,
           wo.order_number
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    JOIN vehicles v ON i.vehicle_id = v.id
    JOIN work_orders wo ON i.work_order_id = wo.id
    WHERE 1=1
";
$params = [];

if (!$isStaff) {
    $listQuery .= " AND i.customer_id = :cid";
    $params[':cid'] = $myCustomerId;
}

if (!empty($statusFilter)) {
    $listQuery .= " AND i.status = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($search)) {
    $listQuery .= " AND (i.invoice_number LIKE :s1 OR wo.order_number LIKE :s2 OR c.full_name LIKE :s3 OR v.plate_number LIKE :s4)";
    $params[':s1'] = "%{$search}%";
    $params[':s2'] = "%{$search}%";
    $params[':s3'] = "%{$search}%";
    $params[':s4'] = "%{$search}%";
}

$listQuery .= " ORDER BY i.id DESC";
$stmt = $pdo->prepare($listQuery);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Eligible completed work orders without invoices
$eligibleOrders = $isStaff ? $pdo->query("
    SELECT wo.id, wo.order_number, wo.actual_cost, wo.estimated_cost, wo.service_id, s.price AS service_price, c.full_name, v.plate_number, v.make, v.model
    FROM work_orders wo
    JOIN customers c ON wo.customer_id = c.id
    JOIN vehicles v ON wo.vehicle_id = v.id
    LEFT JOIN services s ON wo.service_id = s.id
    LEFT JOIN invoices inv ON inv.work_order_id = wo.id
    WHERE inv.id IS NULL
    ORDER BY wo.id DESC
")->fetchAll() : [];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - <?= $isPrintMode ? 'طباعة الفاتورة #' . htmlspecialchars($viewInvoice['invoice_number'] ?? '') : 'الفواتير والمدفوعات' ?></title>
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
    <?php if ($isPrintMode): ?>
        <style>
            @media print {
                body { background: #fff !important; color: #000 !important; }
                .dashboard-wrapper, .sidebar, .topbar, .no-print { display: none !important; }
                .print-invoice-card { box-shadow: none !important; border: 1px solid #ddd !important; width: 100% !important; margin: 0 !important; }
            }
        </style>
    <?php endif; ?>
</head>
<body class="dashboard-body <?= $isPrintMode ? 'print-page-body' : '' ?>">
    <?php if ($isPrintMode && $viewInvoice): ?>
        <!-- ================= PRINTABLE INVOICE VIEW ================= -->
        <div class="print-container">
            <div class="no-print mb-3 flex-between">
                <a href="invoices.php?view=<?= $viewInvoice['id'] ?>" class="btn btn-secondary">← عودة للفاتورة</a>
                <button onclick="window.print()" class="btn btn-primary">🖨️ طباعة الفاتورة الآن</button>
            </div>

            <div class="card print-invoice-card">
                <!-- Header -->
                <div class="invoice-header-box">
                    <div class="invoice-brand">
                        <h2>مركز AutoCare لصيانة السيارات</h2>
                        <p>المركز المتخصص في الفحص والصيانة الميكانيكية والكهربائية</p>
                        <p class="small text-muted">الرقم الضريبي: 310029384700003 | هاتف: 920001234</p>
                    </div>
                    <div class="invoice-meta-box">
                        <div class="invoice-badge-title">فاتورة صيانة ضريبية</div>
                        <div class="inv-meta-row"><strong>رقم الفاتورة:</strong> #<?= htmlspecialchars($viewInvoice['invoice_number']) ?></div>
                        <div class="inv-meta-row"><strong>تاريخ الفاتورة:</strong> <?= htmlspecialchars(substr($viewInvoice['created_at'], 0, 10)) ?></div>
                        <div class="inv-meta-row"><strong>رقم أمر الصيانة:</strong> #<?= htmlspecialchars($viewInvoice['order_number']) ?></div>
                        <div class="inv-meta-row"><strong>حالة السداد:</strong> <?= status_badge($viewInvoice['status']) ?></div>
                    </div>
                </div>

                <?php 
                    $printRemaining = max(0, (float)$viewInvoice['total_amount'] - (float)$viewInvoice['paid_amount']);
                    if ($viewInvoice['status'] === 'partial' || ((float)$viewInvoice['paid_amount'] > 0 && $printRemaining > 0)): 
                ?>
                    <div class="alert alert-warning my-3" style="border: 1px dashed #f59e0b; background: #fffbeb; color: #92400e; padding: 12px; border-radius: 8px;">
                        ⚠️ <strong>حالة السداد: تم تسليم جزء من المبلغ</strong> — تم استلام مبلغ <strong><?= format_money($viewInvoice['paid_amount']) ?></strong> من إجمالي <strong><?= format_money($viewInvoice['total_amount']) ?></strong>، والمبلغ المتبقي المستحق هو <strong><?= format_money($printRemaining) ?></strong>.
                    </div>
                <?php endif; ?>

                <hr class="my-3">

                <!-- Client and Vehicle details -->
                <div class="grid grid-2 my-3">
                    <div class="p-2 border-rounded">
                        <h4 class="mb-2">بيانات العميل:</h4>
                        <div><strong>الاسم:</strong> <?= htmlspecialchars($viewInvoice['customer_name']) ?></div>
                        <div><strong>رقم الجوال:</strong> <?= htmlspecialchars($viewInvoice['customer_phone']) ?></div>
                        <div><strong>العنوان:</strong> <?= htmlspecialchars($viewInvoice['customer_address'] ?: 'الرياض - المملكة العربية السعودية') ?></div>
                    </div>
                    <div class="p-2 border-rounded">
                        <h4 class="mb-2">بيانات المركبة:</h4>
                        <div><strong>السيارة:</strong> <?= htmlspecialchars($viewInvoice['make'] . ' ' . $viewInvoice['model'] . ' (' . $viewInvoice['year'] . ')') ?></div>
                        <div><strong>رقم اللوحة:</strong> <?= htmlspecialchars($viewInvoice['plate_number'] . ' ' . ($viewInvoice['plate_letters'] ?? '')) ?></div>
                        <div><strong>اللون:</strong> <?= htmlspecialchars($viewInvoice['color'] ?: 'غير محدد') ?></div>
                        <div><strong>رقم الهيكل VIN:</strong> <?= htmlspecialchars($viewInvoice['vin'] ?: '-') ?></div>
                    </div>
                </div>

                <!-- Invoice items -->
                <table class="data-table my-3">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>بيان الخدمة / الصيانة المنجزة</th>
                            <th>الملاحظات</th>
                            <th class="text-left">المبلغ (ر.س)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>
                                <strong><?= htmlspecialchars($viewInvoice['service_name'] ?: 'أعمال صيانة وإصلاح وفحص') ?></strong>
                                <div class="small text-muted"><?= htmlspecialchars($viewInvoice['issue_description']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($viewInvoice['diagnosis_notes'] ?: 'تم استكمال الفحص والإصلاح بنجاح') ?></td>
                            <td class="text-left"><strong><?= format_money($viewInvoice['subtotal']) ?></strong></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Summary math -->
                <div class="invoice-summary-grid my-3">
                    <div class="payment-notes">
                        <h4>ملاحظات الضمان والشروط:</h4>
                        <p class="small text-muted">
                            - يسري الضمان على قطع الغيار وأجور اليد لمدة 30 يوماً من تاريخ صدور الفاتورة.<br>
                            - المركز غير مسؤول عن أي أعطال ناتجة عن سوء الاستخدام بعد الاستلام.<br>
                            - شكراً لثقتكم واختياركم مركز AutoCare!
                        </p>
                    </div>
                    <div class="invoice-totals-box">
                        <div class="total-line"><span>المبلغ الإجمالي:</span> <strong><?= format_money($viewInvoice['subtotal']) ?></strong></div>
                        <div class="total-line"><span>الخصم المطبق:</span> <strong class="text-danger">- <?= format_money($viewInvoice['discount']) ?></strong></div>
                        <div class="total-line grand-total"><span>القيمة بعد الخصم (الصافي):</span> <strong><?= format_money($viewInvoice['total_amount']) ?></strong></div>
                        <div class="total-line"><span>المبلغ المسدد:</span> <strong class="text-success"><?= format_money($viewInvoice['paid_amount']) ?></strong></div>
                        <div class="total-line balance-due"><span>المتبقي المستحق:</span> <strong><?= format_money(max(0, $viewInvoice['total_amount'] - $viewInvoice['paid_amount'])) ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- ================= NORMAL DASHBOARD INVOICE VIEW ================= -->
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
                    <a href="vehicles.php" class="nav-item"><span class="icon">🚗</span> <?= $isStaff ? 'إدارة السيارات' : 'سياراتي' ?></a>
                    <?php if (has_permission('manage_services')): ?>
                        <a href="services.php" class="nav-item"><span class="icon">🛠️</span> قائمة الخدمات</a>
                    <?php endif; ?>
                    <a href="work_orders.php" class="nav-item"><span class="icon">📋</span> أوامر الصيانة</a>
                    <a href="invoices.php" class="nav-item active"><span class="icon">🧾</span> الفواتير</a>
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
                        <h2 class="page-title"><?= $isStaff ? 'إدارة الفواتير والمدفوعات' : 'فواتير الصيانة وسجل المدفوعات' ?></h2>
                    </div>
                    <div class="topbar-right">
                        <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                            <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                            
                        </button>
                        <?php if ($isStaff && has_permission('manage_invoices')): ?>
                            <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addInvoiceModal')">➕ إصدار فاتورة جديدة</button>
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

                    <!-- Single Invoice Detail Card -->
                    <?php if ($viewInvoice): ?>
                        <?php $remaining = max(0, (float)$viewInvoice['total_amount'] - (float)$viewInvoice['paid_amount']); ?>
                        <div class="card mb-4">
                            <div class="card-header flex-between">
                                <div>
                                    <h3>فاتورة صيانة: <span class="text-primary">#<?= htmlspecialchars($viewInvoice['invoice_number']) ?></span></h3>
                                    <span class="text-muted small">تاريخ الإصدار: <?= htmlspecialchars($viewInvoice['created_at']) ?></span>
                                </div>
                                <div class="flex-gap">
                                    <?php if (!$isStaff && $remaining > 0): ?>
                                        <button type="button" class="btn btn-sm btn-success" onclick="openModal('customerPayModal')">💳 سداد / دفع الفاتورة</button>
                                    <?php endif; ?>
                                    <a href="invoices.php?print=<?= $viewInvoice['id'] ?>" target="_blank" class="btn btn-sm btn-secondary">🖨️ طباعة الفاتورة</a>
                                    <a href="invoices.php" class="btn btn-sm btn-outline">← عودة للفواتير</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($viewInvoice['status'] === 'partial' || ((float)$viewInvoice['paid_amount'] > 0 && $remaining > 0)): ?>
                                    <div class="alert alert-warning mb-3">
                                        ⚠️ <strong>حالة السداد: تم تسليم جزء من المبلغ</strong> — تم استلام <strong><?= format_money($viewInvoice['paid_amount']) ?></strong> من إجمالي <strong><?= format_money($viewInvoice['total_amount']) ?></strong>، والمبلغ المتبقي المستحق هو <strong class="text-danger font-bold"><?= format_money($remaining) ?></strong>.
                                    </div>
                                <?php endif; ?>

                                <div class="grid grid-3 mb-4">
                                    <div class="info-box">
                                        <span class="info-label">العميل</span>
                                        <strong><?= htmlspecialchars($viewInvoice['customer_name']) ?></strong>
                                        <p class="text-muted small">📞 <?= htmlspecialchars($viewInvoice['customer_phone']) ?></p>
                                    </div>
                                    <div class="info-box">
                                        <span class="info-label">السيارة</span>
                                        <strong><?= htmlspecialchars($viewInvoice['make'] . ' ' . $viewInvoice['model'] . ' ' . $viewInvoice['year']) ?></strong>
                                        <p class="text-muted small">لوحة: <?= htmlspecialchars($viewInvoice['plate_number'] . ' ' . ($viewInvoice['plate_letters'] ?? '')) ?></p>
                                    </div>
                                    <div class="info-box">
                                        <span class="info-label">حالة السداد</span>
                                        <div><?= status_badge($viewInvoice['status']) ?></div>
                                        <p class="text-muted small">طريقة الدفع: <?= $viewInvoice['payment_method'] === 'cash' ? 'نقدي' : ($viewInvoice['payment_method'] === 'card' ? 'شبكة/بطاقة' : 'تحويل') ?></p>
                                    </div>
                                </div>

                                <div class="grid grid-2 mb-4">
                                    <div class="card bg-darker p-3">
                                        <h4>تفاصيل المبالغ والحسابات:</h4>
                                        <div class="total-line"><span>المبلغ الإجمالي الأولي:</span> <strong><?= format_money($viewInvoice['subtotal']) ?></strong></div>
                                        <div class="total-line"><span>الخصم المطبق:</span> <strong class="text-danger">- <?= format_money($viewInvoice['discount']) ?></strong></div>
                                        <div class="total-line grand-total"><span>القيمة بعد الخصم (الصافي):</span> <strong><?= format_money($viewInvoice['total_amount']) ?></strong></div>
                                        <div class="total-line"><span>المسدد فعلياً:</span> <strong class="text-success"><?= format_money($viewInvoice['paid_amount']) ?></strong></div>
                                        <div class="total-line balance-due"><span>الرصيد المتبقي:</span> <strong class="<?= $remaining > 0 ? 'text-danger' : 'text-success' ?>"><?= format_money($remaining) ?></strong></div>
                                    </div>

                                    <!-- Payments History & Add Payment Form -->
                                    <div class="card bg-darker p-3">
                                        <h4>سجل المقبوضات والدفعات:</h4>
                                        <?php if (!empty($paymentsHistory)): ?>
                                            <ul class="payment-receipt-list mb-3">
                                                <?php foreach ($paymentsHistory as $p): ?>
                                                    <li>
                                                        <span><?= htmlspecialchars(substr($p['created_at'], 0, 10)) ?> - <?= htmlspecialchars($p['notes'] ?: 'دفعة') ?></span>
                                                        <strong><?= format_money($p['amount']) ?></strong>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="text-muted small">لا توجد دفعات مسجلة.</p>
                                        <?php endif; ?>

                                        <?php if ($isStaff && $remaining > 0): ?>
                                            <hr class="my-2">
                                            <h5>تسجيل سند قبض / دفعة جديدة</h5>
                                            <form method="POST" action="invoices.php" class="form-grid mt-2">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="add_payment">
                                                <input type="hidden" name="invoice_id" value="<?= $viewInvoice['id'] ?>">

                                                <div class="grid grid-2">
                                                    <div class="form-group">
                                                        <label for="p_amt">المبلغ المدفوع (ر.س) *</label>
                                                        <input type="number" step="0.01" max="<?= $remaining ?>" id="p_amt" name="amount" class="form-control" required value="<?= $remaining ?>">
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="p_meth">طريقة الدفع</label>
                                                        <select id="p_meth" name="payment_method" class="form-control">
                                                            <option value="cash">نقداً (Cash)</option>
                                                            <option value="card">مدى / بطاقة ائتمان (Card)</option>
                                                            <option value="bank_transfer">تحويل بنكي (Transfer)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <button type="submit" class="btn btn-success btn-block">تسجيل الدفعة الآن</button>
                                            </form>
                                        <?php elseif (!$isStaff && $remaining > 0): ?>
                                            <hr class="my-2">
                                            <button type="button" class="btn btn-success btn-block" onclick="openModal('customerPayModal')">💳 سداد الفاتورة إلكترونياً (المتبقي: <?= format_money($remaining) ?>)</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Invoices List Table -->
                    <div class="card">
                        <div class="card-header flex-between">
                            <h3>سجل الفواتير (<?= count($invoices) ?>)</h3>
                            <form method="GET" action="invoices.php" class="search-form">
                                <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                                    <option value="">جميع الحالات</option>
                                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>مدفوع بالكامل</option>
                                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>مدفوع جزئياً</option>
                                    <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>غير مدفوع</option>
                                </select>
                                <input type="text" name="q" class="form-control form-control-sm" placeholder="رقم الفاتورة، العميل، اللوحة..." value="<?= clean($search) ?>">
                                <button type="submit" class="btn btn-sm btn-secondary">بحث</button>
                                <?php if (!empty($search) || !empty($statusFilter)): ?>
                                    <a href="invoices.php" class="btn btn-sm btn-outline">إلغاء</a>
                                <?php endif; ?>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>رقم الفاتورة</th>
                                        <th>أمر الصيانة</th>
                                        <th>العميل</th>
                                        <th>السيارة</th>
                                        <th>المبلغ الإجمالي</th>
                                        <th>الخصم</th>
                                        <th>القيمة بعد الخصم</th>
                                        <th>المدفوع</th>
                                        <th>المتبقي</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($invoices)): ?>
                                        <?php foreach ($invoices as $inv): ?>
                                            <?php 
                                                $sub = (float)$inv['subtotal'] > 0 ? (float)$inv['subtotal'] : ((float)$inv['total_amount'] + (float)($inv['discount'] ?? 0));
                                                $disc = (float)($inv['discount'] ?? 0);
                                                $valAfterDisc = (float)$inv['total_amount'];
                                                $rem = max(0, $valAfterDisc - (float)$inv['paid_amount']); 
                                            ?>
                                            <tr>
                                                <td><strong>#<?= htmlspecialchars($inv['invoice_number']) ?></strong></td>
                                                <td>#<?= htmlspecialchars($inv['order_number']) ?></td>
                                                <td><?= htmlspecialchars($inv['customer_name']) ?></td>
                                                <td><?= htmlspecialchars($inv['make'] . ' ' . $inv['model']) ?></td>
                                                <td><?= format_money($sub) ?></td>
                                                <td>
                                                    <?php if ($disc > 0): ?>
                                                        <span class="text-danger font-bold">- <?= format_money($disc) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0.00 ر.س</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong class="text-primary"><?= format_money($valAfterDisc) ?></strong></td>
                                                <td class="text-success"><?= format_money($inv['paid_amount']) ?></td>
                                                <td class="<?= $rem > 0 ? 'text-danger font-bold' : 'text-muted' ?>"><?= format_money($rem) ?></td>
                                                <td><?= status_badge($inv['status']) ?></td>
                                                <td><?= htmlspecialchars(substr($inv['created_at'], 0, 10)) ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="invoices.php?view=<?= $inv['id'] ?>" class="btn btn-sm btn-outline">استعراض</a>
                                                        <a href="invoices.php?print=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-secondary">طباعة</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="12" class="text-center py-4 text-muted">
                                                <?= (!empty($search) || !empty($statusFilter)) ? 'لا توجد فواتير مطابقة لخيارات البحث.' : 'لا توجد فواتير صادرة حتى الآن.' ?>
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

        <!-- Modal: Create Invoice from Work Order -->
        <?php if ($isStaff): ?>
            <div class="modal <?= $createFromWo ? 'active' : '' ?>" id="addInvoiceModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>إصدار فاتورة صيانة جديدة</h3>
                        <button type="button" class="modal-close" onclick="closeModal('addInvoiceModal')">✕</button>
                    </div>
                    <form method="POST" action="invoices.php" class="modal-body form-grid">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">

                        <div class="form-group">
                            <label for="inv_wo">اختيار أمر الصيانة *</label>
                            <select id="inv_wo" name="work_order_id" class="form-control" required onchange="updateSubtotalFromOrder(this)">
                                <option value="">-- اختر أمر الصيانة --</option>
                                <?php if ($createFromWo): ?>
                                    <option value="<?= $createFromWo['id'] ?>" data-cost="<?= $createFromWo['actual_cost'] > 0 ? $createFromWo['actual_cost'] : ($createFromWo['service_price'] ?: $createFromWo['estimated_cost']) ?>" data-fixed="<?= !empty($createFromWo['service_id']) ? '1' : '0' ?>" selected>
                                        #<?= htmlspecialchars($createFromWo['order_number'] . ' - ' . $createFromWo['customer_name'] . ' (' . $createFromWo['make'] . ' ' . $createFromWo['plate_number'] . ')') ?>
                                    </option>
                                <?php endif; ?>
                                <?php foreach ($eligibleOrders as $eo): ?>
                                    <?php if (!$createFromWo || $eo['id'] != $createFromWo['id']): ?>
                                        <option value="<?= $eo['id'] ?>" data-cost="<?= $eo['actual_cost'] > 0 ? $eo['actual_cost'] : ($eo['service_price'] ?: $eo['estimated_cost']) ?>" data-fixed="<?= !empty($eo['service_id']) ? '1' : '0' ?>">
                                            #<?= htmlspecialchars($eo['order_number'] . ' - ' . $eo['full_name'] . ' (' . $eo['make'] . ' ' . $eo['plate_number'] . ')') ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-2">
                            <div class="form-group">
                                <label for="inv_sub">المبلغ الإجمالي للخدمات (ر.س) *</label>
                                <input type="number" step="0.01" id="inv_sub" name="subtotal" class="form-control" required value="<?= $createFromWo ? ($createFromWo['actual_cost'] > 0 ? $createFromWo['actual_cost'] : ($createFromWo['service_price'] ?: $createFromWo['estimated_cost'])) : '' ?>" oninput="calculateInvoiceTotal()">
                            </div>
                            <div class="form-group">
                                <label for="inv_disc">الخصم الممنوح (ر.س) <?= !$canGrantDiscount ? '<span class="text-danger small">(يتطلب صلاحية إعطاء الخصم)</span>' : '' ?></label>
                                <input type="number" step="0.01" id="inv_disc" name="discount" class="form-control" value="0.00" <?= !$canGrantDiscount ? 'readonly style="background:#1e293b; color:#94a3b8;"' : '' ?> oninput="calculateInvoiceTotal()">
                            </div>
                        </div>

                        <div class="grid grid-2">
                            <div class="form-group">
                                <label for="inv_tot">القيمة بعد الخصم (ر.س)</label>
                                <input type="text" id="inv_tot" class="form-control" readonly style="background: #202b3c; font-weight: bold; color: #10b981;">
                            </div>
                            <div class="form-group">
                                <label for="inv_paid">الدفعة المسددة الآن (ر.س)</label>
                                <input type="number" step="0.01" id="inv_paid" name="initial_paid" class="form-control" value="0.00">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="inv_meth">طريقة الدفع</label>
                            <select id="inv_meth" name="payment_method" class="form-control">
                                <option value="cash">نقداً (Cash)</option>
                                <option value="card">مدى / بطاقة ائتمانية (Card)</option>
                                <option value="bank_transfer">تحويل بنكي (Bank Transfer)</option>
                            </select>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" onclick="closeModal('addInvoiceModal')">إلغاء</button>
                            <button type="submit" class="btn btn-primary">إصدار الفاتورة</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal: Customer Self-Payment (Customer Portal) -->
        <?php if (!$isStaff && $viewInvoice && (max(0, (float)$viewInvoice['total_amount'] - (float)$viewInvoice['paid_amount']) > 0)): ?>
            <?php $remToPay = max(0, (float)$viewInvoice['total_amount'] - (float)$viewInvoice['paid_amount']); ?>
            <div class="modal" id="customerPayModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>💳 سداد فاتورة صيانة إلكترونياً</h3>
                        <button type="button" class="modal-close" onclick="closeModal('customerPayModal')">✕</button>
                    </div>
                    <form method="POST" action="invoices.php" class="modal-body form-grid">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="customer_pay">
                        <input type="hidden" name="invoice_id" value="<?= $viewInvoice['id'] ?>">

                        <div class="alert alert-info">
                            <strong>رقم الفاتورة:</strong> #<?= htmlspecialchars($viewInvoice['invoice_number']) ?><br>
                            <strong>الرصيد المتبقي المستحق:</strong> <span class="font-bold text-primary"><?= format_money($remToPay) ?></span>
                        </div>

                        <div class="form-group">
                            <label for="cust_pay_amt">المبلغ المراد دفعه (ر.س) *</label>
                            <input type="number" step="0.01" min="1" max="<?= $remToPay ?>" id="cust_pay_amt" name="amount" class="form-control" required value="<?= $remToPay ?>">
                            <span class="text-muted small">يمكنك سداد المبلغ كاملاً أو تسليم جزء من المبلغ دفعةً أولى.</span>
                        </div>

                        <div class="form-group">
                            <label for="cust_pay_meth">طريقة الدفع</label>
                            <select id="cust_pay_meth" name="payment_method" class="form-control">
                                <option value="card">💳 مدى / بطاقة ائتمانية (Mada / Credit Card)</option>
                                <option value="bank_transfer">🏦 تحويل بنكي سريع</option>
                                <option value="cash">💵 نقداً عند الاستلام</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="cust_pay_notes">ملاحظات العميل (اختياري)</label>
                            <input type="text" id="cust_pay_notes" name="notes" class="form-control" placeholder="رقم العملية أو ملاحظات السداد">
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" onclick="closeModal('customerPayModal')">إلغاء</button>
                            <button type="submit" class="btn btn-success">تأكيد عملية الدفع الآن</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <script src="app.js"></script>
        <script>
            function updateSubtotalFromOrder(selectElem) {
                const selectedOption = selectElem.options[selectElem.selectedIndex];
                const cost = selectedOption ? selectedOption.getAttribute('data-cost') : '0';
                const isFixed = selectedOption ? selectedOption.getAttribute('data-fixed') === '1' : false;
                const subInput = document.getElementById('inv_sub');
                if (subInput && cost) {
                    subInput.value = parseFloat(cost) || 0;
                    if (isFixed) {
                        subInput.readOnly = true;
                        subInput.style.background = '#1e293b';
                    } else {
                        subInput.readOnly = false;
                        subInput.style.background = '';
                    }
                    calculateInvoiceTotal();
                }
            }

            function calculateInvoiceTotal() {
                const sub = parseFloat(document.getElementById('inv_sub')?.value || 0);
                const disc = parseFloat(document.getElementById('inv_disc')?.value || 0);
                const total = Math.max(0, sub - disc);
                const totElem = document.getElementById('inv_tot');
                if (totElem) totElem.value = total.toFixed(2) + ' ر.س';
            }

            document.addEventListener('DOMContentLoaded', calculateInvoiceTotal);
        </script>
    <?php endif; ?>
</body>
</html>
