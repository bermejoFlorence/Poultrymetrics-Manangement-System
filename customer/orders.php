<?php
// /customer/orders.php — Customer Orders (with cancel)
declare(strict_types=1);

$PAGE_TITLE = 'My Orders';
$CURRENT    = 'orders.php';

require_once __DIR__ . '/inc/common.php'; // session, $conn, helpers (h(), flash_*, csrf_*, require_login, current_user)
date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");

// Require login
require_login($conn);
$USER = current_user($conn);
$uid  = (int)($_SESSION['user_id'] ?? 0);

// Small helpers
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
function tableExists(mysqli $c, string $n): bool {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
  if(!$st=$c->prepare($sql)) return false; $st->bind_param('s',$n); $st->execute(); $st->store_result(); $ok=$st->num_rows>0; $st->close(); return $ok;
}
function firstExistingCol(mysqli $c, string $t, array $cands){ foreach($cands as $x){ $stmt=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"); $stmt->bind_param('ss',$t,$x); $stmt->execute(); $stmt->store_result(); $ok=$stmt->num_rows>0; $stmt->close(); if($ok) return $x; } return null; }
function peso($n){ return '₱'.number_format((float)$n,2); }
function status_label($s){
  $t=strtolower((string)$s);
  if (in_array($t,['fulfilled','completed','delivered','done'])) return 'Done';
  if (in_array($t,['approved','accepted','confirmed'])) return 'Approved';
  if (in_array($t,['cancelled','canceled','void','rejected','failed'])) return 'Cancelled';
  if (in_array($t,['pending','placed','created','new','processing','awaiting'])) return 'Placed';
  return ucfirst((string)$s);
}
function badge_class($s){
  $t=strtolower((string)$s);
  if (in_array($t,['pending','placed','created','new','processing','awaiting'])) return 'warning';
  if (in_array($t,['approved','accepted','confirmed'])) return 'info';
  if (in_array($t,['fulfilled','completed','delivered','done'])) return 'success';
  if (in_array($t,['cancelled','canceled','void','rejected','failed'])) return 'danger';
  return 'secondary';
}
function cancellable($s){
  $t=strtolower((string)$s);
  return in_array($t,['pending','placed','created','new','processing','awaiting'], true);
}

/* Detect tables */
$T_ORDERS = tableExists($conn,'customer_orders') ? 'customer_orders' : (tableExists($conn,'orders') ? 'orders' : null);
if (!$T_ORDERS) { http_response_code(500); die('Orders table not found'); }

/* Column map */
$om = [
  'id'       => firstExistingCol($conn,$T_ORDERS,['order_id','id','oid']),
  'user'     => firstExistingCol($conn,$T_ORDERS,['customer_id','user_id','uid','account_id','users_id']),
  'created'  => firstExistingCol($conn,$T_ORDERS,['created_at','created','order_date','date_added','placed_at','date']),
  'status'   => firstExistingCol($conn,$T_ORDERS,['status','state','order_status']),
  'total'    => firstExistingCol($conn,$T_ORDERS,['grand_total','total','amount','total_amount']),
  'payment'  => firstExistingCol($conn,$T_ORDERS,['payment_method','payment','payment_status']),
];

/* Cancel action (POST) — use shared csrf_ok() & flash_*() from common.php */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='cancel_order') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(400); die('Bad CSRF'); }
  $oid = (int)($_POST['order_id'] ?? 0);
  if ($oid<=0 || !$om['id'] || !$om['user'] || !$om['status']) { flash_set('danger','Invalid order.'); header('Location: orders.php'); exit; }

  // Verify ownership + status
  $sql = "SELECT `{$om['status']}` AS st FROM `{$T_ORDERS}` WHERE `{$om['id']}`=? AND `{$om['user']}`=? LIMIT 1";
  $st=$conn->prepare($sql); $st->bind_param('ii',$oid,$uid); $st->execute(); $st->bind_result($stVal);
  if (!$st->fetch()) { $st->close(); flash_set('danger','Order not found.'); header('Location: orders.php'); exit; }
  $st->close();

  if (!cancellable($stVal)) { flash_set('warning','This order can no longer be cancelled.'); header('Location: orders.php'); exit; }

  // Cancel → set to "cancelled"
  $new='cancelled';
  $up=$conn->prepare("UPDATE `{$T_ORDERS}` SET `{$om['status']}`=? WHERE `{$om['id']}`=? AND `{$om['user']}`=?");
  $up->bind_param('sii',$new,$oid,$uid); $ok=$up->execute(); $up->close();
  flash_set($ok?'success':'danger', $ok?'Order cancelled.':'Failed to cancel order.');
  header('Location: orders.php'); exit;
}

/* Fetch my orders */
$page = max(1,(int)($_GET['page'] ?? 1)); $per=10; $off=($page-1)*$per;
$sel = "SELECT `{$om['id']}` AS oid,
               ".($om['created'] ? "`{$om['created']}`" : "NULL")." AS odate,
               ".($om['status']  ? "`{$om['status']}`"  : "NULL")." AS st,
               ".($om['total']   ? "`{$om['total']}`"   : "NULL")." AS total,
               ".($om['payment'] ? "`{$om['payment']}`" : "NULL")." AS pay
        FROM `{$T_ORDERS}`
        WHERE `{$om['user']}`=?
        ORDER BY ".($om['created']?"`{$om['created']}` DESC,":"")."`{$om['id']}` DESC
        LIMIT ? OFFSET ?";
$st=$conn->prepare($sel); $st->bind_param('iii',$uid,$per,$off); $st->execute();
$rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$st=$conn->prepare("SELECT COUNT(*) FROM `{$T_ORDERS}` WHERE `{$om['user']}`=?"); $st->bind_param('i',$uid); $st->execute();
$cnt = (int)($st->get_result()->fetch_row()[0] ?? 0); $st->close();
$pages = max(1,(int)ceil($cnt/$per));

include __DIR__ . '/inc/layout_head.php';
?>
<style>
  /* Keep modals atop sidebar/header if you add any */
  .modal-backdrop { z-index: 1200 !important; }
  .modal { z-index: 1210 !important; }
</style>

<?php if ($f=flash_pop()): ?>
  <div id="flash" data-type="<?php echo h($f['t']); ?>" data-msg="<?php echo h($f['m']); ?>"></div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><strong>My Orders</strong></div>
  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>Status</th>
          <th>Payment</th>
          <th class="text-end">Total</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows): foreach($rows as $r):
        $oid=(int)$r['oid'];
        $when = $r['odate'] ? date('Y-m-d H:i', strtotime($r['odate'])) : '—';
        $stx  = (string)($r['st'] ?? '');
        $badge = badge_class($stx);
        $canCancel = cancellable($stx);
      ?>
        <tr>
          <td><?php echo $oid; ?></td>
          <td><?php echo h($when); ?></td>
          <td><span class="badge text-bg-<?php echo $badge; ?>"><?php echo h(status_label($stx)); ?></span></td>
          <td><?php echo h((string)($r['pay'] ?? '')); ?></td>
          <td class="text-end"><?php echo peso((float)($r['total'] ?? 0)); ?></td>
          <td class="text-end">
            <?php if ($canCancel): ?>
              <form method="post" class="d-inline js-cancel">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" value="<?php echo $oid; ?>">
                <button class="btn btn-sm btn-outline-danger">
                  <i class="fa-solid fa-ban me-1"></i> Cancel
                </button>
              </form>
            <?php else: ?>
              <button class="btn btn-sm btn-outline-secondary" disabled><i class="fa-regular fa-circle-check me-1"></i> Finalized</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">You don’t have any orders yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="small text-muted">Showing <?php echo count($rows); ?> of <?php echo (int)$cnt; ?></span>
    <ul class="pagination pagination-sm mb-0">
      <?php for($i=1;$i<=$pages;$i++): ?>
        <li class="page-item <?php echo $i===$page?'active':''; ?>">
          <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </div>
</div>

<script>
  // Confirm cancel (uses themedSwal if your layout provides it)
  document.querySelectorAll('form.js-cancel').forEach(function(f){
    f.addEventListener('submit', function(e){
      e.preventDefault();
      if (typeof themedSwal === 'function') {
        themedSwal({
          title:'Cancel order?',
          text:'Are you sure you want to cancel this order?',
          icon:'warning',
          showCancelButton:true,
          confirmButtonText:'Yes, cancel',
          cancelButtonText:'No'
        }).then(function(r){ if(r.isConfirmed) f.submit(); });
      } else if (confirm('Cancel this order?')) {
        f.submit();
      }
    });
  });
</script>
<?php include __DIR__ . '/inc/layout_foot.php';
