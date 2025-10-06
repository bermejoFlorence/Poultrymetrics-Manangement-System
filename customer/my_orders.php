<?php
// customer/my_orders.php — Shows orders (local DB only), auto-maps to new sales_* tables if present
$PAGE_TITLE = 'My Orders';
$CURRENT    = 'my_orders.php';

require_once __DIR__ . '/inc/common.php';
include __DIR__ . '/layout_head.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---------- Utilities ---------- */
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('peso')) { function peso($n){ return '₱'.number_format((float)$n, 2); } }

if (!function_exists('status_badge_html')){
  function status_badge_html($s){
    if (!$s) return '<span class="badge bg-secondary">N/A</span>';
    $t = strtolower((string)$s);
    if (str_contains($t,'new')||str_contains($t,'place')||str_contains($t,'pending')) return '<span class="badge bg-primary">'.h($s).'</span>';
    if (str_contains($t,'paid')||str_contains($t,'complete')) return '<span class="badge bg-success">'.h($s).'</span>';
    if (str_contains($t,'ship')||str_contains($t,'out')||str_contains($t,'dispatch')||str_contains($t,'processing')) return '<span class="badge bg-info text-dark">'.h($s).'</span>';
    if (str_contains($t,'cancel')||str_contains($t,'void')||str_contains($t,'reject')) return '<span class="badge bg-danger">'.h($s).'</span>';
    return '<span class="badge bg-secondary">'.h($s).'</span>';
  }
}
if (!function_exists('can_cancel')){
  function can_cancel($s){
    $t = strtolower((string)$s);
    if ($t==='') return true;
    if (str_contains($t,'cancel')||str_contains($t,'void')||str_contains($t,'complete')||str_contains($t,'ship')||str_contains($t,'paid')) return false;
    return true;
  }
}

/* ---------- Session/user guard ---------- */
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { header('Location: ../login.php'); exit; }

/* Flash banner after placing order */
if (isset($_GET['ok']) && in_array($_GET['ok'], ['1','true','yes','placed','success'], true)) {
  $_SESSION['flash_type'] = 'success';
  $_SESSION['flash_msg']  = 'Order placed successfully.';
}

/* ---------- Light existence helpers (relies on inc/common.php colExists/tableExists) ---------- */
if (!function_exists('firstExistingCol')) {
  function firstExistingCol(mysqli $conn, string $table, array $cands): ?string {
    foreach ($cands as $c) { if (colExists($conn,$table,$c)) return $c; }
    return null;
  }
}

/* ---------- Resolve customer_id for logged-in user (if needed) ---------- */
function resolve_customer_id_for_user(mysqli $conn, int $uid): ?int {
  if (!tableExists($conn,'customers')) return null;

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
  return null;
}

/* ---------- Delivery fallback without strict customer_id dependency ---------- */
function load_delivery_fallback(mysqli $conn, int $uid, array $currentUser): array {
  $out = [
    'deliver_to' => $currentUser['full_name'] ?? ($currentUser['username'] ?? 'Customer'),
    'phone'      => $currentUser['phone'] ?? '',
    'addr_line'  => '', 'barangay'=> '', 'city'=> '', 'province'=> '', 'postal'=> '',
    'notes'      => '', 'lat'=>null, 'lon'=>null,
  ];

  if (tableExists($conn,'customers')) {
    $uidCol = firstExistingCol($conn,'customers',['user_id','uid','users_id','account_id']);
    if ($uidCol) {
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
      $cols=[]; foreach(['fname','mname','lname','name','phone','addr','brgy','city','prov','post','lat','lon','notes'] as $k){ if($m[$k]) $cols[]="`{$m[$k]}` AS `$k`"; }
      if ($cols){
        $sql="SELECT ".implode(',',$cols)." FROM customers WHERE `$uidCol`=? ORDER BY ".($m['pk']?:$uidCol)." DESC LIMIT 1";
        if ($st=$conn->prepare($sql)){
          $st->bind_param('i',$uid); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
          if ($r){
            $fn = trim($r['fname'] ?? ''); $mn = trim($r['mname'] ?? ''); $ln = trim($r['lname'] ?? '');
            if ($fn || $ln) {
              $mid = $mn ? (mb_substr($mn,0,1).'.') : '';
              $out['deliver_to'] = trim($fn.' '.trim($mid).' '.$ln);
            } elseif (!empty($r['name'])) {
              $out['deliver_to'] = $r['name'];
            }
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
      }
    }
  }

  $out['address_text'] = implode(', ', array_filter([$out['addr_line'],$out['barangay'],$out['city'],$out['province'],$out['postal']], fn($v)=>(string)$v!==''));
  return $out;
}

$deliveryFallback = load_delivery_fallback($conn, $uid, $CURRENT_USER ?? []);

/* ---------- Orders source mapper (supports new sales_orders) ---------- */
function map_orders_table(mysqli $conn): ?array {
  // Preference order: sales_orders (new) -> orders -> customer_orders
  foreach (['sales_orders','orders','customer_orders'] as $tbl) {
    if (!tableExists($conn,$tbl)) continue;

    $userCols = ['user_id','uid','users_id','account_id','customer_user_id','customer_id'];
    if ($tbl === 'sales_orders') array_unshift($userCols, 'created_by', 'buyer_user_id');

    $map = [
      'table'   => $tbl,
      'id'      => (colExists($conn,$tbl,'id') ? 'id' : firstExistingCol($conn,$tbl,['order_id','oid'])),
      'user'    => firstExistingCol($conn,$tbl,$userCols),

      // Text/status fields
      'status'  => firstExistingCol($conn,$tbl,['status','state','order_status']),
      'payment' => firstExistingCol($conn,$tbl,['payment_method','payment','payment_mode','pay_method','method','payment_type']),

      // Ship-to fields
      'to'      => firstExistingCol($conn,$tbl,['deliver_to','recipient','customer_name','ship_to','name']),
      'addr'    => firstExistingCol($conn,$tbl,['delivery_address','address','shipping_address','ship_address','address_line']),
      'phone'   => firstExistingCol($conn,$tbl,['phone','contact','contact_number','mobile']),

      // Notes & geo
      'notes'   => firstExistingCol($conn,$tbl,['notes','remarks','instructions']),
      'lat'     => firstExistingCol($conn,$tbl,['latitude','lat']),
      'lon'     => firstExistingCol($conn,$tbl,['longitude','lon','lng','long']),

      // Money
      'subtotal'=> firstExistingCol($conn,$tbl,['subtotal','sub_total']),
      'shipping'=> firstExistingCol($conn,$tbl,['shipping','shipping_fee','delivery_fee']),
      'tax'     => firstExistingCol($conn,$tbl,['tax','vat','sales_tax']),
      'total'   => firstExistingCol($conn,$tbl,['total','grand_total','amount','total_amount']),

      // Timestamp
      'created' => firstExistingCol($conn,$tbl,['created_at','created','order_date','date_added','placed_at','date','ts','timestamp']),
    ];
    if ($map['id'] && $map['user']) return $map; // must have id + user filter
  }
  return null;
}

$om = map_orders_table($conn);
if (!$om) {
  echo '<div class="container-fluid p-0"><div class="row g-0"><div class="col-12">
          <div class="alert alert-info rounded-0 mb-0">No orders table with required columns found.</div>
        </div></div></div>';
  include __DIR__ . '/layout_foot.php'; exit;
}

/* ---------- Build filter value (handles FK customer_id) ---------- */
$filterVal = $uid;
if ($om['user'] === 'customer_id') {
  $cid = resolve_customer_id_for_user($conn, $uid);
  // If you want to show only orders linked to a customer row, require a non-null cid:
  if ($cid === null) {
    // No linked customer row → show none (or set to 0 assuming unsigned FK)
    $filterVal = 0;
  } else {
    $filterVal = $cid;
  }
}

/* ---------- Fetch orders (latest 50 for this user) ---------- */
$cols = [];
foreach ($om as $k=>$c) {
  if ($k==='table') continue;
  if ($c) $cols[] = "`{$c}` AS `$k`";
}
$sql = "SELECT ".implode(',', $cols)." FROM `{$om['table']}` WHERE `{$om['user']}`=? ORDER BY `{$om['id']}` DESC LIMIT 50";
$st  = $conn->prepare($sql);
$st->bind_param('i',$filterVal);
$st->execute();
$orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ---------- Items mapping (supports new sales_order_items) ---------- */
function map_items_table(mysqli $conn): ?array {
  // Preference: sales_order_items (new) -> order_items -> customer_order_items -> bridges
  $candidates = ['sales_order_items','order_items','customer_order_items','orders_bridge','order_items_bridge'];
  foreach ($candidates as $tbl) {
    if (!tableExists($conn,$tbl)) continue;
    $map = [
      'table'    => $tbl,
      'order_id' => firstExistingCol($conn,$tbl,['order_id','orders_id','oid','sales_order_id']),
      'name'     => firstExistingCol($conn,$tbl,['name','product_name','item_name','size_name','title','description']),
      'qty'      => firstExistingCol($conn,$tbl,['qty','quantity','qty_ordered','order_qty','count']),
      'unit'     => firstExistingCol($conn,$tbl,['unit_price','price','unit','rate','unit_amount','unitamount']),
      'total'    => firstExistingCol($conn,$tbl,['line_total','lineamount','line_amount','total','subtotal','amount','line_total_amount']),
    ];
    if ($map['order_id']) return $map;
  }
  return null;
}
$im = map_items_table($conn);

/* ---------- Styles ---------- */
?>
<style>
  .pm-wrap { padding:0; margin:0; }
  .pm-card { border:0; border-radius:0; }
  .pm-card .card-header, .pm-card .card-footer { padding:.5rem .75rem; }
  .pm-card .card-body { padding:.75rem; }
  .pm-row  { border:1px solid #e9ecef; border-radius:10px; padding:.5rem .75rem; background:#fff; }
  .pm-kv   { display:flex; gap:.5rem; }
  .pm-kv > .k { width:130px; color:#6c757d; }
  .pm-kv > .v { flex:1; font-weight:600; }
  .pm-items{ font-size:.95rem; }
  .pm-item + .pm-item { border-top:1px dashed #edf0f2; margin-top:.35rem; padding-top:.35rem; }
  .pm-meta { font-size:.85rem; color:#6c757d; }
  .pm-small-btn{ padding:.125rem .5rem; font-size:.78rem; }
</style>

<div class="container-fluid pm-wrap">
  <div class="row g-0">
    <div class="col-12">
      <?php include __DIR__ . '/inc/flash_alert.php'; ?>
    </div>

    <div class="col-12">
      <div class="card pm-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div class="fw-semibold">My Orders</div>
          <div></div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card pm-card">
        <div class="card-body">
          <?php if (!$orders): ?>
            <div class="alert alert-light border mb-0">No orders yet.</div>
          <?php else: ?>
            <div class="vstack gap-2">
              <?php foreach ($orders as $row):
                $oid      = $row['id'];
                $status   = $row['status'] ?? '';
                $pay      = $row['payment'] ?? '';
                $subtotal = $row['subtotal'] ?? 0.00;
                $shipping = $row['shipping'] ?? 0.00;
                $tax      = $row['tax'] ?? 0.00;
                $total    = $row['total'] ?? ($row['subtotal'] ?? 0.00);
                $when     = $row['created'] ?? '';
                // accept numeric timestamps or datetime strings
                if (is_numeric($when)) { $whenTxt = date('M j, Y g:i A', (int)$when); }
                else { $whenTxt  = $when ? date('M j, Y g:i A', strtotime($when)) : '—'; }

                $deliverTo = trim((string)($row['to']   ?? ''));
                $addr      = trim((string)($row['addr'] ?? ''));
                $phone     = trim((string)($row['phone']?? ''));
                $notes     = trim((string)($row['notes']?? ''));
                $lat       = $row['lat'] ?? null;
                $lon       = $row['lon'] ?? null;

                if ($deliverTo === '') $deliverTo = $deliveryFallback['deliver_to'];
                if ($phone === '')     $phone     = $deliveryFallback['phone'];
                if ($addr === '')      $addr      = $deliveryFallback['address_text'];
                if ($notes === '')     $notes     = $deliveryFallback['notes'];
                if (($lat===null || $lat==='') && $deliveryFallback['lat']!==null) $lat = $deliveryFallback['lat'];
                if (($lon===null || $lon==='') && $deliveryFallback['lon']!==null) $lon = $deliveryFallback['lon'];

                $mapLink = ($lat!==null && $lon!==null && $lat!=='' && $lon!=='')
                  ? ('https://www.openstreetmap.org/?mlat='.rawurlencode($lat).'&mlon='.rawurlencode($lon).'#map=17/'.$lat.'/'.$lon)
                  : '';

                // Items for this order
                $itemsForOrder = [];
                if ($im) {
                  $icols = [];
                  foreach (['name'=>'name','qty'=>'qty','unit'=>'unit','total'=>'total'] as $k=>$alias){
                    if (!empty($im[$k])) $icols[] = "`{$im[$k]}` AS `$alias`";
                  }
                  if ($icols && !empty($im['order_id'])) {
                    $sqlI = "SELECT ".implode(',', $icols)." FROM `{$im['table']}` WHERE `{$im['order_id']}`=? ORDER BY 1 ASC";
                    if ($stI=$conn->prepare($sqlI)){
                      $stI->bind_param('i',$oid); $stI->execute();
                      $itemsForOrder = $stI->get_result()->fetch_all(MYSQLI_ASSOC);
                      $stI->close();
                    }
                  }
                }
              ?>
                <div class="pm-row">
                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-3">
                      <div class="pm-meta"><i class="fa-regular fa-clock me-1"></i><?php echo h($whenTxt); ?></div>
                      <div><?php echo status_badge_html($status); ?></div>
                      <?php if ($pay): ?>
                        <div class="badge text-bg-light border"><i class="fa-solid fa-wallet me-1"></i><?php echo h($pay); ?></div>
                      <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <div class="fw-bold"><?php echo peso($total); ?></div>
                      <?php if (can_cancel($status)): ?>
                        <form method="post" action="cancel_order.php" class="d-inline" onsubmit="return confirm('Cancel this order?');">
                          <input type="hidden" name="order_id" value="<?php echo (int)$oid; ?>">
                          <button class="btn btn-outline-danger btn-sm pm-small-btn" type="submit">
                            <i class="fa-solid fa-ban me-1"></i> Cancel Order
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="mt-2">
                    <div class="pm-kv"><div class="k">Deliver To</div><div class="v"><?php echo h($deliverTo ?: '—'); ?></div></div>
                    <div class="pm-kv"><div class="k">Phone</div><div class="v"><?php echo h($phone ?: '—'); ?></div></div>
                    <div class="pm-kv">
                      <div class="k">Address</div>
                      <div class="v">
                        <?php echo nl2br(h($addr ?: '—')); ?>
                        <?php if ($mapLink): ?>
                          <a class="ms-1 small" target="_blank" rel="noopener" href="<?php echo h($mapLink); ?>">
                            <i class="fa-solid fa-map-location-dot me-1"></i>Open map
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php if ($notes): ?>
                      <div class="pm-kv"><div class="k">Notes</div><div class="v"><?php echo nl2br(h($notes)); ?></div></div>
                    <?php endif; ?>

                    <hr class="my-2">
                    <div class="pm-kv"><div class="k">Subtotal</div><div class="v"><?php echo (isset($om['subtotal']) && $om['subtotal']) ? peso($subtotal) : '—'; ?></div></div>
                    <div class="pm-kv"><div class="k">Shipping</div><div class="v"><?php echo (isset($om['shipping']) && $om['shipping']) ? peso($shipping) : '—'; ?></div></div>
                    <div class="pm-kv"><div class="k">Tax</div><div class="v"><?php echo (isset($om['tax']) && $om['tax']) ? peso($tax) : '—'; ?></div></div>
                    <div class="pm-kv"><div class="k">Total</div><div class="v"><?php echo peso($total); ?></div></div>

                    <hr class="my-2">
                    <?php if ($itemsForOrder): ?>
                      <div class="pm-items">
                        <?php foreach ($itemsForOrder as $it): ?>
                          <div class="pm-item d-flex justify-content-between gap-2">
                            <div class="flex-grow-1">
                              <div class="fw-semibold"><?php echo h(trim((string)($it['name'] ?? 'Item')) ?: 'Item'); ?></div>
                              <div class="pm-meta">Qty: <?php echo (int)($it['qty'] ?? 0); ?></div>
                            </div>
                            <div class="text-end">
                              <?php if (isset($it['unit'])): ?>
                                <div class="pm-meta">Unit</div>
                                <div class="fw-semibold"><?php echo peso($it['unit']); ?></div>
                              <?php endif; ?>
                              <?php if (isset($it['total'])): ?>
                                <div class="pm-meta mt-1">Line Total</div>
                                <div class="fw-semibold"><?php echo peso($it['total']); ?></div>
                              <?php endif; ?>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <div class="pm-meta">No item details available.</div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<?php
$PAGE_FOOT_SCRIPTS = "";
include __DIR__ . '/layout_foot.php';
