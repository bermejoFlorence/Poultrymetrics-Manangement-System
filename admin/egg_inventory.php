<?php
// /admin/egg_inventory.php
// PoultryMetrics – Admin > Egg Inventory (Table UI: clearer actions)

$PAGE_TITLE = 'Egg Inventory';
require_once __DIR__ . '/inc/common.php'; // session, $conn, guards, helpers
@date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

/* -------------------- Fallback helpers if missing in common.php -------------------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $n): bool {
    $n=$c->real_escape_string($n);
    $r=@$c->query("SHOW TABLES LIKE '{$n}'");
    return !!($r && $r->num_rows);
  }
}
if (!function_exists('scalar')) {
  function scalar(mysqli $c, string $sql, array $params = [], string $types = ''){
    $val = null;
    if (!$params){
      if ($r=@$c->query($sql)){ if($row=$r->fetch_row()) $val=$row[0]??null; if($r) $r->free(); }
      return $val;
    }
    if(!$st=$c->prepare($sql)) return null;
    $st->bind_param($types ?: str_repeat('s', count($params)), ...$params);
    if($st->execute()){ $res=$st->get_result(); if($res){ $row=$res->fetch_row(); $val=$row?$row[0]:null; $res->free(); } }
    $st->close(); return $val;
  }
}

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t ?? ''); }

/* -------------------- Ensure egg_stock rows exist for all sizes -------------------- */
if (tableExists($conn,'egg_sizes') && tableExists($conn,'egg_stock')) {
  @$conn->query("
    INSERT IGNORE INTO egg_stock (size_id, trays_on_hand, low_threshold)
    SELECT s.size_id, 0, 10
    FROM egg_sizes s
    LEFT JOIN egg_stock k ON k.size_id=s.size_id
    WHERE k.size_id IS NULL
  ");
}

/* -------------------- POST actions -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
  $action = $_POST['action'] ?? '';

  if ($action === 'add_batch') {
    $size_id    = (int)($_POST['size_id'] ?? 0);
    $qty_trays  = max(0, (int)($_POST['qty_trays'] ?? 0));
    $batch_date = trim($_POST['batch_date'] ?? '');
    if (!$batch_date) $batch_date = date('Y-m-d');

    if ($size_id <= 0 || $qty_trays <= 0) {
      $_SESSION['flash'] = ['type'=>'error','msg'=>'Provide a valid size and quantity.'];
      header('Location: egg_inventory.php'); exit;
    }

    $exists = (int)scalar($conn, "SELECT COUNT(*) FROM egg_sizes WHERE size_id=?", [$size_id], 'i');
    if ($exists <= 0) {
      $_SESSION['flash'] = ['type'=>'error','msg'=>'Selected egg size does not exist.'];
      header('Location: egg_inventory.php'); exit;
    }

    $conn->begin_transaction();
    try {
      $stmt1 = $conn->prepare("INSERT INTO production_batches (batch_date, size_id, qty_trays, remarks) VALUES (?,?,?,?)");
      $remarks = 'Admin Stock In';
      $stmt1->bind_param('siis', $batch_date, $size_id, $qty_trays, $remarks);
      if (!$stmt1->execute()) throw new Exception('Insert batch failed.');
      $stmt1->close();

      $stmt2 = $conn->prepare("UPDATE egg_stock SET trays_on_hand = trays_on_hand + ? WHERE size_id=?");
      $stmt2->bind_param('ii',$qty_trays,$size_id);
      if (!$stmt2->execute()) throw new Exception('Stock update failed.');
      $stmt2->close();

      $conn->commit();
      $_SESSION['flash'] = ['type'=>'success','msg'=>'Production batch recorded and stock updated.'];
    } catch (Throwable $e) {
      $conn->rollback();
      $_SESSION['flash'] = ['type'=>'error','msg'=>'Failed to record batch.'];
    }

    header('Location: egg_inventory.php'); exit;
  }

  if ($action === 'update_threshold') {
    $size_id = (int)($_POST['size_id'] ?? 0);
    $low     = max(0, (int)($_POST['low_threshold'] ?? 0));
    if ($size_id <= 0) {
      $_SESSION['flash'] = ['type'=>'error','msg'=>'Invalid size.'];
      header('Location: egg_inventory.php'); exit;
    }
    $stmt = $conn->prepare("UPDATE egg_stock SET low_threshold=? WHERE size_id=?");
    $stmt->bind_param('ii',$low,$size_id);
    $ok = $stmt->execute(); $stmt->close();

    $_SESSION['flash'] = ['type'=> $ok?'success':'error', 'msg'=> $ok?'Threshold updated.':'Update failed.'];
    header('Location: egg_inventory.php'); exit;
  }

  if ($action === 'update_price') {
    $size_id = (int)($_POST['size_id'] ?? 0);
    $price   = (float)($_POST['price_per_tray'] ?? 0);
    if ($size_id <= 0 || $price < 0) {
      $_SESSION['flash'] = ['type'=>'error','msg'=>'Invalid size or price.'];
      header('Location: egg_inventory.php'); exit;
    }

    $conn->begin_transaction();
    try {
      $stmt1 = $conn->prepare("UPDATE pos_prices SET effective_to=NOW() WHERE size_id=? AND (effective_to IS NULL OR effective_to > NOW())");
      $stmt1->bind_param('i',$size_id);
      if (!$stmt1->execute()) throw new Exception('Close current price failed.');
      $stmt1->close();

      $stmt2 = $conn->prepare("INSERT INTO pos_prices (size_id, price_per_tray, effective_from, effective_to) VALUES (?,?,NOW(),NULL)");
      $stmt2->bind_param('id',$size_id,$price);
      if (!$stmt2->execute()) throw new Exception('Insert new price failed.');
      $stmt2->close();

      $conn->commit();
      $_SESSION['flash'] = ['type'=>'success','msg'=>'Price updated.'];
    } catch (Throwable $e) {
      $conn->rollback();
      $_SESSION['flash'] = ['type'=>'error','msg'=>'Failed to update price.'];
    }
    header('Location: egg_inventory.php'); exit;
  }
}

/* -------------------- Data load -------------------- */
$missing = [];
if (!tableExists($conn,'egg_sizes')) $missing[]='egg_sizes';
if (!tableExists($conn,'egg_stock')) $missing[]='egg_stock';
if (!tableExists($conn,'pos_prices')) $missing[]='pos_prices';
if (!tableExists($conn,'production_batches')) $missing[]='production_batches';

$rows = [];
if (!$missing) {
  $has_view = tableExists($conn,'v_current_prices');
  if ($has_view) {
    $sql = "SELECT z.size_id, z.code, z.label, z.sort_order,
                   k.trays_on_hand, k.low_threshold,
                   cp.price_per_tray
            FROM egg_sizes z
            JOIN egg_stock k ON k.size_id = z.size_id
            LEFT JOIN v_current_prices cp ON cp.size_id = z.size_id
            ORDER BY z.sort_order, z.size_id";
  } else {
    $sql = "SELECT z.size_id, z.code, z.label, z.sort_order,
                   k.trays_on_hand, k.low_threshold,
                   (
                     SELECT p.price_per_tray
                     FROM pos_prices p
                     WHERE p.size_id = z.size_id
                       AND (p.effective_to IS NULL OR p.effective_to > NOW())
                     ORDER BY p.effective_from DESC
                     LIMIT 1
                   ) AS price_per_tray
            FROM egg_sizes z
            JOIN egg_stock k ON k.size_id = z.size_id
            ORDER BY z.sort_order, z.size_id";
  }

  if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $res->free();
  }
}

/* KPIs */
$totalOnHand = 0; $lowCount = 0; $sizesCount = count($rows);
foreach ($rows as $g) {
  $totalOnHand += (int)$g['trays_on_hand'];
  if ((int)$g['trays_on_hand'] < (int)$g['low_threshold']) $lowCount++;
}

/* -------------------- Flash -------------------- */
$FLASH = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* -------------------- Render -------------------- */
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .inv-head { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between }
  .header-actions { display:flex; gap:.5rem; align-items:center }
  .search-wrap { position:relative; min-width:260px }
  .search-wrap .form-control{ padding-left:2rem }
  .search-wrap .ico { position:absolute; left:.5rem; top:50%; transform:translateY(-50%); opacity:.6 }

  .kpi-pills { display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:.5rem }
  .kpi-pill { background:#fff; border:1px solid #e9ecef; border-radius:12px; padding:.55rem .7rem; box-shadow:0 6px 16px rgba(0,0,0,.05) }
  .kpi-pill .k { color:#6b7280; font-size:.8rem }
  .kpi-pill .v { font-weight:800; font-size:1.05rem }

  .table-inv thead th { position:sticky; top:0; z-index:2; background:#f8fafc; }
  .table-inv td, .table-inv th { vertical-align: middle; }
  .w-progress { min-width:180px }
  .progress-xxs { height:6px; border-radius:999px; }
  .badge-status { font-weight:600; letter-spacing:.02em }
  .price-muted { color:#9aa3ad }

  @media (max-width: 768px){
    .w-progress { min-width:120px }
    .header-actions { width:100%; justify-content:flex-start }
  }
</style>

<div class="container-fluid py-3">

  <div class="inv-head mb-3">
    <div>
      <h5 class="mb-1">Egg Inventory</h5>
      <div class="text-muted small">Manage trays on hand, low thresholds, and current POS prices.</div>
    </div>
    <div class="header-actions">
      <div class="search-wrap">
        <i class="fa fa-magnifying-glass ico"></i>
        <input type="search" id="invSearch" class="form-control form-control-sm" placeholder="Search code or size…">
      </div>
      <button class="btn btn-sm btn-success" onclick="openAddProduction()">
        <i class="fa fa-plus me-1"></i> Stock In
      </button>
      <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
        <i class="fa fa-print me-1"></i> Print
      </button>
    </div>
  </div>

  <div class="kpi-pills mb-3">
    <div class="kpi-pill">
      <div class="k">Total On Hand</div>
      <div class="v"><?= (int)$totalOnHand ?> trays</div>
    </div>
    <div class="kpi-pill">
      <div class="k">Sizes Tracked</div>
      <div class="v"><?= (int)$sizesCount ?></div>
    </div>
    <div class="kpi-pill">
      <div class="k">Low Stock</div>
      <div class="v"><?= (int)$lowCount ?> size<?= $lowCount==1?'':'s' ?></div>
    </div>
  </div>

  <?php if ($FLASH): ?>
    <div class="alert alert-<?= $FLASH['type']==='success'?'success':'warning'; ?> alert-dismissible fade show" role="alert">
      <?= h($FLASH['msg'] ?? '') ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($missing): ?>
    <div class="alert alert-warning">
      Missing tables/views: <code><?= h(implode(', ', $missing)) ?></code>. Please run the Egg Inventory SQL schema.
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover table-inv align-middle mb-0" id="invTable">
        <thead class="table-light">
          <tr>
            <th style="width:100px;">Code</th>
            <th>Size</th>
            <th class="text-end" style="width:120px;">On Hand</th>
            <th class="text-end" style="width:140px;">Low Threshold</th>
            <th class="text-center" style="width:90px;">Status</th>
            <th class="text-end" style="width:140px;">Price / Tray</th>
            <th class="w-progress">Progress</th>
            <th class="text-end" style="width:280px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows && !$missing): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No sizes found. Add rows to <code>egg_sizes</code>.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $g): ?>
            <?php
              $on   = (int)$g['trays_on_hand'];
              $low  = max(0,(int)$g['low_threshold']);
              $is_low = ($on < $low);
              $price  = is_null($g['price_per_tray']) ? null : (float)$g['price_per_tray'];
              $priceHtml = is_null($price) ? '<span class="price-muted">Set price</span>' : '₱'.number_format($price,2);
              $pct = $low > 0 ? min(100, round(100 * $on / $low)) : null;
              $key = strtolower(trim(($g['code'] ?? '').' '.($g['label'] ?? '')));
            ?>
            <tr data-key="<?= h($key) ?>">
              <td><span class="badge bg-light text-dark border"><?= h($g['code'] ?: '—') ?></span></td>
              <td class="fw-semibold"><?= h($g['label']) ?></td>
              <td class="text-end"><?= (int)$on ?> trays</td>
              <td class="text-end"><?= (int)$low ?> trays</td>
              <td class="text-center">
                <?php if ($is_low): ?>
                  <span class="badge badge-status bg-warning text-dark">Low</span>
                <?php else: ?>
                  <span class="badge badge-status bg-success">OK</span>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= $priceHtml ?></td>
              <td>
                <?php if (!is_null($pct)): ?>
                  <div class="progress progress-xxs" title="<?= (int)$pct ?>% of threshold">
                    <div class="progress-bar" role="progressbar" style="width: <?= (int)$pct ?>%;" aria-valuenow="<?= (int)$pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                  </div>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <div class="btn-group">
                  <button type="button" class="btn btn-sm btn-success"
                          data-action="stockin"
                          data-size-id="<?= (int)$g['size_id'] ?>"
                          data-label="<?= h($g['label']) ?>">
                    <i class="fa fa-plus me-1"></i> Stock In
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary"
                          data-action="threshold"
                          data-size-id="<?= (int)$g['size_id'] ?>"
                          data-label="<?= h($g['label']) ?>"
                          data-low="<?= (int)$g['low_threshold'] ?>">
                    <i class="fa fa-sliders me-1"></i> Threshold
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-primary"
                          data-action="price"
                          data-size-id="<?= (int)$g['size_id'] ?>"
                          data-label="<?= h($g['label']) ?>"
                          data-price="<?= is_null($price)?'':number_format($price,2,'.','') ?>">
                    <i class="fa fa-tag me-1"></i> Price
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modals -->
<div class="modal fade" id="prodModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Stock In (Production)</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="add_batch">

        <div class="mb-2">
          <label class="form-label small">Egg Size</label>
          <select class="form-select" name="size_id" id="prod-size-id" required>
            <option value="">— Select size —</option>
            <?php foreach ($rows as $opt): ?>
              <option value="<?= (int)$opt['size_id'] ?>"><?= h($opt['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label small">Quantity (trays)</label>
            <input type="number" name="qty_trays" id="prod-qty" class="form-control" min="1" value="1" required>
          </div>
          <div class="col-md-6">
            <label class="form-label small">Batch Date</label>
            <input type="date" name="batch_date" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div class="form-text mt-2">Tip: Record production at the end of collection or per shift.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success"><i class="fa fa-save me-1"></i> Record Production</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="thrModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Edit Low Stock Threshold</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="update_threshold">
        <input type="hidden" name="size_id" id="thr-size-id">
        <div class="mb-2">
          <label class="form-label small">Egg Size</label>
          <input class="form-control" id="thr-size-label" disabled>
        </div>
        <div class="mb-2">
          <label class="form-label small">Low Threshold (trays)</label>
          <input type="number" name="low_threshold" id="thr-low" class="form-control" min="0" value="10" required>
          <div class="form-text">Below this number, the size is flagged as <strong>Low</strong>.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-dark"><i class="fa fa-save me-1"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="priceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Update Price per Tray</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="update_price">
        <input type="hidden" name="size_id" id="price-size-id">
        <div class="mb-2">
          <label class="form-label small">Egg Size</label>
          <input class="form-control" id="price-size-label" disabled>
        </div>
        <div class="mb-2">
          <label class="form-label small">Price (₱ / tray)</label>
          <input type="number" step="0.01" min="0" name="price_per_tray" id="price-val" class="form-control" value="0.00" required>
          <div class="form-text">This closes the current price and starts a new one now.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary"><i class="fa fa-save me-1"></i> Update Price</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Search filter (table rows)
  (function(){
    const q = document.getElementById('invSearch');
    const tbl = document.getElementById('invTable');
    if (!q || !tbl) return;
    q.addEventListener('input', function(){
      const term = (q.value || '').toLowerCase().trim();
      tbl.querySelectorAll('tbody tr').forEach(tr=>{
        const key = (tr.getAttribute('data-key') || '').toLowerCase();
        tr.style.display = term==='' || key.includes(term) ? '' : 'none';
      });
    });
  })();

  // Row actions via event delegation
  (function(){
    const tbl = document.getElementById('invTable');
    if (!tbl) return;
    tbl.addEventListener('click', function(ev){
      const btn = ev.target.closest('[data-action]');
      if (!btn) return;
      const act = btn.getAttribute('data-action');
      const payload = {
        size_id: btn.getAttribute('data-size-id'),
        label: btn.getAttribute('data-label'),
        low: btn.getAttribute('data-low'),
        price: btn.getAttribute('data-price')
      };
      if (act === 'stockin') openAddProduction(payload);
      else if (act === 'threshold') openThreshold({ size_id: payload.size_id, label: payload.label, low: payload.low });
      else if (act === 'price') openPrice({ size_id: payload.size_id, label: payload.label, price: payload.price });
    });
  })();

  // Modals
  function openAddProduction(payload){
    const sel = document.getElementById('prod-size-id');
    sel.value = (payload && payload.size_id) ? String(payload.size_id) : '';
    document.getElementById('prod-qty').value = 1;
    new bootstrap.Modal(document.getElementById('prodModal')).show();
  }
  function openThreshold(payload){
    document.getElementById('thr-size-id').value = payload.size_id || '';
    document.getElementById('thr-size-label').value = payload.label || '';
    document.getElementById('thr-low').value = (payload.low ?? 10);
    new bootstrap.Modal(document.getElementById('thrModal')).show();
  }
  function openPrice(payload){
    document.getElementById('price-size-id').value = payload.size_id || '';
    document.getElementById('price-size-label').value = payload.label || '';
    const p = (payload.price === null || payload.price === '' || isNaN(Number(payload.price))) ? '0.00' : Number(payload.price).toFixed(2);
    document.getElementById('price-val').value = p;
    new bootstrap.Modal(document.getElementById('priceModal')).show();
  }
</script>

<?php include __DIR__ . '/inc/layout_foot.php';
