<?php
// /accountant/reports.php
declare(strict_types=1);

$PAGE_TITLE = 'Reports & Analytics';
$CURRENT    = 'reports.php';
require_once __DIR__ . '/layout_head.php';

if (!function_exists('peso')) {
  function peso($n){ return '₱' . number_format((float)$n, 2); }
}
if (!function_exists('firstCol')) {
  function firstCol(mysqli $c, string $tbl, array $cands){
    foreach ($cands as $col) if (function_exists('colExists') && colExists($c,$tbl,$col)) return $col;
    return null;
  }
}

$haveOrders = ($conn instanceof mysqli) && function_exists('tableExists') && tableExists($conn,'customer_orders');
if (!$haveOrders) {
  echo '<div class="container"><div class="alert alert-warning mt-3">Table <code>customer_orders</code> not found. Create it to use this page.</div></div></main></body></html>';
  exit;
}

$T = 'customer_orders';
$PK = firstCol($conn,$T,['id','order_id','customer_order_id']);
$DATEC = firstCol($conn,$T,['order_date','created_at','date','placed_at']);
$STATUS = firstCol($conn,$T,['status','order_status']);
$TOTAL = firstCol($conn,$T,['grand_total','total_amount','total','amount']);
$CUST = firstCol($conn,$T,['customer_name','client_name','name']);
$CUST_ID = firstCol($conn,$T,['customer_id','client_id','user_id']);

$from = isset($_GET['from']) ? substr($_GET['from'],0,10) : date('Y-m-01');
$to   = isset($_GET['to'])   ? substr($_GET['to'],0,10)   : date('Y-m-d');

$where = $DATEC ? "WHERE DATE($DATEC) BETWEEN ? AND ?" : "WHERE 1";
$params=[]; $types='';
if ($DATEC){ $params=[$from,$to]; $types='ss'; }

/* ---------- KPIs ---------- */
$totalOrders = 0; $revenue = 0.0; $avgOrder = 0.0;
if ($PK){
  $sql = "SELECT COUNT(*), ".($TOTAL?"COALESCE(SUM($TOTAL),0)":"0")." FROM $T $where";
  $st = $conn->prepare($sql); if($params) $st->bind_param($types, ...$params); $st->execute();
  $r = $st->get_result()->fetch_row(); $st->close();
  $totalOrders = (int)($r[0] ?? 0);
  $revenue     = (float)($r[1] ?? 0);
  $avgOrder    = $totalOrders>0 ? ($revenue/$totalOrders) : 0;
}

/* ---------- Revenue by day ---------- */
$series = [];
if ($DATEC) {
  $sql = "SELECT DATE($DATEC) d, ".($TOTAL?"COALESCE(SUM($TOTAL),0)":"0")." s FROM $T $where GROUP BY DATE($DATEC) ORDER BY d";
  $st = $conn->prepare($sql); if($params) $st->bind_param($types, ...$params); $st->execute();
  $res=$st->get_result(); while($row=$res->fetch_assoc()){ $series[]=$row; } $st->close();
}

/* ---------- Orders by status ---------- */
$byStatus = [];
if ($STATUS) {
  $sql = "SELECT $STATUS s, COUNT(*) c FROM $T $where GROUP BY $STATUS ORDER BY c DESC";
  $st = $conn->prepare($sql); if($params) $st->bind_param($types, ...$params); $st->execute();
  $res=$st->get_result(); while($row=$res->fetch_assoc()){ $byStatus[]=$row; } $st->close();
}

/* ---------- Top customers ---------- */
$topCust = [];
if ($CUST || $CUST_ID) {
  $grp = $CUST ? $CUST : $CUST_ID;
  $lbl = $CUST ? "COALESCE($CUST, CONCAT('ID#', $CUST_ID))" : "CONCAT('ID#', $CUST_ID)";
  $sql = "SELECT $lbl AS label, COUNT(*) orders, ".($TOTAL?"COALESCE(SUM($TOTAL),0)":"0")." gross
          FROM $T $where AND $grp IS NOT NULL
          GROUP BY $grp
          ORDER BY gross DESC, orders DESC
          LIMIT 10";
  $st = $conn->prepare($sql); if($params) $st->bind_param($types, ...$params); $st->execute();
  $res=$st->get_result(); while($row=$res->fetch_assoc()){ $topCust[]=$row; } $st->close();
}
?>
<div class="container-fluid">
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-auto">
      <label class="form-label small mb-0">From</label>
      <input type="date" name="from" class="form-control form-control-sm" value="<?php echo h($from); ?>">
    </div>
    <div class="col-auto">
      <label class="form-label small mb-0">To</label>
      <input type="date" name="to" class="form-control form-control-sm" value="<?php echo h($to); ?>">
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
    </div>
  </form>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card p-3">
        <div class="text-muted small">Total Revenue</div>
        <div class="fs-3 fw-bold"><?php echo peso($revenue); ?></div>
        <div class="small text-muted">Period: <?php echo h($from.' to '.$to); ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card p-3">
        <div class="text-muted small">Orders</div>
        <div class="fs-3 fw-bold"><?php echo (int)$totalOrders; ?></div>
        <div class="small text-muted">Average Order: <?php echo peso($avgOrder); ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card p-3">
        <div class="text-muted small">AOV (Avg. Order Value)</div>
        <div class="fs-3 fw-bold"><?php echo peso($avgOrder); ?></div>
        <div class="small text-muted">= Revenue / Orders</div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card p-3">
        <div class="mb-2 fw-semibold">Revenue by Day</div>
        <canvas id="revChart" height="120"></canvas>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card p-3">
        <div class="mb-2 fw-semibold">Orders by Status</div>
        <canvas id="statusChart" height="120"></canvas>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header"><strong>Top Customers</strong></div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr><th>Customer</th><th class="text-end">Orders</th><th class="text-end">Gross</th></tr>
        </thead>
        <tbody>
          <?php if (!$topCust): ?>
            <tr><td colspan="3" class="text-center text-muted">No data.</td></tr>
          <?php else: foreach ($topCust as $c): ?>
            <tr>
              <td><?php echo h($c['label']); ?></td>
              <td class="text-end"><?php echo (int)$c['orders']; ?></td>
              <td class="text-end fw-semibold"><?php echo peso($c['gross']); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const revLabels = <?= json_encode(array_column($series,'d') ?: []); ?>;
const revData   = <?= json_encode(array_map(fn($r)=> (float)$r['s'], $series) ?: []); ?>;
const stLabels  = <?= json_encode(array_column($byStatus,'s') ?: []); ?>;
const stData    = <?= json_encode(array_map(fn($r)=> (int)$r['c'], $byStatus) ?: []); ?>;

if (document.getElementById('revChart')) {
  new Chart(document.getElementById('revChart'), {
    type:'line',
    data:{ labels:revLabels, datasets:[{ label:'Revenue', data:revData, fill:false }] },
    options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true } } }
  });
}
if (document.getElementById('statusChart')) {
  new Chart(document.getElementById('statusChart'), {
    type:'bar',
    data:{ labels:stLabels, datasets:[{ label:'Orders', data:stData }] },
    options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, precision:0 } } }
  });
}
</script>
</main>
</body>
</html>
