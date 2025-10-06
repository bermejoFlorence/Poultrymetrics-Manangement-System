<?php
// customer/customer_profile_edit.php — Edge-to-edge edit profile (split names + structured address + gender/birthday). Only Cancel + Save.
$PAGE_TITLE = 'Edit Profile';
$CURRENT    = 'customer_profile.php'; // keep sidebar highlight on Profile

require_once __DIR__ . '/inc/common.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('firstExistingCol')) {
  function firstExistingCol($conn,$table,$cands){ foreach($cands as $c){ if (colExists($conn,$table,$c)) return $c; } return null; }
}

$uid = (int)($_SESSION['user_id'] ?? $CURRENT_USER['id'] ?? 0);
if ($uid <= 0) { header('Location: ../login.php'); exit; }

/* ===== Detect schema ===== */
$HAS_CUSTOMERS = tableExists($conn,'customers');
$map = [
  'pk'=>null,'uid'=>null,
  'name'=>null, // legacy full name if present
  'email'=>null,'phone'=>null,
  'fname'=>null,'mname'=>null,'lname'=>null,
  'store'=>null,'type'=>null,
  'addr'=>null,'brgy'=>null,'city'=>null,'prov'=>null,'post'=>null,
  'lat'=>null,'lon'=>null,
  'gender'=>null,'dob'=>null,'age'=>null, // new: gender/birthday/age
  'notes'=>null, // delivery instructions / landmark
  'photo'=>null
];

if ($HAS_CUSTOMERS) {
  $map['pk']    = colExists($conn,'customers','id') ? 'id' : firstExistingCol($conn,'customers',['customer_id']);
  $map['uid']   = firstExistingCol($conn,'customers',['user_id','uid','users_id','account_id','customer_id']);

  $map['name']  = firstExistingCol($conn,'customers',['customer_name','full_name','name']); // legacy consolidated name

  $map['email'] = firstExistingCol($conn,'customers',['email']);
  $map['phone'] = firstExistingCol($conn,'customers',['phone','contact','mobile','contact_number','phone_number']);

  // Split names
  $map['fname'] = firstExistingCol($conn,'customers',['first_name','firstname','given_name']);
  $map['mname'] = firstExistingCol($conn,'customers',['middle_name','middlename','middle']);
  $map['lname'] = firstExistingCol($conn,'customers',['last_name','lastname','family_name','surname']);

  // Structured address (with barangay)
  $map['addr']  = firstExistingCol($conn,'customers',['address_line','address','street']);
  $map['brgy']  = firstExistingCol($conn,'customers',['barangay','brgy','bgy']);
  $map['city']  = firstExistingCol($conn,'customers',['city','municipality','town']);
  $map['prov']  = firstExistingCol($conn,'customers',['province','state','region']);
  $map['post']  = firstExistingCol($conn,'customers',['postal_code','zip','zipcode']);

  $map['lat']   = firstExistingCol($conn,'customers',['latitude','lat']);
  $map['lon']   = firstExistingCol($conn,'customers',['longitude','lon','lng','long']);

  // New: demographics
  $map['gender']= firstExistingCol($conn,'customers',['gender','sex']);
  $map['dob']   = firstExistingCol($conn,'customers',['birthday','date_of_birth','dob','birthdate','birthday_date']);
  $map['age']   = firstExistingCol($conn,'customers',['age']);

  // Prefer explicit delivery fields
  $map['notes'] = firstExistingCol($conn,'customers',['delivery_instructions','landmark','notes','remarks']);

  $map['photo'] = firstExistingCol($conn,'customers',['photo','avatar','image','picture']);
}

$USERS_PHOTO_COL = (tableExists($conn,'users') ? firstExistingCol($conn,'users',['photo','avatar','image','picture']) : null);

/* ===== Load for edit (function only) ===== */
function load_profile_edit(mysqli $conn, int $uid, array $map, bool $hasCustomers, ?string $usersPhotoCol){
  $out = null;
  if ($hasCustomers && $map['uid']) {
    $cols = [];
    foreach (['pk','uid','name','email','phone','fname','mname','lname','store','type','addr','brgy','city','prov','post','lat','lon','gender','dob','age','notes','photo'] as $k){
      if ($map[$k]) $cols[] = "{$map[$k]} AS {$k}";
    }
    if ($cols){
      $sql = "SELECT ".implode(',', $cols)." FROM customers WHERE {$map['uid']}=? ORDER BY ".($map['pk'] ?: $map['uid'])." DESC LIMIT 1";
      if ($st = $conn->prepare($sql)){
        $st->bind_param('i',$uid); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
        if ($r) $out = $r;
      }
    }
  }
  if (!$out) {
    $fb = customer_profile($conn,$uid);
    if ($fb) {
      $out = [
        'name'  => $fb['customer_name'] ?? ($GLOBALS['CURRENT_USER']['username'] ?? null),
        'email' => $fb['email'] ?? ($GLOBALS['CURRENT_USER']['email'] ?? null),
        'phone' => $fb['phone'] ?? null,
        'store' => $fb['store_name'] ?? null,
        'type'  => $fb['store_type'] ?? null,
        'addr'  => $fb['address_line'] ?? ($fb['address'] ?? null),
        'brgy'  => $fb['barangay'] ?? null,
        'city'  => $fb['city'] ?? null,
        'prov'  => $fb['province'] ?? null,
        'post'  => $fb['postal_code'] ?? null,
        'lat'   => $fb['latitude'] ?? null,
        'lon'   => $fb['longitude'] ?? null,
        'gender'=> $fb['gender'] ?? ($fb['sex'] ?? null),
        'dob'   => $fb['birthday'] ?? ($fb['date_of_birth'] ?? ($fb['dob'] ?? ($fb['birthdate'] ?? null))),
        'age'   => $fb['age'] ?? null,
        'notes' => $fb['notes'] ?? null,
      ];
    } else {
      $out = ['name'=>$GLOBALS['CURRENT_USER']['username'] ?? ''];
    }
  }
  if ((!$out || empty($out['photo'])) && $usersPhotoCol) {
    if ($st = $conn->prepare("SELECT `$usersPhotoCol` AS p FROM users WHERE id=? LIMIT 1")){
      $st->bind_param('i',$uid); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
      if ($row && !empty($row['p'])) $out['photo'] = $row['p'];
    }
  }
  return $out;
}

/* ===== File upload helpers ===== */
function ensure_dir($path){ if (!is_dir($path)) @mkdir($path,0775,true); }
function save_uploaded_photo(array $file, string $destDir, int $uid): array {
  if ($file['error'] === UPLOAD_ERR_NO_FILE) { return [true,'','']; }
  if ($file['error'] !== UPLOAD_ERR_OK)     { return [false,'Upload error.','']; }
  if ($file['size'] > 2*1024*1024)          { return [false,'File too large (max 2 MB).','']; }
  $finfo=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($finfo,$file['tmp_name']); finfo_close($finfo);
  $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/jpg'=>'jpg','image/webp'=>'webp'];
  if (!isset($allowed[$mime])) return [false,'Invalid image type. Use JPG/PNG/WEBP.',''];
  $ext=$allowed[$mime]; ensure_dir($destDir);
  $fname='cust_'.$uid.'_'.date('Ymd_His').'.'.$ext; $abs=rtrim($destDir,'/\\').DIRECTORY_SEPARATOR.$fname;
  if (!move_uploaded_file($file['tmp_name'],$abs)) return [false,'Could not save file.',''];
  return [true,'','../uploads/customers/'.$fname];
}

/* ===== Helpers ===== */
function compute_age_php(?string $dateStr): ?int {
  if (!$dateStr) return null;
  $t = strtotime($dateStr);
  if ($t === false) return null;
  $d = new DateTime(date('Y-m-d',$t));
  $now = new DateTime('now');
  $diff = $now->diff($d);
  return max(0,(int)$diff->y);
}

/* ===== POST handler BEFORE any output ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Accept split names + structured address + gender/birthday
  $in = [
    'fname' => trim($_POST['first_name']  ?? ''),
    'mname' => trim($_POST['middle_name'] ?? ''),
    'lname' => trim($_POST['last_name']   ?? ''),

    'email' => trim($_POST['email'] ?? ''),
    'phone' => trim($_POST['phone'] ?? ''),
    'store' => trim($_POST['store_name'] ?? ''),
    'type'  => trim($_POST['store_type'] ?? ''),

    'addr'  => trim($_POST['address_line'] ?? ''),
    'brgy'  => trim($_POST['barangay'] ?? ''),
    'city'  => trim($_POST['city'] ?? ''),
    'prov'  => trim($_POST['province'] ?? ''),
    'post'  => trim($_POST['postal_code'] ?? ''),

    'lat'   => trim($_POST['latitude'] ?? ''),
    'lon'   => trim($_POST['longitude'] ?? ''),

    'gender'=> trim($_POST['gender'] ?? ''),
    'dob'   => trim($_POST['birthday'] ?? ''), // yyyy-mm-dd from <input type="date">

    // Delivery Instructions / Landmark
    'notes' => trim($_POST['notes'] ?? ''),
  ];

  // Best-effort barangay guess from a "street, barangay, city, province" pattern if brgy empty
  if ($in['brgy']==='' && $in['addr']!=='') {
    $parts = array_map('trim', explode(',', $in['addr']));
    if (count($parts) >= 3) {
      $in['brgy'] = trim($parts[count($parts)-2]);
    }
  }

  // Validate coords
  if ($in['lat'] !== '' && !is_numeric($in['lat'])) $in['lat'] = '';
  if ($in['lon'] !== '' && !is_numeric($in['lon'])) $in['lon'] = '';

  // Normalize gender a bit
  if ($in['gender']!=='') {
    $g = strtolower($in['gender']);
    if (in_array($g,['m','male'])) $in['gender']='male';
    elseif (in_array($g,['f','female'])) $in['gender']='female';
    elseif (in_array($g,['other','non-binary','nonbinary','nb'])) $in['gender']='other';
  }

  // Compute age from dob if available
  $agePHP = ($in['dob']!=='') ? compute_age_php($in['dob']) : null;

  // Build a legacy full name for back-compat
  $fullName = trim($in['fname'].' '.($in['mname'] ? mb_substr($in['mname'],0,1).'. ' : '').$in['lname']);

  $photoRel = '';
  if (!empty($_FILES['photo'])) {
    [$okUpload,$msgUpload,$p] = save_uploaded_photo($_FILES['photo'], dirname(__DIR__).'/uploads/customers', $uid);
    if (!$okUpload) {
      $_SESSION['flash_type'] = 'danger';
      $_SESSION['flash_msg']  = $msgUpload;
      header('Location: customer_profile_edit.php'); exit;
    }
    $photoRel = $p;
  }

  $okAny = false; $errMsg = null;

  if ($HAS_CUSTOMERS && $map['uid']) {
    $profile = load_profile_edit($conn,$uid,$map,$HAS_CUSTOMERS,$USERS_PHOTO_COL);
    $existsId = $profile['pk'] ?? null; $existsId = $existsId ? (int)$existsId : null;

    if ($existsId) {
      $set=[]; $types=''; $vals=[];

      // Names
      foreach (['fname','mname','lname'] as $k){
        if ($map[$k]){ $set[]="{$map[$k]}=?"; $types.='s'; $vals[] = ($in[$k] !== '' ? $in[$k] : null); }
      }
      if ($map['name']){ $set[]="{$map['name']}=?"; $types.='s'; $vals[] = ($fullName ?: null); }

      // Contact/Store + Address (with barangay)
      foreach (['email','phone','store','type','addr','brgy','city','prov','post'] as $k){
        if ($map[$k]){ $set[]="{$map[$k]}=?"; $types.='s'; $vals[] = ($in[$k] !== '' ? $in[$k] : null); }
      }
      // Coords
      foreach (['lat','lon'] as $k){
        if ($map[$k]){ $set[]="{$map[$k]}=?"; $types.='d'; $vals[] = ($in[$k] !== '' ? (float)$in[$k] : null); }
      }

      // Gender / Birthday / Age
      if ($map['gender']){ $set[]="{$map['gender']}=?"; $types.='s'; $vals[] = ($in['gender'] !== '' ? $in['gender'] : null); }
      if ($map['dob'])   { $set[]="{$map['dob']}=?";     $types.='s'; $vals[] = ($in['dob']    !== '' ? $in['dob']    : null); }
      if ($map['age'])   { $set[]="{$map['age']}=?";     $types.='i'; $vals[] = ($agePHP !== null ? $agePHP : null); }

      // Notes
      if ($map['notes']){ $set[]="{$map['notes']}=?"; $types.='s'; $vals[] = ($in['notes'] !== '' ? $in['notes'] : null); }

      if ($photoRel && $map['photo']) { $set[]="{$map['photo']}=?"; $types.='s'; $vals[]=$photoRel; }

      if ($set){
        $sql="UPDATE customers SET ".implode(', ',$set)." WHERE {$map['pk']}=?";
        if ($st=$conn->prepare($sql)){
          $types.='i'; $vals[]=$existsId;
          foreach($vals as &$v){ if ($v===null){ $v=null; } }
          $st->bind_param($types, ...$vals);
          $okAny=$st->execute(); $st->close();
          if (!$okAny) $errMsg = 'Could not update profile.';
        } else { $errMsg = 'Error preparing update.'; }
      } else { $okAny = true; }
    } else {
      // INSERT
      $cols = [$map['uid']]; $marks=['?']; $types='i'; $vals=[$uid];

      foreach (['fname','mname','lname'] as $k){
        if ($map[$k]){ $cols[]=$map[$k]; $marks[]='?'; $types.='s'; $vals[] = ($in[$k] !== '' ? $in[$k] : null); }
      }
      if ($map['name']){ $cols[]=$map['name']; $marks[]='?'; $types.='s'; $vals[] = ($fullName ?: null); }

      foreach (['email','phone','store','type','addr','brgy','city','prov','post'] as $k){
        if ($map[$k]){ $cols[]=$map[$k]; $marks[]='?'; $types.='s'; $vals[] = ($in[$k] !== '' ? $in[$k] : null); }
      }
      foreach (['lat','lon'] as $k){
        if ($map[$k]){ $cols[]=$map[$k]; $marks[]='?'; $types.='d'; $vals[] = ($in[$k] !== '' ? (float)$in[$k] : null); }
      }
      if ($map['gender']){ $cols[]=$map['gender']; $marks[]='?'; $types.='s'; $vals[] = ($in['gender'] !== '' ? $in['gender'] : null); }
      if ($map['dob'])   { $cols[]=$map['dob'];    $marks[]='?'; $types.='s'; $vals[] = ($in['dob']    !== '' ? $in['dob']    : null); }
      if ($map['age'])   { $cols[]=$map['age'];    $marks[]='?'; $types.='i'; $vals[] = ($agePHP !== null ? $agePHP : null); }

      if ($map['notes']){ $cols[]=$map['notes']; $marks[]='?'; $types.='s'; $vals[] = ($in['notes'] !== '' ? $in['notes'] : null); }

      if ($photoRel && $map['photo']) { $cols[]=$map['photo']; $marks[]='?'; $types.='s'; $vals[]=$photoRel; }

      $sql="INSERT INTO customers (".implode(',',$cols).") VALUES (".implode(',',$marks).")";
      if ($st=$conn->prepare($sql)){
        foreach($vals as &$v){ if ($v===null){ $v=null; } }
        $st->bind_param($types, ...$vals);
        $okAny=$st->execute(); $st->close();
        if (!$okAny) $errMsg = 'Could not save profile.';
      } else { $errMsg = 'Error preparing insert.'; }
    }
  } else {
    // Fallback: write to users if customers link not available
    if (tableExists($conn,'users')) {
      foreach ([['first_name','fname'],['middle_name','mname'],['last_name','lname']] as [$col,$k]){
        if (colExists($conn,'users',$col)){
          if ($st=$conn->prepare("UPDATE users SET `$col`=? WHERE id=?")){
            $val = ($in[$k] !== '' ? $in[$k] : null);
            $st->bind_param('si',$val,$uid); $ok=$st->execute(); $st->close(); $okAny=$ok||$okAny;
          }
        }
      }
      foreach ([['phone','phone'],['email','email'],['address','addr'],['latitude','lat'],['longitude','lon']] as [$col,$k]){
        if (colExists($conn,'users',$col)){
          $sql = "UPDATE users SET `$col`=? WHERE id=?";
          if ($st=$conn->prepare($sql)){
            if ($k==='lat' || $k==='lon'){
              $val = ($in[$k] !== '' ? (float)$in[$k] : null);
              $st->bind_param('di',$val,$uid);
            } else {
              $val = ($in[$k] !== '' ? $in[$k] : null);
              $st->bind_param('si',$val,$uid);
            }
            $ok=$st->execute(); $st->close(); $okAny=$ok||$okAny;
          }
        }
      }
      // Optionally store gender/birthday in users if columns exist
      if (colExists($conn,'users','gender') && $in['gender']!=='') {
        if ($st=$conn->prepare("UPDATE users SET gender=? WHERE id=?")){
          $st->bind_param('si',$in['gender'],$uid); $ok=$st->execute(); $st->close(); $okAny=$ok||$okAny;
        }
      }
      if (colExists($conn,'users','birthday') && $in['dob']!=='') {
        if ($st=$conn->prepare("UPDATE users SET birthday=? WHERE id=?")){
          $st->bind_param('si',$in['dob'],$uid); $ok=$st->execute(); $st->close(); $okAny=$ok||$okAny;
        }
      }
      if (colExists($conn,'users','age') && $agePHP!==null) {
        if ($st=$conn->prepare("UPDATE users SET age=? WHERE id=?")){
          $st->bind_param('ii',$agePHP,$uid); $ok=$st->execute(); $st->close(); $okAny=$ok||$okAny;
        }
      }
    }
  }

  if ($okAny) {
    $_SESSION['flash_type'] = 'success';
    $_SESSION['flash_msg']  = 'Profile saved.';
    header('Location: customer_profile.php'); exit;
  } else {
    $_SESSION['flash_type'] = 'danger';
    $_SESSION['flash_msg']  = $errMsg ?: 'Could not save profile.';
    header('Location: customer_profile_edit.php'); exit;
  }
}

/* ===== Render (no POST) ===== */
include __DIR__ . '/layout_head.php';

$profile = load_profile_edit($conn,$uid,$map,$HAS_CUSTOMERS,$USERS_PHOTO_COL);

function photo_src_or_placeholder(?string $p): string {
  if (!$p) return '../assets/images/placeholder.png';
  if (str_starts_with($p,'../uploads/') || str_starts_with($p,'uploads/')) return $p;
  return '../uploads/customers/'.ltrim($p,'/\\');
}
$photo = photo_src_or_placeholder($profile['photo'] ?? null);

// Flash
$flashType = $_SESSION['flash_type'] ?? null;
$flashMsg  = $_SESSION['flash_msg']  ?? null;
unset($_SESSION['flash_type'], $_SESSION['flash_msg']);
?>
<style>
  /* Edge-to-edge main area */
  .pm-root { margin:0; }
  .pm-container { padding-left:0 !important; padding-right:0 !important; }
  .pm-row { --bs-gutter-x:0; --bs-gutter-y:0; }

  .avatar{ width:120px; height:120px; border-radius:12px; object-fit:cover; border:1px solid #e9ecef; background:#f6f7f9; }
  .map-embed{ width:100%; height:300px; border:0; border-radius:12px; }
  .pm-card{ border:1px solid #e9ecef; border-radius:14px; }
  .pm-card .card-body{ padding:16px 18px; }
  @media (max-width: 575.98px){ .avatar{ width:100px; height:100px; } }
</style>

<div class="container-fluid pm-root pm-container">
  <div class="row pm-row">
    <div class="col-12">
      <!-- Header (no extra buttons) -->
      <div class="pm-card">
        <div class="card-body">
          <div class="small text-muted">Account</div>
          <div class="fw-bold fs-5">Edit Profile</div>
        </div>
      </div>

      <?php if ($flashType): ?>
        <div class="alert alert-<?php echo h($flashType); ?> mt-2"><?php echo h($flashMsg); ?></div>
      <?php endif; ?>

      <form method="post" class="pm-card mt-2" enctype="multipart/form-data">
        <div class="card-body">
          <div class="row g-3">

            <!-- Photo -->
            <div class="col-12">
              <div class="d-flex align-items-center gap-3">
                <img class="avatar" src="<?php echo h($photo); ?>" alt="Photo">
                <div class="flex-grow-1">
                  <label class="form-label small text-muted mb-1">Photo (PNG/JPG/WEBP, max 2 MB)</label>
                  <input type="file" class="form-control" name="photo" accept="image/*">
                </div>
              </div>
              <hr class="my-3">
            </div>

            <!-- Names -->
            <div class="col-md-4">
              <label class="form-label small text-muted">First Name</label>
              <input class="form-control" name="first_name" value="<?php echo h($profile['fname'] ?? ''); ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Middle Name <span class="text-muted">(optional)</span></label>
              <input class="form-control" name="middle_name" value="<?php echo h($profile['mname'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Last Name</label>
              <input class="form-control" name="last_name" value="<?php echo h($profile['lname'] ?? ''); ?>" required>
            </div>

            <!-- Contact -->
            <div class="col-md-6">
              <label class="form-label small text-muted">Email</label>
              <input class="form-control" name="email" type="email" value="<?php echo h($profile['email'] ?? ($CURRENT_USER['email'] ?? '')); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Phone</label>
              <input class="form-control" name="phone" value="<?php echo h($profile['phone'] ?? ''); ?>" required>
            </div>

            <!-- Store -->
            <div class="col-md-6">
              <label class="form-label small text-muted">Store Name</label>
              <input class="form-control" name="store_name" value="<?php echo h($profile['store'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Store Type</label>
              <input class="form-control" name="store_type" value="<?php echo h($profile['type'] ?? ''); ?>" placeholder="e.g., Retailer / Distributor">
            </div>

            <!-- Address -->
            <div class="col-12">
              <label class="form-label small text-muted">Street</label>
              <div class="input-group">
                <input class="form-control" name="address_line" value="<?php echo h($profile['addr'] ?? ''); ?>" placeholder="House/Street (e.g., #10 Mabini St.)">
                <button class="btn btn-outline-secondary" type="button" id="btnUseCurrent">
                  <i class="fa-solid fa-location-crosshairs me-1"></i> Use current location
                </button>
              </div>
              <div class="small text-muted mt-1">Clicking fills the coordinates below. Click <b>Save</b> to persist.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label small text-muted">Barangay</label>
              <input class="form-control" name="barangay" value="<?php echo h($profile['brgy'] ?? ''); ?>" placeholder="e.g., Brgy. San Isidro">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">City/Municipality</label>
              <input class="form-control" name="city" value="<?php echo h($profile['city'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Province</label>
              <input class="form-control" name="province" value="<?php echo h($profile['prov'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Postal Code</label>
              <input class="form-control" name="postal_code" value="<?php echo h($profile['post'] ?? ''); ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label small text-muted">Latitude</label>
              <input class="form-control" id="lat" name="latitude" value="<?php echo h($profile['lat'] ?? ''); ?>" placeholder="e.g., 13.7563" inputmode="decimal" pattern="-?\d+(\.\d+)?">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Longitude</label>
              <input class="form-control" id="lon" name="longitude" value="<?php echo h($profile['lon'] ?? ''); ?>" placeholder="e.g., 121.0583" inputmode="decimal" pattern="-?\d+(\.\d+)?">
            </div>

            <!-- Gender / Birthday / Age -->
            <div class="col-md-4">
              <label class="form-label small text-muted">Gender</label>
              <select class="form-select" name="gender">
                <?php
                  $g = strtolower((string)($profile['gender'] ?? ''));
                  $opts = ['' => '— Select —', 'male'=>'Male', 'female'=>'Female', 'other'=>'Other'];
                  foreach ($opts as $val=>$label){
                    $sel = ($val !== '' && $g === $val) ? 'selected' : '';
                    echo '<option value="'.h($val).'" '.$sel.'>'.h($label).'</option>';
                  }
                ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Birthday</label>
              <input class="form-control" id="birthday" name="birthday" type="date"
                     value="<?php echo h(isset($profile['dob']) && $profile['dob'] ? date('Y-m-d', strtotime($profile['dob'])) : ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted">Age</label>
              <input class="form-control" id="agePreview" value="<?php echo h(isset($profile['age']) ? $profile['age'] : ''); ?>" placeholder="—" readonly>
            </div>

            <!-- Delivery instructions -->
            <div class="col-12">
              <label class="form-label small text-muted">Delivery Instructions / Landmark</label>
              <textarea class="form-control" name="notes" rows="3" placeholder="e.g., Green gate, near barangay hall, call before arrival"><?php echo h($profile['notes'] ?? ''); ?></textarea>
            </div>

            <!-- Map preview -->
            <?php if (!empty($profile['lat']) && !empty($profile['lon'])): ?>
              <div class="col-12">
                <div class="small text-muted mb-1">Map (Preview)</div>
                <?php
                  $lat=(float)$profile['lat']; $lon=(float)$profile['lon'];
                  $bb = implode('%2C', [$lon-0.01, $lat-0.01, $lon+0.01, $lat+0.01]);
                  $marker = $lat.'%2C'.$lon;
                  $src = "https://www.openstreetmap.org/export/embed.html?bbox={$bb}&layer=mapnik&marker={$marker}";
                ?>
                <iframe id="mapEmbed" class="map-embed" src="<?php echo $src; ?>" loading="lazy"></iframe>
                <div class="mt-2">
                  <a id="mapFullLink" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                     href="<?php echo 'https://www.openstreetmap.org/?mlat='.$lat.'&mlon='.$lon.'#map=17/'.$lat.'/'.$lon; ?>">
                    <i class="fa-solid fa-map-location-dot me-1"></i> Open full map
                  </a>
                </div>
              </div>
            <?php else: ?>
              <div class="col-12">
                <div class="alert alert-light border">No coordinates yet. Use the button above or type manually, then Save.</div>
                <iframe id="mapEmbed" class="map-embed" style="display:none"></iframe>
                <div class="mt-2">
                  <a id="mapFullLink" class="btn btn-sm btn-outline-secondary" style="display:none" target="_blank" rel="noopener">Open full map</a>
                </div>
              </div>
            <?php endif; ?>

          </div>
        </div>

        <!-- Footer: ONLY Cancel + Save -->
        <div class="card-footer d-flex justify-content-end gap-2">
          <a class="btn btn-outline-secondary" href="customer_profile.php"><i class="fa-solid fa-xmark me-1"></i> Cancel</a>
          <button class="btn btn-dark" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i> Save</button>
        </div>
      </form>

    </div>
  </div>
</div>

<script>
// Use current location: fills lat/lon only (NO auto-save). Updates preview map live.
document.addEventListener('DOMContentLoaded', function(){
  const btn  = document.getElementById('btnUseCurrent');
  const latF = document.getElementById('lat');
  const lonF = document.getElementById('lon');
  const map  = document.getElementById('mapEmbed');
  const link = document.getElementById('mapFullLink');

  function updateMap(lat, lon){
    const bb = [lon-0.01, lat-0.01, lon+0.01, lat+0.01].join('%2C');
    const marker = lat+'%2C'+lon;
    const src = `https://www.openstreetmap.org/export/embed.html?bbox=${bb}&layer=mapnik&marker=${marker}`;
    const href = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=17/${lat}/${lon}`;
    if (map){ map.src = src; map.style.display='block'; }
    if (link){ link.href = href; link.style.display='inline-block'; }
  }

  if (btn){
    btn.addEventListener('click', function(){
      if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
      const original = btn.innerHTML;
      btn.disabled = true; btn.textContent = 'Locating…';
      navigator.geolocation.getCurrentPosition(
        function(pos){
          const lat = +pos.coords.latitude.toFixed(6);
          const lon = +pos.coords.longitude.toFixed(6);
          latF.value = lat; lonF.value = lon;
          updateMap(lat, lon);
          btn.disabled = false; btn.innerHTML = original;
        },
        function(err){
          alert('Could not get your location: ' + err.message);
          btn.disabled = false; btn.innerHTML = original;
        },
        { enableHighAccuracy:true, timeout:10000, maximumAge:0 }
      );
    });
  }

  // Live update map when typing coords
  ['input','change'].forEach(ev => {
    latF?.addEventListener(ev, () => {
      const lat = parseFloat(latF.value), lon = parseFloat(lonF.value);
      if (isFinite(lat) && isFinite(lon)) updateMap(lat, lon);
    });
    lonF?.addEventListener(ev, () => {
      const lat = parseFloat(latF.value), lon = parseFloat(lonF.value);
      if (isFinite(lat) && isFinite(lon)) updateMap(lat, lon);
    });
  });

  // Live Age preview from birthday
  const bday = document.getElementById('birthday');
  const ageP = document.getElementById('agePreview');
  function calcAgeLocal(iso){
    if(!iso) return '';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    const today = new Date();
    let age = today.getFullYear() - d.getFullYear();
    const m = today.getMonth() - d.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < d.getDate())) age--;
    return (age >= 0 && age <= 150) ? age : '';
  }
  function syncAge(){ ageP.value = calcAgeLocal(bday.value); }
  bday?.addEventListener('input', syncAge);
  bday?.addEventListener('change', syncAge);
  syncAge(); // initial
});
</script>

<?php
$PAGE_FOOT_SCRIPTS = "";
include __DIR__ . '/layout_foot.php';
