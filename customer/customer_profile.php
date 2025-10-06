<?php
// customer/customer_profile.php — self-contained; uses common layout head/foot; no customer_profile() dependency

require_once __DIR__ . '/inc/common.php'; // starts session, $conn, guards role=customer

/* ---------------- Helpers ---------------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function tbl_exists(mysqli $c, string $t){
  if (function_exists('tableExists')) return tableExists($c,$t);
  $t=$c->real_escape_string($t); $r=@$c->query("SHOW TABLES LIKE '$t'"); return !!($r && $r->num_rows);
}
function col_exists(mysqli $c, string $t, string $col){
  if (function_exists('colExists')) return colExists($c,$t,$col);
  $t=$c->real_escape_string($t); $col=$c->real_escape_string($col);
  $r=@$c->query("SHOW COLUMNS FROM `$t` LIKE '$col'"); return !!($r && $r->num_rows);
}
function firstExisting(mysqli $c,string $t,array $cands,$fallback=null){ foreach($cands as $x){ if(col_exists($c,$t,$x)) return $x; } return $fallback; }
function flash_set($type,$msg){ $_SESSION['flash_type']=$type; $_SESSION['flash_msg']=$msg; }

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_ok(){ return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }

/* ---------------- Current user ---------------- */
$USER_TBL = 'users';
if (!tbl_exists($conn,$USER_TBL)) { http_response_code(500); exit('Users table not found.'); }

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid<=0) { header('Location: ../login.php'); exit; }

/* Detect columns in users (flexible to your schema) */
$U = [];
$U['id']   = col_exists($conn,$USER_TBL,'user_id') ? 'user_id' : (col_exists($conn,$USER_TBL,'id') ? 'id' : 'user_id');
$U['un']   = firstExisting($conn,$USER_TBL,['username','user_name'],'username');
$U['role'] = firstExisting($conn,$USER_TBL,['role','user_role'],'role');
$U['email']= firstExisting($conn,$USER_TBL,['email','email_address'], null);
$U['phone']= firstExisting($conn,$USER_TBL,['phone','contact_phone','mobile','contact'], null);
$U['addr'] = firstExisting($conn,$USER_TBL,['address','shipping_address','delivery_address'], null);
$U['pass'] = firstExisting($conn,$USER_TBL,['password','passwd','hash'], null);
$U['ava']  = firstExisting($conn,$USER_TBL,['avatar','photo','profile_photo','image','picture'], null);
$U['full'] = firstExisting($conn,$USER_TBL,['full_name'], null);
$U['name'] = $U['full'] ? null : firstExisting($conn,$USER_TBL,['name'], null);
$U['fn']   = ($U['full']||$U['name']) ? null : firstExisting($conn,$USER_TBL,['first_name','firstname'], null);
$U['ln']   = ($U['full']||$U['name']) ? null : firstExisting($conn,$USER_TBL,['last_name','lastname','surname'], null);

/* Build SELECT for user */
$sel = [$U['id'].' AS id', $U['un'].' AS username', $U['role'].' AS role'];
if ($U['email']) $sel[] = "{$U['email']} AS email";
if ($U['phone']) $sel[] = "{$U['phone']} AS phone";
if ($U['addr'])  $sel[] = "{$U['addr']} AS address";
if ($U['ava'])   $sel[] = "{$U['ava']} AS avatar";
if ($U['full'])  $sel[] = "{$U['full']} AS full_name";
if ($U['name'])  $sel[] = "{$U['name']} AS name";
if ($U['fn'])    $sel[] = "{$U['fn']} AS first_name";
if ($U['ln'])    $sel[] = "{$U['ln']} AS last_name";

$sqlUser = "SELECT ".implode(',',$sel)." FROM {$USER_TBL} WHERE {$U['id']}=? LIMIT 1";
$st=$conn->prepare($sqlUser); $st->bind_param('i',$uid); $st->execute();
$USER = $st->get_result()->fetch_assoc(); $st->close();
if (!$USER){ header('Location: ../logout.php'); exit; }

$display_name = $USER['username'];
if (!empty($USER['full_name'])) $display_name = $USER['full_name'];
elseif (!empty($USER['name']))  $display_name = $USER['name'];
elseif (!empty($USER['first_name']) || !empty($USER['last_name'])) $display_name = trim(($USER['first_name']??'').' '.($USER['last_name']??''));

/* ---------------- Customers table (optional mirror for delivery) ---------------- */
$HAS_CUST = tbl_exists($conn,'customers');
$C = [
  'tbl'  => $HAS_CUST ? 'customers' : null,
  'link' => $HAS_CUST ? firstExisting($conn,'customers',['user_id','uid','users_id','account_id','customer_id']) : null,
  'pk'   => $HAS_CUST ? (col_exists($conn,'customers','id')?'id':firstExisting($conn,'customers',['customer_id'])) : null,

  'fname'=> $HAS_CUST ? firstExisting($conn,'customers',['first_name','firstname','given_name']) : null,
  'mname'=> $HAS_CUST ? firstExisting($conn,'customers',['middle_name','middlename','middle']) : null,
  'lname'=> $HAS_CUST ? firstExisting($conn,'customers',['last_name','lastname','family_name','surname']) : null,
  'name' => $HAS_CUST ? firstExisting($conn,'customers',['customer_name','full_name','name']) : null,

  'phone'=> $HAS_CUST ? firstExisting($conn,'customers',['phone','contact','mobile','contact_number','phone_number']) : null,
  'addr' => $HAS_CUST ? firstExisting($conn,'customers',['address_line','address','street']) : null,
  'brgy' => $HAS_CUST ? firstExisting($conn,'customers',['barangay','brgy','bgy']) : null,
  'city' => $HAS_CUST ? firstExisting($conn,'customers',['city','municipality','town']) : null,
  'prov' => $HAS_CUST ? firstExisting($conn,'customers',['province','state','region']) : null,
  'post' => $HAS_CUST ? firstExisting($conn,'customers',['postal_code','zip','zipcode']) : null,
  'notes'=> $HAS_CUST ? firstExisting($conn,'customers',['delivery_instructions','landmark','notes','remarks']) : null,
];

/* Load existing customer row if any for prefill */
$CUST = null;
if ($C['tbl'] && $C['link']) {
  $cols=[]; foreach (['fname','mname','lname','name','phone','addr','brgy','city','prov','post','notes'] as $k){ if($C[$k]) $cols[]="`{$C[$k]}` AS `$k`"; }
  $selC = $cols ? implode(',',$cols) : '*';
  $sqlC = "SELECT $selC FROM `{$C['tbl']}` WHERE `{$C['link']}`=? ORDER BY ".($C['pk']?:$C['link'])." DESC LIMIT 1";
  if ($st=$conn->prepare($sqlC)){ $st->bind_param('i',$uid); $st->execute(); $CUST = $st->get_result()->fetch_assoc(); $st->close(); }
}

/* ---------------- Upload dir (avatars) ---------------- */
$uploadBase = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$avaDir = $uploadBase . '/avatars';
if (!is_dir($avaDir)) { @mkdir($avaDir, 0775, true); }

/* ---------------- POST: Save profile (before any output) ---------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save_profile'){
  if (!csrf_ok()){ http_response_code(400); exit('Bad CSRF'); }

  $name_input   = trim($_POST['full_name'] ?? '');
  $email_input  = trim($_POST['email'] ?? '');
  $phone_input  = trim($_POST['phone'] ?? '');
  $addr_input   = trim($_POST['address'] ?? '');
  $brgy_input   = trim($_POST['barangay'] ?? '');
  $city_input   = trim($_POST['city'] ?? '');
  $prov_input   = trim($_POST['province'] ?? '');
  $post_input   = trim($_POST['postal_code'] ?? '');
  $notes_input  = trim($_POST['notes'] ?? '');
  $cur_avatar   = trim($_POST['current_avatar'] ?? '');

  // --- users update ---
  $uUpd=[]; $uTyp=''; $uVal=[];
  if ($name_input!==''){
    if ($U['full']) { $uUpd[]="{$U['full']}=?"; $uTyp.='s'; $uVal[]=$name_input; }
    elseif ($U['name']) { $uUpd[]="{$U['name']}=?"; $uTyp.='s'; $uVal[]=$name_input; }
    elseif ($U['fn'] || $U['ln']){
      $fn=''; $ln=''; $parts=preg_split('/\s+/', $name_input);
      $fn=array_shift($parts) ?: ''; $ln=implode(' ', $parts);
      if ($U['fn']) { $uUpd[]="{$U['fn']}=?"; $uTyp.='s'; $uVal[]=$fn; }
      if ($U['ln']) { $uUpd[]="{$U['ln']}=?"; $uTyp.='s'; $uVal[]=$ln; }
    }
  }
  if ($U['email']) { $uUpd[]="{$U['email']}=?"; $uTyp.='s'; $uVal[]=$email_input; }
  if ($U['phone']) { $uUpd[]="{$U['phone']}=?"; $uTyp.='s'; $uVal[]=$phone_input; }
  if ($U['addr'])  { $uUpd[]="{$U['addr']}=?";  $uTyp.='s'; $uVal[]=$addr_input; }

  // avatar upload
  $newAvatar = null;
  if ($U['ava'] && !empty($_FILES['avatar']) && is_array($_FILES['avatar']) && $_FILES['avatar']['error']!==UPLOAD_ERR_NO_FILE){
    if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK){ flash_set('error','Avatar upload failed.'); header('Location: customer_profile.php'); exit; }
    if ($_FILES['avatar']['size'] > 2*1024*1024){ flash_set('error','Avatar too large (max 2MB).'); header('Location: customer_profile.php'); exit; }
    $finfo = new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($_FILES['avatar']['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($allowed[$mime])){ flash_set('error','Invalid avatar image. Use JPG/PNG/WEBP.'); header('Location: customer_profile.php'); exit; }
    $slug = preg_replace('/[^a-z0-9\-]+/i','-', strtolower($display_name?:($_SESSION['username']??'user'))); $slug=trim($slug,'-');
    $newAvatar = time().'_'.bin2hex(random_bytes(3)).'_'.$slug.'.'.$allowed[$mime];
    $dest = $avaDir.'/'.$newAvatar;
    if (!move_uploaded_file($_FILES['avatar']['tmp_name'],$dest)){ flash_set('error','Could not save avatar.'); header('Location: customer_profile.php'); exit; }
    $uUpd[]="{$U['ava']}=?"; $uTyp.='s'; $uVal[]=$newAvatar;
  }

  $ok_users = true;
  if ($uUpd){
    $uTyp.='i'; $uVal[]=$uid;
    $sql="UPDATE {$USER_TBL} SET ".implode(', ',$uUpd)." WHERE {$U['id']}=?";
    if ($st=$conn->prepare($sql)){
      $st->bind_param($uTyp, ...$uVal); $ok_users=$st->execute(); $st->close();
      if ($ok_users && $newAvatar && $cur_avatar){
        $old = $avaDir.'/'.basename($cur_avatar); if (is_file($old)) @unlink($old);
      }
    } else { $ok_users=false; }
  }

  // --- customers upsert delivery details ---
  $ok_customers = true;
  if ($C['tbl'] && $C['link']){
    $sets=[]; $t=''; $v=[];
    if ($name_input!==''){
      if ($C['name']) { $sets[$C['name']] = ['s',$name_input]; }
      elseif ($C['fname']||$C['lname']){
        $fn=''; $ln=''; $parts=preg_split('/\s+/', $name_input);
        $fn=array_shift($parts) ?: ''; $ln=implode(' ', $parts);
        if ($C['fname']) $sets[$C['fname']] = ['s',$fn];
        if ($C['lname']) $sets[$C['lname']] = ['s',$ln];
      }
    }
    if ($C['phone']) $sets[$C['phone']] = ['s',$phone_input];
    if ($C['addr'])  $sets[$C['addr']]  = ['s',$addr_input];
    if ($C['brgy'])  $sets[$C['brgy']]  = ['s',$brgy_input];
    if ($C['city'])  $sets[$C['city']]  = ['s',$city_input];
    if ($C['prov'])  $sets[$C['prov']]  = ['s',$prov_input];
    if ($C['post'])  $sets[$C['post']]  = ['s',$post_input];
    if ($C['notes']) $sets[$C['notes']] = ['s',$notes_input];

    if ($sets){
      $exists=false;
      if ($st=$conn->prepare("SELECT 1 FROM `{$C['tbl']}` WHERE `{$C['link']}`=? LIMIT 1")){
        $st->bind_param('i',$uid); $st->execute(); $st->store_result(); $exists=$st->num_rows>0; $st->close();
      }
      if ($exists){
        $parts=[]; foreach($sets as $col=>$pair){ $parts[]="`$col`=?"; $t.=$pair[0]; $v[]=$pair[1]; }
        $t.='i'; $v[]=$uid;
        $sql="UPDATE `{$C['tbl']}` SET ".implode(', ',$parts)." WHERE `{$C['link']}`=?";
        if ($st=$conn->prepare($sql)){ $st->bind_param($t, ...$v); $ok_customers=$st->execute(); $st->close(); } else { $ok_customers=false; }
      } else {
        $cols=["`{$C['link']}`"]; $ph=['?']; $t='i'; $v=[$uid];
        foreach($sets as $col=>$pair){ $cols[]="`$col`"; $ph[]='?'; $t.=$pair[0]; $v[]=$pair[1]; }
        $sql="INSERT INTO `{$C['tbl']}` (".implode(',',$cols).") VALUES (".implode(',',$ph).")";
        if ($st=$conn->prepare($sql)){ $st->bind_param($t, ...$v); $ok_customers=$st->execute(); $st->close(); } else { $ok_customers=false; }
      }
    }
  }

  $ok = $ok_users && $ok_customers;
  flash_set($ok?'success':'error', $ok?'Profile updated.':'Update failed.');
  header('Location: customer_profile.php'); exit;
}

/* ---------------- Refresh data for form display ---------------- */
$st=$conn->prepare($sqlUser); $st->bind_param('i',$uid); $st->execute();
$USER = $st->get_result()->fetch_assoc(); $st->close();
$display_name = $USER['username'];
if (!empty($USER['full_name'])) $display_name = $USER['full_name'];
elseif (!empty($USER['name']))  $display_name = $USER['name'];
elseif (!empty($USER['first_name']) || !empty($USER['last_name'])) $display_name = trim(($USER['first_name']??'').' '.($USER['last_name']??''));

$avatarFile = (!empty($USER['avatar'])) ? '../uploads/avatars/'.h($USER['avatar']) : 'https://placehold.co/160x160?text=Avatar';

$pref = [
  'phone'     => $CUST['phone'] ?? ($USER['phone'] ?? ''),
  'address'   => $CUST['addr']  ?? ($USER['address'] ?? ''),
  'barangay'  => $CUST['brgy']  ?? '',
  'city'      => $CUST['city']  ?? '',
  'province'  => $CUST['prov']  ?? '',
  'postal'    => $CUST['post']  ?? '',
  'notes'     => $CUST['notes'] ?? '',
];

/* ---------------- Render with common layout ---------------- */
$PAGE_TITLE = 'My Profile';
$CURRENT    = 'customer_profile.php';
include __DIR__ . '/layout_head.php';
?>
<style>
  .avatar-wrap{display:flex;align-items:center;gap:14px}
  .avatar-wrap img{width:80px;height:80px;object-fit:cover;border-radius:50%;box-shadow:0 8px 22px rgba(0,0,0,.12)}
  .form-label{font-size:.85rem}
</style>

<div class="container-fluid">
  <div class="row g-2">
    <div class="col-12"><?php include __DIR__ . '/inc/flash_alert.php'; ?></div>

    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><strong>Account & Delivery Information</strong></div>
        <div class="card-body">
          <div class="avatar-wrap mb-3">
            <img id="avatarPreview" src="<?php echo h($avatarFile); ?>" alt="avatar">
            <div>
              <div class="fw-semibold"><?php echo h($display_name); ?></div>
              <div class="text-muted small">@<?php echo h($USER['username']); ?> · <?php echo h($USER['role'] ?? 'customer'); ?></div>
            </div>
          </div>

          <form method="post" enctype="multipart/form-data" action="customer_profile.php">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
            <input type="hidden" name="action" value="save_profile">
            <input type="hidden" name="current_avatar" value="<?php echo h($USER['avatar'] ?? ''); ?>">

            <div class="mb-2">
              <label class="form-label">Full Name</label>
              <input type="text" class="form-control" name="full_name" value="<?php echo h($display_name); ?>" placeholder="Your name">
            </div>

            <?php if ($U['email']): ?>
            <div class="mb-2">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="email" value="<?php echo h($USER['email'] ?? ''); ?>" placeholder="name@email.com">
            </div>
            <?php endif; ?>

            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" name="phone" value="<?php echo h($pref['phone']); ?>" placeholder="+63 9xx xxx xxxx">
              </div>
              <div class="col-md-6">
                <?php if ($U['ava']): ?>
                  <label class="form-label">Avatar</label>
                  <input type="file" class="form-control" name="avatar" id="avatarInput" accept=".jpg,.jpeg,.png,.webp">
                <?php else: ?>
                  <label class="form-label">&nbsp;</label>
                  <div class="form-text">Avatar column not present in users table.</div>
                <?php endif; ?>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label">Address</label>
              <textarea class="form-control" name="address" rows="2" placeholder="Street / House / Building"><?php echo h($pref['address']); ?></textarea>
            </div>

            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Barangay</label>
                <input type="text" class="form-control" name="barangay" value="<?php echo h($pref['barangay']); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">City / Municipality</label>
                <input type="text" class="form-control" name="city" value="<?php echo h($pref['city']); ?>">
              </div>
            </div>

            <div class="row g-2 mt-0">
              <div class="col-md-6">
                <label class="form-label">Province</label>
                <input type="text" class="form-control" name="province" value="<?php echo h($pref['province']); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Postal Code</label>
                <input type="text" class="form-control" name="postal_code" value="<?php echo h($pref['postal']); ?>">
              </div>
            </div>

            <div class="mb-2 mt-0">
              <label class="form-label">Delivery Notes (Landmark, instructions)</label>
              <textarea class="form-control" name="notes" rows="2" placeholder="Optional"><?php echo h($pref['notes']); ?></textarea>
            </div>

            <div class="text-end">
              <button class="btn btn-dark"><i class="fa-solid fa-floppy-disk me-1"></i> Save Changes</button>
            </div>
          </form>

        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><strong>Password</strong></div>
        <div class="card-body">
          <?php if ($U['pass']): ?>
            <a class="btn btn-outline-dark" href="change_password.php"><i class="fa-solid fa-key me-1"></i> Change Password</a>
          <?php else: ?>
            <div class="alert alert-info mb-0">Password change not available for this account.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
// Avatar preview
document.getElementById('avatarInput')?.addEventListener('change', function(){
  if(this.files && this.files[0]){
    const reader = new FileReader();
    reader.onload = e => document.getElementById('avatarPreview').src = e.target.result;
    reader.readAsDataURL(this.files[0]);
  }
});
</script>

<?php
$PAGE_FOOT_SCRIPTS = "";
include __DIR__ . '/layout_foot.php';
