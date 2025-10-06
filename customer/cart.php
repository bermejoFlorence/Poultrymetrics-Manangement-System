<?php
// /customer/cart.php
declare(strict_types=1);

$PAGE_TITLE = 'My Cart';
$CURRENT    = 'cart.php';

require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/customer_shop_lib.php';

require_login($conn);
date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");

/* ---------- helpers ---------- */
function ensure_orders_schema(mysqli $c){
  @$c->query("
    CREATE TABLE IF NOT EXISTS `customer_orders` (
      `order_id` INT AUTO_INCREMENT PRIMARY KEY,
      `customer_id` INT NOT NULL,
      `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
      `grand_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  @$c->query("
    CREATE TABLE IF NOT EXISTS `customer_order_items` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `order_id` INT NOT NULL,
      `size_id` INT NOT NULL,
      `qty` INT NOT NULL,
      `price_per_tray` DECIMAL(10,2) NOT NULL,
      `subtotal` DECIMAL(10,2) NOT NULL,
      CONSTRAINT `fk_coi_order` FOREIGN KEY (`order_id`) REFERENCES `customer_orders`(`order_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}

function clamp_qty(int $qty, int $stock, int $maxpo): int {
  $qty = max(0, $qty);
  if ($maxpo > 0) $qty = min($qty, $maxpo);
  if ($stock > 0) $qty = min($qty, $stock);
  return $qty;
}

/** Safely apply posted qty to the session cart WITHOUT removing items */
function apply_posted_qty_safely(mysqli $conn, array $posted): void {
  $current = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
  if (!$current) return;

  // Build meta: stock & max per order
  $calc = cart_get_items($conn);
  $meta = [];
  foreach ($calc['items'] as $it) {
    $sid = (int)$it['size_id'];
    $meta[$sid] = array(
      'stock' => (int)$it['stock'],
      'maxpo' => (int)$it['max_per_order'],
    );
  }

  $new = $current;
  foreach ($current as $sid => $oldQty) {
    $sid = (int)$sid;

    // Accept both string/int keys from POST
    $raw = null;
    if (isset($posted[(string)$sid])) $raw = $posted[(string)$sid];
    elseif (isset($posted[$sid]))     $raw = $posted[$sid];

    // Fallback to previous if empty/non-numeric
    $q = is_numeric($raw) ? (int)$raw : (int)$oldQty;

    // Clamp to stock / max per order
    $stock   = isset($meta[$sid]['stock']) ? (int)$meta[$sid]['stock'] : 0;
    $maxpo   = isset($meta[$sid]['maxpo']) ? (int)$meta[$sid]['maxpo'] : 0;
    $clamped = clamp_qty($q, $stock, $maxpo);

    // If clamped <= 0 (e.g., transient blank/0), keep old
    if ($clamped <= 0) $clamped = (int)$oldQty;

    $new[$sid] = $clamped;
  }

  $_SESSION['cart'] = $new;
}

$uid = (int)($_SESSION['user_id'] ?? 0);

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(403); die('Bad CSRF'); }

  // Per-row remove via button name="remove_item" value="<sid>"
  if (isset($_POST['remove_item'])) {
    $sid = (int)$_POST['remove_item'];
    if ($sid > 0 && !empty($_SESSION['cart'][$sid])) unset($_SESSION['cart'][$sid]);
    flash_set('success', 'Item removed.');
    header('Location: cart.php'); exit;
  }

  // Manual update (if you add an Update button later)
  if (($_POST['action'] ?? '') === 'update_qty') {
    if (isset($_POST['qty']) && is_array($_POST['qty'])) apply_posted_qty_safely($conn, $_POST['qty']);
    flash_set('success', 'Changes saved.');
    header('Location: cart.php'); exit;
  }

  // Checkout: apply posted qty first, then place order
  if (($_POST['action'] ?? '') === 'checkout') {
    if (isset($_POST['qty']) && is_array($_POST['qty'])) apply_posted_qty_safely($conn, $_POST['qty']);

    $calc = cart_get_items($conn);
    if (!$calc['items']) {
      flash_set('warning', 'Your cart is empty.');
      header('Location: cart.php'); exit;
    }
    foreach ($calc['items'] as $it) {
      if ((float)$it['price'] <= 0) {
        flash_set('warning', 'Some items have no price; please remove them.');
        header('Location: cart.php'); exit;
      }
    }

    ensure_orders_schema($conn);

    $total = (float)$calc['totals']['amount'];
    $conn->begin_transaction();
    try {
      $st = $conn->prepare("INSERT INTO customer_orders (customer_id, status, grand_total, created_at) VALUES (?,?,?,NOW())");
      $status = 'pending';
      $st->bind_param('isd', $uid, $status, $total);
      $st->execute();
      $orderId = (int)$st->insert_id;
      $st->close();

      $sti = $conn->prepare("INSERT INTO customer_order_items (order_id,size_id,qty,price_per_tray,subtotal) VALUES (?,?,?,?,?)");
      foreach ($calc['items'] as $it) {
        $sid = (int)$it['size_id'];
        $q   = (int)$it['qty'];
        $pr  = (float)$it['price'];
        $sub = (float)$it['subtotal'];
        $sti->bind_param('iiidd', $orderId, $sid, $q, $pr, $sub);
        $sti->execute();

        // Optional: stock deduction
        @$conn->query("UPDATE egg_stock SET trays_on_hand = GREATEST(trays_on_hand - ".(int)$q.", 0) WHERE size_id = ".(int)$sid);
      }
      $sti->close();

      $conn->commit();
      unset($_SESSION['cart']);
      flash_set('success', 'Order placed! Thank you.');
      header('Location: orders.php'); exit;

    } catch (Throwable $e) {
      $conn->rollback();
      flash_set('danger', 'Checkout failed. Please try again.');
      header('Location: cart.php'); exit;
    }
  }
}

/* ---------- Load items ---------- */
$calc = cart_get_items($conn);
$items = $calc['items'];
$tot   = $calc['totals'];

include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .cart-thumb{width:60px;height:60px;border-radius:10px;object-fit:cover;border:1px solid #e5e7eb;background:#f3f4f6}
  .small-muted{color:#94a3b8}
</style>

<?php if ($f = flash_pop()): ?>
  <div id="flash" data-type="<?= h($f['t']) ?>" data-msg="<?= h($f['m']) ?>"></div>
<?php endif; ?>

<div class="container-fluid py-2">
  <h5 class="mb-3">My Cart</h5>

  <?php if (!$items): ?>
    <div class="alert alert-info">Your cart is empty. <a href="shop.php">Go to shop</a>.</div>
  <?php else: ?>
  <!-- SINGLE unified form: quantities + remove + checkout -->
  <form method="post" class="mb-3" id="cartForm">
    <?= csrf_field() ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:60px"></th>
            <th>Item</th>
            <th class="text-end">Price</th>
            <th style="width:140px">Qty</th>
            <th class="text-end">Subtotal</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <?php
            $sid = (int)$it['size_id'];
            $img = (string)($it['img_url'] ?? '');
            $isHttp = preg_match('~^https?://~i', $img);
            $isAbs  = ($img !== '' && $img[0] === '/');
            $src = $img ? ($isHttp || $isAbs ? $img : '../' . $img) : '';
          ?>
          <tr>
            <td>
              <?php if ($src): ?>
                <img class="cart-thumb" src="<?= h($src) ?>" alt="">
              <?php else: ?>
                <div class="cart-thumb d-flex align-items-center justify-content-center text-muted">
                  <i class="fa fa-image"></i>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold"><?= h($it['name']) ?></div>
              <div class="small-muted">Stock: <?= (int)$it['stock'] ?> · Code: <?= h($it['code']) ?></div>
              <?php if ((int)$it['max_per_order'] > 0): ?>
                <div class="small text-muted">Max per order: <?= (int)$it['max_per_order'] ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end">₱<?= number_format((float)$it['price'], 2) ?></td>
            <td>
              <input
                type="number"
                class="form-control form-control-sm qty-input text-end"
                name="qty[<?= $sid ?>]"
                value="<?= (int)$it['qty'] ?>"
                data-prev="<?= (int)$it['qty'] ?>"
                min="1"
                max="<?= (int)($it['max_per_order'] ?: $it['stock'] ?: 9999) ?>">
            </td>
            <td class="text-end">₱<?= number_format((float)$it['subtotal'], 2) ?></td>
            <td class="text-end">
              <!-- Single-form remove: clicked button carries the row's SID -->
              <button class="btn btn-sm btn-outline-danger" title="Remove"
                      type="submit" name="remove_item" value="<?= $sid ?>">
                <i class="fa fa-xmark"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="2">Totals</th>
            <th class="text-end"><?= (int)$tot['qty'] ?> trays</th>
            <th></th>
            <th class="text-end">₱<?= number_format((float)$tot['amount'], 2) ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="d-flex gap-2 justify-content-between">
      <div><!-- no Save/Edit/Clear buttons --></div>
      <div>
        <!-- Checkout submits THIS form with all qty inputs -->
        <input type="hidden" name="action" value="checkout">
        <button class="btn btn-dark" type="submit">
          <i class="fa fa-check me-1"></i> Checkout
        </button>
      </div>
    </div>
  </form>
  <?php endif; ?>
</div>

<script>
  // Keep only digits while typing; we submit only when Checkout is pressed (single form)
  (function(){
    document.querySelectorAll('.qty-input').forEach(function(i){
      i.addEventListener('input', function(){
        i.value = i.value.replace(/[^0-9]/g,'');
      });
    });
  })();
</script>

<?php include __DIR__ . '/inc/layout_foot.php';
