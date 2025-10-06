<?php
// /admin/admin_profile.php
// PoultryMetrics – Admin > My Profile (avatar upload + recent activity, helper shims)

declare(strict_types=1);

$PAGE_TITLE = 'My Profile';
require_once __DIR__ . '/inc/common.php'; // $conn, session, helpers, auth (admin-only)

/* ------------ Guard ------------ */
if (function_exists('require_admin')) { require_admin($conn); }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$my_id = (int)($_SESSION['user_id'] ?? 0);
if ($my_id <= 0) { header('Location: /admin/login.php'); exit; }

/* ------------ Helper shims ------------ */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $t): bool {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    if(!$st=$c->prepare($sql)) return false;
    $st->bind_param('s',$t); $st->execute(); $st->store_result(); $ok=$st->num_rows>0; $st->close(); return $ok;
  }
}
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $tbl, string $col): bool {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if(!$st=$c->prepare($sql)) return false;
    $st->bind_param('ss',$tbl,$col); $st->execute(); $st->store_result(); $ok=$st->num_rows>0; $st->close(); return $ok;
  }
}
if (!function_exists('firstExistingCol')) {
  function firstExistingCol(mysqli $c, string $t, array $cands){
    foreach ($cands as $x) if ($x && colExists($c,$t,$x)) return $x;
    return null;
  }
}
if (!function_exists('flash_set')) {
  function flash_set($type,$msg){ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
}
if (!function_exists('flash_get')) {
  function flash_get(){ $x=$_SESSION['flash']??null; unset($_SESSION['flash']); return $x; }
}
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
if (!function_exists('csrf_valid')) {
  function csrf_valid($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($t ?? '')); }
}

/* ------------ Users schema discovery (adaptive) ------------ */
$U_TBL = 'users';
if (!tableExists($conn, $U_TBL)) { http_response_code(500); die('Users table not found.'); }

$col_id   = firstExistingCol($conn,$U_TBL,['id','user_id']) ?: 'id';
$col_user = firstExistingCol($conn,$U_TBL,['username','user_name','login']) ?: 'username';
$col_mail = firstExistingCol($conn,$U_TBL,['email','email_address']) ?: 'email';

$has_full  = colExists($conn,$U_TBL,'full_name');
$has_name  = colExists($conn,$U_TBL,'name');
$has_fn    = colExists($conn,$U_TBL,'first_name');
$has_ln    = colExists($conn,$U_TBL,'last_name');

$col_role   = colExists($conn,$U_TBL,'role')   ? 'role'   : null;
$col_status = colExists($conn,$U_TBL,'status') ? 'status' : null;

$col_created = firstExistingCol($conn,$U_TBL,['created_at','created','created_on','date_created']);
$col_updated = firstExistingCol($conn,$U_TBL,['updated_at','updated','modified_at','date_updated']);

$pwd_col = firstExistingCol($conn,$U_TBL,['password_hash','password']);

$flag_active   = firstExistingCol($conn,$U_TBL,['is_active','active','enabled']);
$flag_approved = firstExistingCol($conn,$U_TBL,['is_approved','approved','verified','is_verified']);

$col_avatar = firstExistingCol($conn,$U_TBL,['avatar','avatar_path','photo','profile_photo']);

/* ------------ Small helpers ------------ */
function split_name($full){
  $full=trim(preg_replace('/\s+/',' ',(string)$full));
  if($full==='') return ['',''];
  $p=explode(' ',$full); $first=array_shift($p);
  return [$first,implode(' ',$p)];
}

/** Accepts modern password_hash() AND auto-upgrades old MD5/plain to bcrypt on success. */
function verify_and_upgrade_password(mysqli $conn, int $uid, string $input, string $hash, string $pwd_col): bool {
  if ($hash === '') return false;
  if (preg_match('/^\$2y\$/', $hash) || preg_match('/^\$argon2/i', $hash)) {
    return password_verify($input, $hash);
  }
  if (strlen($hash) === 32 && ctype_xdigit($hash)) { // md5
    if (md5($input) === strtolower($hash)) {
      $new = password_hash($input, PASSWORD_BCRYPT);
      if ($st = $conn->prepare("UPDATE users SET `$pwd_col`=? WHERE id=?")) {
        $st->bind_param('si', $new, $uid); $st->execute(); $st->close();
      }
      return true;
    }
  }
  if ($input === $hash) { // plain
    $new = password_hash($input, PASSWORD_BCRYPT);
    if ($st = $conn->prepare("UPDATE users SET `$pwd_col`=? WHERE id=?")) {
      $st->bind_param('si', $new, $uid); $st->execute(); $st->close();
    }
    return true;
  }
  return false;
}

/* ------------ Load current profile ------------ */
$select = ["`$col_id` AS id", "`$col_user` AS username", "`$col_mail` AS email"];
if ($has_full) $select[] = "full_name";
if ($has_name) $select[] = "name";
if ($has_fn)   $select[] = "first_name";
if ($has_ln)   $select[] = "last_name";
if ($col_role)   $select[] = "`$col_role` AS role";
if ($col_status) $select[] = "`$col_status` AS status";
if ($col_created) $select[] = "`$col_created` AS created_at";
if ($col_updated) $select[] = "`$col_updated` AS updated_at";
if ($flag_active)   $select[] = "`$flag_active` AS is_active";
if ($flag_approved) $select[] = "`$flag_approved` AS is_approved";
if ($pwd_col)       $select[] = "`$pwd_col` AS pwdhash";
if ($col_avatar)    $select[] = "`$col_avatar` AS avatar_path";

$sql_me = "SELECT ".implode(', ', $select)." FROM `$U_TBL` WHERE `$col_id`=? LIMIT 1";
$st = $conn->prepare($sql_me); $st->bind_param('i',$my_id); $st->execute();
$me = $st->get_result()->fetch_assoc() ?: []; $st->close();
if (!$me) { http_response_code(404); die('Admin record not found.'); }

/* Normalize display name */
$display_full = $me['username'] ?? '';
if ($has_full && !empty($me['full_name'])) $display_full = $me['full_name'];
elseif ($has_name && !empty($me['name']))   $display_full = $me['name'];
elseif ($has_fn || $has_ln){
  $fn = trim((string)($me['first_name'] ?? ''));
  $ln = trim((string)($me['last_name']  ?? ''));
  $display_full = trim($fn.' '.$ln);
}

/* ------------ Avatar helpers (filesystem) ------------ */
$publicBase = '/assets/uploads/avatars'; // public URL base
$diskBase   = realpath(dirname(__DIR__) . '/assets/uploads'); // /admin -> /assets/uploads
if ($diskBase === false) { $diskBase = dirname(__DIR__) . '/assets/uploads'; }
$avatarDir  = $diskBase . DIRECTORY_SEPARATOR . 'avatars';
if (!is_dir($avatarDir)) { @mkdir($avatarDir, 0775, true); }

function avatar_public_url(array $me, string $publicBase, string $avatarDir): ?string {
  if (!empty($me['avatar_path'])) return $me['avatar_path']; // DB value wins
  $id = (int)($me['id'] ?? 0);
  if ($id > 0) {
    foreach (['jpg','jpeg','png','webp'] as $ext) {
      $cand = $avatarDir . DIRECTORY_SEPARATOR . "u_{$id}.".$ext;
      if (is_file($cand)) return $publicBase . "/u_{$id}.{$ext}";
    }
  }
  return null;
}

$curAvatarUrl = avatar_public_url($me, $publicBase, $avatarDir);

/* ------------ POST actions ------------ */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_valid($_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF validation failed'); }
  $action = $_POST['action'] ?? '';

  if ($action === 'save_profile') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $full_in  = trim($_POST['full_name'] ?? '');

    if ($username==='' || $email==='') { flash_set('error','Username and Email are required.'); header('Location: admin_profile.php'); exit(); }

    // unique check (exclude self)
    $chk = $conn->prepare("SELECT 1 FROM `$U_TBL` WHERE (`$col_user`=? OR `$col_mail`=?) AND `$col_id`<>? LIMIT 1");
    $chk->bind_param('ssi',$username,$email,$my_id);
    $chk->execute(); $chk->store_result();
    if ($chk->num_rows>0) { $chk->close(); flash_set('error','Username or Email already taken.'); header('Location: admin_profile.php'); exit(); }
    $chk->close();

    $sets=[]; $types=''; $vals=[];
    $sets[]="`$col_user`=?"; $types.='s'; $vals[]=$username;
    $sets[]="`$col_mail`=?"; $types.='s'; $vals[]=$email;
    if ($has_full) { $sets[]="`full_name`=?"; $types.='s'; $vals[]=$full_in; }
    elseif ($has_name) { $sets[]="`name`=?"; $types.='s'; $vals[]=$full_in; }
    elseif ($has_fn || $has_ln) {
      [$first,$last] = split_name($full_in);
      if ($has_fn){ $sets[]='`first_name`=?'; $types.='s'; $vals[]=$first; }
      if ($has_ln){ $sets[]='`last_name`=?';  $types.='s'; $vals[]=$last;  }
    }
    if ($col_updated) { $sets[]="`$col_updated`=NOW()"; }
    $sql = "UPDATE `$U_TBL` SET ".implode(', ',$sets)." WHERE `$col_id`=?";
    $types.='i'; $vals[]=$my_id;

    $st=$conn->prepare($sql); $st->bind_param($types, ...$vals);
    if ($st->execute()) { $_SESSION['username'] = $username; flash_set('success','Profile updated.'); }
    else { flash_set('error','Update failed: '.$conn->error); }
    $st->close(); header('Location: admin_profile.php'); exit();

  } elseif ($action === 'change_password') {
    if (!$pwd_col) { flash_set('error',"Users table has no password column."); header('Location: admin_profile.php'); exit(); }
    $cur = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $rep = (string)($_POST['repeat_password'] ?? '');

    if ($cur==='' || $new==='' || $rep==='') { flash_set('error','Please fill in all password fields.'); header('Location: admin_profile.php'); exit(); }
    if ($new !== $rep) { flash_set('error','New password confirmation does not match.'); header('Location: admin_profile.php'); exit(); }
    if (strlen($new) < 8) { flash_set('error','New password must be at least 8 characters.'); header('Location: admin_profile.php'); exit(); }

    $hash = (string)($me['pwdhash'] ?? '');
    $ok = $hash ? password_verify($cur, $hash) : false;
    if (!$ok && $pwd_col) $ok = verify_and_upgrade_password($conn, $my_id, $cur, $hash, $pwd_col);
    if (!$ok) { flash_set('error','Current password is incorrect.'); header('Location: admin_profile.php'); exit(); }

    $newHash = password_hash($new, PASSWORD_BCRYPT);
    $st=$conn->prepare("UPDATE `$U_TBL` SET `$pwd_col`=? ".($col_updated? ", `$col_updated`=NOW()":'')." WHERE `$col_id`=?");
    $st->bind_param('si', $newHash, $my_id);
    if ($st->execute()) flash_set('success','Password changed successfully.');
    else flash_set('error','Password change failed: '.$conn->error);
    $st->close(); header('Location: admin_profile.php'); exit();

  } elseif ($action === 'upload_avatar') {
    if (empty($_FILES['avatar']['name'])) { flash_set('error','No file selected.'); header('Location: admin_profile.php'); exit(); }

    $file = $_FILES['avatar'];
    if ($file['error'] !== UPLOAD_ERR_OK) { flash_set('error','Upload error (code '.$file['error'].').'); header('Location: admin_profile.php'); exit(); }
    if ($file['size'] > 2*1024*1024) { flash_set('error','File too large. Max 2MB.'); header('Location: admin_profile.php'); exit(); }

    // Validate mime
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $allowed = [
      'image/jpeg' => 'jpg',
      'image/png'  => 'png',
      'image/webp' => 'webp'
    ];
    if (!isset($allowed[$mime])) { flash_set('error','Invalid image type. Use JPG/PNG/WEBP.'); header('Location: admin_profile.php'); exit(); }

    $ext = $allowed[$mime];
    $dest = $avatarDir . DIRECTORY_SEPARATOR . "u_{$my_id}.{$ext}";

    // Remove other old formats for this user
    foreach (['jpg','jpeg','png','webp'] as $old) {
      $oldp = $avatarDir . DIRECTORY_SEPARATOR . "u_{$my_id}.{$old}";
      if (is_file($oldp) && $oldp !== $dest) @unlink($oldp);
    }

    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
      flash_set('error','Could not save file on server.');
      header('Location: admin_profile.php'); exit();
    }
    // Persist path if a column exists
    if ($col_avatar) {
      $publicPath = $publicBase . "/u_{$my_id}.{$ext}";
      $st=$conn->prepare("UPDATE `$U_TBL` SET `$col_avatar`=?, ".($col_updated? "`$col_updated`=NOW(), ":'')."`$col_user`=`$col_user` WHERE `$col_id`=?");
      $st->bind_param('si',$publicPath,$my_id);
      $st->execute(); $st->close();
    }

    flash_set('success','Avatar updated.');
    header('Location: admin_profile.php'); exit();

  } elseif ($action === 'remove_avatar') {
    $removed = false;
    foreach (['jpg','jpeg','png','webp'] as $ext) {
      $p = $avatarDir . DIRECTORY_SEPARATOR . "u_{$my_id}.{$ext}";
      if (is_file($p)) { @unlink($p); $removed = true; }
    }
    if ($col_avatar) {
      $st=$conn->prepare("UPDATE `$U_TBL` SET `$col_avatar`=NULL ".($col_updated? ", `$col_updated`=NOW()":'')." WHERE `$col_id`=?");
      $st->bind_param('i',$my_id); $st->execute(); $st->close();
    }
    flash_set('success', $removed ? 'Avatar removed.' : 'No avatar to remove.');
    header('Location: admin_profile.php'); exit();
  }
}

/* ------------ Refresh $me after any POST ------------ */
$st = $conn->prepare($sql_me); $st->bind_param('i',$my_id); $st->execute();
$me = $st->get_result()->fetch_assoc() ?: []; $st->close();
$curAvatarUrl = avatar_public_url($me, $publicBase, $avatarDir);

/* Recompute display name after possible changes */
$display_full = $me['username'] ?? '';
if ($has_full && !empty($me['full_name'])) $display_full = $me['full_name'];
elseif ($has_name && !empty($me['name']))   $display_full = $me['name'];
elseif ($has_fn || $has_ln){
  $fn = trim((string)($me['first_name'] ?? ''));
  $ln = trim((string)($me['last_name']  ?? ''));
  $display_full = trim($fn.' '.$ln);
}
$flash = flash_get();

/* ------------ Recent activity (best-effort) ------------ */
$recent_approvals = [];
$apr_tbl = null;
foreach (['customer_approvals','account_approvals'] as $cand) {
  if (tableExists($conn,$cand) && colExists($conn,$cand,'decided_by')) { $apr_tbl = $cand; break; }
}
if ($apr_tbl) {
  $timecol = colExists($conn,$apr_tbl,'decided_at') ? 'decided_at' : (colExists($conn,$apr_tbl,'created_at')?'created_at':null);
  $q = "SELECT id, status, note".($timecol?(", $timecol AS decided_at"):'')." FROM `$apr_tbl` WHERE decided_by=? ORDER BY ".($timecol?:'id')." DESC LIMIT 10";
  $st = $conn->prepare($q); $st->bind_param('i',$my_id); $st->execute();
  $recent_approvals = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $st->close();
}

/* ------------ Layout ------------ */
$CURRENT = 'admin_profile.php';
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .avatar-frame{ width:84px; height:84px; border-radius:999px; overflow:hidden; background:#f8f9fa; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">My Profile</h5>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?php echo $flash['type']==='success'?'success':'info'; ?>"><?php echo h($flash['msg']); ?></div>
<?php endif; ?>

<div class="row gy-3">
  <!-- Left: Profile + Password -->
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3">Profile Details</h6>
        <form method="post" action="admin_profile.php" autocomplete="off">
          <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
          <input type="hidden" name="action" value="save_profile">

          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small">Username</label>
              <input name="username" class="form-control" required
                     value="<?php echo h($me['username'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small">Email</label>
              <input type="email" name="email" class="form-control" required
                     value="<?php echo h($me['email'] ?? ''); ?>">
            </div>

            <?php if ($has_full || $has_name || $has_fn || $has_ln): ?>
              <div class="col-12">
                <label class="form-label small">Full Name</label>
                <input name="full_name" class="form-control"
                       value="<?php echo h($display_full); ?>">
                <?php if ($has_fn || $has_ln): ?>
                  <div class="form-text">This will populate first/last name fields.</div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div class="col-md-4">
              <label class="form-label small">Role</label>
              <input class="form-control" value="<?php echo h($me['role'] ?? 'admin'); ?>" disabled>
            </div>
            <div class="col-md-4">
              <label class="form-label small">Status</label>
              <?php
                $dispStatus = $me['status'] ?? 'pending';
                if (!$col_status) {
                  if (isset($me['is_active']))   $dispStatus = ((int)$me['is_active']===1 ? 'active':'pending');
                  if (isset($me['is_approved'])) $dispStatus = ((int)$me['is_approved']===1 ? 'active':$dispStatus);
                }
              ?>
              <input class="form-control" value="<?php echo h($dispStatus); ?>" disabled>
            </div>
            <div class="col-md-4">
              <label class="form-label small">Created</label>
              <input class="form-control" value="<?php echo h($me['created_at'] ?? ''); ?>" disabled>
            </div>
            <?php if ($col_updated): ?>
            <div class="col-md-4">
              <label class="form-label small">Updated</label>
              <input class="form-control" value="<?php echo h($me['updated_at'] ?? ''); ?>" disabled>
            </div>
            <?php endif; ?>
          </div>

          <div class="d-flex justify-content-end mt-3">
            <button class="btn btn-dark"><i class="fa fa-save me-1"></i> Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h6 class="fw-bold mb-3">Change Password</h6>
        <form method="post" action="admin_profile.php" autocomplete="off">
          <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
          <input type="hidden" name="action" value="change_password">

          <div class="mb-2">
            <label class="form-label small">Current Password</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label small">New Password</label>
            <input type="password" name="new_password" class="form-control" minlength="8" required>
          </div>
          <div class="mb-2">
            <label class="form-label small">Repeat New Password</label>
            <input type="password" name="repeat_password" class="form-control" minlength="8" required>
          </div>

          <div class="d-flex justify-content-end mt-2">
            <button class="btn btn-outline-dark"><i class="fa fa-key me-1"></i> Update Password</button>
          </div>
        </form>

        <div class="alert alert-info mt-3 mb-0 small">
          <i class="fa fa-circle-info me-1"></i>
          Your password is stored securely (bcrypt). Legacy hashes auto-upgrade after a successful login/change.
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Avatar + Recent activity -->
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3">Profile Photo</h6>
        <div class="d-flex align-items-center gap-3">
          <div class="avatar-frame border">
            <?php if ($curAvatarUrl): ?>
              <img src="<?php echo h($curAvatarUrl); ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
              <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted">
                <i class="fa fa-user fa-2x"></i>
              </div>
            <?php endif; ?>
          </div>
          <div class="small text-muted">
            JPG/PNG/WEBP • Max 2MB
          </div>
        </div>

        <form class="mt-3" method="post" action="admin_profile.php" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
          <input type="hidden" name="action" value="upload_avatar">
          <div class="input-group">
            <input type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp,image/*" class="form-control" required>
            <button class="btn btn-dark">Upload</button>
          </div>
        </form>

        <form class="mt-2" method="post" action="admin_profile.php" onsubmit="return confirm('Remove your profile photo?');">
          <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
          <input type="hidden" name="action" value="remove_avatar">
          <button class="btn btn-outline-danger btn-sm" <?php echo $curAvatarUrl?'':'disabled'; ?>>
            <i class="fa fa-trash me-1"></i> Remove Photo
          </button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h6 class="fw-bold mb-3">Recent Activity</h6>
        <?php if ($recent_approvals): ?>
          <div class="list-group list-group-flush">
            <?php foreach ($recent_approvals as $a): ?>
              <div class="list-group-item px-0">
                <div class="d-flex justify-content-between">
                  <div>
                    <span class="badge text-bg-<?php
                      $s = strtolower((string)($a['status'] ?? ''));
                      echo $s==='approved'?'success':($s==='rejected'?'danger':'secondary');
                    ?>"><?php echo h(ucfirst((string)($a['status'] ?? ''))); ?></span>
                    <span class="ms-2 small text-muted">#<?php echo (int)($a['id'] ?? 0); ?></span>
                  </div>
                  <?php if (!empty($a['decided_at'])): ?>
                    <div class="small text-muted"><?php echo h($a['decided_at']); ?></div>
                  <?php endif; ?>
                </div>
                <?php if (!empty($a['note'])): ?>
                  <div class="small mt-1"><?php echo h($a['note']); ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-muted small">No recent approval actions.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$PAGE_FOOT_SCRIPTS = "<script>
// SweetAlert flash (if available)
window.addEventListener('load', function(){
  var alertEl = document.querySelector('.alert'); if (!alertEl) return;
  var msg = alertEl.textContent.trim();
  if (typeof themedSwal === 'function') {
    themedSwal({ title: alertEl.classList.contains('alert-success')?'Success':'Notice', html: msg, icon: alertEl.classList.contains('alert-success')?'success':'info' });
    alertEl.remove();
  } else if (window.Swal && typeof Swal.fire==='function') {
    Swal.fire({ title: alertEl.classList.contains('alert-success')?'Success':'Notice', text: msg, icon: alertEl.classList.contains('alert-success')?'success':'info' });
    alertEl.remove();
  }
});
</script>";
include __DIR__ . '/inc/layout_foot.php';
