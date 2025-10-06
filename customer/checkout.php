<?php
// customer/checkout.php — Checkout using customer details from DB (COD only)
$PAGE_TITLE = 'Checkout';
$CURRENT    = 'checkout.php';

require_once __DIR__ . '/inc/common.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
@$conn->query("SET time_zone = '+08:00'");

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/* -------------------- Cart helpers -------------------- */
function cart_get(){ return (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : []; }
function cart_save($c){ $_SESSION['cart'] = $c; }

/* -------------------- DB helpers -------------------- */
if (!function_exists('tableExists')) {
  function tableExists(mysqli $conn, string $table): bool {
    $dbRes = $conn->query("SELECT DATABASE()");
    if (!$dbRes) return false;
    $db = $dbRes->fetch_row()[0] ?? null;
    if (!$db) return false;
    $t = $conn->real_escape_string($table);
    $d = $conn->real_escape_string($db);
    $res = $conn->query("SHOW FULL TABLES FROM `$d` LIKE '$t'");
    return $res && $res->num_rows > 0;
  }
}
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $tbl, string $col): bool {
    $tbl=$c->real_escape_string($tbl); $col=$c->real_escape_string($col);
    $r=@$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'");
    return $r && $r->num_rows>0;
  }
}
function firstExistingCol(mysqli $conn, string $table, array $cands): ?string {
  foreach ($cands as $c) { if (colExists($conn,$table,$c)) return $c; }
  return null;
}

/* -------------------- Authoritative customer details from DB -------------------- */
/**
 * Returns a normalized array with keys:
 * deliver_to, phone, addr_line, barangay, city, province, postal, notes, lat, lon, address_text
 */
function load_customer_details(mysqli $conn, int $uid, array $currentUser): array {
  $out = [
    'deliver_to' => $currentUser['username'] ?? 'Customer',
    'phone'      => $currentUser['phone']    ?? '',
    'addr_line'  => '', 'barangay'=> '', 'city'=> '', 'province'=> '', 'postal'=> '',
    'notes'      => '', 'lat'=>null, 'lon'=>null,
  ];

  if (!tableExists($conn,'customers')) {
    $out['address_text'] = implode(', ', array_filter([$out['addr_line'],$out['barangay'],$out['city'],$out['province'],$out['postal']]));
    return $out;
  }

  $uidCol = firstExistingCol($conn,'customers',['user_id','uid','users_id','account_id','customer_id']);
  if (!$uidCol) {
    $out['address_text'] = implode(', ', array_filter([$out['addr_line'],$out['barangay'],$out['city'],$out['province'],$out['postal']]));
    return $out;
  }

  $m = [
    'fname'=> firstExistingCol($conn,'customers',['first_name','firstname','given_name']),
    'mname'=> firstExistingCol($conn,'customers',['middle_name','middlename','middle']),
    'lname'=> firstExistingCol($conn,'customers',['last_name','lastname','family_name','surname']),
    'name' => firstExistingCol($conn,'customers',['customer_name','full_name','name']),
    'phone'=> firstExistingCol($conn,'customers',['phone','contact','mobile','contact_number','phone_number']),
    'addr' => firstExistingCol($conn,'customers',['address_line','address','street']),
    'brgy' => firstExistingCol($conn,'customers',['barangay','brgy','bgy']),
    'city' => firstExistingCol($conn,'customers',['city','municipality','town']),
    'prov' => firstExistingCol($conn,'customers',['province','state','region']),
    'post' => firstExistingCol($conn,'customers',['postal_code','zip','zipcode']),
    'lat'  => firstExistingCol($conn,'customers',['latitude','lat']),
    'lon'  => firstExistingCol($conn,'customers',['longitude','lon','lng','long']),
    'notes'=> firstExistingCol($conn,'customers',['delivery_instructions','landmark','notes','remarks']),
    'pk'   => colExists($conn,'customers','id') ? 'id' : firstExistingCol($conn,'customers',['customer_id']),
  ];

  $cols=[]; foreach(['fname','mname','lname','name','phone','addr','brgy','city','prov','post','lat','lon','notes'] as $k){
    if($m[$k]) $cols[]="`{$m[$k]}` AS `$k`";
  }
  if (!$cols) {
    $out['address_text'] = implode(', ', array_filter([$out['addr_line'],$out['barangay'],$out['city'],$out['province'],$out['postal']]));
    return $out;
  }

  $sql="SELECT ".implode(',',$cols)." FROM customers WHERE `$uidCol`=? ORDER BY ".($m['pk']?:$uidCol)." DESC LIMIT 1";
  if ($st=$conn->prepare($sql)){
    $st->bind_param('i',$uid); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    if ($r){
      $fn = trim($r['fname'] ?? ''); $mn = trim($r['mname'] ?? ''); $ln = trim($r['lname'] ?? '');
      if ($fn || $ln) { $mid = $mn ? (mb_substr($mn,0,1).'.') : ''; $out['deliver_to'] = trim($fn.' '.trim($mid).' '.$ln); }
      elseif (!empty($r['name'])) { $out['deliver_to'] = $r['name']; }
      $out['phone']     = $r['phone'] ?? $out['phone'];
      $out['addr_line'] = $r['addr']  ?? $out['addr_line'];
      $out['barangay']  = $r['brgy']  ?? $out['barangay'];
      $out['city']      = $r['city']  ?? $out['city'];
      $out['province']  = $r['prov']  ?? $out['province'];
      $out['postal']    = $r['post']  ?? $out['postal'];
      $out['lat']       = $r['lat']   ?? $out['lat'];
      $out['lon']       = $r['lon']   ?? $out['lon'];
      $out['notes']     = $r['notes'] ?? $out['notes'];
    }
  }

  $out['address_text'] = implode(', ', array_filter([
    $out['addr_line'],$out['barangay'],$out['city'],$out['province'],$out['postal']
  ], fn($v)=>(string)$v!==''));
  return $out;
}

/* Resolve the correct customer_id for this user (if orders expects customer_id) */
function resolve_customer_id_for_user(mysqli $conn, int $uid): ?int {
  if (!tableExists($conn,'customers')) return null;

  // Find the user-linking column in customers
  $linkCol = firstExistingCol($conn,'customers',['user_id','uid','users_id','account_id']);
  if ($linkCol) {
    $pk = colExists($conn,'customers','customer_id') ? 'customer_id'
       : (colExists($conn,'customers','id') ? 'id' : null);
    if ($pk) {
      $sql = "SELECT `$pk` FROM `customers` WHERE `$linkCol`=? ORDER BY `$pk` DESC LIMIT 1";
      if ($st = $conn->prepare($sql)) {
        $st->bind_param('i',$uid); $st->execute(); $st->bind_result($cid);
        if ($st->fetch()) { $st->close(); return (int)$cid; }
        $st->close();
      }
    }
  }

  // No linked row found → return null (assumes orders.customer_id is NULLABLE with ON DELETE SET NULL)
  return null;
}

/* -------------------- Order/Items mappers (insert) -------------------- */
function map_orders_table_for_insert(mysqli $conn): ?array {
  foreach (['orders','customer_orders'] as $tbl) {
    if (!tableExists($conn,$tbl)) continue;
    $map = [
      'table'    => $tbl,
      'id'       => (colExists($conn,$tbl,'id') ? 'id' : firstExistingCol($conn,$tbl,['order_id','oid'])),
      'user'     => firstExistingCol($conn,$tbl,['user_id','uid','account_id','customer_id','users_id']),
      'status'   => firstExistingCol($conn,$tbl,['status','state','order_status']),
      'payment'  => firstExistingCol($conn,$tbl,['payment_method','payment','payment_mode','pay_method','method']),
      'to'       => firstExistingCol($conn,$tbl,['deliver_to','recipient','customer_name','ship_to']),
      'addr'     => firstExistingCol($conn,$tbl,['delivery_address','address','shipping_address','ship_address']),
      'phone'    => firstExistingCol($conn,$tbl,['phone','contact','contact_number','mobile']),
      'notes'    => firstExistingCol($conn,$tbl,['notes','remarks','instructions']),
      'lat'      => firstExistingCol($conn,$tbl,['latitude','lat']),
      'lon'      => firstExistingCol($conn,$tbl,['longitude','lon','lng','long']),
      'subtotal' => firstExistingCol($conn,$tbl,['subtotal','sub_total']),
      'shipping' => firstExistingCol($conn,$tbl,['shipping','shipping_fee','delivery_fee']),
      'tax'      => firstExistingCol($conn,$tbl,['tax','vat','sales_tax']),
      'total'    => firstExistingCol($conn,$tbl,['total','grand_total','amount','total_amount']),
      'created'  => firstExistingCol($conn,$tbl,['created_at','created','order_date','date_added','placed_at','date']),
    ];
    if ($map['id'] && $map['user']) return $map;
  }
  return null;
}
function map_items_table_for_insert(mysqli $conn): ?array {
  foreach (['order_items','customer_order_items','orders_bridge','order_items_bridge'] as $tbl) {
    if (!tableExists($conn,$tbl)) continue;
    $map = [
      'table'    => $tbl,
      'order_id' => firstExistingCol($conn,$tbl,['order_id','orders_id','oid']),
      'name'     => firstExistingCol($conn,$tbl,['name','product_name','item_name','title','description']),
      'qty'      => firstExistingCol($conn,$tbl,['qty','quantity','qty_ordered','order_qty','count']),
      'unit'     => firstExistingCol($conn,$tbl,['unit_price','price','unit','rate','unit_amount']),
      'total'    => firstExistingCol($conn,$tbl,['line_total','lineamount','line_amount','total','amount']),
    ];
    if ($map['order_id']) return $map;
  }
  return null;
}

/* -------------------- Guards -------------------- */
$uid  = (int)($_SESSION['user_id'] ?? $CURRENT_USER['user_id'] ?? 0);
$cart = cart_get();
if ($uid <= 0) { header('Location: ../login.php'); exit; }
if (!$cart)    { header('Location: cart.php'); exit; }

/* -------------------- Build totals -------------------- */
$subtotal = 0.0;
foreach ($cart as $it) { $subtotal += ((float)($it['price'] ?? 0)) * ((int)($it['qty'] ?? 1)); }
$shipping = 0.0;
$tax      = 0.0;
$total    = $subtotal + $shipping + $tax;

/* -------------------- Pull customer details from DB -------------------- */
$delivery = load_customer_details($conn, $uid, $CURRENT_USER ?? []);

/* -------------------- Place order (POST) BEFORE any HTML -------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
  if (!empty($_SESSION['csrf']) && (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf']))) {
    http_response_code(403); exit('CSRF validation failed.');
  }

  $om = map_orders_table_for_insert($conn);
  if (!$om) { http_response_code(500); exit('No orders table available for inserting.'); }

  // Determine correct value for the mapped "user" column
  $userCol = $om['user'];
  $userVal = $uid; // default
  if ($userCol === 'customer_id') {
    // orders expects a FK to customers.customer_id -> resolve customer's id for this user
    $cid = resolve_customer_id_for_user($conn, $uid);
    $userVal = $cid; // could be NULL; assumes orders.customer_id is NULLABLE with ON DELETE SET NULL
  }

  // Prepare insert
  $cols = []; $types=''; $vals=[];
  $add = function($col, $val, $type='s') use (&$cols,&$types,&$vals){
    if ($col!==null && $col!=='') { $cols[]="`$col`"; $types.=$type; $vals[]=$val; }
  };

  // Use correct user value
  if ($userVal === null) {
    // bind NULL safely: switch to dynamic query building without this column
    // (we simply won't include the user column if we have NULL and FK allows NULL default)
    // If you REQUIRE the column but want NULL explicitly, uncomment below and adjust binding:
    // $add($userCol, null, 'i');
  } else {
    $add($userCol, $userVal, 'i');
  }

  $add($om['status'],   'pending',                             's');
  $add($om['payment'],  'COD',                                 's'); // Cash On Delivery
  $add($om['to'],       $delivery['deliver_to'] ?? 'Customer', 's');
  $add($om['addr'],     $delivery['address_text'] ?? '',       's');
  $add($om['phone'],    $delivery['phone'] ?? '',              's');

  $notes = trim($_POST['notes'] ?? '');
  if ($notes==='') $notes = (string)($delivery['notes'] ?? '');
  $add($om['notes'],    $notes,                                's');

  if ($om['lat'] && $delivery['lat']!==null) $add($om['lat'], (string)$delivery['lat'], 's');
  if ($om['lon'] && $delivery['lon']!==null) $add($om['lon'], (string)$delivery['lon'], 's');

  $add($om['subtotal'], $subtotal, 'd');
  $add($om['shipping'], $shipping, 'd');
  $add($om['tax'],      $tax,      'd');
  $add($om['total'],    $total,    'd');
  if ($om['created'])   $add($om['created'], date('Y-m-d H:i:s'), 's');

  $sql = "INSERT INTO `{$om['table']}` (".implode(',',$cols).") VALUES (".implode(',', array_fill(0,count($cols),'?')).")";
  $st  = $conn->prepare($sql);
  if (!$st) { http_response_code(500); exit('Prepare failed (orders): '.$conn->error); }
  $st->bind_param($types, ...$vals);
  $ok  = $st->execute();
  if (!$ok) { http_response_code(500); exit('Execute failed (orders): '.$st->error); }
  $orderId = $st->insert_id ?: null;
  $st->close();

  // Insert items
  $im = map_items_table_for_insert($conn);
  if ($im && $orderId) {
    $iCols = []; foreach (['order_id','name','qty','unit','total'] as $k){ if($im[$k]) $iCols[] = "`{$im[$k]}`"; }
    if ($iCols) {
      $ph = implode(',', array_fill(0, count($iCols), '?'));
      $sqlI = "INSERT INTO `{$im['table']}` (".implode(',',$iCols).") VALUES ($ph)";
      $stI  = $conn->prepare($sqlI);
      if ($stI) {
        foreach ($cart as $it) {
          $valsI = []; $typesI='';
          if ($im['order_id']) { $typesI.='i'; $valsI[]=$orderId; }
          if ($im['name'])     { $typesI.='s'; $valsI[]= (string)($it['name'] ?? 'Item'); }
          if ($im['qty'])      { $typesI.='i'; $valsI[]= (int)($it['qty'] ?? 1); }
          if ($im['unit'])     { $typesI.='d'; $valsI[]= (float)($it['price'] ?? 0); }
          if ($im['total'])    { $typesI.='d'; $valsI[]= ((float)($it['price'] ?? 0))*((int)($it['qty'] ?? 1)); }
          $stI->bind_param($typesI, ...$valsI);
          $stI->execute();
        }
        $stI->close();
      }
    }
  }

  // Clear cart, flash, redirect BEFORE any HTML
  cart_save([]);
  $_SESSION['flash_type'] = 'success';
  $_SESSION['flash_msg']  = 'Order placed successfully.';
  header('Location: my_orders.php?ok=1'); exit;
}

/* -------------------- Render page (GET) -------------------- */
include __DIR__ . '/inc/layout_head.php';

$hasCustomerRow = ($delivery['addr_line'] || $delivery['barangay'] || $delivery['city'] || $delivery['province'] || $delivery['phone'] || $delivery['deliver_to']);
?>
<style>
  .pm-wrap{ padding:0; margin:0; }
  .pm-card{ border:0; border-radius:0; }
  .pm-card .card-header,.pm-card .card-footer{ padding:.5rem .75rem; }
  .pm-card .card-body{ padding:.75rem; }
  .kv{ display:flex; gap:.5rem; }
  .kv .k{ width:120px; color:#6c757d; }
  .kv .v{ flex:1; font-weight:600; }
  .line{ border:1px solid #e9ecef; border-radius:10px; padding:.5rem; }
  .thumb{ width:56px; height:56px; object-fit:cover; border-radius:8px; background:#f6f7f9 }
  .meta{ color:#6c757d; font-size:.9rem; }
  .cod-badge{ display:inline-block; background:#f5a425; color:#151a1f; padding:.25rem .5rem; border-radius:999px; font-weight:700; font-size:.8rem; }
</style>

<div class="container-fluid pm-wrap">
  <div class="row g-0">
    <div class="col-12">
      <div class="card pm-card">
        <div class="card-header">
          <div class="fw-semibold">Checkout</div>
        </div>
        <div class="card-body">
          <div class="row g-2">
            <!-- Left: Customer / Delivery -->
            <div class="col-lg-6">
              <div class="line">
                <div class="fw-semibold mb-2">Delivery Details</div>

                <?php if (!$hasCustomerRow): ?>
                  <div class="alert alert-warning py-2">
                    We couldn't find your full customer details. Please complete your profile.
                    <a href="profile.php" class="alert-link">Go to Profile</a>
                  </div>
                <?php endif; ?>

                <div class="kv"><div class="k">Deliver To</div><div class="v"><?php echo h($delivery['deliver_to'] ?: '—'); ?></div></div>
                <div class="kv"><div class="k">Phone</div><div class="v"><?php echo h($delivery['phone'] ?: '—'); ?></div></div>
                <div class="kv"><div class="k">Address</div><div class="v"><?php echo nl2br(h($delivery['address_text'] ?: '—')); ?></div></div>
                <div class="kv"><div class="k">Notes</div><div class="v"><?php echo nl2br(h((string)$delivery['notes'])); ?></div></div>
                <div class="small mt-1">
                  <a href="profile.php"><i class="fa-solid fa-pen-to-square me-1"></i>Update customer details</a>
                </div>

                <form method="post" class="mt-2">
                  <input type="hidden" name="action" value="place_order">
                  <?php if (!empty($_SESSION['csrf'])): ?>
                    <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
                  <?php endif; ?>

                  <div class="mb-2">
                    <label class="form-label small">Add Delivery Notes (optional)</label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="e.g., Landmark, gate, delivery window"></textarea>
                  </div>

                  <div class="mb-2">
                    <div class="fw-semibold">Payment</div>
                    <div class="cod-badge mt-1"><i class="fa-solid fa-truck me-1"></i> Cash On Delivery</div>
                    <input type="hidden" name="payment" value="COD">
                  </div>

                  <button class="btn btn-dark"><i class="fa-solid fa-check me-1"></i> Place Order (COD)</button>
                </form>
              </div>
            </div>

            <!-- Right: Order summary -->
            <div class="col-lg-6">
              <div class="line">
                <div class="fw-semibold mb-2">Order Summary</div>
                <div class="vstack gap-2">
                  <?php foreach($cart as $it):
                    $name  = $it['name']  ?? 'Item';
                    $price = (float)($it['price'] ?? 0);
                    $qty   = (int)($it['qty']   ?? 1);
                    $img   = $it['img'] ?? '../assets/images/placeholder.png';
                    $line  = $price * $qty;
                  ?>
                    <div class="d-flex align-items-center gap-2">
                      <img src="<?php echo h($img); ?>" class="thumb" alt="">
                      <div class="flex-grow-1">
                        <div class="fw-semibold"><?php echo h($name); ?></div>
                        <div class="meta">Qty: <?php echo (int)$qty; ?> &middot; Unit ₱<?php echo number_format($price,2); ?></div>
                      </div>
                      <div class="fw-semibold">₱<?php echo number_format($line,2); ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <hr class="my-2">
                <div class="d-flex justify-content-between"><div class="meta">Subtotal</div><div class="fw-semibold">₱<?php echo number_format($subtotal,2); ?></div></div>
                <div class="d-flex justify-content-between"><div class="meta">Shipping</div><div class="fw-semibold">₱<?php echo number_format(0,2); ?></div></div>
                <div class="d-flex justify-content-between"><div class="meta">Tax</div><div class="fw-semibold">₱<?php echo number_format(0,2); ?></div></div>
                <div class="d-flex justify-content-between"><div class="fw-bold">Total</div><div class="fw-bold">₱<?php echo number_format($total,2); ?></div></div>
              </div>
            </div>
          </div><!--/row-->
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$PAGE_FOOT_SCRIPTS = "";
include __DIR__ . '/inc/layout_foot.php';
