<?php
require_once __DIR__.'/config.php';
ensure_extended_schema();
$error='';$success='';$theme=current_theme();
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $input=trim($_POST['login_input']??''); $token=strtoupper(trim($_POST['reset_token']??'')); $pw=$_POST['password']??''; $cp=$_POST['confirm_password']??'';
 if($input===''||$token===''||strlen($pw)<6||$pw!==$cp){$error='تحقق من اسم المستخدم/البريد والرمز وأن كلمة المرور 6 أحرف على الأقل ومتطابقة.';}
 else{
  try{
   $st=db()->prepare("SELECT pr.id,pr.user_id,pr.token_hash,pr.token_expires_at,u.name FROM password_reset_requests pr JOIN users u ON u.id=pr.user_id WHERE (u.username=:x OR u.email=:x) AND pr.status='approved' ORDER BY pr.id DESC LIMIT 1");
   $st->execute([':x'=>$input]);$r=$st->fetch();
   if(!$r || !hash_equals((string)$r['token_hash'],hash('sha256',$token))){$error='رمز الاستعادة غير صحيح أو غير صالح.';}
   elseif(empty($r['token_expires_at']) || strtotime($r['token_expires_at'])<time()){
      db()->prepare("UPDATE password_reset_requests SET status='expired' WHERE id=:id AND status='approved'")->execute([':id'=>$r['id']]);
      $error='انتهت صلاحية رمز الاستعادة. اطلب رمزًا جديدًا من الإدارة.';
   } else {
      $h=password_hash($pw,PASSWORD_DEFAULT);
      db()->beginTransaction();
      db()->prepare("UPDATE users SET password=:p WHERE id=:id")->execute([':p'=>$h,':id'=>$r['user_id']]);
      db()->prepare("UPDATE password_reset_requests SET status='used',used_at=NOW(),token_hash='',token_expires_at=NULL WHERE id=:id AND status='approved'")->execute([':id'=>$r['id']]);
      db()->commit();
      log_audit('reset_password','users',(int)$r['user_id'],'تغيير كلمة المرور بعد استخدام رمز استعادة صالح');
      $success='تم تغيير كلمة المرور بنجاح. يمكنك تسجيل الدخول الآن.';
   }
  }catch(Exception $e){ if(db()->inTransaction()) db()->rollBack(); error_log('AutoCare password reset error: '.$e->getMessage()); $error='تعذر إتمام إعادة تعيين كلمة المرور. يرجى المحاولة لاحقًا.'; }
 }
}
?><!doctype html><html lang="ar" dir="rtl" data-theme="<?=htmlspecialchars($theme)?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تغيير كلمة المرور</title><link rel="stylesheet" href="style.css"></head><body class="auth-body"><div class="auth-container"><div class="auth-card"><div class="auth-header"><h2>تعيين كلمة مرور جديدة</h2></div><?php if($error):?><div class="alert alert-danger"><?=clean($error)?></div><?php endif;?><?php if($success):?><div class="alert alert-success"><?=clean($success)?></div><?php endif;?><form method="post" class="form-grid"><?=csrf_field()?><div class="form-group"><label>اسم المستخدم أو البريد</label><input class="form-control" name="login_input" required></div><div class="form-group"><label>رمز الاستعادة</label><input class="form-control" name="reset_token" required autocomplete="one-time-code"></div><div class="form-group"><label>كلمة المرور الجديدة</label><input type="password" class="form-control" name="password" minlength="6" required></div><div class="form-group"><label>تأكيد كلمة المرور</label><input type="password" class="form-control" name="confirm_password" minlength="6" required></div><button class="btn btn-primary btn-block">حفظ كلمة المرور</button></form><div class="auth-footer"><a href="login.php">← تسجيل الدخول</a></div></div></div></body></html>