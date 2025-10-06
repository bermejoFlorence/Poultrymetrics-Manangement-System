<?php
// shop-details.php — Egg size details (egg inventory aligned, products-aware)
// Tables: egg_sizes, egg_stock, v_current_prices|pos_prices, products
// Requires config.php ($conn) and your public theme assets.

include __DIR__ . '/config.php';
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $c, string $t): bool {
  $t = $c->real_escape_string($t);
  if ($r = $c->query("SHOW TABLES LIKE '{$t}'")) { $ok=(bool)$r->num_rows; $r->free(); return $ok; }
  return false;
}
function col_exists(mysqli $c, string $t, string $col): bool {
  $t = $c->real_escape_string($t);
  $col= $c->real_escape_string($col);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$col}'";
  if ($r = $c->query($sql)) { $ok=(bool)$r->num_rows; $r->free(); return $ok; }
  return false;
}
function detect_photo_col(mysqli $conn): ?string {
  foreach (['image_path','photo','img_path','image','picture'] as $cand) {
    if (col_exists($conn,'egg_sizes',$cand)) return $cand;
  }
  return null;
}
function current_price_sql(mysqli $conn): string {
  if (table_exists($conn,'v_current_prices')) {
    return " (SELECT cp.price_per_tray FROM v_current_prices cp WHERE cp.size_id = z.size_id) ";
  }
  return " (SELECT pz.price_per_tray
             FROM pos_prices pz
            WHERE pz.size_id = z.size_id
              AND (pz.effective_to IS NULL OR pz.effective_to > NOW())
            ORDER BY pz.effective_from DESC
            LIMIT 1) ";
}
/* Prefer product img_url; else inventory image path. Accept http/https/absolute/relative web paths. */
function resolve_image_url(?string $p_img_url, ?string $z_image_path): string {
  $cand = $p_img_url ?: $z_image_path;
  if (!$cand) return '';
  if (preg_match('~^https?://~i', $cand)) return $cand;  // external
  if ($cand[0] === '/') return $cand;                    // absolute
  // Keep relative as-is (served by web root), also try common uploads if you stored filenames only
  return $cand;
}

/* ---------------- Input ---------------- */
if (!isset($_GET['size_id']) || !ctype_digit($_GET['size_id'])) {
  http_response_code(400);
  die('Invalid item.');
}
$size_id = (int)$_GET['size_id'];

/* ---------------- Ensure base tables ---------------- */
if (!table_exists($conn,'egg_sizes')) {
  die('egg_sizes table not found.');
}
$HAS_STOCK = table_exists($conn,'egg_stock');

/* ---------------- Ensure/seed PRODUCTS (silent) ---------------- */
if (!table_exists($conn,'products')) {
  @$conn->query("
    CREATE TABLE `products` (
      `size_id` INT NULL UNIQUE,
      `visible` TINYINT(1) NOT NULL DEFAULT 1,
      `display_name` VARCHAR(255) NOT NULL DEFAULT '',
      `short_desc` VARCHAR(500) NULL,
      `img_url` VARCHAR(255) NULL,
      `sort_order` INT NOT NULL DEFAULT 0,
      `featured` TINYINT(1) NOT NULL DEFAULT 0,
      `max_per_order` INT NOT NULL DEFAULT 0,
      `updated_at` DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}
if (table_exists($conn,'products')) {
  foreach ([
    ['size_id',"INT NULL UNIQUE"],
    ['visible',"TINYINT(1) NOT NULL DEFAULT 1"],
    ['display_name',"VARCHAR(255) NOT NULL DEFAULT ''"],
    ['short_desc',"VARCHAR(500) NULL"],
    ['img_url',"VARCHAR(255) NULL"],
    ['sort_order',"INT NOT NULL DEFAULT 0"],
    ['featured',"TINYINT(1) NOT NULL DEFAULT 0"],
    ['max_per_order',"INT NOT NULL DEFAULT 0"],
    ['updated_at',"DATETIME NULL"],
  ] as [$c,$ddl]) {
    if (!col_exists($conn,'products',$c)) {
      @$conn->query("ALTER TABLE `products` ADD COLUMN `{$c}` {$ddl}");
    }
  }
  // Seed a row for this size if missing (so details page still works even if admin hasn't opened Products yet)
  @$conn->query("
    INSERT IGNORE INTO `products` (size_id, visible, display_name, short_desc, img_url, sort_order, featured, max_per_order, updated_at)
    SELECT z.size_id, 1, COALESCE(z.label, CONCAT('Size ', z.size_id)), NULL, NULL,
           COALESCE(z.sort_order, z.size_id), 0, 0, NOW()
    FROM egg_sizes z
    LEFT JOIN products p ON p.size_id=z.size_id
    WHERE p.size_id IS NULL
  ");
}

/* ---------------- Fetch ---------------- */
$PHOTO_COL = detect_photo_col($conn);
$priceExpr = current_price_sql($conn);

$photoSelect = $PHOTO_COL ? ", z.`{$PHOTO_COL}` AS z_image_path" : ", NULL AS z_image_path";
$joinK       = $HAS_STOCK ? "LEFT JOIN egg_stock s ON s.size_id = z.size_id" : "";
$joinP       = table_exists($conn,'products') ? "LEFT JOIN products p ON p.size_id = z.size_id" : "";

$sql = "SELECT
          z.size_id,
          z.label        AS inv_label,
          COALESCE(p.display_name, z.label) AS display_name,
          p.short_desc   AS short_desc,
          p.visible      AS p_visible,
          p.img_url      AS p_image_url,
          {$priceExpr}   AS price,
          ".($HAS_STOCK ? "COALESCE(s.trays_on_hand,0)" : "0")." AS qty
          {$photoSelect}
        FROM egg_sizes z
        $joinK
        $joinP
        WHERE z.size_id = ?
        LIMIT 1";

$st = $conn->prepare($sql);
$st->bind_param('i',$size_id);
$st->execute();
$res = $st->get_result();
if (!$res || $res->num_rows===0) {
  http_response_code(404);
  die('Item not found.');
}
$p = $res->fetch_assoc();
$st->close();

/* Hide if explicitly unpublished in products */
if (table_exists($conn,'products')) {
  if (isset($p['p_visible']) && (int)$p['p_visible'] === 0) {
    http_response_code(404);
    die('Item not available.');
  }
}

$title       = (string)($p['display_name'] ?? $p['inv_label'] ?? 'Egg Tray');
$short_desc  = trim((string)($p['short_desc'] ?? ''));
$price       = is_null($p['price']) ? null : (float)$p['price'];
$qty         = (int)($p['qty'] ?? 0);
$imgUrl      = resolve_image_url($p['p_image_url'] ?? '', $p['z_image_path'] ?? '');

/* ---------------- View ---------------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>PoultryMetrics | <?php echo h($title); ?></title>
<link href="https://fonts.googleapis.com/css?family=Poppins:100,200,300,400,500,600,700,800,900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/bootstrap.min.css">
<link rel="stylesheet" href="assets/css/font-awesome.css">
<link rel="stylesheet" href="assets/css/style.css">

<style>
/* Row container with subtle lift on hover */
.product-row{
  display:flex; flex-wrap:wrap;
  border:1px solid #e9ecef; border-radius:10px; overflow:hidden;
  transition:transform .25s ease, box-shadow .25s ease;
}
.product-row:hover{ transform:translateY(-3px); box-shadow:0 10px 20px rgba(0,0,0,.12); }

/* Fixed hero image box — no stretch */
.hero-thumb{
  position:relative; flex:1 1 50%;
  min-width:280px; height: 360px; /* adjust if you want taller */
  background:#f6f7f9 url('assets/images/placeholder.png') center/contain no-repeat;
}
.hero-thumb img{
  position:absolute; inset:0; width:100%; height:100%; object-fit:contain; display:block;
}

/* Details panel */
.down-content{
  flex:1 1 50%; min-width:280px;
  padding:28px; display:flex; flex-direction:column; justify-content:center;
}
.badge-stock{ font-size:.8rem; }
.price{ color:#f5a425; font-weight:700; }
</style>
</head>
<body>

<!-- Preloader -->
<div id="js-preloader" class="js-preloader">
  <div class="preloader-inner">
    <span class="dot"></span>
    <div class="dots"><span></span><span></span></div>
  </div>
</div>

<!-- Header -->
<header class="header-area header-sticky">
  <div class="container">
    <div class="row"><div class="col-12">
      <nav class="main-nav">
        <a href="index.php" class="logo">Poultry<em>Metrics</em></a>
        <ul class="nav">
          <li><a href="index.php">Home</a></li>
          <li><a href="shop.php" class="active">Shop</a></li>
          <li><a href="about.html">About</a></li>
          <li><a href="contact.php">Contact</a></li>
          <li><a href="register.php">Register</a></li>
          <li><a href="login.php">Login</a></li>
        </ul>
        <a class="menu-trigger"><span>Menu</span></a>
      </nav>
    </div></div>
  </div>
</header>

<!-- Banner -->
<section class="section section-bg" id="call-to-action" style="background-image:url('assets/images/banner-image-1-1920x500.jpg');">
  <div class="container">
    <div class="row"><div class="col-lg-10 offset-lg-1">
      <div class="cta-content text-center">
        <h2><?php echo h($title); ?></h2>
        <p>Detailed product information</p>
      </div>
    </div></div>
  </div>
</section>
<br><br>

<!-- Details -->
<section class="section" id="product-details">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-10">
        <div class="product-row">
          <div class="hero-thumb">
            <?php if ($imgUrl !== ''): ?>
              <img src="<?php echo h($imgUrl); ?>" alt="<?php echo h($title); ?>" loading="lazy" onerror="this.remove()">
            <?php endif; ?>
          </div>
          <div class="down-content">
            <div class="d-flex align-items-start justify-content-between gap-3">
              <h4 class="mb-1"><?php echo h($title); ?> Tray</h4>
              <span class="badge badge-stock text-bg-<?php
                echo $qty<=0?'danger':($qty<=10?'warning text-dark':'success'); ?>">
                <?php echo $qty<=0?'Out of stock':($qty<=10?'Low stock':'In stock'); ?>
              </span>
            </div>

            <div class="mb-2 price">
              <?php echo is_null($price) ? '—' : '₱'.number_format($price,2); ?> / tray
            </div>

            <?php if ($short_desc !== ''): ?>
              <p class="text-muted mb-2"><?php echo h($short_desc); ?></p>
            <?php else: ?>
              <p class="text-muted mb-2">Fresh farm eggs packaged by tray. Price and availability may change based on inventory and POS updates.</p>
            <?php endif; ?>

            <ul class="small text-muted mb-3">
              <li>Size: <strong><?php echo h($title); ?></strong></li>
              <li>Available stock: <strong><?php echo max(0,$qty); ?></strong> tray(s)</li>
            </ul>

            <div class="d-flex gap-2 flex-wrap">
              <div class="main-button">
                <a href="register.php"><i class="fa fa-shopping-cart"></i> Add to Cart</a>
              </div>
              <div class="main-button">
                <a href="shop.php">← Back to Shop</a>
              </div>
            </div>
          </div>
        </div><!-- /.product-row -->
      </div>
    </div>
  </div>
</section>

<!-- Footer -->
<footer>
  <div class="container">
    <div class="row"><div class="col-lg-12">
      <p>Copyright © <?php echo date('Y'); ?> PoultryMetrics</p>
    </div></div>
  </div>
</footer>

<!-- Scripts -->
<script src="assets/js/jquery-2.1.0.min.js"></script>
<script src="assets/js/popper.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/scrollreveal.min.js"></script>
script src="assets/js/waypoints.min.js"></script>
<script src="assets/js/jquery.counterup.min.js"></script>
<script src="assets/js/imgfix.min.js"></script>
<script src="assets/js/mixitup.js"></script>
<script src="assets/js/accordions.js"></script>
<script src="assets/js/custom.js"></script>

<!-- Preloader Navigation Logic -->
<script>
document.addEventListener("DOMContentLoaded", function () {
  const preloader = document.getElementById("js-preloader");
  function shouldIntercept(a){
    const href = a.getAttribute("href");
    if (!href || href.startsWith("#") || href.startsWith("javascript:")) return false;
    try {
      const url = new URL(href, window.location.href);
      if (url.origin !== window.location.origin) return false;
    } catch(e){ return false; }
    return true;
  }
  document.body.addEventListener("click", function(e){
    const a = e.target.closest("a");
    if (!a) return;
    if (a.target === "_blank" || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
    if (!shouldIntercept(a)) return;
    e.preventDefault();
    preloader.classList.remove("loaded");
    setTimeout(() => { window.location = a.href; }, 250);
  }, true);
  window.addEventListener("load", function () {
    setTimeout(() => preloader.classList.add("loaded"), 200);
  });
});
</script>

</body>
</html>
