<?php
// admin/feed_inventory.php
// Admin Feed Inventory — no location/date in UI

require_once __DIR__ . '/inc/common.php';

@date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
// simple helpers if your common.php doesn’t have them
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $t): bool {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?";
    if(!$st=$c->prepare($sql)) return false;
    $st->bind_param('s',$t); $st->execute(); $st->store_result();
    $ok=$st->num_rows>0; $st->close(); return $ok;
  }
}
if (!function_exists('flash_set')) {
  function flash_set($type,$msg){ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
}
function flash_pop(){
  $x=$_SESSION['flash']??null; if($x) unset($_SESSION['flash']);
  return $x ? ['t'=>$x['type']??'info','m'=>$x['msg']??''] : null;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
$CSRF=$_SESSION['csrf'];
function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$t); }

$PAGE_TITLE = 'Feed Inventory';
$CURRENT    = 'feed_inventory.php';

// ---------- ensure table ----------
$TBL = 'feed_inventory';
if (!tableExists($conn,$TBL)) {
  $sql = "CREATE TABLE `$TBL` (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feed_name     VARCHAR(160) NOT NULL,
    sku           VARCHAR(64)  NULL,
    unit          VARCHAR(24)  NOT NULL DEFAULT 'sack',
    quantity      INT NOT NULL DEFAULT 0,
    location      VARCHAR(80)  NULL,               -- kept for compatibility, but not used in UI
    reorder_level INT NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, -- kept for compatibility, but not used in UI
    UNIQUE KEY uq_feed_name (feed_name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  @$conn->query($sql);
}

// ---------- one-time seed from the sheet (if table empty) ----------
$needSeed = false;
$rc = @$conn->query("SELECT COUNT(*) c FROM `$TBL`");
if ($rc){ $needSeed = ((int)($rc->fetch_assoc()['c'] ?? 0)===0); $rc->free(); }

if ($needSeed || (isset($_GET['seed']) && $_GET['seed']==='1')) {
  $items = [
    ['Corn','sack',0], ['Asin','kg',0],
    ['Lysoparte','sack',0], ['Clostat','sack',0],
    ['Optizym','sack',0], ['Ultrabond','sack',0],
    ['Toxi-H1-D3','sack',0], ['Wormcide','sack',0],
    ['MVE + AX','bottle',0], ['Addipro','pack',0],
    ['Biomos','pack',0], ['Mintall','pack',0],
    ['Tanke','pack',0], ['Enrofloxacin','bottle',0],
    ['Lincomycin','bottle',0],
    ['Grits','sack',0], ['Coarse','sack',0],
    ['Used Oil','L',0],
    ['ATA','pack',0],
  ];

  $ins = $conn->prepare("INSERT IGNORE INTO `$TBL` (feed_name,unit,quantity) VALUES (?,?,?)");
  if ($ins) {
    foreach ($items as [$name,$unit,$qty]) {
      $ins->bind_param('ssi',$name,$unit,$qty);
      $ins->execute();
    }
    $ins->close();
    if (!$needSeed) flash_set('success','Seed items inserted.');
  }
}

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { flash_set('danger','Bad CSRF'); header('Location: feed_inventory.php'); exit; }
  $action = $_POST['action'] ?? '';
  try {
    if ($action==='update') {
      $id   = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['feed_name'] ?? ''));
      $unit = trim((string)($_POST['unit'] ?? 'sack'));
      $qty  = (int)($_POST['quantity'] ?? 0);
      $reo  = (int)($_POST['reorder_level'] ?? 0);
      if ($id<=0 || $name==='') throw new Exception('Invalid data.');

      $sql="UPDATE `$TBL` SET feed_name=?, unit=?, quantity=?, reorder_level=? WHERE id=?";
      $st=$conn->prepare($sql); if(!$st) throw new Exception($conn->error);
      $st->bind_param('ssiii',$name,$unit,$qty,$reo,$id); $st->execute(); $st->close();

      flash_set('success','Item updated.');
      header('Location: feed_inventory.php'); exit;
    }

    if ($action==='adjust') {
      $id   = (int)($_POST['id'] ?? 0);
      $type = strtolower((string)($_POST['move_type'] ?? 'in')); // in|out|set
      $amt  = (int)($_POST['amount'] ?? 0);
      if ($id<=0) throw new Exception('Invalid item.');

      $st=$conn->prepare("SELECT quantity FROM `$TBL` WHERE id=?"); $st->bind_param('i',$id);
      $st->execute(); $q=(int)($st->get_result()->fetch_row()[0] ?? 0); $st->close();

      if ($type==='in')      $new=$q+$amt;
      elseif ($type==='out') $new=max(0,$q-$amt);
      else                   $new=max(0,$amt);

      $u=$conn->prepare("UPDATE `$TBL` SET quantity=? WHERE id=?"); $u->bind_param('ii',$new,$id); $u->execute(); $u->close();
      flash_set('success','Quantity updated.');
      header('Location: feed_inventory.php'); exit;
    }

    if ($action==='delete') {
      $id=(int)($_POST['id'] ?? 0); if ($id<=0) throw new Exception('Invalid item.');
      $d=$conn->prepare("DELETE FROM `$TBL` WHERE id=? LIMIT 1"); $d->bind_param('i',$id); $d->execute(); $d->close();
      flash_set('success','Item deleted.');
      header('Location: feed_inventory.php'); exit;
    }

    throw new Exception('Unknown action.');
  } catch (Throwable $e) {
    flash_set('danger',$e->getMessage());
    header('Location: feed_inventory.php'); exit;
  }
}

// ---------- fetch rows (search by name only) ----------
$q = trim((string)($_GET['q'] ?? ''));
$where=''; $bind=''; $args=[];
if ($q!==''){ $where='WHERE feed_name LIKE ?'; $bind='s'; $like="%{$q}%"; $args=[$like]; }
$sql = "SELECT id, feed_name, unit, quantity, reorder_level
        FROM `$TBL` $where
        ORDER BY feed_name ASC";
$st=$conn->prepare($sql);
if ($bind!=='') $st->bind_param($bind, ...$args);
$st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .pm-compact { font-size:14px; }
  .pm-compact .small{ font-size:.78rem; }
  .pm-compact .form-control,.pm-compact .form-select{ height:1.9rem;padding:.2rem .45rem;font-size:.86rem;line-height:1.2; }
  .pm-compact .btn{ padding:.28rem .6rem;font-size:.85rem;line-height:1.2; }
  .pm-compact .table > :not(caption) > * > *{ padding:.38rem .5rem; }
  /* keep modals above sticky toolbars */
  :root{ --pm-z-backdrop: 2040; --pm-z-modal: 2050; }
  .modal-backdrop{ z-index: var(--pm-z-backdrop) !important; }
  .modal{ z-index: var(--pm-z-modal) !important; }
</style>

<div class="pm-compact">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="fw-bold mb-0">Feed Inventory (Admin)</h6>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="?seed=1" title="Re-insert seed items (ignores duplicates)">Seed Items</a>
    </div>
  </div>

  <?php if($f=flash_pop()): ?>
    <div class="alert alert-<?= $f['t']==='success'?'success':($f['t']==='danger'?'danger':'info') ?> py-2"><?= h($f['m']) ?></div>
  <?php endif; ?>

  <form class="row g-2 align-items-end mb-2" method="get">
    <div class="col-md-6">
      <label class="form-label small">Search</label>
      <input name="q" class="form-control" placeholder="name" value="<?=h($q)?>">
    </div>
    <div class="col-md-2">
      <label class="form-label small d-block">&nbsp;</label>
      <button class="btn btn-outline-dark w-100">Filter</button>
    </div>
  </form>

  <div class="card">
    <div class="card-body table-responsive">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:36%;">Item</th>
            <th>Unit</th>
            <th class="text-end" style="width:150px;">Quantity</th>
            <th class="text-end" style="width:220px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): $low = ($r['reorder_level']>0 && (int)$r['quantity'] <= (int)$r['reorder_level']); ?>
          <tr>
            <td class="align-middle">
              <div class="fw-semibold"><?=h($r['feed_name'])?></div>
              <?php if ($r['reorder_level']>0): ?>
                <div class="small text-muted">Reorder ≤ <?= (int)$r['reorder_level'] ?></div>
              <?php endif; ?>
            </td>
            <td class="align-middle"><?=h($r['unit'])?></td>
            <td class="text-end align-middle">
              <span class="<?= $low?'text-danger fw-semibold':'' ?>"><?= number_format((int)$r['quantity']) ?></span>
            </td>
            <td class="text-end align-middle">
              <!-- Adjust -->
              <button class="btn btn-sm btn-outline-success me-1"
                data-bs-toggle="modal" data-bs-target="#adjustModal"
                data-id="<?=$r['id']?>"
                data-name="<?=h($r['feed_name'])?>"
                data-qty="<?=$r['quantity']?>"
                data-unit="<?=h($r['unit'])?>">
                <i class="fa fa-exchange-alt"></i> Adjust
              </button>
              <!-- Edit -->
              <button class="btn btn-sm btn-outline-primary me-1"
                data-bs-toggle="modal" data-bs-target="#editModal"
                data-id="<?=$r['id']?>"
                data-name="<?=h($r['feed_name'])?>"
                data-unit="<?=h($r['unit'])?>"
                data-qty="<?=$r['quantity']?>"
                data-reorder="<?= (int)$r['reorder_level'] ?>">
                <i class="fa fa-pen"></i>
              </button>
              <!-- Delete -->
              <form class="d-inline" method="post" onsubmit="return confirm('Delete this item?');">
                <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=$r['id']?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; if (!$rows): ?>
            <tr><td colspan="4" class="text-center text-muted">No items found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Edit Modal (no location/date fields) -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="ed_id">
      <div class="modal-header"><h6 class="modal-title">Edit Item</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label small">Name</label><input name="feed_name" id="ed_name" class="form-control" required></div>
        <div class="row g-2">
          <div class="col-md-4"><label class="form-label small">Unit</label><input name="unit" id="ed_unit" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label small">Quantity</label><input name="quantity" id="ed_qty" type="number" min="0" class="form-control" required></div>
          <div class="col-md-4"><label class="form-label small">Reorder level</label><input name="reorder_level" id="ed_reo" type="number" min="0" class="form-control"></div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-dark">Save</button></div>
    </form>
  </div>
</div>

<!-- Adjust Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
      <input type="hidden" name="action" value="adjust">
      <input type="hidden" name="id" id="mv_id">
      <div class="modal-header"><h6 class="modal-title">Adjust Quantity</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><strong id="mv_name">—</strong> <span class="small text-muted" id="mv_unit"></span></div>
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label small">Type</label>
            <select name="move_type" class="form-select">
              <option value="in">IN (+)</option>
              <option value="out">OUT (−)</option>
              <option value="set">SET (=)</option>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label small">Amount</label>
            <input name="amount" type="number" min="0" class="form-control" required>
          </div>
        </div>
        <div class="small text-muted mt-2">Current: <span id="mv_qty">0</span></div>
      </div>
      <div class="modal-footer"><button class="btn btn-dark">Apply</button></div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // edit fill
  document.getElementById('editModal')?.addEventListener('show.bs.modal', function(ev){
    const b = ev.relatedTarget || document.activeElement;
    const get = (k)=> b?.getAttribute(k)||'';
    document.getElementById('ed_id').value   = get('data-id');
    document.getElementById('ed_name').value = get('data-name');
    document.getElementById('ed_unit').value = get('data-unit');
    document.getElementById('ed_qty').value  = get('data-qty');
    document.getElementById('ed_reo').value  = get('data-reorder') || 0;
  });
  // adjust fill
  document.getElementById('adjustModal')?.addEventListener('show.bs.modal', function(ev){
    const b = ev.relatedTarget || document.activeElement;
    const get = (k)=> b?.getAttribute(k)||'';
    document.getElementById('mv_id').value    = get('data-id');
    document.getElementById('mv_name').textContent = get('data-name');
    document.getElementById('mv_unit').textContent = '· ' + (get('data-unit')||'');
    document.getElementById('mv_qty').textContent  = get('data-qty') || '0';
  });
});
</script>

<?php include __DIR__ . '/inc/layout_foot.php';
