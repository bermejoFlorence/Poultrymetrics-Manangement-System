<?php
// customer/shop_details.php — Egg-inventory aligned product page (by size_id)
$PAGE_TITLE = 'Product';
$CURRENT    = 'shop.php';

require_once __DIR__ . '/inc/common.php';
include __DIR__ . '/layout_head.php';

mysqli_report(MYSQLI_REPORT_OFF);
@$conn->query("SET time_zone = '+08:00'");

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/* ---------------- Helpers ---------------- */
function table_exists(mysqli $c, string $t): bool {
  if (function_exists('tableExists')) return tableExists($c,$t);
  $t=$c->real_escape_string($t);
  $r=@$c->query("SHOW TABLES LIKE '{$t}'");
  return $r && $r->num_rows>0;
}
function col_exists(mysqli $c, string $t, string $col): bool {
  if (function_exists('colExists')) return colExists($c,$t,$col);
  $t=$c->real_escape_string($t); $col=$c->real_escape_string($col);
  $r=@$c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");
  return $r && $r->num_rows>0;
}
function detect_photo_col(mysqli $conn): ?string {
  foreach (['image_path','photo','img_path','image','picture'] as $cand) {
    if (col_exists($conn,'egg_sizes',$cand)) return $cand;
  }
  return null;
}
function egg_image_path(?string $image_path): string {
  $placeholder = '../assets/images/placeholder.png';
  if (!$image_path) return $placeholder;
  if (is_file($image_path) && @filesize($image_path)>0) return $image_path;
  $base = basename($image_path);
  foreach (['../uploads/egg_sizes/','../assets/uploads/egg_sizes/','../uploads/products/'] as $dir) {
    $p = $dir.$base;
    if (is_file($p) && @filesize($p)>0) return $p;
  }
  return $placeholder;
}
function current_price_expr(mysqli $conn): string {
  if (table_exists($conn,'v_current_prices')) {
    return " (SELECT cp.price_per_tray FROM v_current_prices cp WHERE cp.size_id=z.size_id) ";
  }
  // fallback: latest active row in pos_prices
  return " (SELECT pp.price_per_tray
             FROM pos_prices pp
            WHERE pp.size_id=z.size_id
              AND (pp.effective_to IS NULL OR pp.effective_to > NOW())
            ORDER BY pp.effective_from DESC
            LIMIT 1) ";
}

/* ---------------- Inputs ---------------- */
$size_id = isset($_GET['size_id']) ? (int)$_GET['size_id'] : 0;
if ($size_id <= 0) {
  echo '<div class="container-fluid"><div class="row"><div class="col-12 col-lg-10 col-xl-9 mx-auto mt-3">
        <div class="alert alert-warning">No product selected.</div></div></div></div>';
  include __DIR__ . '/layout_foot.php'; exit;
}

/* Map legacy success flags into flash */
if ((isset($_GET['added']) && $_GET['added'] === '1') || (isset($_GET['ordered']) && $_GET['ordered'] === '1')) {
  $n = isset($_GET['qty']) ? (int)$_GET['qty'] : 1;
  $_SESSION['flash_type'] = 'success';
  $_SESSION['flash_msg']  = ($n ?: 1).' item(s) added to your cart.';
}

/* ---------------- Fetch egg item ---------------- */
if (!table_exists($conn,'egg_sizes')) {
  echo '<div class="container-fluid"><div class="row"><div class="col-12 col-lg-10 col-xl-9 mx-auto mt-3">
        <div class="alert alert-danger">egg_sizes table not found.</div></div></div></div>';
  include __DIR__ . '/layout_foot.php'; exit;
}
$HAS_STOCK  = table_exists($conn,'egg_stock');
$PHOTO_COL  = detect_photo_col($conn);
$PRICE_EXPR = current_price_expr($conn);

$photoSel = $PHOTO_COL ? ", z.`{$PHOTO_COL}` AS image_path" : ", NULL AS image_path";
$stockSel = $HAS_STOCK ? "COALESCE(s.trays_on_hand,0)" : "0";

$sql = "SELECT
          z.size_id,
          z.label,
          {$PRICE_EXPR} AS price,
          {$stockSel} AS qty
          {$photoSel}
        FROM egg_sizes z
        ".($HAS_STOCK ? "LEFT JOIN egg_stock s ON s.size_id=z.size_id" : "")."
        WHERE z.size_id = ?
        LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param('i',$size_id);
$st->execute();
$res = $st->get_result();
$item = $res ? $res->fetch_assoc() : null;
$st->close();

if (!$item) {
  echo '<div class="container-fluid"><div class="row"><div class="col-12 col-lg-10 col-xl-9 mx-auto mt-3">
        <div class="alert alert-warning">Product not found.</div></div></div></div>';
  include __DIR__ . '/layout_foot.php'; exit;
}

$label = (string)$item['label'];
$price = (float)($item['price'] ?? 0);
$stock = (int)($item['qty'] ?? 0);
$out   = $stock <= 0;
$low   = ($stock > 0 && $stock <= 10);
$img   = egg_image_path($item['image_path'] ?? null);

$returnTo = 'shop-details.php?size_id='.(int)$size_id;
?>
<style>
  .pm-wrap { padding: 8px 10px 14px 10px; }
  .hero{ border:1px solid #e9ecef; border-radius:12px; background:#fff; }
  .hero .imgbox{ height: 340px; background:#f6f7f9; border-radius:12px 0 0 12px; overflow:hidden }
  .hero .imgbox img{ width:100%; height:100%; object-fit:contain } /* contain for egg trays */
  .summary { padding:12px }
  .cat-chip{ display:inline-block; font-size:.75rem; padding:.18rem .5rem; background:#f1f3f5; color:#6c757d; border-radius:999px; margin-right:6px }
  .price-tag{ font-weight:800; color:#f5a425; font-size:1.2rem }
  .stock-badge{ border-radius:999px; padding:.35rem .6rem; font-size:.8rem }
  .desc-card{ border:1px solid #e9ecef; border-radius:10px; padding:10px; background:#fff }
  .qty-wrap{ display:flex; align-items:center; gap:8px }
  .qty-input{ width:110px; text-align:center }
  .qty-btn{ width:38px; height:38px; border-radius:8px; display:flex; align-items:center; justify-content:center }
  .btn-theme{ background:#f5a425; color:#fff; padding:10px 14px; border-radius:8px; font-weight:700; border:0 }
  .btn-theme:disabled{ opacity:.6 }
  .btn-outline-dark{ border-radius:8px }
  @media (max-width: 991.98px){ .hero .imgbox{ height:240px; border-radius:12px 12px 0 0 } }
</style>

<div class="container-fluid pm-wrap">
  <div class="row g-2">
    <div class="col-12 col-lg-10 col-xl-9 mx-auto">

      <?php include __DIR__ . '/inc/flash_alert.php'; ?>

      <div class="small mb-2">
        <a href="shop.php" class="text-muted" style="text-decoration:none"><i class="fa-solid fa-store me-1"></i>Shop</a>
        <span class="mx-1">/</span>
        <span class="text-muted" id="prodName"><?php echo h($label); ?></span>
      </div>

      <div class="hero p-2">
        <div class="row g-2">
          <!-- Image -->
          <div class="col-lg-6">
            <div class="imgbox">
              <img src="<?php echo h($img); ?>" alt="<?php echo h($label); ?>">
            </div>
          </div>

          <!-- Summary -->
          <div class="col-lg-6">
            <div class="summary">
              <span class="cat-chip">Eggs / Tray</span>
              <h3 class="mt-1 mb-1"><?php echo h($label); ?> Tray</h3>

              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <div class="price-tag">₱<span id="unitPrice"><?php echo number_format($price,2); ?></span> <span class="text-muted small">/ tray</span></div>
                <div class="text-muted small">
                  <?php if ($out): ?>
                    <span class="badge bg-danger stock-badge">Out of stock</span>
                  <?php elseif ($low): ?>
                    <span class="badge bg-warning text-dark stock-badge">Low stock (<?php echo $stock; ?>)</span>
                  <?php else: ?>
                    <?php echo $stock; ?> in stock
                  <?php endif; ?>
                </div>
              </div>

              <div class="desc-card mb-3">
                <div class="small text-muted mb-1">Description</div>
                <div>Fresh farm eggs sold by tray. Choose quantity below to see the total.</div>
              </div>

              <!-- Actions -->
              <div class="d-flex flex-column gap-2">
                <!-- Quantity stepper -->
                <div class="qty-wrap">
                  <button class="btn btn-outline-dark qty-btn" type="button" id="btnMinus" <?php echo $out?'disabled':''; ?>><i class="fa-solid fa-minus"></i></button>
                  <input type="number" class="form-control qty-input" id="qtyInput" value="1" min="1" <?php if ($low) echo 'max="'.(int)$stock.'"'; echo $out?' disabled':''; ?>>
                  <button class="btn btn-outline-dark qty-btn" type="button" id="btnPlus" <?php echo $out?'disabled':''; ?>><i class="fa-solid fa-plus"></i></button>
                  <div class="ms-auto fw-semibold">Total: ₱<span id="liveTotal"><?php echo number_format($price,2); ?></span></div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                  <!-- Add to Cart (egg) -->
                  <form class="d-flex" action="cart_add.php" method="post" id="addCartForm">
                    <input type="hidden" name="kind" value="egg">
                    <input type="hidden" name="size_id" value="<?php echo (int)$size_id; ?>">
                    <input type="hidden" name="qty" id="addQty" value="1">
                    <input type="hidden" name="return" value="<?php echo h($returnTo); ?>">
                    <input type="hidden" name="return_params" id="returnParamsAdd" value="">
                    <button class="btn btn-outline-dark" type="submit" <?php echo $out?'disabled':''; ?>>
                      <i class="fa fa-cart-plus me-1"></i> Add to Cart
                    </button>
                  </form>

                  <!-- Order Now -> checkout -->
                  <form action="cart_add.php" method="post" id="orderNowForm">
                    <input type="hidden" name="kind" value="egg">
                    <input type="hidden" name="size_id" value="<?php echo (int)$size_id; ?>">
                    <input type="hidden" name="qty" id="orderQty" value="1">
                    <input type="hidden" name="go" value="checkout">
                    <input type="hidden" name="return" value="<?php echo h($returnTo); ?>">
                    <input type="hidden" name="return_params" id="returnParamsOrder" value="">
                    <button class="btn btn-theme" type="submit" <?php echo $out?'disabled':''; ?>>
                      <i class="fa fa-bolt me-1"></i> Order Now
                    </button>
                  </form>
                </div>

                <div class="mt-1">
                  <a href="shop.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-chevron-left me-1"></i> Back to Shop</a>
                </div>
              </div>
            </div>
          </div>
        </div><!--/row-->
      </div><!--/hero-->

    </div>
  </div>
</div>

<script>
// Qty & total sync (vanilla)
(function(){
  const price = parseFloat(("<?php echo number_format($price,2,'.',''); ?>"));
  const low   = <?php echo $low ? 'true':'false'; ?>;
  const maxSt = <?php echo (int)$stock; ?>;

  const qtyInput = document.getElementById('qtyInput');
  const addQty   = document.getElementById('addQty');
  const orderQty = document.getElementById('orderQty');
  const liveTotal= document.getElementById('liveTotal');
  const btnMinus = document.getElementById('btnMinus');
  const btnPlus  = document.getElementById('btnPlus');

  const retAdd   = document.getElementById('returnParamsAdd');
  const retOrder = document.getElementById('returnParamsOrder');

  const clamp = (v) => {
    let n = parseInt(v,10);
    if (isNaN(n) || n < 1) n = 1;
    if (low && n > maxSt) n = maxSt;
    return n;
  };
  const sync = () => {
    const val = clamp(qtyInput.value);
    qtyInput.value = val;
    addQty.value   = val;
    orderQty.value = val;
    liveTotal.textContent = (price * val).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
    if (retAdd)   retAdd.value   = 'added=1&qty=' + encodeURIComponent(val);
    if (retOrder) retOrder.value = 'ordered=1&qty=' + encodeURIComponent(val);
  };

  qtyInput?.addEventListener('input', sync);
  qtyInput?.addEventListener('blur', sync);
  btnMinus?.addEventListener('click', () => { qtyInput.value = clamp((parseInt(qtyInput.value,10)||1) - 1); sync(); });
  btnPlus?.addEventListener('click',  () => { qtyInput.value = clamp((parseInt(qtyInput.value,10)||1) + 1); sync(); });
  sync();
})();
</script>

<?php
$PAGE_FOOT_SCRIPTS = "";
include __DIR__ . '/layout_foot.php';
