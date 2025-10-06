<?php
$PAGE_TITLE = 'My Orders';
$CURRENT = 'customer_orders.php';
require_once __DIR__.'/inc/layout_head.php';

$uid = (int)($_SESSION['user_id'] ?? 0);

/* Pull orders for this customer */
$st = $conn->prepare("
  SELECT o.order_id, o.order_code, o.order_date, o.status, o.payment_status, o.total_amount
  FROM orders o
  WHERE o.customer_id=?
  ORDER BY o.order_id DESC
");
$st->bind_param('i',$uid);
$st->execute();
$rs = $st->get_result();
$orders = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
$st->close();

/* Count items per order */
$counts = [];
if ($orders){
  $ids = array_column($orders,'order_id');
  $in = implode(',', array_fill(0,count($ids),'?'));
  $types = str_repeat('i', count($ids));
  $q = $conn->prepare("SELECT order_id, SUM(quantity) AS qty FROM order_items WHERE order_id IN ($in) GROUP BY order_id");
  $q->bind_param($types, ...$ids);
  $q->execute();
  $r = $q->get_result();
  while($row=$r->fetch_assoc()){ $counts[(int)$row['order_id']] = (int)$row['qty']; }
  $q->close();
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">My Orders</h5>
</div>

<div class="card">
  <div class="card-body">
    <?php if (!$orders): ?>
      <div class="text-muted">You have no orders yet. <a href="shop.php">Start shopping</a>.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>#</th><th>Date</th><th>Status</th><th>Payment</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php foreach($orders as $o): ?>
              <?php
                $badge = 'secondary';
                $s = strtolower((string)$o['status']);
                if ($s==='pending') $badge='warning';
                if ($s==='completed') $badge='success';
                if ($s==='cancelled' || $s==='rejected') $badge='danger';
                $count = $counts[(int)$o['order_id']] ?? 0;
              ?>
              <tr>
                <td>
                  <div class="fw-semibold">#<?php echo (int)$o['order_id']; ?> <?php echo $o['order_code'] ? '· '.h($o['order_code']) : ''; ?></div>
                  <div class="small text-muted"><?php echo $count; ?> item(s)</div>
                </td>
                <td><?php echo h($o['order_date']); ?></td>
                <td><span class="badge text-bg-<?php echo $badge; ?>"><?php echo ucfirst($s ?: 'pending'); ?></span></td>
                <td><?php echo ucfirst(h($o['payment_status'] ?? 'unpaid')); ?></td>
                <td class="text-end">₱<?php echo number_format((float)$o['total_amount'],2); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__.'/inc/layout_foot.php'; ?>
