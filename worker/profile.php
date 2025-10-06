<?php
// /worker/profile.php
// Worker Profile: clean UI to view core info and update contact, address, photo.
// Includes base-URL handling so images render correctly when app runs in a subfolder.

require_once __DIR__ . '/inc/common.php'; // secure session, $conn, helpers, current_worker(), log_activity()
date_default_timezone_set('Asia/Manila');

$PAGE_TITLE = 'My Profile';
$CURRENT    = 'profile.php';

/* ---------- App base URL (fix images when app is in subfolder like /Poultrymetrix) ---------- */
$BASE_URI = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
if ($BASE_URI === '/' || $BASE_URI === '\\') $BASE_URI = ''; // when app is at webroot

function normalize_url_for_app(?string $u, string $base=''): string {
  if (!$u) return '';
  if (preg_match('~^https?://~i', $u)) return $u;          // already absolute
  if ($u[0] === '/') {
    // Prefix app base for /uploads/... or /assets/... only
    if (strpos($u, '/uploads/') === 0 || strpos($u, '/assets/') === 0) {
      return $base . $u;
    }
  }
  return $u;
}

/* ---------- Local helpers (only if not defined) ---------- */
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $t){ $t=$c->real_escape_string($t); $r=@$c->query("SHOW TABLES LIKE '{$t}'"); return !!($r && $r->num_rows); }
}
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $tbl, string $col): bool {
    $tbl=$c->real_escape_string($tbl); $col=$c->real_escape_string($col);
    $r=@$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'"); return $r && $r->num_rows>0;
  }
}
if (!function_exists('firstExistingCol')) {
  function firstExistingCol(mysqli $c, string $tbl, array $cands, $fallback=null){
    foreach($cands as $x){ if (colExists($c,$tbl,$x)) return $x; } return $fallback;
  }
}

/* ---------- CSRF + flash ---------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];
function flash_set($type,$msg){ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function flash_get(){ $x=$_SESSION['flash']??null; unset($_SESSION['flash']); return $x; }

/* ---------- Employee record ---------- */
if (!tableExists($conn,'employees')) {
  include __DIR__ . '/layout_head.php';
  echo '<div class="alert alert-warning">The <code>employees</code> table was not found.</div>';
  include __DIR__ . '/layout_foot.php';
  exit;
}

$emp = current_worker($conn);
if (!$emp) {
  // Render minimal UI with hint to admin
  include __DIR__ . '/layout_head.php';
  echo '<div class="alert alert-warning">Your user is not linked to an employee profile yet. Please contact Admin to link your account.</div>';
  include __DIR__ . '/layout_foot.php';
  exit;
}

/* Map columns dynamically */
$EMP_TBL   = 'employees';
$COL_ID    = firstExistingCol($conn,$EMP_TBL, ['employee_id','id'], 'employee_id');
$COL_NAME  = firstExistingCol($conn,$EMP_TBL, ['full_name','name'], null);
$COL_USER  = firstExistingCol($conn,$EMP_TBL, ['username','user_name','login'], null);
$COL_EMAIL = firstExistingCol($conn,$EMP_TBL, ['email','email_address'], null);
$COL_POS   = firstExistingCol($conn,$EMP_TBL, ['position','title','designation'], null);
$COL_ROLE  = firstExistingCol($conn,$EMP_TBL, ['role','user_role'], null);
$COL_PHONE = firstExistingCol($conn,$EMP_TBL, ['contact_number','phone','mobile','phone_number'], null);
$COL_ADDR  = firstExistingCol($conn,$EMP_TBL, ['address','home_address','address_line'], null);
$COL_PHOTO = firstExistingCol($conn,$EMP_TBL, ['photo','avatar','image','photo_path'], null);

$employee_id = (int)($emp[$COL_ID] ?? 0);
$display_name= $COL_NAME  ? ($emp[$COL_NAME]  ?? '') : ($_SESSION['username'] ?? 'Worker');
$username    = $COL_USER  ? ($emp[$COL_USER]  ?? '') : ($_SESSION['username'] ?? '');
$email       = $COL_EMAIL ? ($emp[$COL_EMAIL] ?? '') : '';
$position    = $COL_POS   ? ($emp[$COL_POS]   ?? '') : 'Worker';
$role        = $COL_ROLE  ? ($emp[$COL_ROLE]  ?? 'worker') : 'worker';
$contact     = $COL_PHONE ? ($emp[$COL_PHONE] ?? '') : '';
$address     = $COL_ADDR  ? ($emp[$COL_ADDR]  ?? '') : '';
$photo       = $COL_PHOTO ? ($emp[$COL_PHOTO] ?? '') : '';

/* ---------- Uploads dir (worker side) ---------- */
// /htdocs/Poultrymetrix/uploads/workers (filesystem)
$uploadsRootFS = realpath(dirname(__DIR__).'/uploads');
if ($uploadsRootFS === false) $uploadsRootFS = dirname(__DIR__).'/uploads';
@mkdir($uploadsRootFS, 0775, true);

$uploadFS  = $uploadsRootFS . '/workers';
@mkdir($uploadFS, 0775, true);

// IMPORTANT: URL must include app base when app is in a subfolder
$uploadURL = $BASE_URI . '/uploads/workers';

/* ---------- POST: update profile ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='update_profile') {
  if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(400); exit('Bad CSRF');
  }
  if ($employee_id<=0) {
    flash_set('danger','Your profile is not fully linked. Please contact Admin.');
    header('Location: profile.php'); exit;
  }

  $new_contact = trim($_POST['contact_number'] ?? '');
  $new_address = trim($_POST['address'] ?? '');
  $remove_photo= isset($_POST['remove_photo']) && $_POST['remove_photo']=='1';

  $new_photo_url = $photo;

  // Handle upload
  if (!$remove_photo && !empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext,['jpg','jpeg','png','webp'])) {
      flash_set('danger','Invalid photo (allowed: jpg, jpeg, png, webp).');
      header('Location: profile.php'); exit;
    }
    // (Optional) simple max size check ~ 4MB
    if (!empty($_FILES['photo']['size']) && $_FILES['photo']['size'] > 4*1024*1024) {
      flash_set('danger','Photo too large. Max 4MB.');
      header('Location: profile.php'); exit;
    }
    $fname = 'w_'.$employee_id.'_'.time().'.'.$ext;
    if (!@move_uploaded_file($_FILES['photo']['tmp_name'], $uploadFS.'/'.$fname)) {
      flash_set('danger','Failed to save photo.');
      header('Location: profile.php'); exit;
    }
    $new_photo_url = $uploadURL.'/'.$fname; // includes BASE_URI when in subfolder
  }
  if ($remove_photo) $new_photo_url = null;

  // Build dynamic UPDATE
  $sets=[]; $types=''; $vals=[];
  if ($COL_PHONE){ $sets[]="`$COL_PHONE`=?"; $types.='s'; $vals[]=$new_contact; }
  if ($COL_ADDR){  $sets[]="`$COL_ADDR`=?";  $types.='s'; $vals[]=$new_address; }
  if ($COL_PHOTO){ $sets[]="`$COL_PHOTO`=?"; $types.='s'; $vals[]=$new_photo_url; }
  if (!$sets){
    flash_set('warning','No editable columns available in employees table.');
    header('Location: profile.php'); exit;
  }
  $types.='i'; $vals[]=$employee_id;
  $sql="UPDATE `$EMP_TBL` SET ".implode(', ',$sets)." WHERE `$COL_ID`=? LIMIT 1";
  $st=$conn->prepare($sql); $st->bind_param($types, ...$vals);
  if ($st->execute()) {
    // Log & refresh local variables
    log_activity($conn,'profile_update',$employee_id,'worker','employee',(string)$employee_id,['contact'=>$new_contact]);
    if ($COL_PHONE) $contact = $new_contact;
    if ($COL_ADDR)  $address = $new_address;
    if ($COL_PHOTO) $photo   = $new_photo_url;
    flash_set('success','Profile updated.');
  } else {
    flash_set('danger','Could not update profile.');
  }
  $st->close();
  header('Location: profile.php'); exit;
}

/* ---------- Render ---------- */
$flash = flash_get();
include __DIR__ . '/layout_head.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">My Profile</h5>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?php echo h($flash['type']); ?>"><?php echo h($flash['msg']); ?></div>
<?php endif; ?>

<div class="row g-3">
  <!-- Left: Photo + Basic -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex flex-column align-items-center text-center">
          <div class="rounded-circle overflow-hidden border mb-3" style="width:140px;height:140px;background:#f8f9fa;">
            <?php $photo_url = normalize_url_for_app($photo, $BASE_URI); ?>
            <?php if ($photo_url): ?>
              <img src="<?php echo h($photo_url); ?>" alt="Photo" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
              <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted">
                <i class="fa-regular fa-user fa-3x"></i>
              </div>
            <?php endif; ?>
          </div>
          <div class="text-center">
            <div class="fw-bold fs-5"><?php echo h($display_name ?: '(no name)'); ?></div>
            <div class="small text-muted">@<?php echo h($username ?: ''); ?></div>
            <span class="badge text-bg-secondary mt-2"><?php echo h(ucfirst((string)$role)); ?></span>
          </div>
          <hr class="w-100">
          <div class="w-100">
            <div class="small text-muted">Position</div>
            <div class="fw-semibold mb-2"><?php echo h($position ?: '—'); ?></div>
            <div class="small text-muted">Email</div>
            <div class="fw-semibold"><?php echo h($email ?: '—'); ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Editable -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Update Information</strong>
        <span class="small text-muted">Keep your contact details up to date.</span>
      </div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="action" value="update_profile">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small">Contact Number</label>
              <input name="contact_number" class="form-control" value="<?php echo h($contact); ?>" <?php echo $COL_PHONE?'':'disabled'; ?>>
              <?php if(!$COL_PHONE): ?><small class="text-muted">Contact column not found; field disabled.</small><?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Address</label>
              <input name="address" class="form-control" value="<?php echo h($address); ?>" <?php echo $COL_ADDR?'':'disabled'; ?>>
              <?php if(!$COL_ADDR): ?><small class="text-muted">Address column not found; field disabled.</small><?php endif; ?>
            </div>

            <div class="col-md-8">
              <label class="form-label small">Photo (JPG/PNG/WEBP)</label>
              <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,image/*" class="form-control" <?php echo $COL_PHOTO?'':'disabled'; ?>>
              <?php if(!$COL_PHOTO): ?><small class="text-muted">Photo column not found; upload disabled.</small><?php endif; ?>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="remove_photo" name="remove_photo" <?php echo $COL_PHOTO?'':'disabled'; ?>>
                <label class="form-check-label small" for="remove_photo">Remove current photo</label>
              </div>
            </div>
          </div>

          <div class="mt-3">
            <button class="btn btn-dark px-4">Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <div class="small text-muted mt-2">
      * If you need to change your name, username, or position, please contact your supervisor or admin.
    </div>
  </div>
</div>

<?php
$PAGE_FOOT_SCRIPTS = "";
include __DIR__ . '/layout_foot.php';
