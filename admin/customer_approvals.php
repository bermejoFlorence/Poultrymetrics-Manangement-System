<?php
// /admin/customer_approvals.php
// PoultryMetrics – Admin > Customer Approvals (map in View modal; top summary removed)

$PAGE_TITLE = 'Customer Approvals';
require_once __DIR__ . '/inc/common.php'; // session, config, admin auth; may or may not define helper names

/* ---------- Helper shims (define if your common.php doesn't) ---------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $table): bool {
    if (function_exists('table_exists')) return table_exists($c,$table);
    $like = $c->real_escape_string($table);
    $res = @$c->query("SHOW TABLES LIKE '{$like}'"); if(!$res) return false;
    $ok = ($res->num_rows>0); $res->free(); return $ok;
  }
}
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $table, string $col): bool {
    if (function_exists('table_has_col')) return table_has_col($c,$table,$col);
    $tbl = str_replace('`','``',$table);
    $like = $c->real_escape_string($col);
    $res = @$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$like}'"); if(!$res) return false;
    $ok = ($res->num_rows>0); $res->free(); return $ok;
  }
}
if (!function_exists('firstExistingCol')) {
  function firstExistingCol(mysqli $c, string $table, array $cands): ?string {
    foreach ($cands as $x) if (colExists($c,$table,$x)) return $x;
    return null;
  }
}
if (!function_exists('scalar')) {
  function scalar(mysqli $c, string $sql){
    $res = @$c->query($sql); if(!$res) return null;
    $row = $res->fetch_row(); $res->free();
    return $row ? $row[0] : null;
  }
}

/* ---------- CSRF (guarded to avoid redeclare) ---------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
if (!function_exists('csrf_ok')) {
  function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($t ?? '')); }
}

/* ---------- Users column discovery ---------- */
$U_TBL = 'users';
if (!tableExists($conn,$U_TBL)) { http_response_code(500); die('Users table not found.'); }

$U_ID       = colExists($conn,$U_TBL,'id')        ? 'id'        : (colExists($conn,$U_TBL,'user_id') ? 'user_id' : 'id');
$U_USER     = colExists($conn,$U_TBL,'username')  ? 'username'  : (colExists($conn,$U_TBL,'user_name') ? 'user_name' : null);
$U_MAIL     = colExists($conn,$U_TBL,'email')     ? 'email'     : (colExists($conn,$U_TBL,'email_address') ? 'email_address' : null);
$U_ROLE     = colExists($conn,$U_TBL,'role')      ? 'role'      : (colExists($conn,$U_TBL,'user_role') ? 'user_role' : null);
$U_CREATED  = colExists($conn,$U_TBL,'created_at')? 'created_at': null;
$U_STATUS   = colExists($conn,$U_TBL,'status')    ? 'status'    : (colExists($conn,$U_TBL,'account_status') ? 'account_status' : null);
$U_ACTIVE   = colExists($conn,$U_TBL,'is_active') ? 'is_active' : (colExists($conn,$U_TBL,'active') ? 'active' : (colExists($conn,$U_TBL,'enabled') ? 'enabled' : null));
$U_APPROVED = colExists($conn,$U_TBL,'is_approved') ? 'is_approved'
            : (colExists($conn,$U_TBL,'approved') ? 'approved'
            : (colExists($conn,$U_TBL,'verified') ? 'verified'
            : (colExists($conn,$U_TBL,'is_verified') ? 'is_verified' : null)));
$U_PHONE    = colExists($conn,$U_TBL,'phone') ? 'phone'
            : (colExists($conn,$U_TBL,'phone_number') ? 'phone_number'
            : (colExists($conn,$U_TBL,'mobile') ? 'mobile' : null));
$U_FIRST    = colExists($conn,$U_TBL,'first_name') ? 'first_name'
            : (colExists($conn,$U_TBL,'given_name') ? 'given_name'
            : (colExists($conn,$U_TBL,'fname') ? 'fname' : null));
$U_MIDDLE   = colExists($conn,$U_TBL,'middle_name') ? 'middle_name'
            : (colExists($conn,$U_TBL,'mname') ? 'mname'
            : (colExists($conn,$U_TBL,'middlename') ? 'middlename' : null));
$U_LAST     = colExists($conn,$U_TBL,'last_name') ? 'last_name'
            : (colExists($conn,$U_TBL,'family_name') ? 'family_name'
            : (colExists($conn,$U_TBL,'lname') ? 'lname' : null));

/* ---------- Related tables ---------- */
$HAS_PROFILES  = tableExists($conn,'customer_profiles');

$HAS_CUSTOMERS = tableExists($conn,'customers');
$C_TBL = $HAS_CUSTOMERS ? 'customers' : null;
$C_ID  = $HAS_CUSTOMERS ? firstExistingCol($conn,'customers',['id','customer_id']) : null;
$C_UID = $HAS_CUSTOMERS ? firstExistingCol($conn,'customers',['user_id','uid','users_id']) : null;

/* Approvals table autodetect (and columns) */
$APR_TBL = null;
foreach (['account_approvals','customer_approvals'] as $cand) {
  if (tableExists($conn,$cand)) { $APR_TBL = $cand; break; }
}
$APR_UID = null;      // user_id/customer_id/uid/users_id
$APR_STATUS = null;   // status/state/approval_status
$APR_NOTE = null;     // note/remarks/comment
$APR_TIME_COL = null; // submitted_at or created_at
$APR_PK = null;

if ($APR_TBL) {
  foreach (['user_id','customer_id','uid','users_id'] as $c)  if (colExists($conn,$APR_TBL,$c)) { $APR_UID = $c; break; }
  foreach (['status','state','approval_status'] as $c)        if (colExists($conn,$APR_TBL,$c)) { $APR_STATUS = $c; break; }
  foreach (['note','remarks','comment'] as $c)                if (colExists($conn,$APR_TBL,$c)) { $APR_NOTE = $c; break; }

  $APR_TIME_COL = colExists($conn,$APR_TBL,'submitted_at') ? 'submitted_at'
               : (colExists($conn,$APR_TBL,'created_at')    ? 'created_at'    : null);

  $APR_PK = firstExistingCol($conn,$APR_TBL,['id','approval_id','log_id','entry_id']);
}

/* ---------- Helpers ---------- */
function normalize_status_row(array $r, ?string $U_STATUS, $U_ACTIVE, $U_APPROVED): string {
  if ($U_STATUS && isset($r['u_status']) && $r['u_status']!==''){
    $v=strtolower((string)$r['u_status']);
    return in_array($v,['active','approved','enabled'],true) ? 'active'
         : (in_array($v,['disabled','blocked','banned','rejected'],true) ? 'disabled' : 'pending');
  }
  if ($U_ACTIVE !== null && array_key_exists('u_active',$r)) {
    return ((int)$r['u_active'] ? 'active' : 'pending');
  }
  if ($U_APPROVED !== null && array_key_exists('u_approved',$r)) {
    return ((int)$r['u_approved'] ? 'active' : 'pending');
  }
  if (!empty($r['appr_status'])) {
    $a=strtolower((string)$r['appr_status']);
    return ($a==='approved'?'active':($a==='rejected'?'disabled':'pending'));
  }
  return 'pending';
}

/* ---------- JSON view endpoint for modal (returns full data) ---------- */
if (isset($_GET['view']) && (int)($_GET['view'])===1) {
  header('Content-Type: application/json; charset=utf-8');
  $uid = (int)($_GET['user_id'] ?? 0);
  if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid user']); exit; }

  // Latest approval time
  if ($APR_TBL && $APR_UID && $APR_TIME_COL) {
    $latestTimeExpr = "(SELECT MAX(a.`$APR_TIME_COL`) FROM `$APR_TBL` a WHERE a.`$APR_UID`=("
                    . (strtolower($APR_UID)==='customer_id'
                      ? ($C_TBL && $C_ID && $C_UID ? "(SELECT `$C_ID` FROM `$C_TBL` c WHERE c.`$C_UID`=u.`$U_ID` LIMIT 1)" : "NULL")
                      : "u.`$U_ID`")
                    . "))";
  } else {
    $latestTimeExpr = $U_CREATED ? "u.`$U_CREATED`" : "NULL";
  }

  // Latest approval status
  $latestStatusOrder = $APR_TIME_COL ? "a2.`$APR_TIME_COL` DESC" : ($APR_PK ? "a2.`$APR_PK` DESC" : "1");
  if ($APR_TBL && $APR_UID && $APR_STATUS) {
    $latestStatusExpr = "(SELECT a2.`$APR_STATUS` FROM `$APR_TBL` a2 WHERE a2.`$APR_UID`=("
                      . (strtolower($APR_UID)==='customer_id'
                        ? ($C_TBL && $C_ID && $C_UID ? "(SELECT `$C_ID` FROM `$C_TBL` c2 WHERE c2.`$C_UID`=u.`$U_ID` LIMIT 1)" : "NULL")
                        : "u.`$U_ID`")
                      . ") ORDER BY $latestStatusOrder LIMIT 1)";
  } else {
    $latestStatusExpr = "NULL";
  }

  $selCols = [
    "u.`$U_ID` AS uid",
    $U_USER ? "u.`$U_USER` AS username" : "'' AS username",
    $U_MAIL ? "u.`$U_MAIL` AS email"     : "'' AS email",
    "$latestTimeExpr AS requested_at",
    "$latestStatusExpr AS appr_status"
  ];
  if ($U_CREATED)      $selCols[] = "u.`$U_CREATED` AS created_at";
  if ($U_STATUS)       $selCols[] = "u.`$U_STATUS` AS u_status";
  if ($U_ACTIVE!==null)$selCols[] = "u.`$U_ACTIVE` AS u_active";
  if ($U_APPROVED!==null)$selCols[] = "u.`$U_APPROVED` AS u_approved";
  if ($U_ROLE)         $selCols[] = "u.`$U_ROLE` AS u_role";
  if ($U_PHONE)        $selCols[] = "u.`$U_PHONE` AS u_phone";
  if ($U_FIRST)        $selCols[] = "u.`$U_FIRST` AS u_first";
  if ($U_MIDDLE)       $selCols[] = "u.`$U_MIDDLE` AS u_middle";
  if ($U_LAST)         $selCols[] = "u.`$U_LAST` AS u_last";

  $sql = "SELECT ".implode(',', $selCols)." FROM `$U_TBL` u WHERE u.`$U_ID`=? LIMIT 1";
  $st=$conn->prepare($sql);
  $st->bind_param('i',$uid);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$row) { echo json_encode(['ok'=>false,'error'=>'User not found']); exit; }

  $status_norm = normalize_status_row($row, $U_STATUS, $U_ACTIVE, $U_APPROVED);
  $requested   = !empty($row['requested_at']) ? $row['requested_at'] : (!empty($row['created_at']) ? $row['created_at'] : '');

  // Profile details + raw lat/lng for the map
  $profile = [];
  $lat = null; $lng = null;
  if ($HAS_PROFILES){
    $p=$conn->prepare("SELECT id, user_id, customer_name,
                              first_name, middle_name, last_name,
                              phone, store_name, store_type,
                              address_line, city, province, postal_code,
                              latitude, longitude, notes, created_at
                       FROM customer_profiles
                       WHERE user_id=? ORDER BY id DESC LIMIT 1");
    if ($p){
      $p->bind_param('i',$uid);
      $p->execute();
      $x=$p->get_result();
      if ($x && $x->num_rows){
        $pr=$x->fetch_assoc();

        $add = function($label,$value) use (&$profile){
          $v = trim((string)($value ?? ''));
          if($v!=='') $profile[]=['label'=>$label,'value'=>$v];
        };

        // Names
        $add('First Name',  $pr['first_name']);
        $add('Middle Name', $pr['middle_name']);
        $add('Last Name',   $pr['last_name']);
        $add('Customer Name',$pr['customer_name']);

        // Contact
        $add('Phone (Profile)', $pr['phone']);
        if (!empty($row['u_phone'])) $add('Phone (Account)', $row['u_phone']);

        // Store + address
        $add('Store Name', $pr['store_name']);
        $add('Store Type', $pr['store_type']);
        $add('Address Line', $pr['address_line']);
        $add('City',         $pr['city']);
        $add('Province',     $pr['province']);
        $add('Postal Code',  $pr['postal_code']);
        $addrParts = array_filter([$pr['address_line'],$pr['city'],$pr['province'],$pr['postal_code']], fn($s)=>trim((string)$s)!=='');
        if ($addrParts) $add('Full Address', implode(', ', $addrParts));

        // Geo
        if ($pr['latitude'] !== null && $pr['longitude'] !== null && $pr['latitude'] !== '' && $pr['longitude'] !== '') {
          $lat = (float)$pr['latitude'];
          $lng = (float)$pr['longitude'];
          $add('Latitude',  $lat);
          $add('Longitude', $lng);
          $profile[]=['label'=>'Map Link','value'=> 'https://www.openstreetmap.org/?mlat='.$lat.'&mlon='.$lng.'#map=17/'.$lat.'/'.$lng ];
        }

        // Notes + timestamp
        $add('Notes', $pr['notes']);
        $add('Profile Created', $pr['created_at']);
      }
      $p->close();
    }
  }

  echo json_encode([
    'ok'=>true,
    'user'=>[
      'id'=>$uid,
      'username'=>$row['username'] ?? '',
      'email'=>$row['email'] ?? '',
      'first_name'=>$row['u_first'] ?? null,
      'middle_name'=>$row['u_middle'] ?? null,
      'last_name'=>$row['u_last'] ?? null,
      'phone'=>$row['u_phone'] ?? null,
      'role'=>$row['u_role'] ?? null,
      'status_norm'=>$status_norm,
      'requested_at'=>$requested
    ],
    'profile'=>$profile,
    'geo'=>[
      'lat'=>$lat, 'lng'=>$lng
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Status helpers used by actions ---------- */
function set_user_status(mysqli $conn, int $uid, string $target,
                         string $U_TBL, ?string $U_STATUS, ?string $U_ACTIVE, ?string $U_APPROVED, string $U_ID): bool {
  $parts=[]; $types=''; $vals=[];
  if ($U_STATUS){   $parts[]="`$U_STATUS`=?";   $types.='s'; $vals[] = ($target==='active'?'active':($target==='disabled'?'disabled':'pending')); }
  if ($U_ACTIVE){   $parts[]="`$U_ACTIVE`=?";   $types.='i'; $vals[] = ($target==='active'?1:0); }
  if ($U_APPROVED){ $parts[]="`$U_APPROVED`=?"; $types.='i'; $vals[] = ($target==='active'?1:0); }
  if (!$parts) return true;
  $sql="UPDATE `$U_TBL` SET ".implode(',', $parts)." WHERE `$U_ID`=?";
  $types.='i'; $vals[]=$uid;
  $st=$conn->prepare($sql); if(!$st) return false;
  $st->bind_param($types, ...$vals);
  $ok=$st->execute(); $st->close();
  return $ok;
}
function map_approval_fk_for_user(mysqli $conn, int $uid,
                                  ?string $APR_UID, ?string $C_TBL, ?string $C_ID, ?string $C_UID): ?int {
  if (!$APR_UID) return null;
  $u = strtolower($APR_UID);
  if (in_array($u, ['user_id','uid','users_id'], true)) return $uid;
  if ($u === 'customer_id') {
    if ($C_TBL && $C_ID && $C_UID) {
      $st = $conn->prepare("SELECT `$C_ID` FROM `$C_TBL` WHERE `$C_UID`=? LIMIT 1");
      if($st){ $st->bind_param('i',$uid); $st->execute(); $res=$st->get_result(); $cid = ($res && $res->num_rows) ? (int)$res->fetch_row()[0] : null; $st->close(); return $cid; }
    }
    return null;
  }
  return null;
}
function log_approval(mysqli $conn, int $uid, string $newStatus, string $note='',
                      ?string $APR_TBL=null, ?string $APR_UID=null, ?string $APR_STATUS=null, ?string $APR_NOTE=null,
                      ?string $C_TBL=null, ?string $C_ID=null, ?string $C_UID=null): bool {
  if (!$APR_TBL || !$APR_UID || !$APR_STATUS) return true; // nothing to log, but OK
  $fkVal = map_approval_fk_for_user($conn,$uid,$APR_UID,$C_TBL,$C_ID,$C_UID);
  if ($fkVal === null) return true; // avoid FK errors
  if ($APR_NOTE) { $st=$conn->prepare("INSERT INTO `$APR_TBL` (`$APR_UID`, `$APR_STATUS`, `$APR_NOTE`) VALUES (?,?,?)"); if(!$st) return false; $st->bind_param('iss',$fkVal,$newStatus,$note); }
  else           { $st=$conn->prepare("INSERT INTO `$APR_TBL` (`$APR_UID`, `$APR_STATUS`) VALUES (?,?)");             if(!$st) return false; $st->bind_param('is',$fkVal,$newStatus); }
  $ok=$st->execute(); $st->close(); return $ok;
}

/* ---------- Actions ---------- */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF validation failed'); }
  $action = $_POST['action'] ?? '';
  $uid    = (int)($_POST['user_id'] ?? 0);
  if ($uid<=0){ $_SESSION['flash']=['type'=>'error','msg'=>'Invalid user.']; header('Location: customer_approvals.php'); exit(); }

  if ($action==='approve'){
    $ok1 = set_user_status($conn,$uid,'active',$U_TBL,$U_STATUS,$U_ACTIVE,$U_APPROVED,$U_ID);
    $ok2 = log_approval($conn,$uid,'approved','Approved by admin: '.($_SESSION['username'] ?? 'admin'),
                        $APR_TBL,$APR_UID,$APR_STATUS,$APR_NOTE,$C_TBL,$C_ID,$C_UID);
    $_SESSION['flash']=['type'=>($ok1||$ok2)?'success':'error','msg'=>($ok1||$ok2)?'User approved.':'Approve failed.'];

  } elseif ($action==='disable'){
    $ok1 = set_user_status($conn,$uid,'disabled',$U_TBL,$U_STATUS,$U_ACTIVE,$U_APPROVED,$U_ID);
    $ok2 = log_approval($conn,$uid,'rejected','Disabled by admin: '.($_SESSION['username'] ?? 'admin'),
                        $APR_TBL,$APR_UID,$APR_STATUS,$APR_NOTE,$C_TBL,$C_ID,$C_UID);
    $_SESSION['flash']=['type'=>($ok1||$ok2)?'success':'error','msg'=>($ok1||$ok2)?'User disabled.':'Disable failed.'];

  } elseif ($action==='delete'){
    if ($HAS_PROFILES){ $d=$conn->prepare("DELETE FROM customer_profiles WHERE user_id=?"); if($d){ $d->bind_param('i',$uid); @$d->execute(); $d->close(); } }
    if ($APR_TBL && $APR_UID){
      $val = map_approval_fk_for_user($conn,$uid,$APR_UID,$C_TBL,$C_ID,$C_UID) ?? $uid;
      $d=$conn->prepare("DELETE FROM `$APR_TBL` WHERE `$APR_UID`=?"); if($d){ $d->bind_param('i',$val); @$d->execute(); $d->close(); }
    }
    $d=$conn->prepare("DELETE FROM `$U_TBL` WHERE `$U_ID`=?");
    $ok=$d && $d->bind_param('i',$uid) && $d->execute(); if($d) $d->close();
    $_SESSION['flash']=['type'=>$ok?'success':'error','msg'=>$ok?'User deleted.':'Delete failed (FK?).'];
  }
  header('Location: customer_approvals.php'); exit();
}

/* ---------- Filters & pagination ---------- */
$status = $_GET['status'] ?? 'pending'; // pending|active|disabled|all
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 12;

/* Base WHERE for customers */
$whereParts=[]; $types=''; $bind=[];
if ($U_ROLE) $whereParts[]="LOWER(u.`$U_ROLE`)='customer'";
if ($q !== ''){
  if ($U_USER && $U_MAIL){
    $whereParts[]="(u.`$U_USER` LIKE CONCAT('%',?,'%') OR u.`$U_MAIL` LIKE CONCAT('%',?,'%'))"; $types.='ss'; $bind[]=$q; $bind[]=$q;
  } elseif ($U_USER){
    $whereParts[]="u.`$U_USER` LIKE CONCAT('%',?,'%')"; $types.='s'; $bind[]=$q;
  } elseif ($U_MAIL){
    $whereParts[]="u.`$U_MAIL` LIKE CONCAT('%',?,'%')"; $types.='s'; $bind[]=$q;
  }
}

/* Latest approval time/status expressions */
$latestTimeExpr = ($APR_TBL && $APR_UID && $APR_TIME_COL)
  ? "(SELECT MAX(a.`$APR_TIME_COL`) FROM `$APR_TBL` a WHERE a.`$APR_UID`=("
      . (strtolower($APR_UID)==='customer_id'
        ? ($C_TBL && $C_ID && $C_UID ? "(SELECT `$C_ID` FROM `$C_TBL` c WHERE c.`$C_UID`=u.`$U_ID` LIMIT 1)" : "NULL")
        : "u.`$U_ID`")
     . "))"
  : ($U_CREATED ? "u.`$U_CREATED`" : "NULL");

$latestStatusOrder = $APR_TIME_COL ? "a2.`$APR_TIME_COL` DESC" : ($APR_PK ? "a2.`$APR_PK` DESC" : "1");
$latestStatusExpr = ($APR_TBL && $APR_UID && $APR_STATUS)
  ? "(SELECT a2.`$APR_STATUS` FROM `$APR_TBL` a2 WHERE a2.`$APR_UID`=("
      . (strtolower($APR_UID)==='customer_id'
        ? ($C_TBL && $C_ID && $C_UID ? "(SELECT `$C_ID` FROM `$C_TBL` c2 WHERE c2.`$C_UID`=u.`$U_ID` LIMIT 1)" : "NULL")
        : "u.`$U_ID`")
     . ") ORDER BY $latestStatusOrder LIMIT 1)"
  : "NULL";

/* Status filter */
if ($status !== 'all') {
  if ($U_STATUS) {
    if ($status==='active')         $whereParts[] = "LOWER(u.`$U_STATUS`) IN ('active','approved','enabled')";
    elseif ($status==='disabled')   $whereParts[] = "LOWER(u.`$U_STATUS`) IN ('disabled','blocked','banned','rejected')";
    else                            $whereParts[] = "(u.`$U_STATUS` IS NULL OR LOWER(u.`$U_STATUS`) NOT IN ('active','approved','enabled','disabled','blocked','banned','rejected'))";
  } elseif ($U_ACTIVE !== null) {
    if ($status==='active')         $whereParts[] = "u.`$U_ACTIVE`=1";
    elseif ($status==='disabled')   $whereParts[] = ($APR_TBL && $APR_STATUS && $APR_UID) ? "LOWER($latestStatusExpr)='rejected'" : "u.`$U_ACTIVE`=0";
    else /* pending */              $whereParts[] = ($APR_TBL && $APR_STATUS && $APR_UID) ? "(LOWER($latestStatusExpr)='pending' OR $latestStatusExpr IS NULL)" : "u.`$U_ACTIVE`=0";
  } elseif ($U_APPROVED !== null) {
    if ($status==='active')         $whereParts[] = "u.`$U_APPROVED`=1";
    elseif ($status==='disabled')   $whereParts[] = ($APR_TBL && $APR_STATUS && $APR_UID) ? "LOWER($latestStatusExpr)='rejected'" : "u.`$U_APPROVED`=0";
    else /* pending */              $whereParts[] = ($APR_TBL && $APR_STATUS && $APR_UID) ? "(LOWER($latestStatusExpr)='pending' OR $latestStatusExpr IS NULL)" : "u.`$U_APPROVED`=0";
  } elseif ($APR_TBL && $APR_STATUS && $APR_UID) {
    if ($status==='active')         $whereParts[] = "LOWER($latestStatusExpr)='approved'";
    elseif ($status==='disabled')   $whereParts[] = "LOWER($latestStatusExpr)='rejected'";
    else                            $whereParts[] = "(LOWER($latestStatusExpr)='pending' OR $latestStatusExpr IS NULL)";
  }
}

$where = $whereParts ? ('WHERE '.implode(' AND ',$whereParts)) : '';

/* Count */
$countSQL = "SELECT COUNT(*) AS c FROM `$U_TBL` u $where";
$st=$conn->prepare($countSQL); if ($types!=='') $st->bind_param($types, ...$bind); $st->execute(); $total=(int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();

$pages = max(1,(int)ceil($total/$per)); if ($page>$pages) $page=$pages; $off=($page-1)*$per;

/* Select */
$selCols = [
  "u.`$U_ID` AS uid",
  $U_USER ? "u.`$U_USER` AS username" : "'' AS username",
  $U_MAIL ? "u.`$U_MAIL` AS email"     : "'' AS email",
  "$latestTimeExpr AS requested_at",
  "$latestStatusExpr AS appr_status"
];
if ($U_CREATED)  $selCols[] = "u.`$U_CREATED` AS created_at";
if ($U_STATUS)   $selCols[] = "u.`$U_STATUS` AS u_status";
if ($U_ACTIVE!==null)   $selCols[] = "u.`$U_ACTIVE` AS u_active";
if ($U_APPROVED!==null) $selCols[] = "u.`$U_APPROVED` AS u_approved";

$selSQL = "SELECT ".implode(',', $selCols)." FROM `$U_TBL` u $where ORDER BY u.`$U_ID` DESC LIMIT ? OFFSET ?";
$st=$conn->prepare($selSQL);
$types2=$types.'ii'; $bind2=$bind; $bind2[]=$per; $bind2[]=$off;
$st->bind_param($types2, ...$bind2);
$st->execute(); $res=$st->get_result();

/* Build rows */
$rows=[];
while($r=$res->fetch_assoc()){
  $r['status_norm'] = normalize_status_row($r, $U_STATUS, $U_ACTIVE, $U_APPROVED);
  $rows[]=$r;
}
$st->close();

/* ---------- Notifications (header bell) ---------- */
$notif_count = 0; $notifs = [];
if (tableExists($conn,'customer_orders')) {
  $pending_orders = (int)(scalar($conn, "SELECT COUNT(*) FROM customer_orders WHERE status='pending'") ?? 0);
  if ($pending_orders > 0) { $notif_count += $pending_orders; $notifs[] = ['label'=>"$pending_orders pending customer order(s)", 'url'=>'customer_orders.php?status=pending', 'icon'=>'fa-cart-shopping']; }
}
if ($APR_TBL && $APR_STATUS){
  $pending_approvals = (int)(scalar($conn, "SELECT COUNT(*) FROM `$APR_TBL` WHERE `$APR_STATUS`='pending'") ?? 0);
  if ($pending_approvals > 0) { $notif_count += $pending_approvals; $notifs[] = ['label'=>"$pending_approvals customer approval request(s)", 'url'=>'customer_approvals.php?status=pending', 'icon'=>'fa-user-check']; }
}
if (tableExists($conn,'feed_batches') && colExists($conn,'feed_batches','expiry_date')) {
  $expiring = (int)(scalar($conn, "SELECT COUNT(*) FROM feed_batches
                                   WHERE expiry_date IS NOT NULL
                                     AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)") ?? 0);
  if ($expiring > 0) { $notif_count += $expiring; $notifs[] = ['label'=>"$expiring feed batch(es) expiring soon", 'url'=>'feed_inventory.php?filter=expiring', 'icon'=>'fa-wheat-awn']; }
}
if (tableExists($conn,'feed_items')) {
  $low_stock = (int)(scalar($conn, "SELECT COUNT(*) FROM (
    SELECT i.id, COALESCE(SUM(b.qty_remaining_kg),0) on_hand, i.reorder_level_kg
    FROM feed_items i
    LEFT JOIN feed_batches b ON b.feed_item_id=i.id
    GROUP BY i.id
  )x WHERE on_hand < reorder_level_kg") ?? 0);
  if ($low_stock > 0) { $notif_count += $low_stock; $notifs[] = ['label'=>"$low_stock feed item(s) in Low Stock", 'url'=>'feed_inventory.php', 'icon'=>'fa-clipboard-check']; }
}

/* ---------- Layout head ---------- */
$CURRENT = 'customer_approvals.php';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">Customer Approvals</h5>
</div>

<?php if (!$APR_TBL || !$APR_STATUS || !$APR_UID): ?>
  <div class="alert alert-warning d-flex align-items-center" role="alert">
    <i class="fa fa-triangle-exclamation me-2"></i>
    <div>No fully-supported approvals table detected. Approve/Disable will still update user flags, but logs may not be recorded.</div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body table-responsive">
    <form method="get" class="row g-2 align-items-end mb-3">
      <div class="col-auto">
        <label class="form-label small mb-1">Status</label>
        <?php $statusOpts=['pending','active','disabled','all']; ?>
        <select name="status" class="form-select form-select-sm">
          <?php foreach($statusOpts as $s): ?>
            <option value="<?php echo h($s); ?>" <?php echo ($status===$s?'selected':''); ?>><?php echo ucfirst($s); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Username / Email…" value="<?php echo h($q); ?>">
      </div>
      <div class="col-auto d-flex align-items-end">
        <button class="btn btn-sm btn-dark">Filter</button>
      </div>
    </form>

    <table class="table align-middle">
      <thead>
        <tr>
          <th>Username / Email</th>
          <th>Requested / Created</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <?php
            $badge = $r['status_norm']==='active' ? 'success' : ($r['status_norm']==='disabled' ? 'danger' : 'warning');
            $when  = !empty($r['requested_at']) ? $r['requested_at'] : (!empty($r['created_at']) ? $r['created_at'] : '');
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?php echo h($r['username']); ?></div>
              <div class="small text-muted"><?php echo h($r['email']); ?></div>
            </td>
            <td><div class="small"><?php echo h($when); ?></div></td>
            <td><span class="badge text-bg-<?php echo $badge; ?>"><?php echo ucfirst($r['status_norm']); ?></span></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary me-1"
                      data-bs-toggle="modal" data-bs-target="#viewModal"
                      data-id="<?php echo (int)$r['uid']; ?>">
                <i class="fa fa-eye"></i>
              </button>

              <?php if ($r['status_norm']!=='active'): ?>
              <form class="d-inline js-confirm" method="post" data-message="Approve this customer account?">
                <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="user_id" value="<?php echo (int)$r['uid']; ?>">
                <button class="btn btn-sm btn-outline-dark"><i class="fa fa-check"></i></button>
              </form>
              <?php endif; ?>

              <?php if ($r['status_norm']!=='disabled'): ?>
              <form class="d-inline js-confirm ms-1" method="post" data-message="Disable this customer account?">
                <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
                <input type="hidden" name="action" value="disable">
                <input type="hidden" name="user_id" value="<?php echo (int)$r['uid']; ?>">
                <button class="btn btn-sm btn-outline-warning"><i class="fa fa-ban"></i></button>
              </form>
              <?php endif; ?>

              <form class="d-inline js-confirm ms-1" method="post" data-message="Delete this user permanently? This cannot be undone.">
                <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?php echo (int)$r['uid']; ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="text-center text-muted">No customers match your filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="small text-muted">Showing <?php echo count($rows); ?> of <?php echo (int)$total; ?> customer(s)</span>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php for($i=1;$i<=max(1,(int)$pages);$i++): $qs=http_build_query(array_merge($_GET,['page'=>$i])); ?>
          <li class="page-item <?php echo $i===$page?'active':''; ?>"><a class="page-link" href="?<?php echo $qs; ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>
</div>

<!-- View Modal (Registration + Map only) -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title">Customer Details</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <!-- Registration / profile details -->
        <small class="text-muted d-block mb-1">Registration Details</small>
        <div id="vm_profile"><div class="text-muted small">No additional details captured.</div></div>

        <hr class="my-3">

        <!-- Map -->
        <small class="text-muted d-block mb-1">Store Location (Registered)</small>
        <div id="vm_map" style="width:100%;height:260px;border-radius:8px;border:1px solid #e9ecef;overflow:hidden;">
          <div class="text-muted small p-2">Map is loading…</div>
        </div>
        <div class="mt-2">
          <a id="vm_maplink" href="#" target="_blank" rel="noopener" class="small d-inline-block text-decoration-none" style="display:none;">Open in OpenStreetMap</a>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<?php
/* ---------- Page-specific JS + tiny CSS ---------- */
$PAGE_FOOT_SCRIPTS = "<script>
  // SweetConfirm
  document.querySelectorAll('form.js-confirm').forEach(form=>{
    form.addEventListener('submit', (e)=>{
      e.preventDefault();
      const msg=form.dataset.message||'Are you sure?';
      (window.themedSwal ? themedSwal({
        title:'Please confirm', text:msg, icon:'question',
        showCancelButton:true, confirmButtonText:'Yes, proceed', cancelButtonText:'Cancel'
      }).then(r=>{ if(r.isConfirmed) form.submit(); }) : (confirm(msg) && form.submit()));
    });
  });

  // Lazy-load Leaflet (CSS+JS) only once
  function ensureLeaflet(){ 
    return new Promise((resolve)=>{
      if (window.L && window.L.map) return resolve();
      // CSS
      if (!document.querySelector('link[data-leaflet]')){
        const l=document.createElement('link'); l.rel='stylesheet'; l.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; l.setAttribute('data-leaflet','1');
        document.head.appendChild(l);
      }
      // JS
      if (!document.querySelector('script[data-leaflet]')){
        const s=document.createElement('script'); s.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; s.defer=true; s.setAttribute('data-leaflet','1');
        s.onload=()=>resolve(); document.head.appendChild(s);
      } else {
        const existing=document.querySelector('script[data-leaflet]');
        if (existing && existing.dataset.loaded==='1') return resolve();
        existing.addEventListener('load', ()=>resolve());
      }
    });
  }

  // Modal: fetch details + render profile + map
  document.getElementById('viewModal')?.addEventListener('show.bs.modal', async (ev)=>{
    const trigger = ev.relatedTarget;
    const btn = trigger?.closest('button[data-bs-target=\"#viewModal\"]') || trigger;
    const uid = btn?.getAttribute('data-id');

    const profWrap = document.getElementById('vm_profile');
    const mapEl = document.getElementById('vm_map');
    const mapLink = document.getElementById('vm_maplink');

    // placeholders
    profWrap.innerHTML = '<div class=\"text-muted small\">Loading…</div>';
    mapEl.innerHTML = '<div class=\"text-muted small p-2\">Map is loading…</div>';
    mapLink.style.display = 'none'; mapLink.href = '#';

    if(!uid){ profWrap.innerHTML = '<div class=\"text-muted small\">No additional details captured.</div>'; mapEl.innerHTML=''; return; }

    try{
      const r = await fetch('customer_approvals.php?view=1&user_id='+encodeURIComponent(uid), {headers:{'Accept':'application/json'}});
      const data = await r.json();
      if(!data.ok){ throw new Error(data.error||'Failed'); }

      // Profile labeled items
      const items = Array.isArray(data.profile) ? data.profile : [];
      if (!items.length){
        profWrap.innerHTML = '<div class=\"text-muted small\">No additional details captured.</div>';
      } else {
        profWrap.innerHTML = items.map(it=>{
          if (it.label==='Map Link' && it.value){
            mapLink.href = it.value; mapLink.style.display = 'inline-block';
            return '';
          }
          if (!it.label) return '';
          return '<div class=\"d-flex justify-content-between border-bottom py-1\">'+
                   '<span class=\"small text-muted\">'+(it.label||'Field')+'</span>'+
                   '<span class=\"small fw-semibold ms-2\">'+(it.value??'')+'</span>'+
                 '</div>';
        }).join('') || '<div class=\"text-muted small\">No additional details captured.</div>';
      }

      // Map rendering (if we have geo)
      const lat = data.geo?.lat; const lng = data.geo?.lng;
      if (typeof lat === 'number' && typeof lng === 'number'){
        await ensureLeaflet();
        // clear
        mapEl.innerHTML = '';
        // init
        const map = L.map(mapEl).setView([lat, lng], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
        const m = L.marker([lat, lng], { draggable:false }).addTo(map);
        m.bindPopup('Store location').openPopup();
        // expose map link if not already set
        if (mapLink.href==='#') {
          mapLink.href = 'https://www.openstreetmap.org/?mlat='+lat+'&mlon='+lng+'#map=17/'+lat+'/'+lng;
          mapLink.style.display = 'inline-block';
        }
        // Fix sizing after modal animation
        setTimeout(()=>{ map.invalidateSize(); }, 300);
      } else {
        mapEl.innerHTML = '<div class=\"text-muted small p-2\">No registered map location.</div>';
      }
    }catch(e){
      profWrap.innerHTML = '<div class=\"text-danger small\">Unable to load details.</div>';
      mapEl.innerHTML = '<div class=\"text-danger small p-2\">Unable to load map.</div>';
    }
  });
</script>";

/* (Optional) small CSS kept in case you later re-add long fields */
$PAGE_FOOT_SCRIPTS .= '<style>
  #viewModal .text-break { word-break: break-word; overflow-wrap: anywhere; }
</style>';

if ($flash) {
  $ttl = ($flash['type']==='success') ? 'Success' : 'Notice';
  $ico = ($flash['type']==='success') ? 'success' : 'info';
  $html = $flash['msg'];
  $PAGE_FOOT_SCRIPTS .= "<script>window.themedSwal ? themedSwal({ title: ".json_encode($ttl).", html: ".json_encode($html).", icon: ".json_encode($ico).", confirmButtonText:'OK' }) : alert(".json_encode(strip_tags($html)).");</script>";
}

/* ---------- Layout foot ---------- */
include __DIR__ . '/inc/layout_foot.php';
