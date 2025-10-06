<?php
// /admin/customer_orders.php — Admin > Customer Orders (works with customer_orders/* or legacy orders/*)
declare(strict_types=1);

$PAGE_TITLE = 'Customer Orders';
$CURRENT    = 'customer_orders.php';

require_once __DIR__ . '/inc/common.php'; // $conn + helpers, session, guards

/* -------------------- Basic helpers (self-contained) -------------------- */
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
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
if (!function_exists('scalar')) {
  function scalar(mysqli $c, string $sql, array $args=[], string $types=''){
    $val=null; if(!$st=$c->prepare($sql)) return $val;
    if($args){ $st->bind_param($types ?: str_repeat('s',count($args)), ...$args); }
    if($st->execute()){ $r=$st->get_result(); if($r){ $row=$r->fetch_row(); $val = $row? $row[0] : null; $r->free(); } }
    $st->close(); return $val;
  }
}

/* -------------------- Admin guard -------------------- */
if (function_exists('require_admin')) { require_admin($conn); }

/* -------------------- CSRF & Flash -------------------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$t); }
function flash_set($type,$msg){ $_SESSION['orders_flash']=['t'=>$type,'m'=>$msg]; }
function flash_pop(){ $f=$_SESSION['orders_flash']??null; if($f) unset($_SESSION['orders_flash']); return $f; }

/* -------------------- Detect table names -------------------- */
$T_ORDERS = null;
$T_ITEMS  = null;

// Prefer the new customer_* tables; fallback to generic
if (tableExists($conn,'customer_orders'))        $T_ORDERS = 'customer_orders';
elseif (tableExists($conn,'orders'))             $T_ORDERS = 'orders';

if (tableExists($conn,'customer_order_items'))   $T_ITEMS  = 'customer_order_items';
elseif (tableExists($conn,'order_items'))        $T_ITEMS  = 'order_items';

if (!$T_ORDERS) {
  include __DIR__ . '/inc/layout_head.php';
  echo '<div class="alert alert-warning">No orders table found (expected <code>customer_orders</code> or <code>orders</code>).</div>';
  include __DIR__ . '/inc/layout_foot.php'; exit;
}

/* -------------------- Column maps for detected schema -------------------- */
// Orders map: try new columns first, then legacy
$om = [
  'id'       => firstExistingCol($conn,$T_ORDERS, ['order_id','id','oid']),
  'user'     => firstExistingCol($conn,$T_ORDERS, ['customer_id','user_id','uid','account_id','users_id']),
  'status'   => firstExistingCol($conn,$T_ORDERS, ['status','state','order_status']),
  'payment'  => firstExistingCol($conn,$T_ORDERS, ['payment_method','payment','payment_mode','pay_method','method','payment_status']),
  'to'       => firstExistingCol($conn,$T_ORDERS, ['deliver_to','recipient','customer_name','ship_to']),
  'addr'     => firstExistingCol($conn,$T_ORDERS, ['delivery_address','address','shipping_address','ship_address']),
  'phone'    => firstExistingCol($conn,$T_ORDERS, ['phone','contact','contact_number','mobile']),
  'notes'    => firstExistingCol($conn,$T_ORDERS, ['notes','remarks','instructions']),
  'lat'      => firstExistingCol($conn,$T_ORDERS, ['latitude','lat']),
  'lon'      => firstExistingCol($conn,$T_ORDERS, ['longitude','lon','lng','long']),
  'subtotal' => firstExistingCol($conn,$T_ORDERS, ['subtotal','sub_total']),
  'shipping' => firstExistingCol($conn,$T_ORDERS, ['shipping','shipping_fee','delivery_fee']),
  'tax'      => firstExistingCol($conn,$T_ORDERS, ['tax','vat','sales_tax']),
  'total'    => firstExistingCol($conn,$T_ORDERS, ['grand_total','total','amount','total_amount']),
  'created'  => firstExistingCol($conn,$T_ORDERS, ['created_at','created','order_date','date_added','placed_at','date']),
  'code'     => firstExistingCol($conn,$T_ORDERS, ['order_code','code','order_no','order_number']),
];

// Items map: new (customer_order_items) vs legacy (order_items)
$im = $T_ITEMS ? [
  'order_id' => firstExistingCol($conn,$T_ITEMS, ['order_id','orders_id','oid']),
  // Name is optional; for new schema we compute from egg_sizes via join
  'name'     => firstExistingCol($conn,$T_ITEMS, ['name','product_name','item_name','title','description']),
  'qty'      => firstExistingCol($conn,$T_ITEMS, ['qty','quantity','qty_ordered','order_qty','count']),
  'unit'     => firstExistingCol($conn,$T_ITEMS, ['price_per_tray','unit_price','price','unit','rate','unit_amount']),
  'total'    => firstExistingCol($conn,$T_ITEMS, ['subtotal','line_total','lineamount','line_amount','total','amount']),
  'size_id'  => firstExistingCol($conn,$T_ITEMS, ['size_id']), // for egg_sizes join
] : null;

// Customers / Users maps (optional enrichment)
$HAS_CUST = tableExists($conn,'customers');
$HAS_USER = tableExists($conn,'users');
$cm = $HAS_CUST ? [
  'uid'     => firstExistingCol($conn,'customers',['user_id','uid','users_id','account_id']),
  'fname'   => firstExistingCol($conn,'customers',['first_name','firstname']),
  'mname'   => firstExistingCol($conn,'customers',['middle_name','middlename']),
  'lname'   => firstExistingCol($conn,'customers',['last_name','lastname','surname']),
  'name'    => firstExistingCol($conn,'customers',['name','customer_name','full_name']),
  'phone'   => firstExistingCol($conn,'customers',['phone','contact','mobile','contact_number','phone_number']),
  'addr'    => firstExistingCol($conn,'customers',['address','address_line','street']),
  'addr_ln' => firstExistingCol($conn,'customers',['address_line','street']),
  'brgy'    => firstExistingCol($conn,'customers',['barangay','brgy','bgy']),
  'city'    => firstExistingCol($conn,'customers',['city','municipality','town']),
  'prov'    => firstExistingCol($conn,'customers',['province','state','region']),
  'postal'  => firstExistingCol($conn,'customers',['postal_code','zip','zipcode']),
  'lat'     => firstExistingCol($conn,'customers',['latitude','lat']),
  'lon'     => firstExistingCol($conn,'customers',['longitude','lon','lng','long']),
  'notes'   => firstExistingCol($conn,'customers',['delivery_instructions','landmark','notes','remarks']),
] : null;

$um = $HAS_USER ? [
  'id'   => firstExistingCol($conn,'users',['id','user_id','uid','users_id','account_id']),
  'name' => firstExistingCol($conn,'users',['username','user_name','login','email']),
] : null;

/* -------------------- Actions (status/delete) -------------------- */
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && !csrf_ok($_POST['csrf'] ?? '')) {
  http_response_code(400); die('CSRF validation failed');
}

if ($action === 'set_status' && $_SERVER['REQUEST_METHOD']==='POST' && $om['status'] && $om['id']) {
  $oid = (int)($_POST['order_id'] ?? 0);
  $new = trim($_POST['new_status'] ?? '');
  if ($oid<=0 || $new===''){ flash_set('error','Invalid request.'); header('Location: customer_orders.php'); exit; }
  $up=$conn->prepare("UPDATE `{$T_ORDERS}` SET `{$om['status']}`=? WHERE `{$om['id']}`=?");
  $up->bind_param('si',$new,$oid); $ok=$up->execute(); $up->close();
  flash_set($ok?'success':'error', $ok? ('Order status updated to '.ucfirst($new).'.') : 'Could not update status.');
  header('Location: customer_orders.php'); exit;
}

if ($action === 'delete_order' && $_SERVER['REQUEST_METHOD']==='POST' && $om['id']) {
  $oid = (int)($_POST['order_id'] ?? 0);
  if ($oid<=0){ flash_set('error','Invalid order.'); header('Location: customer_orders.php'); exit; }

  $stLow = '';
  if ($om['status']) {
    $stmt=$conn->prepare("SELECT `{$om['status']}` FROM `{$T_ORDERS}` WHERE `{$om['id']}`=? LIMIT 1");
    $stmt->bind_param('i',$oid); $stmt->execute();
    $stmt->bind_result($stVal); if($stmt->fetch()) $stLow=strtolower((string)$stVal); $stmt->close();
  }
  if (in_array($stLow, ['fulfilled','approved','completed','delivered','done'], true)) {
    flash_set('error','Cannot delete approved/fulfilled orders.');
    header('Location: customer_orders.php'); exit;
  }

  if ($T_ITEMS && $im && $im['order_id']){
    $d=$conn->prepare("DELETE FROM `{$T_ITEMS}` WHERE `{$im['order_id']}`=?"); $d->bind_param('i',$oid); $d->execute(); $d->close();
  }
  $d=$conn->prepare("DELETE FROM `{$T_ORDERS}` WHERE `{$om['id']}`=?"); $d->bind_param('i',$oid);
  $ok=$d->execute(); $d->close();
  flash_set($ok?'success':'error', $ok?'Order deleted.':'Delete failed.');
  header('Location: customer_orders.php'); exit;
}

/* -------------------- CSV export (single order) -------------------- */
if (($_GET['export'] ?? '') === 'order' && $om['id']) {
  $oid = (int)($_GET['order_id'] ?? 0);
  if ($oid <= 0) { http_response_code(400); echo 'Bad request'; exit; }

  // Header
  $hdrCols = [
    $om['id']      ? "`{$om['id']}` AS oid"        : "NULL AS oid",
    $om['code']    ? "`{$om['code']}` AS ocode"    : "NULL AS ocode",
    $om['created'] ? "`{$om['created']}` AS odate" : "NULL AS odate",
    $om['status']  ? "`{$om['status']}` AS ost"    : "NULL AS ost",
    $om['payment'] ? "`{$om['payment']}` AS opay"  : "NULL AS opay",
    $om['user']    ? "`{$om['user']}` AS ouid"     : "NULL AS ouid",
    $om['to']      ? "`{$om['to']}` AS oto"        : "NULL AS oto",
    $om['phone']   ? "`{$om['phone']}` AS ophone"  : "NULL AS ophone",
    $om['addr']    ? "`{$om['addr']}` AS oaddr"    : "NULL AS oaddr",
    $om['notes']   ? "`{$om['notes']}` AS onotes"  : "NULL AS onotes",
    $om['total']   ? "`{$om['total']}` AS ototal"  : "NULL AS ototal",
  ];
  $sql = "SELECT ".implode(',', $hdrCols)." FROM `{$T_ORDERS}` WHERE `{$om['id']}`=? LIMIT 1";
  $st=$conn->prepare($sql); $st->bind_param('i',$oid); $st->execute();
  $hd = $st->get_result()->fetch_assoc(); $st->close();
  if (!$hd){ http_response_code(404); echo 'Order not found'; exit; }

  // Resolve customer display
  $cname = 'Customer';
  if ($HAS_CUST && $cm && $cm['uid'] && $hd['ouid']!==null){
    $cuCols = [];
    foreach (['fname','mname','lname','name'] as $ck){ if ($cm[$ck]) $cuCols[] = "c.`{$cm[$ck]}` AS `$ck`"; }
    if ($cuCols){
      $s=$conn->prepare("SELECT ".implode(',',$cuCols)." FROM customers c WHERE c.`{$cm['uid']}`=? LIMIT 1");
      $uid=(int)$hd['ouid']; $s->bind_param('i',$uid); $s->execute();
      $cr=$s->get_result()->fetch_assoc(); $s->close();
      if ($cr){
        $fn=trim((string)($cr['fname']??'')); $mn=trim((string)($cr['mname']??'')); $ln=trim((string)($cr['lname']??''));
        if ($fn || $ln){ $cname=trim($fn.' '.($mn?mb_substr($mn,0,1).'. ':'').$ln); }
        elseif (!empty($cr['name'])) $cname=(string)$cr['name'];
      }
    }
  } elseif ($HAS_USER && $um && $um['id'] && $um['name'] && $hd['ouid']!==null) {
    $s=$conn->prepare("SELECT `{$um['name']}` FROM users WHERE `{$um['id']}`=? LIMIT 1");
    $uid=(int)$hd['ouid']; $s->bind_param('i',$uid); $s->execute();
    $cname = ($s->get_result()->fetch_row()[0] ?? 'Customer'); $s->close();
  }

  // Items
  $items = [];
  if ($T_ITEMS && $im && $im['order_id']){
    // if no name column, compute from egg_sizes by size_id
    $nameExpr = $im['name'] ? "`{$im['name']}`" :
                ($im['size_id'] ? "COALESCE(es.label, CONCAT('Size ', i.`{$im['size_id']}`))" : "''");
    $joinEgg  = $im['size_id'] ? " LEFT JOIN egg_sizes es ON es.size_id = i.`{$im['size_id']}` " : "";
    $iCols = "
      $nameExpr AS iname,
      ".($im['qty']  ? "i.`{$im['qty']}`"  : "0")."  AS iqty,
      ".($im['unit'] ? "i.`{$im['unit']}`" : "0")."  AS iunit,
      ".($im['total']? "i.`{$im['total']}`": "NULL")." AS itot
    ";
    $s=$conn->prepare("SELECT $iCols FROM `{$T_ITEMS}` i $joinEgg WHERE i.`{$im['order_id']}`=? ORDER BY 1");
    $s->bind_param('i',$oid); $s->execute();
    $items = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
  }

  // CSV
  $fn = 'order_'.$oid.'.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$fn.'"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Order ID','Order Code','Date','Status','Payment','Customer','Deliver To','Phone','Address','Notes','Item','Qty','Unit Price','Line Total','Order Total']);

  if ($items){
    foreach($items as $it){
      $unit = (float)($it['iunit'] ?? 0); $qty=(int)($it['iqty'] ?? 0);
      $line = isset($it['itot']) && $it['itot']!==''
                ? (float)$it['itot']
                : $unit * $qty;
      fputcsv($out, [
        $hd['oid'], $hd['ocode'], $hd['odate'], $hd['ost'], $hd['opay'], $cname,
        ($hd['oto'] ?: $cname), $hd['ophone'], $hd['oaddr'], $hd['onotes'],
        ($it['iname'] ?? 'Item'), $qty, number_format($unit,2,'.',''), number_format($line,2,'.',''),
        number_format((float)($hd['ototal'] ?? 0),2,'.',''),
      ]);
    }
  } else {
    fputcsv($out, [
      $hd['oid'], $hd['ocode'], $hd['odate'], $hd['ost'], $hd['opay'], $cname,
      ($hd['oto'] ?: $cname), $hd['ophone'], $hd['oaddr'], $hd['onotes'],
      '', 0, '0.00', '0.00', number_format((float)($hd['ototal'] ?? 0),2,'.',''),
    ]);
  }
  fclose($out); exit;
}

/* -------------------- Filters & pagination -------------------- */
$from   = $_GET['from']   ?? '';
$to     = $_GET['to']     ?? '';
$status = $_GET['status'] ?? '';
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 12; $off = ($page-1)*$per;

/* WHERE */
$where=[]; $types=''; $bind=[];
if ($from && $to && $om['created']) { $where[]="`{$om['created']}` BETWEEN ? AND ?"; $types.='ss'; $bind[]=$from.' 00:00:00'; $bind[]=$to.' 23:59:59'; }
elseif ($from && $om['created'])    { $where[]="`{$om['created']}` >= ?"; $types.='s'; $bind[]=$from.' 00:00:00'; }
elseif ($to && $om['created'])      { $where[]="`{$om['created']}` <= ?"; $types.='s'; $bind[]=$to.' 23:59:59'; }

if ($status!=='' && $om['status']) { $where[]="`{$om['status']}`=?"; $types.='s'; $bind[]=$status; }

/* Search across order fields + try customers/users for name match */
if ($q!=='') {
  $parts=[];
  if ($om['id'] && ctype_digit($q)) { $parts[]="`{$om['id']}`=?"; $types.='i'; $bind[]=(int)$q; }
  foreach (['to','addr','phone','notes','payment'] as $k) {
    if ($om[$k]) { $parts[] = "`{$om[$k]}` LIKE CONCAT('%',?,'%')"; $types.='s'; $bind[]=$q; }
  }
  // Resolve user ids by name/email
  $resolvedUids = [];
  if ($HAS_CUST && $cm && $cm['uid']) {
    $cols=[]; foreach (['name','fname','lname'] as $ck){ if($cm[$ck]) $cols[]="`{$cm[$ck]}` LIKE CONCAT('%',?,'%')"; }
    if ($cols){
      $sql="SELECT DISTINCT `{$cm['uid']}` AS uid FROM `customers` WHERE ".implode(' OR ',$cols)." LIMIT 500";
      $st=$conn->prepare($sql);
      $tQ=str_repeat('s', count($cols)); $args=array_fill(0,count($cols),$q);
      $st->bind_param($tQ, ...$args); $st->execute();
      $r=$st->get_result(); while($row=$r->fetch_assoc()){ $resolvedUids[]=(int)$row['uid']; }
      $st->close();
    }
  }
  if ($HAS_USER && $um && $um['id'] && $um['name'] && empty($resolvedUids)) {
    $sqlU="SELECT `{$um['id']}` AS uid FROM users WHERE `{$um['name']}` LIKE CONCAT('%',?,'%') LIMIT 500";
    $st=$conn->prepare($sqlU); $st->bind_param('s',$q); $st->execute();
    $r=$st->get_result(); while($row=$r->fetch_assoc()){ $resolvedUids[]=(int)$row['uid']; }
    $st->close();
  }
  if ($resolvedUids && $om['user']){
    $place = implode(',', array_fill(0,count($resolvedUids),'?'));
    $parts[] = "`{$om['user']}` IN ($place)";
    $types .= str_repeat('i', count($resolvedUids));
    $bind  = array_merge($bind, $resolvedUids);
  }
  if ($parts) $where[] = '('.implode(' OR ',$parts).')';
}
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* -------------------- Count & Select (orders-only) -------------------- */
$cntSql = "SELECT COUNT(*) FROM `{$T_ORDERS}` {$wsql}";
$cnt = $conn->prepare($cntSql);
if ($types!=='') $cnt->bind_param($types, ...$bind);
$cnt->execute(); $total = (int)($cnt->get_result()->fetch_row()[0] ?? 0); $cnt->close();
$pages = max(1, (int)ceil($total/$per)); if ($page>$pages) $page=$pages; $off = ($page-1)*$per;

$selCols = [
  "`{$om['id']}` AS oid",
  ($om['created'] ? "`{$om['created']}`" : "NULL")." AS odate",
  ($om['status']  ? "`{$om['status']}`"  : "NULL")." AS st",
  ($om['total']   ? "`{$om['total']}`"   : "NULL")." AS total",
  ($om['payment'] ? "`{$om['payment']}`" : "NULL")." AS payment",
  ($om['to']      ? "`{$om['to']}`"      : "NULL")." AS deliver_to",
  ($om['phone']   ? "`{$om['phone']}`"   : "NULL")." AS ophone",
  ($om['addr']    ? "`{$om['addr']}`"    : "NULL")." AS oaddr",
  ($om['notes']   ? "`{$om['notes']}`"   : "NULL")." AS onotes",
  ($om['lat']     ? "`{$om['lat']}`"     : "NULL")." AS olat",
  ($om['lon']     ? "`{$om['lon']}`"     : "NULL")." AS olon",
  ($om['user']    ? "`{$om['user']}`"    : "NULL")." AS ouid",
];
$orderBy = ($om['created'] ? "`{$om['created']}` DESC," : "")." `{$om['id']}` DESC";
$sel = "SELECT ".implode(",", $selCols)." FROM `{$T_ORDERS}` {$wsql} ORDER BY {$orderBy} LIMIT ? OFFSET ?";
$types2 = $types.'ii'; $bind2=$bind; $bind2[]=$per; $bind2[]=$off;

$stmt=$conn->prepare($sel);
if ($types2!=='') $stmt->bind_param($types2, ...$bind2);
$stmt->execute(); 
$orders=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* -------------------- Batch enrich with customers/users -------------------- */
$userIds = array_values(array_unique(array_map(fn($r)=> (int)($r['ouid'] ?? 0), $orders)));

$CUSTOMAP = []; // uid => name/phone/addr/lat/lon/notes
if ($HAS_CUST && $cm && $cm['uid'] && $orders && $userIds){
  $in = implode(',', array_fill(0,count($userIds),'?'));
  $cols = [];
  foreach (['fname','mname','lname','name','phone','addr','addr_ln','brgy','city','prov','postal','lat','lon','notes'] as $k){
    if ($cm[$k]) $cols[] = "c.`{$cm[$k]}` AS `$k`";
  }
  $sql="SELECT c.`{$cm['uid']}` AS uid".($cols?(','.implode(',',$cols)):'')." FROM `customers` c WHERE c.`{$cm['uid']}` IN ($in)";
  $st=$conn->prepare($sql);
  $st->bind_param(str_repeat('i',count($userIds)), ...$userIds);
  $st->execute(); $res=$st->get_result();
  while($r=$res->fetch_assoc()){
    $uid=(int)$r['uid'];
    $name = 'Customer';
    $fn = trim((string)($r['fname']??'')); $mn=trim((string)($r['mname']??'')); $ln=trim((string)($r['lname']??''));
    if ($fn || $ln){ $mi = $mn ? (mb_substr($mn,0,1).'. ') : ''; $name = trim($fn.' '.$mi.$ln); }
    elseif (!empty($r['name'])) $name = (string)$r['name'];
    $addrPieces=[];
    if (!empty($r['addr_ln'])) $addrPieces[]=(string)$r['addr_ln'];
    if (!empty($r['brgy']))    $addrPieces[]=(string)$r['brgy'];
    if (!empty($r['city']))    $addrPieces[]=(string)$r['city'];
    if (!empty($r['prov']))    $addrPieces[]=(string)$r['prov'];
    if (!empty($r['postal']))  $addrPieces[]=(string)$r['postal'];
    if (!$addrPieces && !empty($r['addr'])) $addrPieces[]=(string)$r['addr'];
    $CUSTOMAP[$uid] = [
      'name'  => $name,
      'phone' => (string)($r['phone'] ?? ''),
      'addr'  => implode(', ', array_filter($addrPieces, fn($v)=>(string)$v!=='')),
      'lat'   => $r['lat'] ?? null,
      'lon'   => $r['lon'] ?? null,
      'notes' => $r['notes'] ?? '',
    ];
  }
  $st->close();
}
$USERMAP  = []; // uid => username/login/email display
if ($HAS_USER && $um && $um['id'] && $um['name'] && $orders && $userIds){
  $in = implode(',', array_fill(0,count($userIds),'?'));
  $sqlU = "SELECT `{$um['id']}` AS uid, `{$um['name']}` AS uname FROM users WHERE `{$um['id']}` IN ($in)";
  $st=$conn->prepare($sqlU);
  $st->bind_param(str_repeat('i',count($userIds)), ...$userIds);
  $st->execute(); $res=$st->get_result();
  while($r=$res->fetch_assoc()){ $USERMAP[(int)$r['uid']] = $r['uname']; }
  $st->close();
}

/* -------------------- Prefetch order items (with egg_sizes name) -------------------- */
$orderItems = [];
if ($T_ITEMS && $im && $orders){
  $ids = array_map(fn($r)=> (int)$r['oid'], $orders);
  if ($ids){
    $in  = implode(',', array_fill(0,count($ids),'?'));
    // Compute name from egg_sizes if item name column absent
    $nameExpr = $im['name'] ? "i.`{$im['name']}`" :
                ($im['size_id'] ? "COALESCE(es.label, CONCAT('Size ', i.`{$im['size_id']}`))" : "''");
    $joinEgg  = $im['size_id'] ? " LEFT JOIN egg_sizes es ON es.size_id = i.`{$im['size_id']}` " : "";
    $isql = "SELECT i.`{$im['order_id']}` AS oid,
                    $nameExpr AS name,
                    ".($im['qty']  ? "i.`{$im['qty']}`"  : "0")."  AS qty,
                    ".($im['unit'] ? "i.`{$im['unit']}`" : "0")."  AS unit,
                    ".($im['total']? "i.`{$im['total']}`": "NULL")." AS total
             FROM `{$T_ITEMS}` i
             $joinEgg
             WHERE i.`{$im['order_id']}` IN ({$in})
             ORDER BY i.`{$im['order_id']}` ASC";
    $st=$conn->prepare($isql);
    $st->bind_param(str_repeat('i',count($ids)), ...$ids);
    $st->execute(); $res=$st->get_result();
    while($r=$res->fetch_assoc()){ $orderItems[(int)$r['oid']][] = $r; }
    $st->close();
  }
}

/* -------------------- UI helpers -------------------- */
function status_flags($s){
  $t = strtolower(trim((string)$s));
  return [
    'pending'   => in_array($t, ['pending','placed','created','new','processing','awaiting']),
    'approved'  => in_array($t, ['approved','accepted','confirmed']),
    'fulfilled' => in_array($t, ['fulfilled','completed','delivered','done']),
    'canceled'  => in_array($t, ['cancelled','canceled','void','rejected','failed']),
  ];
}
function status_label($s){
  $t = strtolower(trim((string)$s));
  if (in_array($t, ['fulfilled','completed','delivered','done'])) return 'Done';
  if (in_array($t, ['approved','accepted','confirmed']))         return 'Approved';
  if (in_array($t, ['cancelled','canceled','void','rejected','failed'])) return 'Cancelled';
  if (in_array($t, ['pending','placed','created','new','processing','awaiting'])) return 'Placed';
  return ucfirst((string)$s);
}
function badge_class($s){
  $f = status_flags($s);
  if ($f['pending'])   return 'warning';
  if ($f['approved'])  return 'info';
  if ($f['fulfilled']) return 'success';
  if ($f['canceled'])  return 'danger';
  return 'secondary';
}
function peso($n){ return '₱'.number_format((float)$n,2); }

/* -------------------- Layout -------------------- */
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  /* Keep modals above fixed header/sidebar */
  .modal-backdrop { z-index: 1200 !important; }
  .modal          { z-index: 1210 !important; }
  @media (min-width: 576px){
    .modal-dialog { margin-top: 10vh; }
  }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">Customer Orders</h5>
</div>

<?php if ($flash = flash_pop()): ?>
  <div id="flash" data-type="<?php echo h($flash['t']); ?>" data-msg="<?php echo h($flash['m']); ?>"></div>
<?php endif; ?>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-auto">
    <label class="form-label small mb-1">From</label>
    <input type="date" name="from" class="form-control form-control-sm" value="<?php echo h($from); ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">To</label>
    <input type="date" name="to" class="form-control form-control-sm" value="<?php echo h($to); ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Status</label>
    <select name="status" class="form-select form-select-sm">
      <option value="">All</option>
      <?php if ($om['status']):
        $rs = $conn->query("SELECT DISTINCT `{$om['status']}` AS s FROM `{$T_ORDERS}` WHERE `{$om['status']}` IS NOT NULL ORDER BY 1");
        while($r=$rs->fetch_assoc()):
          $s=$r['s']; if($s==='') continue; ?>
          <option value="<?php echo h($s); ?>" <?php echo $status===$s?'selected':''; ?>><?php echo h(status_label($s)); ?></option>
      <?php endwhile; endif; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Search</label>
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Order fields / Name / Username…" value="<?php echo h($q); ?>">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-filter me-1"></i>Filter</button>
    <a class="btn btn-sm btn-outline-secondary" href="customer_orders.php"><i class="fa-solid fa-rotate me-1"></i>Reset</a>
  </div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Customer</th>
          <th>Date</th>
          <th>Status</th>
          <th class="text-end">Total</th>
          <th class="text-end" style="width:380px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($orders): foreach($orders as $o):
          $uid = (int)($o['ouid'] ?? 0);
          $cname = $CUSTOMAP[$uid]['name'] ?? ($USERMAP[$uid] ?? 'Customer');

          $flag  = status_flags($o['st'] ?? '');
          $badge = badge_class($o['st'] ?? '');
          $when  = $o['odate'] ? date('Y-m-d H:i:s', strtotime($o['odate'])) : '—';
          $tot   = isset($o['total']) ? (float)$o['total'] : 0.00;

          $deliv_to = trim((string)($o['deliver_to'] ?? '')); if ($deliv_to==='') $deliv_to = $cname;
          $phone = trim((string)($o['ophone'] ?? '')); if ($phone==='') $phone = (string)($CUSTOMAP[$uid]['phone'] ?? '');

          $addr = trim((string)($o['oaddr'] ?? ''));
          if ($addr==='') $addr = (string)($CUSTOMAP[$uid]['addr'] ?? '');

          $lat  = ($o['olat']!=='') ? $o['olat'] : ($CUSTOMAP[$uid]['lat'] ?? null);
          $lon  = ($o['olon']!=='') ? $o['olon'] : ($CUSTOMAP[$uid]['lon'] ?? null);
          $map  = ($lat!==null && $lon!==null && $lat!=='' && $lon!=='')
                  ? ('https://www.openstreetmap.org/?mlat='.rawurlencode($lat).'&mlon='.rawurlencode($lon).'#map=17/'.$lat.'/'.$lon)
                  : '';

          $notes = trim((string)($o['onotes'] ?? ''));
          if ($notes==='') $notes = (string)($CUSTOMAP[$uid]['notes'] ?? '');
        ?>
          <tr>
            <td><?php echo h($cname); ?></td>
            <td><?php echo h($when); ?></td>
            <td><span class="badge rounded-pill text-bg-<?php echo $badge; ?>"><?php echo h(status_label($o['st'])); ?></span></td>
            <td class="text-end"><?php echo peso($tot); ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary js-view"
                      data-oid="<?php echo (int)$o['oid']; ?>"
                      data-cname="<?php echo h($cname); ?>"
                      data-odate="<?php echo h($when); ?>"
                      data-total="<?php echo number_format($tot,2,'.',''); ?>"
                      data-status="<?php echo h(status_label((string)$o['st'])); ?>"
                      data-to="<?php echo h($deliv_to); ?>"
                      data-phone="<?php echo h($phone); ?>"
                      data-addr="<?php echo h($addr); ?>"
                      data-notes="<?php echo h($notes); ?>"
                      data-payment="<?php echo h((string)($o['payment'] ?? '')); ?>"
                      data-map="<?php echo h($map); ?>">
                <i class="fa-solid fa-eye"></i>
              </button>

              <?php if ($flag['pending']): ?>
              <form class="d-inline js-status" method="post" action="customer_orders.php">
                <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['oid']; ?>">
                <input type="hidden" name="new_status" value="approved">
                <button type="submit" class="btn btn-sm btn-outline-dark" title="Approve">
                  <i class="fa-solid fa-check"></i>
                </button>
              </form>
              <?php endif; ?>

              <?php if ($flag['pending'] || $flag['approved']): ?>
              <form class="d-inline js-status" method="post" action="customer_orders.php">
                <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['oid']; ?>">
                <input type="hidden" name="new_status" value="fulfilled">
                <button type="submit" class="btn btn-sm btn-outline-success" title="Mark Done">
                  <i class="fa-regular fa-circle-check"></i>
                </button>
              </form>
              <?php endif; ?>

              <?php if ($flag['pending'] || $flag['canceled']): ?>
              <form class="d-inline js-delete" method="post" action="customer_orders.php">
                <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
                <input type="hidden" name="action" value="delete_order">
                <input type="hidden" name="order_id" value="<?php echo (int)$o['oid']; ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </form>
              <?php endif; ?>

              <a class="btn btn-sm btn-outline-secondary" href="?export=order&order_id=<?php echo (int)$o['oid']; ?>" title="Export CSV">
                <i class="fa-solid fa-file-export"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No orders found for the selected filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="small text-muted">Showing <?php echo count($orders); ?> of <?php echo (int)$total; ?> order(s)</span>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php for($i=1;$i<=$pages;$i++):
          $qs = http_build_query(array_merge($_GET,['page'=>$i])); ?>
          <li class="page-item <?php echo $i===$page?'active':''; ?>">
            <a class="page-link" href="?<?php echo h($qs); ?>"><?php echo $i; ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>
</div>

<footer class="mt-2"><p class="mb-0 small text-muted">&copy; <?php echo date('Y'); ?> PoultryMetrics</p></footer>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Order Details</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 mb-2">
          <div class="col-md-4"><small class="text-muted d-block">Customer</small><div id="vm_cust" class="fw-semibold">—</div></div>
          <div class="col-md-4"><small class="text-muted d-block">Date</small><div id="vm_date" class="fw-semibold">—</div></div>
          <div class="col-md-4"><small class="text-muted d-block">Status</small><div id="vm_status" class="fw-semibold">—</div></div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <div class="border rounded p-2 h-100">
              <div class="small text-muted">Delivery</div>
              <div class="fw-semibold mt-1" id="vm_to">—</div>
              <div class="mt-1" id="vm_phone">—</div>
              <div class="mt-1" id="vm_addr">—</div>
              <div class="mt-1 small text-muted" id="vm_notes"></div>
              <div class="mt-1">
                <a id="vm_map" class="small" target="_blank" rel="noopener" href="#" style="display:none">
                  <i class="fa-solid fa-map-location-dot me-1"></i>Open map
                </a>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded p-2 h-100">
              <div class="small text-muted">Payment & Totals</div>
              <div class="mt-1">Payment: <span id="vm_payment" class="fw-semibold">—</span></div>
              <div class="mt-1">Total: <span id="vm_total" class="fw-bold">₱0.00</span></div>
            </div>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Line Total</th></tr></thead>
            <tbody id="vm_items"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <a id="vm_export" class="btn btn-outline-dark" href="#"><i class="fa-solid fa-file-export me-1"></i> Export CSV</a>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php
// Inline JS (does NOT rely on $PAGE_FOOT_SCRIPTS so buttons always work)
$ORDER_ITEMS_JSON = json_encode($orderItems, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>
<script>
  // ---------- Data ----------
  const ORDER_ITEMS = <?php echo $ORDER_ITEMS_JSON; ?>;

  // ---------- Small helpers ----------
  function askConfirm(message, title){
    if (typeof themedSwal === 'function') {
      return themedSwal({
        title: title || 'Please confirm',
        text: message || 'Proceed?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, proceed',
        cancelButtonText: 'Cancel'
      });
    }
    if (window.Swal && typeof Swal.fire === 'function') {
      return Swal.fire({
        title: title || 'Please confirm',
        text: message || 'Proceed?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, proceed',
        cancelButtonText: 'Cancel'
      });
    }
    return Promise.resolve({ isConfirmed: window.confirm(message || 'Proceed?') });
  }
  function showModalById(id){
    var el = document.getElementById(id);
    if (window.bootstrap && typeof bootstrap.Modal === 'function') {
      new bootstrap.Modal(el).show();
      return;
    }
    // Fallback modal (no Bootstrap)
    if (!document.querySelector('.modal-backdrop')){
      const b = document.createElement('div');
      b.className = 'modal-backdrop fade show';
      document.body.appendChild(b);
    }
    el.style.display = 'block';
    el.classList.add('show');
    document.body.classList.add('modal-open');
    el.querySelectorAll('[data-bs-dismiss="modal"], .btn-close').forEach(btn=>{
      btn.addEventListener('click', function(){
        el.classList.remove('show');
        el.style.display = 'none';
        document.body.classList.remove('modal-open');
        const b = document.querySelector('.modal-backdrop'); if (b) b.remove();
      }, { once: true });
    });
  }

  // Flash after full load so themedSwal/Swal are available
  window.addEventListener('load', function(){
    var el=document.getElementById('flash'); if(!el) return;
    if (typeof themedSwal === 'function') {
      themedSwal({
        title: el.dataset.type==='success' ? 'Success' : (el.dataset.type==='error'?'Error':'Notice'),
        html: el.dataset.msg || '',
        icon: el.dataset.type==='success' ? 'success' : (el.dataset.type==='error'?'error':'info'),
        confirmButtonText:'OK'
      });
    } else if (window.Swal && typeof Swal.fire === 'function') {
      Swal.fire({
        title: el.dataset.type==='success' ? 'Success' : (el.dataset.type==='error'?'Error':'Notice'),
        html: el.dataset.msg || '',
        icon: el.dataset.type==='success' ? 'success' : (el.dataset.type==='error'?'error':'info'),
        confirmButtonText:'OK'
      });
    } else {
      alert((el.dataset.type || 'Notice') + ': ' + (el.dataset.msg || ''));
    }
  });

  // Bind actions
  document.addEventListener('DOMContentLoaded', function(){
    // Approve / Done
    document.querySelectorAll('form.js-status').forEach(function(form){
      if (form.dataset.bound === '1') return; form.dataset.bound='1';
      form.addEventListener('submit', function(e){
        e.preventDefault();
        var newStatus = (form.querySelector('input[name="new_status"]')||{}).value || '';
        var msg = (newStatus==='approved') ? 'Approve this order?' : 'Mark this order as Done?';
        askConfirm(msg, 'Please confirm').then(function(r){ if (r && r.isConfirmed) form.submit(); });
      });
    });

    // Delete
    document.querySelectorAll('form.js-delete').forEach(function(form){
      if (form.dataset.bound === '1') return; form.dataset.bound='1';
      form.addEventListener('submit', function(e){
        e.preventDefault();
        askConfirm('Delete this order? This cannot be undone.', 'Delete order?')
          .then(function(r){ if (r && r.isConfirmed) form.submit(); });
      });
    });

    // View details
    document.querySelectorAll('.js-view').forEach(function(btn){
      if (btn.dataset.bound === '1') return; btn.dataset.bound='1';
      btn.addEventListener('click', function(){
        var oid   = btn.getAttribute('data-oid');
        var cname = btn.getAttribute('data-cname') || 'Customer';
        var odate = btn.getAttribute('data-odate') || '';
        var total = btn.getAttribute('data-total') || '0';
        var st    = btn.getAttribute('data-status') || '';

        var to    = btn.getAttribute('data-to') || '';
        var phone = btn.getAttribute('data-phone') || '';
        var addr  = btn.getAttribute('data-addr') || '';
        var notes = btn.getAttribute('data-notes') || '';
        var pay   = btn.getAttribute('data-payment') || '';
        var map   = btn.getAttribute('data-map') || '';

        document.getElementById('vm_cust').textContent   = cname;
        document.getElementById('vm_date').textContent   = odate;
        document.getElementById('vm_status').textContent = st;
        document.getElementById('vm_payment').textContent= pay || '—';
        document.getElementById('vm_total').textContent  = '₱'+(parseFloat(total)||0).toFixed(2);

        document.getElementById('vm_to').textContent     = to || '—';
        document.getElementById('vm_phone').textContent  = phone || '—';
        document.getElementById('vm_addr').textContent   = addr || '—';
        document.getElementById('vm_notes').textContent  = notes || '';

        var mapA = document.getElementById('vm_map');
        if (map){ mapA.style.display='inline-block'; mapA.href = map; } else { mapA.style.display='none'; }

        var tbody = document.getElementById('vm_items');
        tbody.innerHTML = '';
        var items = (ORDER_ITEMS && ORDER_ITEMS[oid]) ? ORDER_ITEMS[oid] : [];
        for (var i=0;i<items.length;i++){
          var it = items[i];
          var tr = document.createElement('tr');

          var td1=document.createElement('td'); td1.textContent = (it.name||'Item');
          var td2=document.createElement('td'); td2.className='text-end'; td2.textContent = (parseInt(it.qty,10)||0);
          var td3=document.createElement('td'); td3.className='text-end'; td3.textContent = '₱'+(parseFloat(it.unit)||0).toFixed(2);
          var line = (it.total!==null && it.total!=='') ? parseFloat(it.total) : (parseFloat(it.unit)||0)*(parseInt(it.qty,10)||0);
          var td4=document.createElement('td'); td4.className='text-end'; td4.textContent = '₱'+(line||0).toFixed(2);

          tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3); tr.appendChild(td4);
          tbody.appendChild(tr);
        }

        document.getElementById('vm_export').href = '?export=order&order_id=' + encodeURIComponent(oid);
        showModalById('viewModal');
      });
    });
  });
</script>

<?php include __DIR__ . '/inc/layout_foot.php';
