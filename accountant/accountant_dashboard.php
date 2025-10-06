<?php
// accountant/accountant_dashboard.php — Overview cards, recent payroll, recent customer orders
declare(strict_types=1);

$PAGE_TITLE = 'Accountant Dashboard';
$CURRENT    = 'accountant_dashboard.php';

require_once __DIR__ . '/inc/common.php';
include __DIR__ . '/layout_head.php';

@date_default_timezone_set('Asia/Manila');
if (isset($conn) && $conn instanceof mysqli) {
  @$conn->query("SET time_zone = '+08:00'");
  @$conn->set_charset('utf8mb4');
}
mysqli_report(MYSQLI_REPORT_OFF);

/* ----- tiny helpers if not provided by common ----- */
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('firstExistingCol')) {
  function firstExistingCol(mysqli $c, string $t, array $cands){
    if (!function_exists('colExists')) return null;
    foreach ($cands as $x){ if ($x && colExists($c,$t,$x)) return $x; }
    return null;
  }
}

/* ===== Dates ===== */
$today     = date('Y-m-d');
$THIS_YEAR = (int)date('Y');
$month     = (int)date('n');
$firstDay  = sprintf('%04d-%02d-01', $THIS_YEAR, $month);
$lastDay   = date('Y-m-t', strtotime($firstDay));
$firstDT   = $firstDay . ' 00:00:00';
$lastDT    = $lastDay  . ' 23:59:59';

/* ===== Quick stats (defensive with tableExists checks) ===== */
$stats = [
  'unpaid'    => 0,
  'paid'      => 0,
  'periods'   => 0,
  'todayLogs' => 0,
];

if (function_exists('tableExists') && tableExists($conn,'payroll')) {
  $stats['unpaid']  = (int)(scalar($conn,"SELECT COUNT(*) FROM payroll WHERE status='unpaid'") ?? 0);
  $stats['paid']    = (int)(scalar($conn,"SELECT COUNT(*) FROM payroll WHERE status='paid'") ?? 0);
  $stats['periods'] = (int)(scalar($conn,"SELECT COUNT(DISTINCT CONCAT(pay_period_start,'|',pay_period_end)) FROM payroll") ?? 0);
}
if (function_exists('tableExists') && function_exists('colExists') && tableExists($conn,'attendance_logs') && colExists($conn,'attendance_logs','date')) {
  $stats['todayLogs'] = (int)(scalar($conn,"SELECT COUNT(*) FROM attendance_logs WHERE date=CURDATE()") ?? 0);
}

/* ===== Recent payroll list (robust: no fragile columns) ===== */
$recent = [];
if (function_exists('tableExists') && tableExists($conn,'payroll')) {
  $empPk = 'id';
  if (tableExists($conn,'employees')) {
    if (!colExists($conn,'employees','id') && colExists($conn,'employees','employee_id')) $empPk = 'employee_id';
  }

  $joinEmployees = tableExists($conn,'employees')
    ? "LEFT JOIN employees e ON e.`{$empPk}` = p.employee_id"
    : "";

  $joinUsers = (tableExists($conn,'employees') && colExists($conn,'employees','user_id') && tableExists($conn,'users') && colExists($conn,'users','id'))
    ? "LEFT JOIN users u ON u.id = e.user_id"
    : (tableExists($conn,'users') && colExists($conn,'users','id')
        ? "LEFT JOIN users u ON u.id = p.employee_id"
        : "");

  $empNameExpr = "COALESCE(" .
                   (tableExists($conn,'users') && colExists($conn,'users','username') ? "u.username" : "NULL") . ", " .
                   (tableExists($conn,'employees') && colExists($conn,'employees','username') ? "e.username" : "NULL") . ", " .
                   "CONCAT('#', p.employee_id)" .
                 ")";

  $sql = "
    SELECT
      p.payroll_id,
      p.employee_id,
      p.pay_period_start,
      p.pay_period_end,
      p.net_pay,
      p.status,
      p.date_processed,
      {$empNameExpr} AS emp_name
    FROM payroll p
    {$joinEmployees}
    {$joinUsers}
    ORDER BY p.date_processed IS NULL, p.date_processed DESC, p.payroll_id DESC
    LIMIT 8
  ";
  if ($rs = $conn->query($sql)) {
    while ($row = $rs->fetch_assoc()) { $recent[] = $row; }
    $rs->free();
  }
}

/* ===== Customer Orders: monthly stats + recent list ===== */
$ordersTbl = null;
if (function_exists('tableExists')) {
  if (tableExists($conn,'customer_orders'))      $ordersTbl = 'customer_orders';
  elseif (tableExists($conn,'orders'))           $ordersTbl = 'orders';
}

$ordersStats = [
  'count_month'  => 0,
  'pending_month'=> 0,
  'sales_month'  => 0.0,
];
$ordersRecent = [];

if ($ordersTbl && function_exists('colExists')) {
  // Map common columns
  $OM = [
    'id'      => firstExistingCol($conn,$ordersTbl, ['order_id','id','oid']),
    'user'    => firstExistingCol($conn,$ordersTbl, ['customer_id','user_id','uid','account_id','users_id']),
    'status'  => firstExistingCol($conn,$ordersTbl, ['status','state','order_status']),
    'total'   => firstExistingCol($conn,$ordersTbl, ['grand_total','total','amount','total_amount']),
    'created' => firstExistingCol($conn,$ordersTbl, ['created_at','created','order_date','date_added','placed_at','date']),
    'to'      => firstExistingCol($conn,$ordersTbl, ['deliver_to','recipient','customer_name','ship_to']),
    'phone'   => firstExistingCol($conn,$ordersTbl, ['phone','contact','contact_number','mobile']),
    'addr'    => firstExistingCol($conn,$ordersTbl, ['delivery_address','address','shipping_address','ship_address']),
  ];

  // Month counts
  if ($OM['created']) {
    // Total orders in month
    $sql = "SELECT COUNT(*) FROM `{$ordersTbl}` WHERE `{$OM['created']}` BETWEEN ? AND ?";
    if ($st = $conn->prepare($sql)) {
      $st->bind_param('ss',$firstDT,$lastDT); $st->execute();
      $ordersStats['count_month'] = (int)($st->get_result()->fetch_row()[0] ?? 0);
      $st->close();
    }

    // Pending-like statuses
    if ($OM['status']) {
      $sql = "SELECT COUNT(*) FROM `{$ordersTbl}` WHERE `{$OM['created']}` BETWEEN ? AND ?
              AND LOWER(`{$OM['status']}`) IN ('pending','placed','created','new','processing','awaiting')";
      if ($st = $conn->prepare($sql)) {
        $st->bind_param('ss',$firstDT,$lastDT); $st->execute();
        $ordersStats['pending_month'] = (int)($st->get_result()->fetch_row()[0] ?? 0);
        $st->close();
      }
    }

    // Sales this month = sum(total) for fulfilled/done/completed/delivered
    if ($OM['total'] && $OM['status']) {
      $sql = "SELECT COALESCE(SUM(`{$OM['total']}`),0) FROM `{$ordersTbl}`
              WHERE `{$OM['created']}` BETWEEN ? AND ?
                AND LOWER(`{$OM['status']}`) IN ('fulfilled','completed','delivered','done')";
      if ($st = $conn->prepare($sql)) {
        $st->bind_param('ss',$firstDT,$lastDT); $st->execute();
        $ordersStats['sales_month'] = (float)($st->get_result()->fetch_row()[0] ?? 0);
        $st->close();
      }
    }
  }

  // Recent orders (8)
  $selCols = [];
  $selCols[] = $OM['id']      ? "`{$OM['id']}` AS oid" : "NULL AS oid";
  $selCols[] = $OM['created'] ? "`{$OM['created']}` AS odate" : "NULL AS odate";
  $selCols[] = $OM['status']  ? "`{$OM['status']}` AS st" : "NULL AS st";
  $selCols[] = $OM['total']   ? "`{$OM['total']}` AS total" : "NULL AS total";
  $selCols[] = $OM['to']      ? "`{$OM['to']}` AS deliver_to" : "NULL AS deliver_to";
  $selCols[] = $OM['phone']   ? "`{$OM['phone']}` AS ophone" : "NULL AS ophone";
  $selCols[] = $OM['addr']    ? "`{$OM['addr']}` AS oaddr" : "NULL AS oaddr";

  $orderBy = $OM['created'] ? "`{$OM['created']}` DESC" : ($OM['id'] ? "`{$OM['id']}` DESC" : "1");
  $sql = "SELECT ".implode(',', $selCols)." FROM `{$ordersTbl}` ORDER BY {$orderBy} LIMIT 8";
  if ($r = @$conn->query($sql)) { $ordersRecent = $r->fetch_all(MYSQLI_ASSOC); $r->free(); }
}
?>
<div class="container-fluid">
  <!-- KPI Row -->
  <div class="row g-3">
    <div class="col-md-3">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted">Unpaid Payrolls</div>
            <div class="fs-3 fw-bold"><?php echo (int)$stats['unpaid']; ?></div>
          </div>
          <i class="fa-solid fa-wallet fa-2x text-warning"></i>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted">Paid Payrolls</div>
            <div class="fs-3 fw-bold"><?php echo (int)$stats['paid']; ?></div>
          </div>
          <i class="fa-solid fa-circle-check fa-2x text-success"></i>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted">Pay Periods</div>
            <div class="fs-3 fw-bold"><?php echo (int)$stats['periods']; ?></div>
          </div>
          <i class="fa-solid fa-calendar-days fa-2x text-primary"></i>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted">Attendance Logs (Today)</div>
            <div class="fs-3 fw-bold"><?php echo (int)$stats['todayLogs']; ?></div>
          </div>
          <i class="fa-solid fa-user-clock fa-2x text-info"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Orders Mini-KPIs (only if orders table exists) -->
  <?php if ($ordersTbl): ?>
  <div class="row g-3 mt-1">
    <div class="col-md-4">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted">Orders (<?php echo h(date('M Y', strtotime($firstDay))); ?>)</div>
            <div class="fs-3 fw-bold"><?php echo (int)$ordersStats['count_month']; ?></div>
          </div>
          <i class="fa-solid fa-basket-shopping fa-2x text-secondary"></i>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted">Pending (<?php echo h(date('M Y', strtotime($firstDay))); ?>)</div>
            <div class="fs-3 fw-bold"><?php echo (int)$ordersStats['pending_month']; ?></div>
          </div>
          <i class="fa-solid fa-hourglass-half fa-2x text-warning"></i>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted">Sales (<?php echo h(date('M Y', strtotime($firstDay))); ?>)</div>
            <div class="fs-3 fw-bold">₱<?php echo number_format((float)$ordersStats['sales_month'],2); ?></div>
          </div>
          <i class="fa-solid fa-peso-sign fa-2x text-success"></i>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <br>

  <!-- Two-column row: Recent Payroll + Recent Customer Orders -->
  <div class="row g-3">
    <!-- Recent payroll -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header fw-semibold">Recent Payroll</div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Period</th>
                <th>Status</th>
                <th class="text-end">Net Pay</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($recent): foreach($recent as $r): ?>
              <?php $badge = (strtolower((string)$r['status'])==='paid' ? 'success' : 'warning'); ?>
              <tr>
                <td><?php echo h($r['emp_name'] ?? ('#'.$r['employee_id'])); ?></td>
                <td class="small"><?php echo h(($r['pay_period_start']??'').' to '.($r['pay_period_end']??'')); ?></td>
                <td><span class="badge text-bg-<?php echo $badge; ?>"><?php echo h(ucfirst((string)$r['status'])); ?></span></td>
                <td class="text-end fw-semibold">₱<?php echo number_format((float)($r['net_pay'] ?? 0),2); ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="text-center text-muted">No payroll records yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer text-end">
          <a class="btn btn-sm btn-outline-secondary" href="/admin/payroll.php">View all</a>
        </div>
      </div>
    </div>

    <!-- Recent Customer Orders -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header fw-semibold">
          Recent Customer Orders <?php echo $ordersTbl ? '(' . h($ordersTbl) . ')' : ''; ?>
        </div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Date</th>
                <th>Status</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($ordersTbl && $ordersRecent): foreach($ordersRecent as $o): ?>
              <?php
                $cname = trim((string)($o['deliver_to'] ?? ''));
                if ($cname === '') $cname = 'Customer';
                $when  = !empty($o['odate']) ? date('Y-m-d H:i:s', strtotime($o['odate'])) : '—';
                $st    = (string)($o['st'] ?? '');
                $tot   = (float)($o['total'] ?? 0);
                $t = strtolower(trim($st));
                $cls = in_array($t,['fulfilled','completed','delivered','done']) ? 'success'
                     : (in_array($t,['approved','accepted','confirmed']) ? 'info'
                     : (in_array($t,['cancelled','canceled','void','rejected','failed']) ? 'danger'
                     : 'warning'));
              ?>
              <tr>
                <td><?php echo h($cname); ?></td>
                <td class="small"><?php echo h($when); ?></td>
                <td><span class="badge text-bg-<?php echo $cls; ?>"><?php echo h($st !== '' ? ucfirst($st) : '—'); ?></span></td>
                <td class="text-end fw-semibold">₱<?php echo number_format($tot,2); ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="text-center text-muted">
                <?php echo $ordersTbl ? 'No recent orders.' : 'Orders table not found.'; ?>
              </td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer text-end">
          <!-- Adjust the link below if you have an accountant-specific orders page -->
          <a class="btn btn-sm btn-outline-secondary" href="/admin/customer_orders.php">Open Orders</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$PAGE_FOOT_SCRIPTS = ""; // add page-specific JS if needed
include __DIR__ . '/layout_foot.php';
