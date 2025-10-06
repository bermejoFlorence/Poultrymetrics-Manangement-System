<?php
// ======================================================================
// /admin/products.php — Products (Inventory-style card UI)
// Mirrors egg_sizes; edit display name, description, publish, image.
// Removes KPIs and table columns (Low / F / Max / Sort / Image URL).
// ======================================================================

$PAGE_TITLE = 'Products';
require_once __DIR__ . '/inc/common.php'; // session, $conn, guards, helpers
@date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

$DEBUG = isset($_GET['debug']);

/* -------------------- helpers -------------------- */
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
function tableExists(mysqli $c, string $n): bool {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
  if(!$st=$c->prepare($sql)) return false; $st->bind_param('s',$n); $st->execute(); $st->store_result(); $ok=$st->num_rows>0; $st->close(); return $ok;
}
function colExists(mysqli $c, string $tbl, string $col): bool {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
  if(!$st=$c->prepare($sql)) return false; $st->bind_param('ss',$tbl,$col); $st->execute(); $st->store_result(); $ok=$st->num_rows>0; $st->close(); return $ok;
}

/* -------------------- constants -------------------- */
$TBL_SIZES  = 'egg_sizes';        // size_id, code, label, image_path, sort_order
$TBL_STOCK  = 'egg_stock';        // size_id, trays_on_hand, low_threshold
$TBL_PRICE  = 'pos_prices';       // size_id, price_per_tray, effective_from, effective_to
$TBL_SHOP   = 'products';         // shop config per size_id
$VIEW_PRICE = 'v_current_prices'; // optional

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t ?? ''); }

/* -------------------- ensure products table (and columns) -------------------- */
if (!tableExists($conn,$TBL_SHOP)) {
  @$conn->query("
    CREATE TABLE `{$TBL_SHOP}` (
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
if (tableExists($conn,$TBL_SHOP)) {
  if (!colExists($conn,$TBL_SHOP,'size_id')) { @$conn->query("ALTER TABLE `{$TBL_SHOP}` ADD COLUMN `size_id` INT NULL UNIQUE"); }
  foreach ([
    ['visible',"TINYINT(1) NOT NULL DEFAULT 1"],
    ['display_name',"VARCHAR(255) NOT NULL DEFAULT ''"],
    ['short_desc',"VARCHAR(500) NULL"],
    ['img_url',"VARCHAR(255) NULL"],
    ['sort_order',"INT NOT NULL DEFAULT 0"],
    ['featured',"TINYINT(1) NOT NULL DEFAULT 0"],
    ['max_per_order',"INT NOT NULL DEFAULT 0"],
    ['updated_at',"DATETIME NULL"],
  ] as [$col,$ddl]) if (!colExists($conn,$TBL_SHOP,$col)) { @$conn->query("ALTER TABLE `{$TBL_SHOP}` ADD COLUMN `{$col}` {$ddl}"); }
}

/* -------------------- seed products from egg_sizes -------------------- */
if (tableExists($conn,$TBL_SIZES) && tableExists($conn,$TBL_SHOP) && colExists($conn,$TBL_SHOP,'size_id')) {
  @$conn->query("
    INSERT IGNORE INTO `{$TBL_SHOP}` (size_id, visible, display_name, short_desc, img_url, sort_order, featured, max_per_order, updated_at)
    SELECT z.size_id, 1, COALESCE(z.label, CONCAT('Size ', z.size_id)), NULL, NULL,
           COALESCE(z.sort_order, z.size_id), 0, 0, NOW()
    FROM `{$TBL_SIZES}` z
    LEFT JOIN `{$TBL_SHOP}` p ON p.size_id = z.size_id
    WHERE p.size_id IS NULL
  ");
}

/* -------------------- ensure egg_stock rows (silent) -------------------- */
if (tableExists($conn,$TBL_SIZES) && tableExists($conn,$TBL_STOCK)) {
  @$conn->query("
    INSERT IGNORE INTO `{$TBL_STOCK}` (size_id, trays_on_hand, low_threshold)
    SELECT z.size_id, 0, 10
    FROM `{$TBL_SIZES}` z
    LEFT JOIN `{$TBL_STOCK}` k ON k.size_id = z.size_id
    WHERE k.size_id IS NULL
  ");
}

/* -------------------- uploads config -------------------- */
$FS_UPLOAD_DIR = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
$WEB_UPLOAD_REL = 'uploads/products'; // relative to app root
$ADMIN_PREFIX   = '../';              // admin → root
if (!is_dir($FS_UPLOAD_DIR)) { @mkdir($FS_UPLOAD_DIR, 0775, true); }

/* -------------------- POST actions -------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(403); die('Bad CSRF'); }
  $act = $_POST['action'] ?? '';

  if ($act==='save_row') {
    $sid=(int)($_POST['size_id']??0);
    if ($sid<=0) { $_SESSION['flash']=['type'=>'error','msg'=>'Invalid product.']; header('Location: products.php'); exit; }
    $visible = (int)($_POST['visible'] ?? 0) ? 1 : 0;
    $disp    = trim((string)($_POST['display_name'] ?? ''));
    $desc    = trim((string)($_POST['short_desc'] ?? ''));
    $st=$conn->prepare("UPDATE `{$TBL_SHOP}` SET visible=?, display_name=?, short_desc=?, updated_at=NOW() WHERE size_id=?");
    $st->bind_param('issi',$visible,$disp,$desc,$sid);
    $ok=$st->execute(); $st->close();
    $_SESSION['flash']=['type'=>$ok?'success':'warning','msg'=>$ok?'Saved.':'Save failed.'];
    header('Location: products.php'); exit;
  }

  if ($act==='upload_image') {
    $sid = (int)($_POST['size_id'] ?? 0);
    if ($sid<=0 || empty($_FILES['image_file']['name'])) { $_SESSION['flash']=['type'=>'warning','msg'=>'No image selected.']; header('Location: products.php'); exit; }
    $f = $_FILES['image_file'];
    if ($f['error'] !== UPLOAD_ERR_OK) { $_SESSION['flash']=['type'=>'warning','msg'=>'Upload error.']; header('Location: products.php'); exit; }
    $maxBytes = 3 * 1024 * 1024; if ($f['size'] > $maxBytes) { $_SESSION['flash']=['type'=>'warning','msg'=>'Image too large (max 3 MB).']; header('Location: products.php'); exit; }
    $fi = new finfo(FILEINFO_MIME_TYPE); $mime = $fi->file($f['tmp_name']) ?: ''; $allow = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($allow[$mime])) { $_SESSION['flash']=['type'=>'warning','msg'=>'Unsupported image type.']; header('Location: products.php'); exit; }
    $ext=$allow[$mime]; $fname = sprintf('%d_%s.%s', $sid, bin2hex(random_bytes(4)), $ext);
    $dest  = $FS_UPLOAD_DIR . DIRECTORY_SEPARATOR . $fname;
    if (!@move_uploaded_file($f['tmp_name'], $dest)) { $_SESSION['flash']=['type'=>'error','msg'=>'Failed to save image.']; header('Location: products.php'); exit; }
    $rel = $WEB_UPLOAD_REL.'/'.$fname; // e.g. uploads/products/abc.jpg
    $st=$conn->prepare("UPDATE `{$TBL_SHOP}` SET img_url=?, updated_at=NOW() WHERE size_id=?"); $st->bind_param('si',$rel,$sid); $ok=$st->execute(); $st->close();
    $_SESSION['flash']=['type'=>$ok?'success':'warning','msg'=>$ok?'Image updated.':'Image saved but DB update failed.'];
    header('Location: products.php'); exit;
  }

  if ($act==='toggle_publish') {
    $sid=(int)$_POST['size_id']; $vis=(int)($_POST['to']??0)?1:0;
    if ($sid>0) { $st=$conn->prepare("UPDATE `{$TBL_SHOP}` SET visible=?, updated_at=NOW() WHERE size_id=?"); $st->bind_param('ii',$vis,$sid); $st->execute(); $st->close(); }
    exit; // AJAX call returns empty 200
  }
}

/* -------------------- data loader (view → fallback) -------------------- */
function fetch_products(mysqli $conn, string $sizes, string $stock, string $shop, ?string $viewPrice, string $tblPrice): array {
  $rows=[]; $err=null; $sql='';

  $base = "SELECT z.size_id, z.code, z.label, z.image_path, z.sort_order,
                  k.trays_on_hand,
                  p.visible, p.display_name, p.short_desc, p.img_url";
  $joinK = " LEFT JOIN `{$stock}` k ON k.size_id = z.size_id ";
  $joinP = " LEFT JOIN `{$shop}`  p ON p.size_id = z.size_id ";
  $order = " ORDER BY COALESCE(p.sort_order,z.sort_order,z.size_id), z.size_id";

  if ($viewPrice && tableExists($conn,$viewPrice)) {
    $sql = $base.", cp.price_per_tray
            FROM `{$sizes}` z
            $joinK
            LEFT JOIN `{$viewPrice}` cp ON cp.size_id = z.size_id
            $joinP
            $order";
    if ($res=@$conn->query($sql)) { while($r=$res->fetch_assoc()) $rows[]=$r; $res->free(); return [$rows,$sql,null]; }
    $err = $conn->error ?: 'query_failed_using_view';
  }

  $sql = $base.",
            (
              SELECT pr.price_per_tray
              FROM `{$tblPrice}` pr
              WHERE pr.size_id = z.size_id
                AND (pr.effective_to IS NULL OR pr.effective_to > NOW())
              ORDER BY pr.effective_from DESC
              LIMIT 1
            ) AS price_per_tray
          FROM `{$sizes}` z
          $joinK
          $joinP
          $order";
  if ($res=@$conn->query($sql)) { while($r=$res->fetch_assoc()) $rows[]=$r; $res->free(); return [$rows,$sql,$err]; }
  return [[], $sql, $conn->error ?: $err ?: 'query_failed_both'];
}
list($rows, $SQL_USED, $SQL_ERR) = fetch_products($conn, $TBL_SIZES, $TBL_STOCK, $TBL_SHOP, $VIEW_PRICE, $TBL_PRICE);

/* -------------------- render -------------------- */
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  /* Inventory-like styles */
  .inv-head { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between }
  .header-actions { display:flex; gap:.5rem; align-items:center }
  .search-wrap { position:relative; min-width:240px }
  .search-wrap .form-control{ padding-left:2rem }
  .search-wrap .ico { position:absolute; left:.5rem; top:50%; transform:translateY(-50%); opacity:.6 }

  .card-inv { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.05); transition: transform .15s ease, box-shadow .15s ease }
  .card-inv:hover { transform: translateY(-2px); box-shadow:0 10px 22px rgba(0,0,0,.08) }
  .badge-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.12rem .5rem; border-radius:999px; border:1px solid #e9ecef; background:#f8fafc; font-size:.75rem }
  .badge-chip .dot { width:8px; height:8px; border-radius:999px; background:#6c757d }

  .thumb { width:68px; height:68px; border-radius:10px; background:#f3f4f6; object-fit:cover; border:1px solid #e5e7eb }
  .muted { color:#6b7280 }
  .small-muted { color:#9aa3ad; font-size:.85rem }
  .switch { position:relative; display:inline-block; width:40px; height:22px; vertical-align:middle }
  .switch input{ display:none }
  .slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#e5e7eb; transition:.2s; border-radius:999px }
  .slider:before{ position:absolute; content:""; height:18px; width:18px; left:2px; top:2px; background:white; transition:.2s; border-radius:50% }
  input:checked + .slider{ background:#198754 }
  input:checked + .slider:before{ transform:translateX(18px) }
</style>

<div class="container-fluid py-3">

  <div class="inv-head mb-3">
    <div>
      <h5 class="mb-1">Products</h5>
      <div class="text-muted small">Manage product display for the shop. Images can be uploaded inline.</div>
    </div>
    <div class="header-actions">
      <div class="search-wrap">
        <i class="fa fa-magnifying-glass ico"></i>
        <input type="search" id="prodSearch" class="form-control form-control-sm" placeholder="Search code or size…">
      </div>
    </div>
  </div>

  <?php if ($DEBUG): ?>
    <div class="alert alert-info">
      <div class="fw-bold mb-1">Debug</div>
      <div class="small">
        DB: <code><?= h(($conn->query('SELECT DATABASE()')->fetch_row()[0] ?? '')) ?></code> ·
        egg_sizes rows: <code><?= (int)($conn->query("SELECT COUNT(*) FROM `{$TBL_SIZES}`")->fetch_row()[0] ?? 0) ?></code> ·
        egg_stock rows: <code><?= tableExists($conn,$TBL_STOCK) ? (int)($conn->query("SELECT COUNT(*) FROM `{$TBL_STOCK}`")->fetch_row()[0] ?? 0) : 0 ?></code>
      </div>
      <div class="mt-1"><code>Rows fetched: <?= (int)count($rows) ?></code></div>
      <?php if ($SQL_ERR): ?><div class="mt-1 text-danger small">Last SQL error: <code><?= h($SQL_ERR) ?></code></div><?php endif; ?>
      <details class="mt-2"><summary class="small">Show SQL</summary><pre class="small mb-0" style="white-space:pre-wrap"><?= h($SQL_USED) ?></pre></details>
    </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash'])): $FLASH=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= $FLASH['type']==='success'?'success':'warning'; ?> alert-dismissible fade show" role="alert">
      <?= h($FLASH['msg'] ?? '') ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-3" id="invGrid">
    <?php if (!$rows): ?>
      <div class="col-12"><div class="alert alert-info mb-0">No products to display. Make sure <code>egg_sizes</code> has rows.</div></div>
    <?php endif; ?>

    <?php foreach ($rows as $g): ?>
      <?php
        $sid   = (int)$g['size_id'];
        $code  = (string)($g['code'] ?? '');
        $label = (string)($g['label'] ?? '');
        $on    = (int)($g['trays_on_hand'] ?? 0);
        $price = is_null($g['price_per_tray']) ? null : (float)$g['price_per_tray'];
        $visible = (int)($g['visible'] ?? 1)===1;
        $disp  = (string)($g['display_name'] ?? $label);
        $desc  = (string)($g['short_desc'] ?? '');
        // preview: prefer product img_url; fallback to inventory image_path
        $rawPreview = $g['img_url'] ?: ($g['image_path'] ?? '');
        $isHttp = preg_match('~^https?://~i', $rawPreview);
        $isAbs  = ($rawPreview !== '' && $rawPreview[0] === '/');
        $preview = $rawPreview ? ($isHttp || $isAbs ? $rawPreview : $ADMIN_PREFIX.$rawPreview) : '';
        $payloadPub = json_encode(['sid'=>$sid,'to'=>$visible?0:1], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
      ?>
      <div class="col-12 col-sm-6 col-lg-4 col-xl-3 inv-card" data-key="<?= h(strtolower($label.' '.$code)) ?>">
        <div class="card card-inv h-100">
          <div class="card-header d-flex justify-content-between align-items-center py-2">
            <div class="d-flex align-items-center gap-2">
              <?php if ($code): ?><span class="badge-chip"><span class="dot"></span><?= h($code) ?></span><?php endif; ?>
              <strong><?= h($label) ?></strong>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="small-muted"><?= $visible?'Published':'Hidden' ?></span>
              <label class="switch mb-0">
                <input type="checkbox" <?= $visible?'checked':'' ?> onchange='togglePublish(<?= $payloadPub ?>, this)'>
                <span class="slider"></span>
              </label>
            </div>
          </div>

          <div class="card-body">
            <div class="d-flex gap-3">
              <div>
                <?php if ($preview): ?>
                  <img src="<?= h($preview) ?>" class="thumb" alt="">
                <?php else: ?>
                  <div class="thumb d-flex align-items-center justify-content-center text-muted"><i class="fa fa-image"></i></div>
                <?php endif; ?>

                <!-- Image upload (auto-submit) -->
                <form class="mt-1" method="post" enctype="multipart/form-data">
                  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="action" value="upload_image">
                  <input type="hidden" name="size_id" value="<?= $sid ?>">
                  <input type="file" name="image_file" accept="image/*" class="d-none" onchange="this.form.submit()">
                  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="this.previousElementSibling.click()">
                    <i class="fa fa-pen me-1"></i> Change
                  </button>
                </form>
              </div>

              <div class="flex-grow-1">
                <div class="mb-2">
                  <div class="small muted">Display Name</div>
                  <input type="text" class="form-control form-control-sm" form="frm<?= $sid ?>" name="display_name" value="<?= h($disp) ?>" maxlength="255">
                </div>
                <div class="mb-2">
                  <div class="small muted">Short Description</div>
                  <input type="text" class="form-control form-control-sm" form="frm<?= $sid ?>" name="short_desc" value="<?= h($desc) ?>" maxlength="500" placeholder="Optional">
                </div>
                <div class="d-flex justify-content-between small">
                  <div class="muted">On Hand</div>
                  <div><strong><?= (int)$on ?></strong> trays</div>
                </div>
                <div class="d-flex justify-content-between small mt-1">
                  <div class="muted">Current Price</div>
                  <div><strong><?= is_null($price) ? '—' : '₱'.number_format($price,2) ?></strong></div>
                </div>
              </div>
            </div>
          </div>

          <div class="card-footer bg-white d-flex justify-content-end">
            <form id="frm<?= $sid ?>" method="post" class="d-inline">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="save_row">
              <input type="hidden" name="size_id" value="<?= $sid ?>">
              <input type="hidden" name="visible" value="<?= $visible?1:0 ?>">
              <button class="btn btn-primary btn-sm">
                <i class="fa fa-save me-1"></i> Save
              </button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
  // Search filter (client-side)
  (function(){
    const q = document.getElementById('prodSearch');
    const grid = document.getElementById('invGrid');
    if (!q || !grid) return;
    q.addEventListener('input', function(){
      const term = (q.value || '').toLowerCase().trim();
      grid.querySelectorAll('.inv-card').forEach(card=>{
        const key = (card.getAttribute('data-key') || '').toLowerCase();
        card.style.display = term==='' || key.includes(term) ? '' : 'none';
      });
    });
  })();

  // Publish toggle (AJAX)
  function togglePublish(payload, cb){
    const to = cb.checked ? 1 : 0;
    const body = new URLSearchParams();
    body.set('csrf', '<?= h($_SESSION['csrf']) ?>');
    body.set('action','toggle_publish');
    body.set('size_id', String(payload.sid));
    body.set('to', String(to));
    fetch('products.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
      .catch(()=>{ cb.checked = !cb.checked; alert('Failed to update.'); });
    // also sync hidden visible value in the Save form
    const frm = document.getElementById('frm'+payload.sid);
    if (frm) frm.querySelector('input[name="visible"]').value = String(to);
  }
</script>

<?php include __DIR__ . '/inc/layout_foot.php';
