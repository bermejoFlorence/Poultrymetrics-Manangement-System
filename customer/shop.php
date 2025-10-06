<?php
// /customer/shop.php
declare(strict_types=1);

$PAGE_TITLE = 'Shop';
$CURRENT    = 'shop.php';

require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/customer_shop_lib.php';

require_login($conn);
date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");

// --- POST: add to cart ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(403); die('Bad CSRF'); }

  $act = $_POST['action'] ?? '';
  if ($act === 'add_to_cart') {
    $sid = (int)($_POST['size_id'] ?? 0);
    $qty = max(0, (int)($_POST['qty'] ?? 0));
    if ($sid <= 0 || $qty <= 0) {
      flash_set('warning', 'Invalid product/quantity.');
      header('Location: shop.php'); exit;
    }

    // fetch limits (max_per_order, stock) and price presence
    $st = $conn->prepare("
      SELECT COALESCE(p.max_per_order,0) AS max_per_order,
             COALESCE(k.trays_on_hand,0) AS stock,
             (
               SELECT pr.price_per_tray
               FROM pos_prices pr
               WHERE pr.size_id = z.size_id
                 AND (pr.effective_to IS NULL OR pr.effective_to > NOW())
               ORDER BY pr.effective_from DESC
               LIMIT 1
             ) AS price_per_tray
      FROM egg_sizes z
      LEFT JOIN products  p ON p.size_id = z.size_id
      LEFT JOIN egg_stock k ON k.size_id = z.size_id
      WHERE z.size_id = ?
      LIMIT 1
    ");
    $st->bind_param('i', $sid);
    $st->execute();
    $limits = $st->get_result()->fetch_assoc(); $st->close();

    if (!$limits) {
      flash_set('warning', 'Product not found.');
      header('Location: shop.php'); exit;
    }
    $stock = (int)$limits['stock'];
    $maxpo = (int)$limits['max_per_order'];
    $price = $limits['price_per_tray'];

    if (is_null($price)) {
      flash_set('warning', 'This product is not orderable yet (no price set).');
      header('Location: shop.php'); exit;
    }
    if ($stock <= 0) {
      flash_set('warning', 'Out of stock.');
      header('Location: shop.php'); exit;
    }

    // clamp quantity by stock and max_per_order (if set)
    if ($maxpo > 0) $qty = min($qty, $maxpo);
    $qty = min($qty, $stock);

    if ($qty <= 0) {
      flash_set('warning', 'Quantity not allowed.');
      header('Location: shop.php'); exit;
    }

    cart_add($sid, $qty, $maxpo > 0 ? $maxpo : null);
    flash_set('success', 'Added to cart.');
    header('Location: cart.php'); exit;
  }
}

$prods = fetch_shop_products($conn);

include __DIR__ . '/inc/layout_head.php';
?>
<style>
.prod-card{border:none;border-radius:14px;box-shadow:0 8px 20px rgba(0,0,0,.06)}
.prod-card:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(0,0,0,.08)}
.prod-thumb{width:84px;height:84px;border-radius:12px;object-fit:cover;border:1px solid #e5e7eb;background:#f3f4f6}
.badge-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.12rem .5rem;border-radius:999px;border:1px solid #e9ecef;background:#f8fafc;font-size:.75rem}
.small-muted{color:#94a3b8;font-size:.85rem}
</style>

<?php if ($f = flash_pop()): ?>
  <div id="flash" data-type="<?= h($f['t']) ?>" data-msg="<?= h($f['m']) ?>"></div>
<?php endif; ?>

<div class="container-fluid py-2">
  <h5 class="mb-3">Shop</h5>

  <div class="row g-3">
    <?php if (!$prods): ?>
      <div class="col-12"><div class="alert alert-info mb-0">No products available right now.</div></div>
    <?php endif; ?>

    <?php foreach ($prods as $p): ?>
      <?php
        $sid   = (int)$p['size_id'];
        $name  = trim($p['display_name'] ?: $p['label']);
        $price = $p['price_per_tray'];
        $stock = (int)($p['trays_on_hand'] ?? 0);
        $img   = (string)($p['img_url'] ?: $p['image_path'] ?: '');
        $isHttp= preg_match('~^https?://~i',$img);
        $isAbs = ($img !== '' && $img[0]==='/');
        $preview = $img ? ($isHttp||$isAbs ? $img : '../'.$img) : '';
        $mpo   = (int)($p['max_per_order'] ?? 0);
        $disabled = ($stock<=0 || is_null($price));
      ?>
      <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
        <div class="card prod-card h-100">
          <div class="card-body">
            <div class="d-flex gap-3">
              <div>
                <?php if ($preview): ?>
                  <img class="prod-thumb" src="<?= h($preview) ?>" alt="">
                <?php else: ?>
                  <div class="prod-thumb d-flex align-items-center justify-content-center text-muted"><i class="fa fa-image"></i></div>
                <?php endif; ?>
              </div>
              <div class="flex-grow-1">
                <div class="d-flex align-items-center justify-content-between">
                  <strong><?= h($name) ?></strong>
                </div>
                <div class="small-muted mt-1">Stock: <strong><?= (int)$stock ?></strong> trays</div>
                <div class="mt-1"><?= is_null($price) ? '<span class="text-muted">—</span>' : '<span class="fw-semibold">₱'.number_format((float)$price,2).'</span>' ?></div>
              </div>
            </div>

            <form class="mt-3" method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="add_to_cart">
              <input type="hidden" name="size_id" value="<?= $sid ?>">
              <div class="input-group input-group-sm">
                <span class="input-group-text">Qty</span>
                <input type="number" name="qty" class="form-control" value="1" min="1" max="<?= $mpo>0? $mpo : $stock ?>" <?= $disabled?'disabled':'' ?>>
                <button class="btn btn-dark" <?= $disabled?'disabled':'' ?>>
                  <i class="fa fa-cart-plus me-1"></i>Add
                </button>
              </div>
              <?php if ($mpo>0): ?>
                <div class="form-text small">Max per order: <?= (int)$mpo ?></div>
              <?php endif; ?>
              <?php if (is_null($price)): ?>
                <div class="text-warning small mt-1">Price not set yet</div>
              <?php elseif ($stock<=0): ?>
                <div class="text-danger small mt-1">Out of stock</div>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php';
