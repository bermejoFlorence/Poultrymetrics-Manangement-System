<?php
// customer/place_order.php — inserts into orders & order_items (schema-adaptive) with strict normalization
require_once __DIR__ . '/inc/common.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
// PHP 7 polyfill for str_contains
if (!function_exists('str_contains')) { function str_contains($h,$n){ return $n === '' ? true : (mb_strpos($h,$n) !== false); } }

/* === Toggle simple debug logs (writes to customer/place_order_debug.log) === */
const PM_DEBUG = false;
function log_dbg($msg){
  if (!PM_DEBUG) return;
  $file = __DIR__ . '/place_order_debug.log';
  @file_put_contents($file, '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL, FILE_APPEND);
}

/* ---------- Fallback helpers (only if common.php doesn’t define them) ---------- */
if (!function_exists('tableExists')) {
  function tableExists($conn, $table){
    $sql="SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1";
    if (!$st=@$conn->prepare($sql)) return false;
    $st->bind_param('s',$table); $st->execute();
    $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
  }
}
if (!function_exists('colExists')) {
  function colExists($conn, $table, $col){
    $sql="SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1";
    if (!$st=@$conn->prepare($sql)) return false;
    $st->bind_param('ss',$table,$col); $st->execute();
    $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
  }
}
if (!function_exists('firstExistingCol')) {
  function firstExistingCol($conn, $table, $cands){
    foreach ($cands as $c) { if (colExists($conn,$table,$c)) return $c; }
    return null;
  }
}

/* ---------- tiny helpers ---------- */
function cart_get(){ return (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : []; }
function bad($code=''){
  $redir = $_POST['redirect'] ?? 'my_orders.php';
  $qs = 'err=save'; if ($code!=='') $qs .= '&code='.rawurlencode($code);
  log_dbg('BAD: '.$code);
  header('Location: '.$redir.(str_contains($redir,'?')?'&':'?').$qs); exit;
}
function ok(){
  $redir = $_POST['redirect'] ?? 'my_orders.php';
  header('Location: '.$redir.(str_contains($redir,'?')?'&':'?').'ok=1'); exit;
}

/* ---------- auth & basic guards ---------- */
$uid = (int)($_SESSION['user_id'] ?? ($CURRENT_USER['id'] ?? 0));
if ($uid <= 0) bad('no_user');
if (($_POST['confirm'] ?? '') !== '1') bad('no_confirm');
$cart = cart_get();
if (!$cart) bad('empty_cart');
log_dbg('RAW_CART='.json_encode($cart, JSON_UNESCAPED_UNICODE));

/* ---------- inputs ---------- */
$deliver_to = trim($_POST['deliver_to'] ?? '');
$addr       = trim($_POST['delivery_address'] ?? '');
$phone      = trim($_POST['phone'] ?? '');
$notes      = trim($_POST['notes'] ?? '');
$payment    = trim($_POST['payment_method'] ?? ($_POST['payment'] ?? 'COD'));
if ($deliver_to==='' || $addr==='' || $phone==='') bad('missing_fields');

/* optional coords from POST or profile */
$lat = $_POST['latitude']  ?? null;
$lon = $_POST['longitude'] ?? null;
if (($lat===null || $lon===null) && function_exists('customer_profile')) {
  $cp = customer_profile($conn, $uid);
  if ($cp) {
    $lat = $lat ?? ($cp['latitude']  ?? ($cp['lat'] ?? null));
    $lon = $lon ?? ($cp['longitude'] ?? ($cp['lon'] ?? null));
  }
}
$lat = ($lat!==null && $lat!=='') ? (float)$lat : null;
$lon = ($lon!==null && $lon!=='') ? (float)$lon : null;

/* ---------- detect target tables & columns ---------- */
$ORD_TBL  = tableExists($conn,'orders') ? 'orders' : (tableExists($conn,'customer_orders') ? 'customer_orders' : null);
$ITEM_TBL = tableExists($conn,'order_items') ? 'order_items' : (tableExists($conn,'customer_order_items') ? 'customer_order_items' : null);
if (!$ORD_TBL) bad('no_orders_table');

$ord = [
  'id'      => (colExists($conn,$ORD_TBL,'id') ? 'id' : firstExistingCol($conn,$ORD_TBL,['order_id','oid'])),
  'user'    => firstExistingCol($conn,$ORD_TBL,['user_id','customer_id','uid','account_id','users_id']),
  'status'  => firstExistingCol($conn,$ORD_TBL,['status','state','order_status']) ?: 'status',
  'payment' => firstExistingCol($conn,$ORD_TBL,['payment_method','payment','payment_mode','pay_method','method']),
  'to'      => firstExistingCol($conn,$ORD_TBL,['deliver_to','recipient','customer_name','ship_to']),
  'addr'    => firstExistingCol($conn,$ORD_TBL,['delivery_address','address','shipping_address','ship_address']),
  'phone'   => firstExistingCol($conn,$ORD_TBL,['phone','contact','contact_number','mobile']),
  'notes'   => firstExistingCol($conn,$ORD_TBL,['notes','remarks','instructions']),
  'lat'     => firstExistingCol($conn,$ORD_TBL,['latitude','lat']),
  'lon'     => firstExistingCol($conn,$ORD_TBL,['longitude','lon','lng','long']),
  'subtotal'=> firstExistingCol($conn,$ORD_TBL,['subtotal','sub_total']),
  'shipping'=> firstExistingCol($conn,$ORD_TBL,['shipping','shipping_fee','delivery_fee']),
  'tax'     => firstExistingCol($conn,$ORD_TBL,['tax','vat','sales_tax']),
  'total'   => firstExistingCol($conn,$ORD_TBL,['total','grand_total','amount','total_amount']),
  'created' => firstExistingCol($conn,$ORD_TBL,['created_at','created','order_date','date_added','placed_at']),
];

$item = null;
if ($ITEM_TBL){
  $item = [
    'order'   => firstExistingCol($conn,$ITEM_TBL,['order_id','orders_id','customer_order_id','oid']) ?: 'order_id',
    'prod'    => firstExistingCol($conn,$ITEM_TBL,['product_id','prod_id','pid']),
    'name'    => firstExistingCol($conn,$ITEM_TBL,['name','product_name','item_name','title','description']),
    'qty'     => firstExistingCol($conn,$ITEM_TBL,['qty','quantity','qty_ordered','order_qty','count']) ?: 'qty',
    'unit'    => firstExistingCol($conn,$ITEM_TBL,['unit_price','price','unit','rate','unit_amount','unitamount']) ?: 'unit_price',
    'total'   => firstExistingCol($conn,$ITEM_TBL,['line_total','lineamount','line_amount','total','line_total_amount']), // optional
  ];
}

/* ---------- Live product lookup (minimal) ---------- */
function fetch_live(mysqli $conn, int $pid): ?array {
  if (!tableExists($conn,'products')) return null;

  $idC   = colExists($conn,'products','id') ? 'id' : (colExists($conn,'products','product_id') ? 'product_id' : null);
  $nameC = colExists($conn,'products','name') ? 'name' : (colExists($conn,'products','product_name') ? 'product_name' : null);
  $priceC= colExists($conn,'products','price') ? 'price' : (colExists($conn,'products','unit_price') ? 'unit_price' : null);
  $qtyC  = colExists($conn,'products','quantity') ? 'quantity' : (colExists($conn,'products','qty') ? 'qty' : (colExists($conn,'products','stock') ? 'stock' : null));
  if (!$idC || !$nameC) return null;

  $sel = "SELECT `$idC` AS pid, `$nameC` AS pname, ".
         ($priceC ? "`$priceC`" : "NULL")." AS pprice, ".
         ($qtyC   ? "`$qtyC`"   : "NULL")." AS pqty
         FROM products WHERE `$idC`=? LIMIT 1";
  $st=$conn->prepare($sel); $st->bind_param('i',$pid); $st->execute();
  $r=$st->get_result()->fetch_assoc(); $st->close();
  return $r ?: null;
}

/* ---------- Normalize cart (strict) ---------- */
function normalize_cart_for_order(mysqli $conn, array $cart): array {
  $acc = [];

  foreach ($cart as $line) {
    $pid   = (int)($line['pid'] ?? 0);
    $qty   = (int)($line['qty'] ?? 0);
    $name  = trim((string)($line['name'] ?? ''));
    $price = (float)($line['price'] ?? 0);

    if ($qty <= 0) continue;

    // Skip unnamed/placeholder lines without a real product id
    $placeholderNames = ['','item','(item)','n/a','na'];
    if ($pid <= 0 && in_array(mb_strtolower($name), $placeholderNames, true)) {
      continue;
    }

    // Build a stable key: prefer pid; otherwise name+price
    $key = ($pid > 0) ? "pid:$pid" : ("name:".mb_strtolower($name).":price:".$price);

    if (!isset($acc[$key])) {
      $acc[$key] = ['pid'=>$pid>0?$pid:null, 'name'=>$name!==''?$name:'Custom Item', 'qty'=>0, 'price'=>$price];
    }
    $acc[$key]['qty'] += $qty;
    // latest price wins
    $acc[$key]['price'] = $price;
  }

  // Apply live stock and finalize rows
  $norm = [];
  foreach ($acc as $row) {
    $pid   = (int)($row['pid'] ?? 0);
    $req   = (int)$row['qty'];
    $name  = (string)$row['name'];
    $price = (float)$row['price'];

    $live  = $pid > 0 ? fetch_live($conn, $pid) : null;
    if ($live) {
      $name  = $live['pname']  ?? $name;
      $price = isset($live['pprice']) ? (float)$live['pprice'] : $price;
      $avail = array_key_exists('pqty',$live) ? (int)$live['pqty'] : null;

      if ($avail !== null) {
        if ($avail <= 0) continue;        // exclude OOS
        if ($req > $avail) $req = $avail; // clamp to stock
      }
    }

    if ($req <= 0) continue;

    $norm[] = [
      'pid'   => $pid ?: null,
      'name'  => $name,
      'qty'   => $req,
      'price' => $price,
      'total' => $price * $req,
    ];
  }

  return array_values($norm);
}

/* ---------- Build normalized list & totals ---------- */
$norm = normalize_cart_for_order($conn, $cart);
log_dbg('NORM='.json_encode($norm, JSON_UNESCAPED_UNICODE));

if (!$norm) bad('all_excluded_or_zero_qty');

$subtotal = 0.0;
foreach ($norm as $n) { $subtotal += (float)$n['total']; }
$shipping = 0.00;
$tax      = 0.00;
$total    = $subtotal + $shipping + $tax;

/* ---------- build dynamic INSERT for order ---------- */
$cols = []; $types=''; $vals=[];

if ($ord['user'])    { $cols[]="`{$ord['user']}`";    $types.='i'; $vals[]=$uid; }
if ($ord['status'])  { $cols[]="`{$ord['status']}`";  $types.='s'; $vals[]='placed'; }
if ($ord['payment']) { $cols[]="`{$ord['payment']}`"; $types.='s'; $vals[]=$payment; }
if ($ord['to'])      { $cols[]="`{$ord['to']}`";      $types.='s'; $vals[]=$deliver_to; }
if ($ord['addr'])    { $cols[]="`{$ord['addr']}`";    $types.='s'; $vals[]=$addr; }
if ($ord['phone'])   { $cols[]="`{$ord['phone']}`";   $types.='s'; $vals[]=$phone; }
if ($ord['notes'])   { $cols[]="`{$ord['notes']}`";   $types.='s'; $vals[]=$notes; }
if ($ord['lat'] && $lat!==null) { $cols[]="`{$ord['lat']}`"; $types.='d'; $vals[]=$lat; }
if ($ord['lon'] && $lon!==null) { $cols[]="`{$ord['lon']}`"; $types.='d'; $vals[]=$lon; }
if ($ord['subtotal']){ $cols[]="`{$ord['subtotal']}`";$types.='d'; $vals[]=$subtotal; }
if ($ord['shipping']){ $cols[]="`{$ord['shipping']}`";$types.='d'; $vals[]=$shipping; }
if ($ord['tax'])     { $cols[]="`{$ord['tax']}`";     $types.='d'; $vals[]=$tax; }
if ($ord['total'])   { $cols[]="`{$ord['total']}`";   $types.='d'; $vals[]=$total; }
if ($ord['created'] && !in_array($ord['created'], ['created_at','placed_at'], true)) {
  $cols[]="`{$ord['created']}`"; $types.='s'; $vals[]=date('Y-m-d H:i:s');
}

$place = implode(',', array_fill(0,count($cols),'?'));
$sqlOrder = "INSERT INTO `{$ORD_TBL}` (".implode(',',$cols).") VALUES ({$place})";

/* ---------- insert order + items ---------- */
$conn->begin_transaction();
try {
  $st = $conn->prepare($sqlOrder);
  if (!$st) throw new Exception('prepare_order: '.$conn->error);
  if ($types !== '') $st->bind_param($types, ...$vals);
  if (!$st->execute()) throw new Exception('exec_order: '.$st->error);
  $orderId = (int)$st->insert_id;
  $st->close();

  log_dbg("ORDER_ID=$orderId TOTAL=$total SUB=$subtotal COUNT_ITEMS=".count($norm));

  if ($ITEM_TBL && $item && $norm){
    // Only include product_id column if EVERY normalized line has a pid
    $allHavePid = $item['prod'] && array_reduce($norm, fn($a,$l)=> $a && !empty($l['pid']), true);

    // Build columns sequence: order_id, [product_id], [name], qty, unit_price, (line_total?)
    $icols=["`{$item['order']}`"]; $itypes='i';
    if ($allHavePid) { $icols[]="`{$item['prod']}`"; $itypes.='i'; }
    if ($item['name']) { $icols[]="`{$item['name']}`"; $itypes.='s'; }
    $icols[]="`{$item['qty']}`";  $itypes.='i';
    $icols[]="`{$item['unit']}`"; $itypes.='d';
    if ($item['total']) { $icols[]="`{$item['total']}`"; $itypes.='d'; }

    $iplace=implode(',', array_fill(0,count($icols),'?'));
    $isql="INSERT INTO `{$ITEM_TBL}` (".implode(',',$icols).") VALUES ({$iplace})";
    $ist=$conn->prepare($isql);
    if (!$ist) throw new Exception('prepare_items: '.$conn->error);

    foreach ($norm as $line){
      $pid = $allHavePid ? (int)$line['pid'] : null;
      $nm  = (string)$line['name'];
      $qty = (int)$line['qty'];
      $up  = (float)$line['price'];
      $lt  = (float)$line['total'];

      $args = [$orderId];
      if ($allHavePid) $args[] = $pid;
      if ($item['name']) $args[] = $nm;
      $args[] = $qty;
      $args[] = $up;
      if ($item['total']) $args[] = $lt;

      $refs = []; $refs[] = $itypes;
      foreach ($args as $k=>$v) { $refs[] = &$args[$k]; }
      call_user_func_array([$ist,'bind_param'], $refs);

      if (!$ist->execute()) throw new Exception('exec_item: '.$ist->error);
      log_dbg('ITEM='.json_encode(['pid'=>$pid,'name'=>$nm,'qty'=>$qty,'unit'=>$up,'total'=>$lt], JSON_UNESCAPED_UNICODE));
    }
    $ist->close();
  }

  $conn->commit();
  $_SESSION['cart'] = []; // clear after success
  ok();
} catch (Throwable $e) {
  $conn->rollback();
  log_dbg('ERROR='.$e->getMessage());
  bad($e->getMessage());
}
