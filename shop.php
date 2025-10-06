<?php
// shop.php — Public shop listing page (uniform card size, egg-inventory aligned)
// Now reads product display from `products` (display_name, img_url, visible)
// Falls back to egg_sizes fields when product row is absent.
// Silently ensures/updates `products` schema and seeds rows from egg_sizes.

include __DIR__ . '/config.php';
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

/* ---------------- Inputs ---------------- */
$limit = 12; // cards per page
$page  = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1,(int)$_GET['page']) : 1;
$start = ($page - 1) * $limit;
$q     = trim($_GET['q'] ?? '');  // search by size label / display name

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $c, string $table): bool {
  $t = $c->real_escape_string($table);
  if ($r = $c->query("SHOW TABLES LIKE '{$t}'")) { $ok = (bool)$r->num_rows; $r->free(); return $ok; }
  return false;
}
function col_exists(mysqli $c, string $table, string $col): bool {
  $t = $c->real_escape_string($table); $co= $c->real_escape_string($col);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$co}'";
  if ($r = $c->query($sql)) { $ok = (bool)$r->num_rows; $r->free(); return $ok; }
  return false;
}
function detect_price_sql(mysqli $conn): string {
  if (table_exists($conn,'v_current_prices')) {
    return " (SELECT cp.price_per_tray FROM v_current_prices cp WHERE cp.size_id=z.size_id) ";
  }
  return " (SELECT p.price_per_tray
             FROM pos_prices p
            WHERE p.size_id=z.size_id
              AND (p.effective_to IS NULL OR p.effective_to > NOW())
            ORDER BY p.effective_from DESC
            LIMIT 1) ";
}
function detect_photo_col(mysqli $conn): ?string {
  foreach (['image_path','photo','img_path','image','picture'] as $cand) {
    if (col_exists($conn,'egg_sizes',$cand)) return $cand;
  }
  return null;
}

/* Prefer product img_url; if none, try inventory image_path. Accept http/https/absolute paths.
   For relative paths, return as-is (web server should serve them). */
function resolve_image_url(?string $p_img_url, ?string $z_image_path): string {
  $cand = $p_img_url ?: $z_image_path;
  if (!$cand) return '';
  if (preg_match('~^https?://~i', $cand)) return $cand;     // external
  if ($cand[0] === '/') return $cand;                       // absolute web path
  // Otherwise, relative to app root; return as-is (theme expects web path)
  return $cand;
}

/* ---------------- Ensure source tables ---------------- */
if (!table_exists($conn,'egg_sizes')) {
  die('<!DOCTYPE html><html><body style="font-family:system-ui;margin:2rem"><div class="alert alert-danger">Table <code>egg_sizes</code> not found. Please create/populate your egg inventory.</div></body></html>');
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
  // Add any missing columns (safe no-op if exist)
  $needed = [
    ['size_id',"INT NULL UNIQUE"],
    ['visible',"TINYINT(1) NOT NULL DEFAULT 1"],
    ['display_name',"VARCHAR(255) NOT NULL DEFAULT ''"],
    ['short_desc',"VARCHAR(500) NULL"],
    ['img_url',"VARCHAR(255) NULL"],
    ['sort_order',"INT NOT NULL DEFAULT 0"],
    ['featured',"TINYINT(1) NOT NULL DEFAULT 0"],
    ['max_per_order',"INT NOT NULL DEFAULT 0"],
    ['updated_at',"DATETIME NULL"],
  ];
  foreach ($needed as [$col,$ddl]) {
    if (!col_exists($conn,'products',$col)) {
      @$conn->query("ALTER TABLE `products` ADD COLUMN `{$col}` {$ddl}");
    }
  }
  // Seed rows for each egg size
  @$conn->query("
    INSERT IGNORE INTO `products` (size_id, visible, display_name, short_desc, img_url, sort_order, featured, max_per_order, updated_at)
    SELECT z.size_id, 1, COALESCE(z.label, CONCAT('Size ', z.size_id)), NULL, NULL,
           COALESCE(z.sort_order, z.size_id), 0, 0, NOW()
    FROM egg_sizes z
    LEFT JOIN products p ON p.size_id=z.size_id
    WHERE p.size_id IS NULL
  ");
}

/* ---------------- Price + image columns ---------------- */
$priceExpr = detect_price_sql($conn);
$PHOTO_COL = detect_photo_col($conn);

/* ---------------- WHERE (prepared) ---------------- */
$where = [];
$types = '';
$args  = [];

// search against label and display_name (if products exists)
if ($q !== '') {
  if (table_exists($conn,'products')) {
    $where[] = "(z.label LIKE CONCAT('%', ?, '%') OR p.display_name LIKE CONCAT('%', ?, '%'))";
    $types  .= 'ss';
    $args[]  = $q; $args[] = $q;
  } else {
    $where[] = "z.label LIKE CONCAT('%', ?, '%')";
    $types  .= 's';
    $args[]  = $q;
  }
}

// show only published products if a products row exists; otherwise show (fallback)
if (table_exists($conn,'products')) {
  $where[] = "(p.visible = 1 OR p.size_id IS NULL)";
}

$wsql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------------- Count total (with joins/filters) ---------------- */
$joinP = table_exists($conn,'products') ? "LEFT JOIN products p ON p.size_id=z.size_id" : "";
$sqlCount = "SELECT COUNT(*) AS c FROM egg_sizes z $joinP $wsql";

$stc = $conn->prepare($sqlCount);
if ($types !== '') $stc->bind_param($types, ...$args);
$stc->execute();
$resC = $stc->get_result();
$total = 0;
if ($resC) { $row = $resC->fetch_assoc(); $total = (int)($row['c'] ?? 0); }
$stc->close();

$totalPages = max(1,(int)ceil($total/$limit));
if ($page > $totalPages) { $page = $totalPages; $start = ($page - 1) * $limit; }

/* ---------------- Fetch page ---------------- */
$items = [];
$photoSelect = $PHOTO_COL ? ", z.`{$PHOTO_COL}` AS z_image_path" : ", NULL AS z_image_path";
$orderBy = "ORDER BY COALESCE(p.sort_order, z.sort_order, z.size_id), z.size_id";
$joinK   = $HAS_STOCK ? "LEFT JOIN egg_stock k ON k.size_id = z.size_id" : "";
$joinP   = table_exists($conn,'products') ? "LEFT JOIN products p ON p.size_id = z.size_id" : "";
$qtyExpr = $HAS_STOCK ? "COALESCE(k.trays_on_hand,0)" : "0";

$sql = "SELECT
          z.size_id,
          z.label AS inv_label,
          COALESCE(p.display_name, z.label) AS display_name,
          {$qtyExpr} AS qty,
          {$priceExpr} AS price,
          p.img_url    AS p_image_url
          {$photoSelect}
        FROM egg_sizes z
        $joinK
        $joinP
        $wsql
        $orderBy
        LIMIT ? OFFSET ?";

$st = $conn->prepare($sql);
if ($types !== '') {
  $types2 = $types.'ii';
  $args2  = $args; $args2[] = $limit; $args2[] = $start;
  $st->bind_param($types2, ...$args2);
} else {
  $st->bind_param('ii', $limit, $start);
}
$st->execute();
$rs = $st->get_result();
while ($rs && ($r = $rs->fetch_assoc())) {
  $r['size_id']     = (int)$r['size_id'];
  $r['qty']         = (int)($r['qty'] ?? 0);
  $r['price']       = is_null($r['price']) ? null : (float)$r['price'];
  $r['display_name']= (string)$r['display_name'];
  $r['inv_label']   = (string)$r['inv_label'];
  $r['p_image_url'] = $r['p_image_url'] ?? '';
  $r['z_image_path']= $r['z_image_path'] ?? '';
  // resolved image url (prefer product img_url)
  $r['image_url']   = resolve_image_url($r['p_image_url'], $r['z_image_path']);
  $items[] = $r;
}
$st->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>PoultryMetrics | Shop</title>
<link href="https://fonts.googleapis.com/css?family=Poppins:100,200,300,400,500,600,700,800,900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/bootstrap.min.css">
<link rel="stylesheet" href="assets/css/font-awesome.css">
<link rel="stylesheet" href="assets/css/style.css">
<style>
  /* ===== UNIFORM CARD SIZE ===== */
  :root{
    --card-h: 360px;
    --thumb-h: 160px;
  }
  .product-card{
    border:1px solid #e9ecef;
    border-radius:10px;
    overflow:hidden;
    transition:transform .2s ease, box-shadow .2s ease;
    display:flex; flex-direction:column;
    height: var(--card-h) !important;
    max-height: var(--card-h) !important;
    min-height: var(--card-h) !important;
  }
  .product-card:hover{ transform:translateY(-4px); box-shadow:0 10px 20px rgba(0,0,0,.12); }

  .product-thumb{
    position: relative;
    height: var(--thumb-h) !important;
    max-height: var(--thumb-h) !important;
    min-height: var(--thumb-h) !important;
    flex: 0 0 var(--thumb-h) !important;
    background:#f6f7f9 url('assets/images/placeholder.png') center/contain no-repeat;
    overflow:hidden;
  }
  .product-thumb img{
    position:absolute; inset:0;
    width:100% !important; height:100% !important;
    object-fit:contain !important; display:block !important;
  }
  .product-card .card-body{ display:flex; flex-direction:column; gap:.25rem; flex:1 1 auto; padding:.75rem .75rem .25rem; }
  .product-card .card-footer{ margin-top:auto; padding:.5rem .75rem; }

  .cat-chip{ display:inline-block; font-size:.75rem; padding:.18rem .5rem; background:#f1f3f5; color:#6c757d; border-radius:999px; }
  .price{ font-weight:700; color:#f5a425; }
  .stock-badge{ font-size:.75rem; }
  .main-button.is-compact a{ display:inline-block; transform:scale(.9); transform-origin:center; }
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
        <a class='menu-trigger'><span>Menu</span></a>
      </nav>
    </div></div>
  </div>
</header>

<!-- Banner -->
<section class="section section-bg" id="call-to-action" style="background-image: url('assets/images/banner-image-1-1920x500.jpg');">
  <div class="container">
    <div class="row"><div class="col-lg-10 offset-lg-1">
      <div class="cta-content text-center">
        <h2>Our Shop</h2>
        <p>Browse our latest egg trays</p>
      </div>
    </div></div>
  </div>
</section>
<br><br>

<!-- Filters -->
<section class="section">
  <div class="container">
    <div class="bg-white border rounded p-3 mb-3">
      <form class="row g-2 align-items-end" method="get" action="shop.php">
        <div class="col-md-6">
          <label class="form-label small text-muted">Search</label>
          <input class="form-control" name="q" placeholder="Search by name (e.g., Large, XL) …" value="<?php echo h($q); ?>">
        </div>
        <div class="col-md-6 text-md-end">
          <div class="main-button is-compact d-inline-block">
            <a href="#" onclick="this.closest('form').submit(); return false;">
              <i class="fa fa-search me-1"></i> Apply
            </a>
          </div>
          <button type="submit" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">Apply</button>
        </div>
      </form>
    </div>

    <!-- Grid -->
    <?php if (!$items): ?>
      <div class="alert alert-light border text-center">No products found<?php echo ($q!=='')?' for your search':''; ?>.</div>
    <?php else: ?>
      <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-4 g-3">
        <?php foreach($items as $it):
          $imgUrl = $it['image_url'];
          $qty    = (int)$it['qty'];
          $out    = ($qty <= 0);
          $low    = (!$out && $qty <= 10);
          $price  = $it['price'];
          $title  = $it['display_name']; // product display name (fallback to label)
        ?>
        <div class="col">
          <div class="card product-card">
            <div class="product-thumb">
              <?php if ($imgUrl !== ''): ?>
                <img src="<?php echo h($imgUrl); ?>"
                     alt="<?php echo h($title); ?>"
                     loading="lazy"
                     onerror="this.remove()">
              <?php endif; ?>
            </div>

            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start">
                <h6 class="card-title mb-1"><?php echo h($title); ?></h6>
                <span class="badge text-bg-<?php echo $out?'danger':($low?'warning text-dark':'success'); ?> stock-badge">
                  <?php echo $out ? 'Out of stock' : ($low ? 'Low stock' : 'In stock'); ?>
                </span>
              </div>

              <div class="mb-1"><span class="cat-chip">Eggs</span></div>

              <div class="mt-auto d-flex justify-content-between align-items-center">
                <div class="price">
                  <?php echo is_null($price) ? '—' : '₱'.number_format((float)$price,2); ?> / tray
                </div>
                <div class="main-button is-compact d-inline-block">
                  <a href="shop-details.php?size_id=<?php echo (int)$it['size_id']; ?>">View Details</a>
                </div>
              </div>
            </div>

            <div class="card-footer small text-muted">
              <?php echo $out ? '—' : ($qty.' tray(s) left'); ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation">
          <ul class="pagination justify-content-center mt-3">
            <?php
              $qsBase = http_build_query(array_filter(['q'=>$q]));
              $base   = 'shop.php?'.($qsBase ? $qsBase.'&' : '');
            ?>
            <?php if ($page > 1): ?>
              <li class="page-item"><a class="page-link" href="<?php echo $base.'page='.($page-1); ?>">&laquo;</a></li>
            <?php endif; ?>
            <?php for ($i=1; $i<=$totalPages; $i++): ?>
              <li class="page-item <?php echo ($i==$page)?'active':''; ?>">
                <a class="page-link" href="<?php echo $base.'page='.$i; ?>"><?php echo $i; ?></a>
              </li>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
              <li class="page-item"><a class="page-link" href="<?php echo $base.'page='.($page+1); ?>">&raquo;</a></li>
            <?php endif; ?>
          </ul>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
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
<script src="assets/js/waypoints.min.js"></script>
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
