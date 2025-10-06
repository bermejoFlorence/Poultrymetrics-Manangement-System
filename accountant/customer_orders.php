<?php
// /admin/customer_orders.php — Admin side Orders with Approve + Mark Done actions
declare(strict_types=1);

$PAGE_TITLE = 'Customer Orders';
$CURRENT    = 'customer_orders.php';
require_once __DIR__ . '/layout_head.php'; // includes admin guard, $conn, helpers

if (!function_exists('peso')) {
  function peso($n){ return '₱' . number_format((float)$n, 2); }
}
if (!function_exists('firstCol')) {
  function firstCol(mysqli $c, string $tbl, array $cands){
    foreach ($cands as $col) if (function_exists('colExists') && colExists($c,$tbl,$col)) return $col;
    return null;
  }
}

/* ---------- Schema discovery ---------- */
$haveOrders = ($conn instanceof mysqli) && function_exists('tableExists') && tableExists($conn,'customer_orders');
if (!$haveOrders) {
  echo '<div class="container"><div class="alert alert-warning mt-3">Table <code>customer_orders</code> not found. Create it to use this page.</div></div></main></body></html>';
  exit;
}

$T       = 'customer_orders';
$PK      = firstCol($conn,$T,['id','order_id','customer_order_id']);
$CUST    = firstCol($conn,$T,['customer_name','client_name','name']);
$CUST_ID = firstCol($conn,$T,['customer_id','client_id','user_id']);
$EMAIL   = firstCol($conn,$T,['email','customer_email']);
$DATEC   = firstCol($conn,$T,['order_date','created_at','date','placed_at']);
$STATUS  = firstCol($conn,$T,['status','order_status']);
$PAYSTAT = firstCol($conn,$T,['payment_status','pay_status']);
$TOTAL   = firstCol($conn,$T,['grand_total','total_amount','total','amount']);

if (!$PK) {
  echo '<div class="container"><div class="alert alert-danger mt-3">Primary key column not found (expected one of: id, order_id, customer_order_id).</div></div></main></body></html>';
  exit;
}

/* ---------- Status vocabulary (auto-learn) ---------- */
$knownStatuses = [];
if ($STATUS) {
  if ($st = $conn->prepare("SELECT DISTINCT LOWER($STATUS) FROM $T WHERE $STATUS IS NOT NULL AND $STATUS<>'' LIMIT 50")){
    $st->execute(); $res=$st->get_result();
    while($r=$res->fetch_row()){ $knownStatuses[] = $r[0]; }
    $st->close();
  }
}
$choose = function(array $cands, string $fallback) use ($knownStatuses){
  foreach ($cands as $s) if (in_array($s, $knownStatuses, true)) return $s;
  return $fallback;
};
$labelApproved  = $choose(['approved','processing','confirmed'], 'approved');
$labelCompleted = $choose(['completed','complete','done','fulfilled','closed'], 'completed');

/* ---------- Filters ---------- */
$from = isset($_GET['from']) ? substr($_GET['from'],0,10) : date('Y-m-01');
$to   = isset($_GET['to'])   ? substr($_GET['to'],0,10)   : date('Y-m-d');
$stf  = isset($_GET['status']) ? trim($_GET['status']) : '';

/* ---------- CSV Export ---------- */
if (($DATEC && $PK) && isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=admin_customer_orders_'.date('Ymd_His').'.csv');
  $out = fopen('php://output','w');
  fputcsv($out, ['Order #','Date','Customer','Email','Status','Payment','Total']);

  $where = "WHERE ".($DATEC ? "DATE($DATEC) BETWEEN ? AND ?" : "1");
  $params=[]; $types='';
  if ($DATEC){ $params=[$from,$to]; $types='ss'; }
  if ($stf !== '' && $STATUS){ $where .= " AND $STATUS=?"; $params[]=$stf; $types.='s'; }

  $cols = "$PK".($DATEC?",$DATEC":"")
        .($CUST?",$CUST":"").($EMAIL?",$EMAIL":"")
        .($STATUS?",$STATUS":"").($PAYSTAT?",$PAYSTAT":"")
        .($TOTAL?",$TOTAL":"");
  $sql = "SELECT $cols FROM $T $where ORDER BY ".($DATEC?$DATEC:'1')." DESC, $PK DESC";
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

/* ---------- Inline Actions (Approve / Mark Done / Mark Paid / Set Status / View) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int)($_POST['id'] ?? 0);
  if ($id<=0) { echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit; }

  // Mark Paid
  if ($_POST['action']==='mark_paid' && $PAYSTAT) {
    $sql = "UPDATE $T SET $PAYSTAT='paid' WHERE $PK=?";
    $st = $conn->prepare($sql); $st->bind_param('i',$id); $ok=$st->execute(); $st->close();
    echo json_encode(['ok'=>$ok]); exit;
  }

  // Approve
  if ($_POST['action']==='approve' && $STATUS) {
    $val = $labelApproved;
    $sql = "UPDATE $T SET $STATUS=? WHERE $PK=?";
    $st = $conn->prepare($sql); $st->bind_param('si',$val,$id); $ok=$st->execute(); $st->close();
    echo json_encode(['ok'=>$ok]); exit;
  }

  // Mark Done (Completed)
  if ($_POST['action']==='mark_done' && $STATUS) {
    $val = $labelCompleted;
    $sql = "UPDATE $T SET $STATUS=? WHERE $PK=?";
    $st = $conn->prepare($sql); $st->bind_param('si',$val,$id); $ok=$st->execute(); $st->close();
    echo json_encode(['ok'=>$ok]); exit;
  }

  // Explicit Set Status (dropdown)
  if ($_POST['action']==='set_status' && $STATUS) {
    $new = substr((string)($_POST['status']??''),0,32);
    $sql = "UPDATE $T SET $STATUS=? WHERE $PK=?";
    $st = $conn->prepare($sql); $st->bind_param('si',$new,$id); $ok=$st->execute(); $st->close();
    echo json_encode(['ok'=>$ok]); exit;
  }

  // View items
  if ($_POST['action']==='view') {
    $itemsTbl = tableExists($conn,'order_items') ? 'order_items' : (tableExists($conn,'customer_order_items') ? 'customer_order_items' : null);
    $rows = [];
    if ($itemsTbl){
      $fk    = firstCol($conn,$itemsTbl,['order_id','customer_order_id',$PK]);
      $qty   = firstCol($conn,$itemsTbl,['qty','quantity']);
      $price = firstCol($conn,$itemsTbl,['unit_price','price']);
      $name  = firstCol($conn,$itemsTbl,['product_name','item_name','name']);
      if ($fk){
        $sql="SELECT ".($name?$name.' AS item_name,':'').($qty?$qty.' AS qty,':'').($price?$price.' AS unit_price':'1 AS unit_price')." FROM $itemsTbl WHERE $fk=?";
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
  if ($stmt=$conn->prepare("SELECT DISTINCT $STATUS FROM $T WHERE $STATUS IS NOT NULL AND $STATUS<>'' ORDER BY 1")){
    $stmt->execute(); $res=$stmt->get_result();
    while($r=$res->fetch_row()){ $statuses[]=$r[0]; }
    $stmt->close();
  }
}

/* ---------- Fetch list ---------- */
$where = $DATEC ? "WHERE DATE($DATEC) BETWEEN ? AND ?" : "WHERE 1";
$params=[]; $types='';
if ($DATEC){ $params=[$from,$to]; $types='ss'; }
if ($stf !== '' && $STATUS){ $where .= " AND $STATUS=?"; $params[]=$stf; $types.='s'; }

$cols = "$PK"
      .($DATEC?",$DATEC":"")
      .($CUST?",$CUST":"")
      .($CUST_ID?",$CUST_ID":"")
      .($EMAIL?",$EMAIL":"")
      .($STATUS?",$STATUS":"")
      .($PAYSTAT?",$PAYSTAT":"")
      .($TOTAL?",$TOTAL":"");
$sql = "SELECT $cols FROM $T $where ORDER BY ".($DATEC?$DATEC:'1')." DESC, $PK DESC";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute(); $list = $stmt->get_result();

/* ---------- Totals ---------- */
$sumTotal = 0.0; $countOrders = 0;
if ($TOTAL) {
  $sumSQL = "SELECT COALESCE(SUM($TOTAL),0) FROM $T $where";
  $stm2 = $conn->prepare($sumSQL);
  if ($params) $stm2->bind_param($types, ...$params);
  $stm2->execute(); $sumTotal = (float)($stm2->get_result()->fetch_row()[0] ?? 0); $stm2->close();
}
if ($PK) {
  $cntSQL = "SELECT COUNT(*) FROM $T $where";
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
        <a class="btn btn-sm btn-outline-secondary mt-3" href="?from=<?php echo h($from); ?>&to=<?php echo h($to); ?>&status=<?php echo h($stf); ?>&export=csv"><i class="fa-solid fa-file-csv"></i> CSV</a>
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
              $isApproved  = $s === $labelApproved;
              $isCompleted = in_array($s, [$labelCompleted,'completed','complete','done','fulfilled','closed'], true);
              $cls = match($s){
                'pending' => 'warning',
                'processing' => 'info',
                'approved' => 'primary',
                'completed','complete','done','fulfilled','closed' => 'success',
                'cancelled','canceled' => 'secondary',
                default => 'light'
              };
              $p = $PAYSTAT ? strtolower((string)($row[$PAYSTAT] ?? '')) : '';
              $pcls = ($p==='paid') ? 'success' : (($p==='refunded') ? 'secondary' : 'warning');
            ?>
            <tr>
              <td class="text-muted"><?php echo h($row[$PK]); ?></td>
              <?php if ($DATEC): ?><td><?php echo h(date('M d, Y', strtotime($row[$DATEC]))); ?></td><?php endif; ?>
              <td><?php echo h($CUST && !empty($row[$CUST]) ? $row[$CUST] : ($CUST_ID && !empty($row[$CUST_ID]) ? 'ID#'.$row[$CUST_ID] : '—')); ?></td>
              <?php if ($EMAIL): ?><td><?php echo h($row[$EMAIL] ?: '—'); ?></td><?php endif; ?>
              <?php if ($STATUS): ?><td><span class="badge text-bg-<?php echo $cls; ?>"><?php echo h(ucfirst($s?:'n/a')); ?></span></td><?php endif; ?>
              <?php if ($PAYSTAT): ?><td><span class="badge text-bg-<?php echo $pcls; ?>"><?php echo h(ucfirst($p?:'n/a')); ?></span></td><?php endif; ?>
              <?php if ($TOTAL): ?><td class="text-end fw-semibold"><?php echo peso($row[$TOTAL]); ?></td><?php endif; ?>
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
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Order Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div id="orderItems">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
function postJSON(data){
  return fetch('', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams(data)
  }).then(r=>r.json());
}
function toast(msg){
  if (window.Swal && Swal.fire){
    Swal.fire({toast:true,position:'top',timer:1500,showConfirmButton:false,icon:'success',title:msg});
  } else { console.log(msg); }
}
function markPaid(id){
  postJSON({action:'mark_paid', id}).then(res=>{ if(res.ok){ toast('Marked paid'); location.reload(); } else alert('Failed'); });
}
function approveOrder(id){
  postJSON({action:'approve', id}).then(res=>{ if(res.ok){ toast('Order approved'); location.reload(); } else alert('Failed'); });
}
function markDone(id){
  postJSON({action:'mark_done', id}).then(res=>{ if(res.ok){ toast('Order marked done'); location.reload(); } else alert('Failed'); });
}
function setStatus(id,status){
  postJSON({action:'set_status', id, status}).then(res=>{ if(res.ok){ toast('Status updated'); location.reload(); } else alert('Failed'); });
}
function viewOrder(id){
  postJSON({action:'view', id}).then(res=>{
    const box = document.getElementById('orderItems');
    if(!res.ok){ box.textContent='No details.'; return; }
    if(!res.items || !res.items.length){ box.textContent='No items recorded for this order.'; }
    else{
      let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Line</th></tr></thead><tbody>';
      res.items.forEach(x=>{
        const name = x.item_name ?? '(item)';
        const qty = Number(x.qty ?? 1);
        const unit = Number(x.unit_price ?? 0);
        html += `<tr><td>${name}</td><td class="text-end">${qty}</td><td class="text-end">₱${unit.toFixed(2)}</td><td class="text-end">₱${(qty*unit).toFixed(2)}</td></tr>`;
      });
      html += '</tbody></table></div>';
      box.innerHTML = html;
    }
    new bootstrap.Modal(document.getElementById('orderModal')).show();
  });
}
</script>

<?php // FOOTER SCRIPTS ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</main>
</body>
</html>
