<?php
// customer/dashboard.php
declare(strict_types=1);
$PAGE_TITLE = 'My Dashboard';
$CURRENT    = 'customer_dashboard.php';

require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/customer_shop_lib.php';

date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");

// Gate: logged-in user (no redirect loop)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user_id'])) { http_response_code(403); die('Forbidden'); }

$uid = (int)($_SESSION['user_id'] ?? 0);

// Stats
$totalOrders = (int)scalar($conn, "SELECT COUNT(*) FROM customer_orders WHERE customer_id=?", [$uid], 'i');
$pending     = (int)scalar($conn, "SELECT COUNT(*) FROM customer_orders WHERE customer_id=? AND status IN ('pending','processing')", [$uid], 'i');
$spent       = (float)scalar($conn, "SELECT COALESCE(SUM(grand_total),0) FROM customer_orders WHERE customer_id=?", [$uid], 'i');

include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .kpi-card .v{ font-size:22px;font-weight:800 }
  .kpi-card .lbl{ color:#6b7280; font-size:.85rem }
</style>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card kpi-card">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div><div class="lbl">My Orders</div><div class="v"><?= (int)$totalOrders ?></div></div>
        <i class="fa fa-box-open text-muted"></i>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card kpi-card">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div><div class="lbl">Pending</div><div class="v"><?= (int)$pending ?></div></div>
        <i class="fa fa-hourglass-half text-muted"></i>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card kpi-card">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div><div class="lbl">Total Spent</div><div class="v">₱<?= number_format($spent,2) ?></div></div>
        <i class="fa fa-peso-sign text-muted"></i>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <h6 class="mb-1">Ready to buy?</h6>
          <div class="text-muted small">Browse fresh egg sizes and add trays to your cart.</div>
        </div>
        <a class="btn btn-dark" href="<?= h(($BASE ?? '').'/customer/shop.php') ?>">
          <i class="fa fa-store me-1"></i> Go to Shop
        </a>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php';
