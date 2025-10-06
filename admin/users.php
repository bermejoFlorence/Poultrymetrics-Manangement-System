<?php
// =====================================================================
// /admin/users.php — schema-aware Users list (no auth enforcement)
// - Robust include of common.php (root /inc first, then /admin/inc fallback)
// - Status toggles: AUTO-map to valid enum values with correct CASE
// - Create/Approve/Disable: stores EXACT ENUM token from schema
// =====================================================================

// ---- Robust common include: prefer ROOT /inc/common.php, fallback to /admin/inc/common.php
$ROOT_COMMON  = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'common.php';
$ADMIN_COMMON = __DIR__         . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'common.php';
if (is_file($ROOT_COMMON)) {
  require_once $ROOT_COMMON;
} elseif (is_file($ADMIN_COMMON)) {
  require_once $ADMIN_COMMON;
} else {
  http_response_code(500);
  echo 'common.php not found. Looked for: ' . $ROOT_COMMON . ' or ' . $ADMIN_COMMON;
  exit;
}

// ---------- Safety shims ----------
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $t): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    if (!$st = $c->prepare($sql)) return false;
    $st->bind_param('s', $t); $st->execute(); $st->store_result();
    $ok = $st->num_rows > 0; $st->close(); return $ok;
  }
}
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $t, string $col): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if (!$st = $c->prepare($sql)) return false;
    $st->bind_param('ss', $t, $col); $st->execute(); $st->store_result();
    $ok = $st->num_rows > 0; $st->close(); return $ok;
  }
}
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('scalar')) {
  function scalar(mysqli $c, string $sql){
    $r = @$c->query($sql); if(!$r) return null; $row = $r->fetch_row(); $r->free();
    return $row ? $row[0] : null;
  }
}

// ---------- Page config ----------
$PAGE_TITLE = 'Users';
$CURRENT    = 'users.php';
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

// ---------- CSRF + Flash ----------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_valid($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($t ?? '')); }
if (!function_exists('flash_set')) {
  function flash_set($type,$msg){ $_SESSION['flash']=['type'=>$type,'msg'=>$msg,'t'=>$type,'m'=>$msg]; }
}
if (!function_exists('flash_get')) {
  function flash_get(){
    $x = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    if ($x===null) return null;
    if (is_string($x)) return ['type'=>'info','msg'=>$x,'t'=>'info','m'=>$x];
    if (is_array($x)) {
      $type = $x['type'] ?? ($x['t'] ?? 'info');
      $msg  = $x['msg']  ?? ($x['m'] ?? '');
      return ['type'=>$type,'msg'=>$msg,'t'=>$type,'m'=>$msg];
    }
    return null;
  }
}

// ---------- Helpers ----------
$ALL_ROLES = ['admin','accountant','worker','customer'];
function sanitize_role($r){
  $r = strtolower(trim((string)$r)); global $ALL_ROLES;
  return in_array($r,$ALL_ROLES,true) ? $r : 'customer';
}
function split_name($full){
  $full=trim(preg_replace('/\s+/',' ',$full)); if($full==='') return ['',''];
  $p=explode(' ',$full); $first=array_shift($p); return [$first,implode(' ',$p)];
}

/**
 * Return column meta: ['type' => 'enum(...)'|'varchar...'|..., 'enum_map' => [lower=>exact, ...]]
 * enum_map preserves original CASE for saving; keys are lowercase for matching.
 */
function column_meta(mysqli $c, string $table, string $col): array {
  $sql="SELECT DATA_TYPE, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st=$c->prepare($sql); if(!$st) return ['type'=>null,'enum_map'=>[]];
  $st->bind_param('ss',$table,$col); $st->execute();
  $row=$st->get_result()->fetch_assoc() ?: []; $st->close();
  $dataType = strtolower((string)($row['DATA_TYPE']??''));
  $colType  = (string)($row['COLUMN_TYPE']??'');
  $map=[];
  if ($dataType==='enum' && stripos($colType,'enum(')===0){
    // parse ENUM('A','B','C')
    $inside = trim(substr($colType,5,-1));
    // split by commas not inside quotes
    $parts = preg_split('/,(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/',$inside);
    foreach ($parts as $p){
      $tok = trim($p," \t\n\r\0\x0B'\"");
      if ($tok!=='') $map[strtolower($tok)] = $tok; // key: lower, val: exact
    }
  }
  return ['type'=>$colType, 'enum_map'=>$map];
}
/** Pick a legal status string from preferred list; returns EXACT token to save (from enum_map) */
function pick_status_exact(array $enum_map, array $preferred, ?string $fallback=null): ?string {
  if ($enum_map){
    foreach ($preferred as $p){
      $k = strtolower($p);
      if (isset($enum_map[$k])) return $enum_map[$k]; // exact-case token
    }
    // fallback to first enum if still nothing
    $first = reset($enum_map);
    return $first!==false ? $first : null;
  }
  // non-enum: return preferred[0] or fallback
  return $preferred[0] ?? $fallback;
}

// ---------- Users schema map ----------
$U_TBL = 'users';

// PK
$U_PK  = colExists($conn,$U_TBL,'id') ? 'id' :
         (colExists($conn,$U_TBL,'user_id') ? 'user_id' :
         (colExists($conn,$U_TBL,'uid') ? 'uid' :
         (colExists($conn,$U_TBL,'users_id') ? 'users_id' : 'id')));

// username
$uname_col = colExists($conn,$U_TBL,'username') ? 'username' :
             (colExists($conn,$U_TBL,'user_name') ? 'user_name' : null);
$has_username = (bool)$uname_col;

// email
$email_col    = colExists($conn,$U_TBL,'email') ? 'email' :
               (colExists($conn,$U_TBL,'email_address') ? 'email_address' : null);
$has_email    = (bool)$email_col;

// password
$pwd_col = colExists($conn,$U_TBL,'password_hash') ? 'password_hash' :
          (colExists($conn,$U_TBL,'password') ? 'password' :
          (colExists($conn,$U_TBL,'pass') ? 'pass' :
          (colExists($conn,$U_TBL,'pwd') ? 'pwd' : null)));

// role
$has_role_col = colExists($conn,$U_TBL,'role');

// name columns (used on create/search; not displayed)
$has_full     = colExists($conn,$U_TBL,'full_name');
$has_name     = colExists($conn,$U_TBL,'name');
$has_fn       = colExists($conn,$U_TBL,'first_name');
$has_ln       = colExists($conn,$U_TBL,'last_name');
$fullname_expr = $has_full ? "full_name"
               : ($has_name ? "name"
               : (($has_fn||$has_ln) ? "TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))" : ($uname_col ?? "'User'")));

// dates
$has_created_at = colExists($conn,$U_TBL,'created_at') || colExists($conn,$U_TBL,'created') || colExists($conn,$U_TBL,'registered_at');
$created_col    = colExists($conn,$U_TBL,'created_at') ? 'created_at' :
                 (colExists($conn,$U_TBL,'created') ? 'created' :
                 (colExists($conn,$U_TBL,'registered_at') ? 'registered_at' : null));
$has_updated_at = colExists($conn,$U_TBL,'updated_at');

// order
$orderExpr      = $created_col ? "`$created_col` DESC, `$U_PK` DESC" : "`$U_PK` DESC";

// status support
$status_col       = colExists($conn,$U_TBL,'status') ? 'status' : null; // may be ENUM or VARCHAR
$gate_active_col  = colExists($conn,$U_TBL,'is_active')   ? 'is_active'
                  : (colExists($conn,$U_TBL,'active')     ? 'active'
                  : (colExists($conn,$U_TBL,'enabled')    ? 'enabled' : null));
$gate_approve_col = colExists($conn,$U_TBL,'is_approved') ? 'is_approved'
                  : (colExists($conn,$U_TBL,'approved')   ? 'approved'
                  : (colExists($conn,$U_TBL,'verified')   ? 'verified'
                  : (colExists($conn,$U_TBL,'is_verified')? 'is_verified' : null)));

$supportToggle = (bool)($status_col || $gate_active_col || $gate_approve_col);

// column meta for status
$STATUS_META   = $status_col ? column_meta($conn,$U_TBL,$status_col) : ['type'=>null,'enum_map'=>[]];
$STATUS_ENUM_MAP = $STATUS_META['enum_map']; // [lower => ExactToken]

// smart defaults (EXACT tokens)
$DEFAULT_PENDING  = pick_status_exact($STATUS_ENUM_MAP, ['pending','inactive','disabled','new','unverified'], 'pending');
$VALUE_ACTIVE     = pick_status_exact($STATUS_ENUM_MAP, ['active','enabled','approved'], 'active');
$VALUE_DISABLED   = pick_status_exact($STATUS_ENUM_MAP, ['disabled','inactive','rejected','blocked','banned'], 'disabled');

// if status exists but we could not pick any (rare), skip writing status to avoid invalid enum
if ($status_col && !$DEFAULT_PENDING && !$VALUE_ACTIVE && !$VALUE_DISABLED) {
  $status_col = null;
}

$HAS_CO = tableExists($conn,'customer_orders') && colExists($conn,'customer_orders','customer_id');

// ---------- Actions (POST) ----------
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_valid($_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF validation failed'); }
  $action = $_POST['action'] ?? '';

  if ($action==='create') {
    if (!$has_username || !$pwd_col) { flash_set('error','Create not available: username/user_name or password column missing.'); header('Location: users.php'); exit; }

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $full     = trim($_POST['full_name'] ?? '');
    $role     = sanitize_role($_POST['role'] ?? 'customer');
    $password = (string)($_POST['password'] ?? '');

    if ($username==='' || $full==='' || $password==='') {
      flash_set('error','Username, Full name and Password are required.'); header('Location: users.php'); exit;
    }
    if ($has_email) {
      if ($email==='') { flash_set('error','Email is required in this schema.'); header('Location: users.php'); exit; }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash_set('error','Invalid email format.'); header('Location: users.php'); exit; }
    }

    // Uniqueness checks
    if ($has_email) {
      $stmt=$conn->prepare("SELECT SUM(`$uname_col` = ?) AS u_taken, SUM(`$email_col` = ?) AS e_taken
                            FROM `$U_TBL`
                            WHERE `$uname_col`=? OR `$email_col`=?");
      $stmt->bind_param('ssss',$username,$email,$username,$email);
      $stmt->execute();
      $dup = $stmt->get_result()->fetch_assoc() ?: ['u_taken'=>0,'e_taken'=>0];
      $stmt->close();
      if ((int)$dup['u_taken']>0) { flash_set('error','Username already exists.'); header('Location: users.php'); exit; }
      if ((int)$dup['e_taken']>0) { flash_set('error','Email already exists.'); header('Location: users.php'); exit; }
    } else {
      $stmt=$conn->prepare("SELECT 1 FROM `$U_TBL` WHERE `$uname_col`=? LIMIT 1");
      $stmt->bind_param('s',$username);
      $stmt->execute(); $stmt->store_result();
      if ($stmt->num_rows>0){ $stmt->close(); flash_set('error','Username already exists.'); header('Location: users.php'); exit; }
      $stmt->close();
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    // Build INSERT dynamically
    $fields=["`$uname_col`","`$pwd_col`"]; $place=['?','?']; $types='ss'; $vals=[$username,$hash];
    if ($has_email){ $fields[]="`$email_col`"; $place[]='?'; $types.='s'; $vals[]=$email; }

    // Names (still saved even if not displayed)
    if     ($has_full)                  { $fields[]='`full_name`';  $place[]='?'; $types.='s'; $vals[]=$full; }
    elseif ($has_name)                  { $fields[]='`name`';       $place[]='?'; $types.='s'; $vals[]=$full; }
    elseif ($has_fn || $has_ln)         { [$fn,$ln]=split_name($full);
                                          if ($has_fn){ $fields[]='`first_name`'; $place[]='?'; $types.='s'; $vals[]=$fn; }
                                          if ($has_ln){ $fields[]='`last_name`';  $place[]='?'; $types.='s'; $vals[]=$ln; } }
    if ($has_role_col) { $fields[]='`role`'; $place[]='?'; $types.='s'; $vals[]=$role; }

    // Defaults for status/gates (use EXACT enum token)
    if ($status_col && $DEFAULT_PENDING){ $fields[]="`$status_col`"; $place[]='?'; $types.='s'; $vals[]=$DEFAULT_PENDING; }
    if ($gate_active_col) { $fields[]="`$gate_active_col`"; $place[]='?'; $types.='i'; $vals[]=0; }
    if ($gate_approve_col){ $fields[]="`$gate_approve_col`";$place[]='?'; $types.='i'; $vals[]=0; }

    if ($created_col) { $fields[]="`$created_col`"; $place[]='NOW()'; }

    $sql = "INSERT INTO `$U_TBL` (".implode(',',$fields).") VALUES (".implode(',',$place).")";
    $st  = $conn->prepare($sql);
    if (substr_count($sql,'?')>0) $st->bind_param($types, ...$vals);
    $ok = $st->execute(); $err = $conn->error; $st->close();
    flash_set($ok?'success':'error', $ok?'User created.':('Create failed: '.$err));
    header('Location: users.php'); exit;
  }

  if ($action==='reset_password') {
    if (!$pwd_col){ flash_set('error','Reset not available: no password column.'); header('Location: users.php'); exit; }
    $id = (int)($_POST['id'] ?? 0); if ($id<=0){ flash_set('error','Invalid user.'); header('Location: users.php'); exit; }
    $hash = password_hash('password', PASSWORD_BCRYPT);
    $sql  = "UPDATE `$U_TBL` SET `$pwd_col`=?, ".($has_updated_at?'`updated_at`=NOW(), ':'')."`$U_PK`=`$U_PK` WHERE `$U_PK`=?";
    $st   = $conn->prepare($sql); $st->bind_param('si',$hash,$id);
    $ok   = $st->execute(); $st->close();
    flash_set($ok?'success':'error',$ok?"Password reset to <code>password</code>.":'Reset failed.');
    header('Location: users.php'); exit;
  }

  if ($action==='approve' || $action==='disable') {
    if (!$supportToggle){ flash_set('error','Status toggle not supported by current users schema.'); header('Location: users.php'); exit; }
    $id = (int)($_POST['id'] ?? 0); if ($id<=0){ flash_set('error','Invalid user.'); header('Location: users.php'); exit; }

    // Prevent disabling last admin / self (if role column exists)
    if ($has_role_col && $action==='disable') {
      $q=$conn->prepare("SELECT `role` FROM `$U_TBL` WHERE `$U_PK`=?"); $q->bind_param('i',$id); $q->execute();
      $role=strtolower($q->get_result()->fetch_assoc()['role'] ?? ''); $q->close();
      if ($role==='admin') {
        $adminCount=(int)scalar($conn,"SELECT COUNT(*) FROM `$U_TBL` WHERE `role`='admin'");
        $actingId=(int)($_SESSION['user_id'] ?? 0);
        if ($id===$actingId){ flash_set('error','You cannot disable your own admin account.'); header('Location: users.php'); exit; }
        if ($adminCount<=1){ flash_set('error','Cannot disable the last remaining admin.'); header('Location: users.php'); exit; }
      }
    }

    $toActive = ($action==='approve');
    $parts=[]; $types=''; $vals=[];
    if ($status_col) {
      // choose exact-case token for approve/disable
      $target = $toActive ? ($VALUE_ACTIVE ?: 'active') : ($VALUE_DISABLED ?: 'disabled');
      $parts[]="`$status_col`=?"; $types.='s'; $vals[]=$target;
    }
    if ($gate_active_col) { $parts[]="`$gate_active_col`=?"; $types.='i'; $vals[]=$toActive?1:0; }
    if ($gate_approve_col){ $parts[]="`$gate_approve_col`=?";$types.='i'; $vals[]=$toActive?1:0; }
    if ($has_updated_at)  { $parts[]="`updated_at`=NOW()"; }
    $sql="UPDATE `$U_TBL` SET ".implode(',',$parts)." WHERE `$U_PK`=?";
    $types.='i'; $vals[]=$id; $st=$conn->prepare($sql); $st->bind_param($types, ...$vals);
    $ok=$st->execute(); $err=$conn->error; $st->close();
    flash_set($ok?'success':'error', $ok?ucfirst($action).'d.':(ucfirst($action).' failed. '.$err));
    header('Location: users.php'); exit;
  }

  if ($action==='delete') {
    $id = (int)($_POST['id'] ?? 0); if ($id<=0){ flash_set('error','Invalid user.'); header('Location: users.php'); exit; }
    $actingId = (int)($_SESSION['user_id'] ?? 0);
    if ($id === $actingId){ flash_set('error','You cannot delete your own account.'); header('Location: users.php'); exit; }
    if ($has_role_col) {
      $q=$conn->prepare("SELECT `role` FROM `$U_TBL` WHERE `$U_PK`=?"); $q->bind_param('i',$id); $q->execute();
      $role=strtolower($q->get_result()->fetch_assoc()['role'] ?? ''); $q->close();
      if ($role==='admin') {
        $adminCount=(int)scalar($conn,"SELECT COUNT(*) FROM `$U_TBL` WHERE `role`='admin'");
        if ($adminCount<=1){ flash_set('error','Cannot delete the last remaining admin.'); header('Location: users.php'); exit; }
      }
    }
    if ($HAS_CO) {
      $st=$conn->prepare("SELECT COUNT(*) c FROM `customer_orders` WHERE `customer_id`=?");
      $st->bind_param('i',$id); $st->execute();
      $c=(int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
      if ($c>0){ flash_set('error','Cannot delete: user has linked orders. Disable instead.'); header('Location: users.php'); exit; }
    }
    $st = $conn->prepare("DELETE FROM `$U_TBL` WHERE `$U_PK`=? LIMIT 1"); $st->bind_param('i',$id);
    $ok = $st->execute(); $aff=$st->affected_rows; $st->close();
    flash_set(($ok && $aff>0)?'success':'error', ($ok && $aff>0)?'User deleted.' : 'Delete failed.');
    header('Location: users.php'); exit;
  }

  flash_set('error','Unknown action.'); header('Location: users.php'); exit;
}

// ---------- Filters & pagination ----------
$search = trim($_GET['q'] ?? '');
$f_role = $has_role_col ? trim($_GET['role'] ?? '') : '';
$page   = max(1,(int)($_GET['page'] ?? 1));
$per    = 10;
$where=[]; $bind=''; $args=[];

if ($search!==''){
  if ($has_email) {
    $where[]="(`$uname_col` LIKE CONCAT('%',?,'%') OR `$email_col` LIKE CONCAT('%',?,'%') OR ($fullname_expr) LIKE CONCAT('%',?,'%'))";
    $bind.='sss'; $args[]=$search; $args[]=$search; $args[]=$search;
  } else {
    $where[]="(`$uname_col` LIKE CONCAT('%',?,'%') OR ($fullname_expr) LIKE CONCAT('%',?,'%'))";
    $bind.='ss'; $args[]=$search; $args[]=$search;
  }
}
if ($has_role_col && $f_role!==''){ $where[]='`role`=?'; $bind.='s'; $args[]=$f_role; }
$W = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// Count
$sqlCount = "SELECT COUNT(*) c FROM `$U_TBL` $W";
$st = $conn->prepare($sqlCount);
if ($bind!=='') $st->bind_param($bind, ...$args);
$st->execute(); $total = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();

$pages = max(1,(int)ceil($total/$per));
if ($page>$pages) $page=$pages;
$off = ($page-1)*$per;

// Build SELECT (NO Name column shown)
$email_select = $has_email ? "`$email_col` AS email" : "'' AS email";
$extra = [];
if ($status_col)        $extra[] = "`$status_col` AS u_status";
if ($gate_active_col)   $extra[] = "`$gate_active_col` AS u_active";
if ($gate_approve_col)  $extra[] = "`$gate_approve_col` AS u_approved";
$orders_select = $HAS_CO ? ", (SELECT COUNT(*) FROM `customer_orders` co WHERE co.`customer_id` = `$U_TBL`.`$U_PK`) AS orders_ct" : '';

$sql = "SELECT `$U_PK` AS id, `$uname_col` AS username, $email_select"
     . ($has_role_col ? ", `role`" : ", 'customer' AS role")
     . ($created_col ? ", `$created_col`" : ", NULL AS created_at")
     . (count($extra)? ', '.implode(', ',$extra) : '')
     . $orders_select
     . " FROM `$U_TBL` $W ORDER BY $orderExpr LIMIT ".(int)$per." OFFSET ".(int)$off;

$st = $conn->prepare($sql);
if ($bind!=='') $st->bind_param($bind, ...$args);
$st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

// ---------- Flash ----------
$flash = flash_get();
if ($flash) {
  $msg = trim((string)($flash['msg'] ?? $flash['m'] ?? ''));
  if ($msg === 'Admin access required.' || $msg === 'Please log in to continue.') {
    $flash = null; // hide auth leftovers
  }
}

// ---------- Layout head ----------
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .pm-compact { font-size: 14px; }
  .pm-compact .small { font-size: .78rem; }
  .pm-compact .form-control,.pm-compact .form-select{ height:1.9rem;padding:.2rem .45rem;font-size:.85rem;line-height:1.2; }
  .pm-compact .btn{ padding:.22rem .55rem;font-size:.82rem;line-height:1.2; }
  .pm-compact .card-body{ padding:.6rem; } .pm-compact .card-header,.pm-compact .card-footer{ padding:.45rem .6rem; }
  .pm-compact .table{ margin-bottom:0; }
  .pm-compact .table > :not(caption) > * > *{ padding:.35rem .45rem; }
  .pm-compact .badge{ padding:.16rem .42rem;font-size:.72rem;font-weight:600; }
  :root{ --pm-z-backdrop: 2040; --pm-z-modal: 2050; }
  .modal-backdrop{ z-index: var(--pm-z-backdrop) !important; }
  .modal{ z-index: var(--pm-z-modal) !important; }
  body.modal-open{ overflow: hidden; }
  @media (max-width: 576px){
    .pm-compact { font-size: 13px; }
    .pm-compact .card-body{ padding:.5rem; }
    .pm-compact .table td, .pm-compact .table th{ vertical-align: middle; }
  }
</style>

<div class="pm-compact">
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h6 class="fw-bold mb-0">Users</h6>
    <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#createModal"
      <?= (!$has_username || !$pwd_col) ? 'disabled title="Create requires username/user_name & a password column"' : '' ?>>
      <i class="fa fa-plus me-1"></i> New User
    </button>
  </div>

  <?php if ($flash): ?>
    <?php $ft = $flash['type'] ?? ($flash['t'] ?? 'info'); $fm = $flash['msg'] ?? ($flash['m'] ?? ''); ?>
    <div class="alert alert-<?= $ft==='success'?'success':($ft==='warning'?'warning':($ft==='error' || $ft==='danger'?'danger':'info')) ?> py-2">
      <?= $fm !== '' ? $fm : 'Done.' ?>
    </div>
  <?php endif; ?>

  <!-- Filters -->
  <form class="row g-2 align-items-end mb-2" method="get">
    <div class="col-md-5">
      <label class="form-label small">Search</label>
      <input type="text" name="q" value="<?=h($search)?>" class="form-control" placeholder="username / <?= $has_email?'email':'name or username' ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small">Role</label>
      <select name="role" class="form-select" <?= $has_role_col?'':'disabled title="No role column in users table"' ?>>
        <option value="">All</option>
        <?php foreach($ALL_ROLES as $r): ?>
          <option value="<?=h($r)?>" <?=$has_role_col && $f_role===$r?'selected':''?>><?=ucfirst($r)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small">&nbsp;</label>
      <button class="btn btn-outline-dark w-100">Filter</button>
    </div>
  </form>

  <!-- Table -->
  <div class="card">
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Username</th>
            <th><?= $has_email ? 'Email' : 'Email (n/a)' ?></th>
            <th>Role</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $u):
          // Determine display status
          $dispStatus = '—';
          if (isset($u['u_status']) && $u['u_status']!=='') {
            $dispStatus = (string)$u['u_status'];
          } elseif (isset($u['u_active']) || isset($u['u_approved'])) {
            $isActive = isset($u['u_active']) ? ((int)$u['u_active']===1) : null;
            $isAppr   = isset($u['u_approved']) ? ((int)$u['u_approved']===1) : null;
            $dispStatus = ($isActive===1 || $isAppr===1) ? 'active' : 'pending';
          }
          $lc = strtolower($dispStatus);
          $color = ($lc==='active') ? 'success'
                 : (in_array($lc,['inactive','disabled','rejected','blocked','banned']) ? 'secondary' : 'warning');
          $hasOrders = $HAS_CO ? ((int)($u['orders_ct'] ?? 0) > 0) : false;
        ?>
          <tr>
            <td><?=h($u['username'])?></td>
            <td><?= $has_email ? h($u['email']) : '—' ?></td>
            <td><span class="badge text-bg-secondary"><?=h(ucfirst((string)$u['role']))?></span></td>
            <td><span class="badge text-bg-<?=$color?>"><?=h(ucfirst($dispStatus))?></span></td>
            <td class="text-end">
              <?php if ($supportToggle): ?>
                <?php if (strtolower($dispStatus)!=='active'): ?>
                <form method="post" class="d-inline js-confirm" data-message="Approve this user?">
                  <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sm btn-outline-success" title="Approve"><i class="fa fa-check"></i></button>
                </form>
                <?php endif; ?>
                <?php if (in_array(strtolower($dispStatus),['active','pending','inactive'])): ?>
                <form method="post" class="d-inline js-confirm ms-1" data-message="Disable this user?">
                  <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                  <input type="hidden" name="action" value="disable">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sm btn-outline-warning" title="Disable"><i class="fa fa-ban"></i></button>
                </form>
                <?php endif; ?>
              <?php endif; ?>

              <!-- Reset password -->
              <form method="post" class="d-inline js-confirm ms-1" data-message="Reset password to 'password'?"
                    <?= $pwd_col ? '' : 'onsubmit="return false" title="No password column"' ?>>
                <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-outline-dark" <?= $pwd_col?'':'disabled' ?> title="Reset Password"><i class="fa fa-key"></i></button>
              </form>

              <!-- Delete -->
              <button class="btn btn-sm btn-outline-danger ms-1"
                      data-bs-toggle="modal" data-bs-target="#deleteModal"
                      data-id="<?= (int)$u['id'] ?>"
                      data-username="<?= h($u['username']) ?>"
                      data-role="<?= h($u['role']) ?>"
                      <?= $hasOrders ? 'disabled title="Has orders; cannot delete"' : '' ?>>
                <i class="fa fa-trash"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="text-center text-muted">No users found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span class="small text-muted">Showing <?=count($rows)?> of <?=$total?> user(s)</span>
      <nav><ul class="pagination pagination-sm mb-0">
        <?php for($i=1;$i<=$pages;$i++): $qs=http_build_query(array_merge($_GET, ['page'=>$i])); ?>
          <li class="page-item <?=$i===$page?'active':''?>"><a class="page-link" href="?<?=$qs?>"><?=$i?></a></li>
        <?php endfor; ?>
      </ul></nav>
    </div>
  </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
    <form class="modal-content" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-header"><h6 class="modal-title">New User</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label small">Username</label><input name="username" class="form-control" required <?= $has_username?'':'disabled' ?>></div>
        <?php if ($has_email): ?>
        <div class="mb-2"><label class="form-label small">Email</label><input type="email" name="email" class="form-control" required></div>
        <?php endif; ?>
        <div class="mb-2"><label class="form-label small">Full name</label><input name="full_name" class="form-control" required></div>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small">Role</label>
            <select name="role" class="form-select" <?= $has_role_col?'':'disabled' ?>>
              <?php foreach($ALL_ROLES as $r): ?>
                <option value="<?=h($r)?>" <?=$r==='customer'?'selected':''?>><?= ucfirst($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label small">Password</label>
            <input type="text" name="password" class="form-control" value="password" required <?= $pwd_col?'':'disabled' ?>>
          </div>
        </div>
        <?php if (!$pwd_col): ?>
          <small class="text-muted d-block mt-1">Create is disabled: users table has no password column.</small>
        <?php endif; ?>
        <?php if (!$has_email): ?>
          <small class="text-muted d-block mt-1">Your users table has no email column; email will be omitted.</small>
        <?php endif; ?>
      </div>
      <div class="modal-footer"><button class="btn btn-dark" <?= ($has_username && $pwd_col)?'':'disabled' ?>>Create</button></div>
    </form>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="del_id">
      <div class="modal-header">
        <h6 class="modal-title">Confirm Deletion</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">You're about to delete this user:</p>
        <div class="p-2 border rounded bg-light">
          <div><strong>Username:</strong> <span id="del_username">—</span></div>
          <div><strong>Role:</strong> <span id="del_role">—</span></div>
        </div>
        <div class="alert alert-warning mt-3 mb-0">This action cannot be undone.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger">Delete</button>
      </div>
    </form>
  </div>
</div>

<script>
// Simple confirm hooks + fill delete modal + ensure only one modal visible
document.addEventListener('DOMContentLoaded', function(){
  document.addEventListener('show.bs.modal', (ev) => {
    document.querySelectorAll('.modal.show').forEach(m => {
      if (m !== ev.target) {
        const inst = bootstrap.Modal.getInstance(m);
        if (inst) inst.hide();
      }
    });
  });
  for (const f of document.querySelectorAll('form.js-confirm')) {
    if (f.dataset.bound==='1') continue; f.dataset.bound='1';
    f.addEventListener('submit', function(e){
      const msg = f.getAttribute('data-message') || 'Are you sure?';
      if (!confirm(msg)) { e.preventDefault(); return false; }
    });
  }
  const del = document.getElementById('deleteModal');
  del?.addEventListener('show.bs.modal', (ev)=>{
    const b = ev.relatedTarget || document.activeElement; if (!b) return;
    document.getElementById('del_id').value = b.getAttribute('data-id') || '';
    document.getElementById('del_username').textContent = b.getAttribute('data-username') || '—';
    document.getElementById('del_role').textContent = b.getAttribute('data-role') || '—';
  });
});
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
