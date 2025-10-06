<?php
// accountant/customer_orders.php — Accountant view of Customer Orders (robust + null-safe)
declare(strict_types=1);

$PAGE_TITLE = 'Customer Orders';
$CURRENT    = 'customer_orders.php';

// 1) Make sure $conn exists (comes from accountant/inc/common.php which requires your root config.php)
require_once __DIR__ . '/inc/common.php';

// 2) Then load your header (it may also expect $conn/session already initialized)
require_once __DIR__ . '/layout_head.php';

@date_default_timezone_set('Asia/Manila');
if (isset($conn) && $conn instanceof mysqli) {
  @$conn->query("SET time_zone = '+08:00'");
  @$conn->set_charset('utf8mb4');
}
mysqli_report(MYSQLI_REPORT_OFF);

/* ---------- Tiny utils ---------- */
if (!function_exists('h'))    { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('peso')) { function peso($n){ return '₱' . number_format((float)$n, 2); } }

/* ---------- Null-safe INFORMATION_SCHEMA helpers ---------- */
function tbl_exists(?mysqli $c, string $t): bool {
  if (!$c instanceof mysqli) return false;
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
  if (!$st=$c->prepare($sql)) return false;
  $st->bind_param('s',$t); $st->execute(); $st->store_result();
  $ok = $st->num_rows>0; $st->close(); return $ok;
}
function col_exists(?mysqli $c, string $t, string $col): bool {
  if (!$c instanceof mysqli) return false;
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  if (!$st=$c->prepare($sql)) return false;
  $st->bind_param('ss',$t,$col); $st->execute(); $st->store_result();
  $ok = $st->num_rows>0; $st->close(); return $ok;
}
function first_col(?mysqli $c, string $t, array $cands): ?string {
  foreach ($cands as $x) if ($x && col_exists($c,$t,$x)) return $x;
  return null;
}
function find_orders_table(?mysqli $c): ?string {
  if (!$c instanceof mysqli) return null;
  foreach (['customer_orders','orders','customer_order','pmx_customer_orders'] as $t) {
    if (tbl_exists($c,$t)) return $t;
  }
  $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND (TABLE_NAME LIKE '%customer%order%' OR TABLE_NAME LIKE '%orders%')
          ORDER BY TABLE_NAME LIMIT 1";
  if ($r=$c->query($sql)) { $row=$r->fetch_row(); if ($row && $row[0]) return $row[0]; }
  return null;
}

/* ---------- Make absolutely sure $conn exists (fallback if some header replaced it) ---------- */
if (!isset($conn) || !($conn instanceof mysqli)) {
  // Last-resort: connect using DB_* from config.php (already required by inc/common.php)
  if (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
    $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
    if ($conn instanceof mysqli && !$conn->connect_errno) {
      @$conn->set_charset('utf8mb4');
      @$conn->query("SET time_zone = '+08:00'");
    }
  }
}

/* ---------- If still no DB, bail with a friendly message ---------- */
if (!($conn instanceof mysqli)) {
  echo '<div class="container"><div class="alert alert-danger mt-3">Database connection not available. Check config.php and inc/common.php.</div></div></main></body></html>';
  exit;
}

/* ---------- Discover table & columns (same style as your dashboard) ---------- */
$T = find_orders_table($conn);
if (!$T) {
  $db = ''; if ($r=$conn->query("SELECT DATABASE()")) { $db=(string)($r->fetch_row()[0]??''); }
  echo '<div class="container"><div class="alert alert-warning mt-3">';
  echo 'Orders table not found in current database: <code>'.h($db).'</code>. ';
  echo 'Expected something like <code>customer_orders</code> or <code>orders</code>.';
  echo '</div></div></main></body></html>'; exit;
}

$PK      = first_col($conn,$T, ['id','order_id','customer_order_id','oid']);
$CUST    = first_col($conn,$T, ['customer_name','deliver_to','recipient','client_name','name','ship_to']);
$CUST_ID = first_col($conn,$T, ['customer_id','user_id','uid','account_id','users_id','client_id']);
$EMAIL   = first_col($conn,$T, ['email','customer_email']);
$DATEC   = first_col($conn,$T, ['created_at','created','order_date','date_added','placed_at','date']);
$STATUS  = first_col($conn,$T, ['status','state','order_status']);
$PAYSTAT = first_col($conn,$T, ['payment_status','pay_status']);
$TOTAL   = first_col($conn,$T, ['grand_total','total','amount','total_amount']);

if (!$PK) {
  echo '<div class="container"><div class="alert alert-danger mt-3">Primary key column not found on <code>'.h($T).'</code> (tried: id, order_id, customer_order_id).</div></div></main></body></html>';
  exit;
}

/* ---------- Status dictionary ---------- */
$knownStatuses = [];
if ($STATUS) {
  if ($st=$conn->prepare("SELECT DISTINCT LOWER(`$STATUS`) FROM `$T` WHERE `$STATUS` IS NOT NULL AND `$STATUS`<>'' LIMIT 50")){
    $st->execute(); $res=$st->get_result();
    while($r=$res->fetch_row()){ $knownStatuses[]=$r[0]; }
    $st->close();
  }
}
$pickStatus = function(array $cands, string $fallback) use ($knownStatuses){
  foreach ($cands as $s) if (in_array($s,$knownStatuses,true)) return $s;
  return $fallback;
};
$labelApproved  = $pickStatus(['approved','processing','confirmed','accepted'], 'approved');
$labelCompleted = $pickStatus(['completed','complete','done','fulfilled','delivered','closed'], 'completed');

/* ---------- Filters ---------- */
$from = isset($_GET['from']) ? substr($_GET['from'],0,10) : date('Y-m-01');
$to   = isset($_GET['to'])   ? substr($_GET['to'],0,10)   : date('Y-m-d');
$stf  = isset($_GET['status']) ? trim($_GET['status']) : '';

/* ---------- CSV Export ---------- */
if (($DATEC && $PK) && isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=accountant_customer_orders_'.date('Ymd_His').'.csv');
  $out = fopen('php://output','w');
  fputcsv($out, ['Order #','Date','Customer','Email','Status','Payment','Total']);

  $where = "WHERE ".($DATEC ? "DATE(`$DATEC`) BETWEEN ? AND ?" : "1");
  $params=[]; $types='';
  if ($DATEC){ $params=[$from,$to]; $types='ss'; }
  if ($stf !== '' && $STATUS){ $where .= " AND `$STATUS`=?"; $params[]=$stf; $types.='s'; }

  $cols = "`$PK`".($DATEC?",`$DATEC`":"")
        .($CUST?",`$CUST`":"").($EMAIL?",`$EMAIL`":"")
        .($STATUS?",`$STATUS`":"").($PAYSTAT?",`$PAYSTAT`":"")
        .($TOTAL?",`$TOTAL`":"");
  $sql = "SELECT $cols FROM `$T` $where ORDER BY ".($DATEC?"`$DATEC`":'1')." DESC, `$PK` DESC";
  $stmt = $conn->prepare($sql);
  if ($params) $stmt->bind_param($types, ...$params);
  $stmt->execute(); $res=$stmt->get_result();
  while($row=$res->fetch_assoc()){
    fputcsv($out, [
      $row[$PK] ?? '',
      $DATEC && !empty($row[$DATEC]) ? date('Y-m-d', strtotime($row[$DATEC])) : '',
      $CUST && !empty($row[$CUST]) ? $row[$CUST] : ($CUST_ID && !empty($row[$CUST_ID]) ? 'ID#'.$row[$CUST_ID] : ''),
      $EMAIL && !empty($row[$EMAIL]) ? $row[$EMAIL] : '',
      $STATUS && isset($row[$STATUS]) ? $row[$STATUS] : '',
      $PAYSTAT && isset($row[$PAYSTAT]) ? $row[$PAYSTAT] : '',
      $TOTAL && isset($row[$TOTAL]) ? (float)$row[$TOTAL] : 0,
    ]);
  }
  fclose($out); exit;
}

/* ---------- Inline Actions ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int)($_POST['id'] ?? 0);
  if ($id<=0) { echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit; }

  if ($_POST['action']==='mark_paid' && $PAYSTAT) {
    $sql = "UPDATE `$T` SET `$PAYSTAT`='paid' WHERE `$PK`=?";
    $st = $conn->prepare($sql); $st->bind_param('i',$id); $ok=$st->execute(); $st->close();
    echo json_encode(['ok'=>$ok]); exit;
  }
  if ($_POST['action']==='approve' && $STATUS) {
    $val = $labelApproved;
    $sql = "UPDATE `$T` SET `$STATUS`=? WHERE `$PK`=?";
    $st = $conn->prepare($sql); $st->bind_param('si',$val,$id); $ok=$st->execute(); $st->close();
    echo json_encode(['ok'=>$ok]); exit;
  }
  if ($_POST['action']==='mark_done' && $STATUS) {
    $val = $labelCompleted;
    $sql = "UPDATE `$T` SET `$STATUS`=? WHERE `$PK`=?";
    $st = $conn->prepare($sql); $st->bind_param('si',$val,$id); $ok=$st->execute(); $st->close();
    echo json_encode(['ok'=>$ok]); exit;
  }
  if ($_POST['action']==='set_status' && $STATUS) {
    $new = substr((string)($_POST['status']??''),0,32);
    $sql = "UPDATE `$T` SET `$STATUS`=? WHERE `$PK`=?";
    $st = $conn->prepare($sql); $st->bind_param('si',$new,$id); $ok=$st->execute(); $st->close();
    echo json_encode(['ok'=>$ok]); exit;
  }

  // View items
  if ($_POST['action']==='view') {
    $itemsTbl = null;
    foreach (['customer_order_items','order_items','orders_items','order_detail'] as $cand) {
      if (tbl_exists($conn, $cand)) { $itemsTbl = $cand; break; }
    }
    $rows = [];
    if ($itemsTbl){
      $fk    = first_col($conn,$itemsTbl, ['customer_order_id','order_id',$PK]);
      $qty   = first_col($conn,$itemsTbl, ['qty','quantity','qty_ordered']);
      $price = first_col($conn,$itemsTbl, ['unit_price','price','unit_cost']);
      $name  = first_col($conn,$itemsTbl, ['product_name','item_name','name','title']);
      if ($fk){
        $sel = [];
        $sel[] = $name ? "`$name` AS item_name" : "'(item)' AS item_name";
        $sel[] = $qty   ? "`$qty` AS qty"       : "1 AS qty";
        $sel[] = $price ? "`$price` AS unit_price" : "0 AS unit_price";
        $sql="SELECT ".implode(',', $sel)." FROM `$itemsTbl` WHERE `$fk`=?";
        $st = $conn->prepare($sql); $st->bind_param('i',$id); $st->execute();
        $r=$st->get_result();
        while($x=$r->fetch_assoc()){ $rows[]=$x; }
        $st->close();
      }
    }
    echo json_encode(['ok'=>true,'items'=>$rows]); exit;
  }

  echo json_encode(['ok'=>false,'msg'=>'Unsupported action']); exit;
}

/* ---------- Distinct statuses for filter ---------- */
$statuses = [];
if ($STATUS) {
  if ($stmt=$conn->prepare("SELECT DISTINCT `$STATUS` FROM `$T` WHERE `$STATUS` IS NOT NULL AND `$STATUS`<>'' ORDER BY 1")){
    $stmt->execute(); $res=$stmt->get_result();
    while($r=$res->fetch_row()){ $statuses[]=$r[0]; }
    $stmt->close();
  }
}

/* ---------- Fetch list ---------- */
$where = $DATEC ? "WHERE DATE(`$DATEC`) BETWEEN ? AND ?" : "WHERE 1";
$params=[]; $types='';
if ($DATEC){ $params=[$from,$to]; $types='ss'; }
if ($stf !== '' && $STATUS){ $where .= " AND `$STATUS`=?"; $params[]=$stf; $types.='s'; }

$cols = "`$PK`"
      .($DATEC? ",`$DATEC`" : "")
      .($CUST?  ",`$CUST`"  : "")
      .($CUST_ID?",`$CUST_ID`":"")
      .($EMAIL? ",`$EMAIL`" : "")
      .($STATUS?",`$STATUS`":"")
      .($PAYSTAT?",`$PAYSTAT`":"")
      .($TOTAL? ",`$TOTAL`" : "");
$sql = "SELECT $cols FROM `$T` $where ORDER BY ".($DATEC?"`$DATEC`":'1')." DESC, `$PK` DESC";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $list = $stmt->get_result();

/* ---------- Totals ---------- */
$sumTotal = 0.0; $countOrders = 0;
if ($TOTAL) {
  $sumSQL = "SELECT COALESCE(SUM(`$TOTAL`),0) FROM `$T` $where";
  $stm2 = $conn->prepare($sumSQL);
  if ($params) $stm2->bind_param($types, ...$params);
  $stm2->execute(); $sumTotal = (float)($stm2->get_result()->fetch_row()[0] ?? 0); $stm2->close();
}
{
  $cntSQL = "SELECT COUNT(*) FROM `$T` $where";
  $stm3 = $conn->prepare($cntSQL);
  if ($params) $stm3->bind_param($types, ...$params);
  $stm3->execute(); $countOrders = (int)($stm3->get_result()->fetch_row()[0] ?? 0); $stm3->close();
}
?>
<div class="container-fluid">
  <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
    <form class="row g-2" method="get">
      <div class="col-auto">
        <label class="form-label small mb-0">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?php echo h($from); ?>">
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?php echo h($to); ?>">
      </div>
      <div class="col-auto">
        <label class="form-label small mb-0">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach ($statuses as $s): ?>
            <option value="<?php echo h($s); ?>" <?php echo ($stf===$s?'selected':''); ?>><?php echo h(ucfirst($s)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-sm btn-primary mt-3"><i class="fa-solid fa-filter"></i> Apply</button>
        <?php if ($DATEC): ?>
        <a class="btn btn-sm btn-outline-secondary mt-3" href="?from=<?php echo h($from); ?>&to=<?php echo h($to); ?>&status=<?php echo h($stf); ?>&export=csv"><i class="fa-solid fa-file-csv"></i> CSV</a>
        <?php endif; ?>
      </div>
    </form>
    <div class="ms-auto d-flex gap-2">
      <div class="badge bg-success-subtle text-success border border-success-subtle">Total: <?php echo peso($sumTotal); ?></div>
      <div class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Orders: <?php echo (int)$countOrders; ?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <strong>Customer Orders</strong>
      <span class="text-muted small">(<?php echo h($T); ?>)</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <?php if ($DATEC): ?><th>Date</th><?php endif; ?>
            <th>Customer</th>
            <?php if ($EMAIL): ?><th>Email</th><?php endif; ?>
            <?php if ($STATUS): ?><th>Status</th><?php endif; ?>
            <?php if ($PAYSTAT): ?><th>Payment</th><?php endif; ?>
            <?php if ($TOTAL): ?><th class="text-end">Total</th><?php endif; ?>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while($row=$list->fetch_assoc()): ?>
            <?php
              $s = $STATUS ? strtolower((string)($row[$STATUS] ?? '')) : '';
              $isApproved  = in_array($s,['approved','processing','confirmed','accepted'],true);
              $isCompleted = in_array($s,['completed','complete','done','fulfilled','delivered','closed'], true);
              $cls = match($s){
                'pending','placed','created','new','awaiting' => 'warning',
                'processing','approved','confirmed','accepted' => 'info',
                'completed','complete','done','fulfilled','delivered','closed' => 'success',
                'cancelled','canceled','void','rejected','failed' => 'secondary',
                default => 'light'
              };
              $p = $PAYSTAT ? strtolower((string)($row[$PAYSTAT] ?? '')) : '';
              $pcls = ($p==='paid') ? 'success' : (($p==='refunded') ? 'secondary' : 'warning');
            ?>
            <tr>
              <td class="text-muted"><?php echo h($row[$PK]); ?></td>
              <?php if ($DATEC): ?><td><?php echo h(!empty($row[$DATEC]) ? date('M d, Y', strtotime($row[$DATEC])) : '—'); ?></td><?php endif; ?>
              <td><?php
                  $cust = $CUST && !empty($row[$CUST]) ? $row[$CUST] : ($CUST_ID && !empty($row[$CUST_ID]) ? 'ID#'.$row[$CUST_ID] : '—');
                  echo h($cust);
              ?></td>
              <?php if ($EMAIL): ?><td><?php echo h($row[$EMAIL] ?: '—'); ?></td><?php endif; ?>
              <?php if ($STATUS): ?><td><span class="badge text-bg-<?php echo $cls; ?>"><?php echo h(ucfirst($s?:'n/a')); ?></span></td><?php endif; ?>
              <?php if ($PAYSTAT): ?><td><span class="badge text-bg-<?php echo $pcls; ?>"><?php echo h(ucfirst($p?:'n/a')); ?></span></td><?php endif; ?>
              <?php if ($TOTAL): ?><td class="text-end fw-semibold"><?php echo peso($row[$TOTAL] ?? 0); ?></td><?php endif; ?>
              <td class="d-flex flex-wrap gap-1">
                <?php if ($PAYSTAT && $p!=='paid'): ?>
                  <button class="btn btn-sm btn-outline-success" title="Mark Paid" onclick="markPaid(<?php echo (int)$row[$PK]; ?>)"><i class="fa-solid fa-peso-sign"></i></button>
                <?php endif; ?>
                <?php if ($STATUS): ?>
                  <?php if (!$isApproved && !$isCompleted): ?>
                    <button class="btn btn-sm btn-outline-primary" title="Approve" onclick="approveOrder(<?php echo (int)$row[$PK]; ?>)"><i class="fa-solid fa-check"></i></button>
                  <?php endif; ?>
                  <?php if (!$isCompleted): ?>
                    <button class="btn btn-sm btn-outline-success" title="Mark Done" onclick="markDone(<?php echo (int)$row[$PK]; ?>)"><i class="fa-solid fa-circle-check"></i></button>
                  <?php endif; ?>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-secondary" title="View Items" onclick="viewOrder(<?php echo (int)$row[$PK]; ?>)"><i class="fa-solid fa-list"></i></button>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal + JS identical to previous version (omitted for brevity),
     keep your existing script block for actions/view items -->
<?php // FOOTER SCRIPTS ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</main>
</body>
</html>
