<?php
require_once __DIR__ . '/config.php';
require_auth(['admin']);
require_primary_admin();
ensure_recycle_bin_table();

$pdo = db();
$flash = get_flash();
$error = '';
$theme = current_theme();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $binId = (int)($_POST['bin_id'] ?? 0);

    if ($binId <= 0) {
        $error = 'العنصر غير صالح.';
    } else {
        $st = $pdo->prepare("SELECT * FROM recycle_bin WHERE id=:id AND status='deleted' LIMIT 1");
        $st->execute([':id'=>$binId]);
        $item = $st->fetch();
        if (!$item) {
            $error = 'العنصر غير موجود أو تمت معالجته مسبقًا.';
        } elseif ($item['entity_type'] !== 'user') {
            $error = 'نوع العنصر غير مدعوم حاليًا.';
        } elseif ($action === 'restore') {
            try {
                $pdo->beginTransaction();
                $u = $pdo->prepare("SELECT id, role, is_primary_admin FROM users WHERE id=:id LIMIT 1");
                $u->execute([':id'=>(int)$item['record_id']]);
                $userRow = $u->fetch();
                if (!$userRow) throw new Exception('الحساب لم يعد موجودًا في قاعدة البيانات.');
                if ((int)$userRow['is_primary_admin'] === 1) throw new Exception('لا يمكن استعادة أو تعديل المدير الرئيسي من السلة.');
                $pdo->prepare("UPDATE users SET status='active', deletion_requested=0, deletion_requested_at=NULL WHERE id=:id AND status='deleted'")
                    ->execute([':id'=>(int)$item['record_id']]);
                $pdo->prepare("UPDATE recycle_bin SET status='restored', restored_at=NOW(), restored_by=:by WHERE id=:id")
                    ->execute([':by'=>current_user()['id'], ':id'=>$binId]);
                $pdo->commit();
                log_audit('restore','users',(int)$item['record_id'],"استعادة {$item['record_role']} من سلة المحذوفات: {$item['record_name']}");
                set_flash('success','تمت استعادة الحساب بنجاح وأصبح نشطًا.');
                header('Location: recycle_bin.php'); exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'تعذر الاستعادة: ' . $e->getMessage();
            }
        } elseif ($action === 'delete_permanently') {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM users WHERE id=:id AND is_primary_admin=0 AND status='deleted'")
                    ->execute([':id'=>(int)$item['record_id']]);
                $pdo->prepare("UPDATE recycle_bin SET status='restored', restored_at=NOW(), restored_by=:by WHERE id=:id")
                    ->execute([':by'=>current_user()['id'], ':id'=>$binId]);
                $pdo->commit();
                log_audit('delete_permanent','users',(int)$item['record_id'],"حذف نهائي من سلة المحذوفات: {$item['record_name']}");
                set_flash('success','تم حذف الحساب نهائيًا.');
                header('Location: recycle_bin.php'); exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'تعذر الحذف النهائي. قد توجد بيانات مرتبطة تمنع الحذف.';
            }
        }
    }
}

$rows = $pdo->query("SELECT rb.*, d.name AS deleter_name FROM recycle_bin rb LEFT JOIN users d ON d.id=rb.deleted_by WHERE rb.status='deleted' ORDER BY rb.id DESC LIMIT 200")->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl" data-theme="<?=htmlspecialchars($theme)?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AutoCare - سلة المحذوفات</title><link rel="stylesheet" href="style.css"></head>
<body class="dashboard-body">
<div class="dashboard-wrapper"><main class="main-content" style="width:100%">
<header class="topbar"><h2 class="page-title">🗑️ سلة المحذوفات</h2><a class="btn btn-outline" href="dashboard.php">العودة للوحة التحكم</a></header>
<div class="content-container">
<?php if($flash):?><div class="alert alert-<?=clean($flash['type'])?>"><?=clean($flash['message'])?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger"><?=clean($error)?></div><?php endif;?>
<div class="card"><div class="card-header"><h3>الحسابات المحذوفة</h3><span class="badge badge-warning"><?=count($rows)?> عنصر</span></div>
<div class="card-body" style="overflow-x:auto"><table class="data-table"><thead><tr><th>الحساب</th><th>الدور</th><th>حذف بواسطة</th><th>تاريخ الحذف</th><th>الإجراءات</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="5" style="text-align:center;padding:30px">سلة المحذوفات فارغة.</td></tr><?php endif;?>
<?php foreach($rows as $r):?><tr>
<td><strong><?=clean($r['record_name'])?></strong><br><small>ID: <?=intval($r['record_id'])?></small></td>
<td><?=clean($r['record_role'])?></td><td><?=clean($r['deleter_name'] ?? 'غير معروف')?></td><td><?=clean($r['deleted_at'])?></td>
<td style="white-space:nowrap"><form method="post" style="display:inline" onsubmit="return confirm('استعادة هذا الحساب؟');"><?=csrf_field()?><input type="hidden" name="bin_id" value="<?=intval($r['id'])?>"><button name="action" value="restore" class="btn btn-sm btn-primary">↩️ استعادة</button></form>
<form method="post" style="display:inline" onsubmit="return confirm('تحذير: سيتم حذف الحساب نهائيًا. هل أنت متأكد؟');"><?=csrf_field()?><input type="hidden" name="bin_id" value="<?=intval($r['id'])?>"><button name="action" value="delete_permanently" class="btn btn-sm btn-outline-danger">حذف نهائي</button></form></td>
</tr><?php endforeach;?></tbody></table></div></div>
<div class="alert alert-info" style="margin-top:16px">الحذف من صفحات الإدارة ينقل الحساب إلى السلة بدل حذفه مباشرة، ويمكن للمدير الرئيسي استعادته. الحذف النهائي قد يزيل البيانات المرتبطة بالحساب إذا سمحت علاقات قاعدة البيانات بذلك.</div>
</div></main></div><script src="app.js"></script></body></html>
