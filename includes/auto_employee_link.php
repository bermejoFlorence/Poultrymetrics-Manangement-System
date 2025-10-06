<?php
// /includes/auto_employee_link.php
// Auto-create/link an employees row for the currently logged-in WORKER.
// Safe: detects existing columns and only uses what's available.
// No global helper name collisions (all prefixed pm_ae_*).

if (!function_exists('pm_ae_dbname')) {
  function pm_ae_dbname(mysqli $c){
    $r = $c->query("SELECT DATABASE()");
    $d = $r ? ($r->fetch_row()[0] ?? '') : '';
    if ($r) $r->close();
    return $d;
  }
}
if (!function_exists('pm_ae_table_exists')) {
  function pm_ae_table_exists(mysqli $c, string $t){
    $t = $c->real_escape_string($t);
    $r = @$c->query("SHOW TABLES LIKE '{$t}'");
    return !!($r && $r->num_rows);
  }
}
if (!function_exists('pm_ae_col_exists')) {
  function pm_ae_col_exists(mysqli $c, string $t, string $col){
    $db = pm_ae_dbname($c);
    $st = $c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $st->bind_param('sss', $db, $t, $col);
    $st->execute(); $st->store_result();
    $ok = $st->num_rows > 0; $st->close();
    return $ok;
  }
}
if (!function_exists('pm_ae_first_existing')) {
  function pm_ae_first_existing(mysqli $c, string $t, array $cands, $fallback=null){
    foreach($cands as $x){ if(pm_ae_col_exists($c,$t,$x)) return $x; }
    return $fallback;
  }
}
if (!function_exists('pm_ae_get_user_info')) {
  function pm_ae_get_user_info(mysqli $c, int $user_id){
    // Detect columns in users
    $cols = ['id'=>'id'];
    foreach (['username','email','first_name','last_name','full_name','name'] as $k){
      if (pm_ae_col_exists($c,'users',$k)) $cols[$k] = $k;
    }
    $sel = [];
    foreach ($cols as $k=>$dbcol) $sel[] = "$dbcol AS $k";
    $sql = "SELECT ".implode(',', $sel)." FROM users WHERE id=? LIMIT 1";
    $st = $c->prepare($sql);
    $st->bind_param('i', $user_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc() ?: [];
    $st->close();
    return $row;
  }
}

if (!function_exists('pm_ae_ensure_employee_for_current_user')) {
  function pm_ae_ensure_employee_for_current_user(mysqli $conn){
    // Only run for workers
    $role = strtolower($_SESSION['role'] ?? '');
    if ($role !== 'worker') return [false, 'not-worker'];

    // Need user id + username
    $uid = $_SESSION['user_id'] ?? null;
    $uname = $_SESSION['username'] ?? null;

    // Fallback: try to fetch id by username if missing
    if (!$uid && $uname) {
      if (pm_ae_col_exists($conn,'users','username')) {
        if ($st = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1")){
          $st->bind_param('s',$uname); $st->execute(); $st->bind_result($x); $st->fetch(); $st->close();
          if ($x) { $uid = (int)$x; $_SESSION['user_id']=$uid; }
        }
      }
    }
    if (!$uid) return [false, 'no-user-id'];

    // Employees table must exist
    if (!pm_ae_table_exists($conn,'employees')) return [false, 'no-employees-table'];

    // Which link column(s) exist?
    $has_e_user_id = pm_ae_col_exists($conn,'employees','user_id');
    $has_e_username= pm_ae_col_exists($conn,'employees','username');
    $has_e_email   = pm_ae_col_exists($conn,'employees','email');

    // Gather user info for insert
    $user = pm_ae_get_user_info($conn, $uid);
    $email = $user['email'] ?? null;
    $first_name = $user['first_name'] ?? null;
    $last_name  = $user['last_name'] ?? null;
    $full_name  = $user['full_name'] ?? null;
    $name       = $user['name'] ?? null;

    // 1) Check if already linked
    // Preferred order: user_id -> username -> email
    if ($has_e_user_id){
      if ($st = $conn->prepare("SELECT id FROM employees WHERE user_id=? LIMIT 1")){
        $st->bind_param('i',$uid); $st->execute(); $st->bind_result($eid); $st->fetch(); $st->close();
        if ($eid) return [(int)$eid, 'exists-user_id'];
      }
    }
    if ($has_e_username && $uname){
      if ($st = $conn->prepare("SELECT id FROM employees WHERE username=? LIMIT 1")){
        $st->bind_param('s',$uname); $st->execute(); $st->bind_result($eid); $st->fetch(); $st->close();
        if ($eid) return [(int)$eid, 'exists-username'];
      }
    }
    if ($has_e_email && $email){
      if ($st = $conn->prepare("SELECT id FROM employees WHERE email=? LIMIT 1")){
        $st->bind_param('s',$email); $st->execute(); $st->bind_result($eid); $st->fetch(); $st->close();
        if ($eid) return [(int)$eid, 'exists-email'];
      }
    }

    // 2) Build INSERT with whatever columns exist
    $cols = []; $ph = []; $types=''; $vals=[];

    if ($has_e_user_id){ $cols[]='user_id'; $ph[]='?'; $types.='i'; $vals[]=(int)$uid; }
    if ($has_e_username && $uname){ $cols[]='username'; $ph[]='?'; $types.='s'; $vals[]=$uname; }
    if ($has_e_email && $email){ $cols[]='email'; $ph[]='?'; $types.='s'; $vals[]=$email; }

    // Name mapping: prefer full_name/name if present, else first+last if present
    $has_full  = pm_ae_col_exists($conn,'employees','full_name');
    $has_name  = pm_ae_col_exists($conn,'employees','name');
    $has_fn    = pm_ae_col_exists($conn,'employees','first_name');
    $has_ln    = pm_ae_col_exists($conn,'employees','last_name');

    $display = $full_name ?: ($name ?: trim((($first_name ?? '').' '.($last_name ?? ''))));
    if ($has_full && $display){
      $cols[]='full_name'; $ph[]='?'; $types.='s'; $vals[]=$display;
    } elseif ($has_name && $display){
      $cols[]='name'; $ph[]='?'; $types.='s'; $vals[]=$display;
    } else {
      if ($has_fn && $first_name){ $cols[]='first_name'; $ph[]='?'; $types.='s'; $vals[]=$first_name; }
      if ($has_ln && $last_name){  $cols[]='last_name';  $ph[]='?'; $types.='s'; $vals[]=$last_name; }
    }

    // Optional status = 'active' if exists
    if (pm_ae_col_exists($conn,'employees','status')){
      $cols[]='status'; $ph[]='?'; $types.='s'; $vals[]='active';
    }
    // Optional created_at
    $has_created = pm_ae_col_exists($conn,'employees','created_at');
    if ($has_created){ $cols[]='created_at'; $ph[]='NOW()'; }

    if (!$cols){ return [false, 'no-insertable-columns']; }

    $sql = "INSERT INTO employees (".implode(',',$cols).") VALUES (".implode(',',$ph).")";
    $st = $conn->prepare($sql);
    // Bind only if we actually have "?" placeholders
    if (strpos($sql,'?') !== false) $st->bind_param($types, ...$vals);
    $ok = $st->execute();
    $newId = $ok ? $conn->insert_id : null;
    $st->close();

    return [$newId ?: false, $ok ? 'created' : 'insert-failed'];
  }
}
