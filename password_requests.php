<?php
require_once __DIR__.'/config.php';
require_auth(['admin']);
require_permission('manage_password_reset_requests');
ensure_extended_schema();
$pdo=db(); $flash=get_flash(); $error=''; $theme=current_theme();
$issuedToken = $_SESSION['reset_issued_token'] ?? null;
unset($_SESSION['reset_issued_token']);

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $id=(int)($_POST['request_id']??0); $action=$_POST['action']??'';
  $st=$pdo->prepare("SELECT pr.*,u.name,u.username FROM password_reset_requests pr JOIN users u ON u.id=pr.user_id WHERE pr.id=:id LIMIT 1");
  $st->execute([':id'=>$id]); $r=$st->fetch();
  if(!$r || $r['status']!=='pending') $error='طلب غير صالح أو تمت معالجته.';
  elseif($action==='reject'){
    $pdo->prepare("UPDATE password_reset_requests SET status='rejected',approved_by=:a,approved_at=NOW() WHERE id=:id AND status='pending'")
      ->execute([':a'=>current_user()['id'],':id'=>$id]);
    log_audit('reject','password_reset_requests',$id,'رفض طلب استعادة كلمة المرور');
    set_flash('success','تم رفض الطلب.'); header('Location: password_requests.php'); exit;
  } elseif($action==='approve'){
    $rawToken = strtoupper(bin2hex(random_bytes(5)));
    $tokenHash = hash('sha256',$rawToken);
    $expiresAt = date('Y-m-d H:i:s', time()+1800);
    $ok=$pdo->prepare("UPDATE password_reset_requests SET status='approved',approved_by=:a,approved_at=NOW(),token_hash=:h,token_expires_at=:exp WHERE id=:id AND status='pending'")
      ->execute([':a'=>current_user()['id'],':h'=>$tokenHash,':exp'=>$expiresAt,':id'=>$id]);
    if($ok){
      $_SESSION['reset_issued_token']=['request_id'=>$id,'username'=>$r['username'],'token'=>$rawToken,'expires_at'=>$expiresAt];
      log_audit('approve','password_reset_requests',$id,'الموافقة على طلب استعادة كلمة المرور وإصدار رمز مؤقت صالح لمدة 30 دقيقة');
      set_flash('success','تمت الموافقة وإصدار رمز الاستعادة. سلّم الرمز للمستخدم بشكل آمن.');
    }
    header('Location: password_requests.php'); exit;
  }
}
$rows=$pdo->query("SELECT pr.*,u.name,u.username,u.email,u.role FROM password_reset_requests pr JOIN users u ON u.id=pr.user_id ORDER BY pr.id DESC LIMIT 100")->fetchAll();
?>
<!doctype html><html lang="ar" dir="rtl" data-theme="<?=htmlspecialchars($theme)?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>طلبات استعادة كلمة المرور</title><link rel="stylesheet" href="style.css"></head><body class="dashboard-body"><div class="dashboard-wrapper"><main class="main-content" style="width:100%"><header class="topbar"><h2 class="page-title">🔐 طلبات استعادة كلمة المرور</h2><a class="btn btn-outline" href="dashboard.php">العودة</a></header><div class="content-container">
<?php if($flash):?><div class="alert alert-<?=clean($flash['type'])?>"><?=clean($flash['message'])?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=clean($error)?></div><?php endif;?>
<?php if($issuedToken):?><div class="alert alert-warning"><strong>رمز الاستعادة الصادر:</strong> <code style="font-size:1.2rem;letter-spacing:2px"><?=clean($issuedToken['token'])?></code><br><small>للحساب: <?=clean($issuedToken['username'])?> — صالح حتى <?=clean($issuedToken['expires_at'])?>. سيظهر هذا الرمز مرة واحدة فقط في هذه الجلسة.</small></div><?php endif;?>
<div class="card"><div class="card-body" style="overflow-x:auto"><table class="data-table"><thead><tr><th>المستخدم</th><th>الدور</th><th>التاريخ</th><th>الحالة</th><th>الرمز/الإجراء</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=clean($r['name'])?><br><small><?=clean($r['username'])?></small></td><td><?=clean($r['role'])?></td><td><?=clean($r['requested_at'])?></td><td><?=status_badge($r['status'])?></td><td><?php if($r['status']==='pending'):?><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=$r['id']?>"><button name="action" value="approve" class="btn btn-sm btn-primary">موافقة وإصدار رمز</button> <button name="action" value="reject" class="btn btn-sm btn-outline-danger">رفض</button></form><?php elseif($r['status']==='approved' && !empty($r['token_expires_at'])):?>صالح حتى <?=clean($r['token_expires_at'])?><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></div>
</div></main></div><script src="app.js"></script></body></html>