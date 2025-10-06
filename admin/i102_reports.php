<?php
// admin/i102_reports.php — schema-aware finder + responsive + no modal overlap
require_once __DIR__ . '/inc/common.php'; // $conn, session, (auth)
@date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

/* ----------------- helpers ----------------- */
if (!function_exists('h')){ function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function tableExists(mysqli $c, string $t): bool {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"; 
  if(!$st=$c->prepare($sql)) return false;
  $st->bind_param('s',$t); $st->execute(); $st->store_result();
  $ok=$st->num_rows>0; $st->close(); return $ok;
}
function colExists(mysqli $c, string $t, string $col): bool {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  if(!$st=$c->prepare($sql)) return false;
  $st->bind_param('ss',$t,$col); $st->execute(); $st->store_result();
  $ok=$st->num_rows>0; $st->close(); return $ok;
}
function primaryKey(mysqli $c, string $t): ?string {
  $res = @$c->query("SHOW KEYS FROM `".$c->real_escape_string($t)."` WHERE Key_name='PRIMARY'");
  if(!$res) return null; $row = $res->fetch_assoc(); $res->free();
  return $row['Column_name'] ?? null;
}
function listLikeTables(mysqli $c, string $like): array {
  $like = str_replace('%','\%',$like);
  $res = @$c->query("SHOW TABLES LIKE '%$like%'"); if(!$res) return [];
  $out=[]; while($r=$res->fetch_row()){ $out[]=$r[0]; } $res->free(); return $out;
}

/* Try to pick a table from a list of candidates; else scan for any table containing all $mustHaveCols */
function pickTable(mysqli $c, array $candidates, array $mustHaveCols=[]): ?string {
  foreach($candidates as $t){ if(tableExists($c,$t)){ 
    $ok=true; foreach($mustHaveCols as $col){ if(!colExists($c,$t,$col)) { $ok=false; break; } }
    if($ok) return $t;
  } }
  // soft scan using LIKE for i102 keyword
  foreach(['i102','report','egg'] as $kw){
    foreach(listLikeTables($c,$kw) as $t){
      $ok=true; foreach($mustHaveCols as $col){ if(!colExists($c,$t,$col)) { $ok=false; break; } }
      if($ok) return $t;
    }
  }
  // fallback: any candidate that simply exists (even if it misses some cols)
  foreach($candidates as $t){ if(tableExists($c,$t)) return $t; }
  return null;
}

/* ----------------- discover schema ----------------- */
// Allow overrides via query (handy for mismatched names)
$rtbl_override = trim((string)($_GET['rtbl'] ?? ''));
$itbl_override = trim((string)($_GET['itbl'] ?? ''));

$R_TBL = $rtbl_override !== '' ? $rtbl_override
  : pickTable($conn,
      ['i102_reports','i102_report','reports_i102','i102','i102_hdr','report_headers','egg_reports','egg_report_headers'],
      [] // don't force columns; we’ll adapt filters below
    );

$I_TBL = $itbl_override !== '' ? $itbl_override
  : pickTable($conn,
      ['i102_report_items','i102_items','i102_item','report_items','egg_report_items','i102_details','i102_dtls'],
      []
    );

// If still not found, print a helpful error so you can pass ?rtbl=YourTable&itbl=YourItems
if (!$R_TBL) {
  http_response_code(500);
  $hint = implode(', ', array_map('h', array_unique(array_merge(
    listLikeTables($conn,'i102'),
    listLikeTables($conn,'report'),
    listLikeTables($conn,'egg')
  ))));
  echo "Report table not found. Try adding query params like <code>?rtbl=your_reports_table&itbl=your_items_table</code>.<br>";
  echo "Tables that look relevant: <em>$hint</em>";
  exit;
}

$R_PK = primaryKey($conn,$R_TBL) ?: (colExists($conn,$R_TBL,'id') ? 'id' : (colExists($conn,$R_TBL,'report_id') ? 'report_id' : 'id'));

/* Prefer these if present */
$R_DATE_COL = null; foreach (['report_date','date','reported_at','created_at','dt'] as $c) { if (colExists($conn,$R_TBL,$c)) { $R_DATE_COL=$c; break; } }
$R_FARM_COL = null; foreach (['farm','farm_name','site','location'] as $c) { if (colExists($conn,$R_TBL,$c)) { $R_FARM_COL=$c; break; } }
$R_USER_COL = null; foreach (['username','submitted_by','created_by','worker_id','user_id'] as $c) { if (colExists($conn,$R_TBL,$c)) { $R_USER_COL=$c; break; } }

// Items linkage & eggs column (optional)
$I_FK       = ($I_TBL && colExists($conn,$I_TBL,'report_id')) ? 'report_id' : null;
if (!$I_FK && $I_TBL && $R_PK && colExists($conn,$I_TBL,$R_PK)) $I_FK = $R_PK;
if (!$I_FK && $I_TBL) { foreach(['i102_report_id','parent_id','header_id','hdr_id'] as $cand){ if(colExists($conn,$I_TBL,$cand)){ $I_FK=$cand; break; } } }
$I_PK       = ($I_TBL && colExists($conn,$I_TBL,'id')) ? 'id' : (($I_TBL && colExists($conn,$I_TBL,'item_id')) ? 'item_id' : '*');
$I_EGGS_COL = null; if ($I_TBL) { foreach (['egg_total','eggs_total','total_eggs','eggs','egg_count','eggcount'] as $c) { if (colExists($conn,$I_TBL,$c)) { $I_EGGS_COL=$c; break; } } }

/* ----------------- filters ----------------- */
$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-d');
$farmQ = trim((string)($_GET['farm'] ?? ''));

$whereParts=[]; $types=''; $params=[];
if ($R_DATE_COL) { $whereParts[]="r.`$R_DATE_COL` BETWEEN ? AND ?"; $types.='ss'; array_push($params,$start,$end); } // only if date column exists
if ($farmQ!=='' && $R_FARM_COL) { $whereParts[]="r.`$R_FARM_COL` LIKE ?"; $types.='s'; $params[]='%'.$farmQ.'%'; }
$where = $whereParts ? implode(' AND ',$whereParts) : '1=1';

/* ----------------- query ----------------- */
$rowsExpr = ($I_TBL && $I_FK)
  ? "(SELECT COUNT(" . ($I_PK==='*'?'*':"`$I_PK`") . ") FROM `$I_TBL` i WHERE i.`$I_FK`=r.`$R_PK`) AS rows_cnt"
  : "0 AS rows_cnt";

$sumExpr = ($I_TBL && $I_FK && $I_EGGS_COL)
  ? "(SELECT COALESCE(SUM(i.`$I_EGGS_COL`),0) FROM `$I_TBL` i WHERE i.`$I_FK`=r.`$R_PK`) AS eggs_sum"
  : "0 AS eggs_sum";

$orderExpr = $R_DATE_COL ? "r.`$R_DATE_COL` DESC, r.`$R_PK` DESC" : "r.`$R_PK` DESC";

$sql = "SELECT r.*, $rowsExpr, $sumExpr
        FROM `$R_TBL` r
        WHERE $where
        ORDER BY $orderExpr";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo "SQL prepare failed: ".h($conn->error)."<br>Query:<pre>".h($sql)."</pre>";
  exit;
}
if ($types!=='') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
$reports = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

/* ----------------- view ----------------- */
$PAGE_TITLE = 'I-102 Reports';
$CURRENT    = 'i102_reports.php';
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .pm-compact { font-size: 14px; }
  .pm-compact .small { font-size: .78rem; }
  .pm-compact .form-control, .pm-compact .form-select { height:1.9rem; padding:.2rem .45rem; font-size:.85rem; line-height:1.2; }
  .pm-compact .btn { padding:.22rem .55rem; font-size:.82rem; line-height:1.2; }
  .pm-compact .card-body { padding:.6rem; }
  .pm-compact .card-header, .pm-compact .card-footer { padding:.45rem .6rem; }
  .pm-compact .table { margin-bottom:0; }
  .pm-compact .table > :not(caption) > * > * { padding:.35rem .45rem; }
  .pm-compact .table thead th{ position:sticky; top:0; z-index:1; background:#fff; }

  /* Keep modals above sticky bars/sidebars */
  :root{ --pm-z-backdrop: 2040; --pm-z-modal: 2050; }
  .modal-backdrop{ z-index: var(--pm-z-backdrop) !important; }
  .modal{ z-index: var(--pm-z-modal) !important; }
  body.modal-open{ overflow: hidden; }

  @media (max-width: 576px){
    .pm-compact { font-size: 13px; }
    .pm-compact .card-body{ padding:.5rem; }
    .pm-compact .table td, .pm-compact .table th{ vertical-align: middle; white-space: nowrap; }
    .pm-compact .card-header .d-flex.gap-2{ flex-wrap: wrap; }
    .pm-compact form.d-flex{ flex-wrap: wrap; gap:.5rem; }
    .pm-compact form.d-flex > *{ width:100%; }
  }
</style>

<div class="pm-compact">
  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <i class="fa-solid fa-folder-open text-warning"></i>
        <strong>I-102 Reports</strong>
      </div>

      <form class="d-flex gap-2" method="get">
        <?php if ($R_DATE_COL): ?>
          <input type="date" class="form-control form-control-sm" name="start" value="<?=h($start)?>">
          <input type="date" class="form-control form-control-sm" name="end" value="<?=h($end)?>">
        <?php else: ?>
          <input type="text" class="form-control form-control-sm" value="(no date column)" disabled>
        <?php endif; ?>
        <input type="text" class="form-control form-control-sm" name="farm" value="<?=h($farmQ)?>" placeholder="<?= $R_FARM_COL ? 'Farm' : '(no farm column)' ?>" <?= $R_FARM_COL?'':'disabled' ?>>
        <button class="btn btn-sm btn-dark" title="Filter" <?= $R_DATE_COL || $R_FARM_COL ? '' : 'disabled' ?>><i class="fa-solid fa-magnifying-glass"></i></button>
        <a class="btn btn-sm btn-outline-secondary" href="i102_reports.php">Reset</a>

        <!-- Optional: show which tables were selected (hidden inputs keep them on navigation if set by query) -->
        <?php if ($rtbl_override!==''): ?><input type="hidden" name="rtbl" value="<?=h($rtbl_override)?>"><?php endif; ?>
        <?php if ($itbl_override!==''): ?><input type="hidden" name="itbl" value="<?=h($itbl_override)?>"><?php endif; ?>
      </form>
    </div>

    <div class="card-body table-responsive p-2">
      <table class="table table-hover table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:110px;">Date</th>
            <th>Farm</th>
            <th style="width:160px;">Submitted By</th>
            <th class="text-end" style="width:90px;">Rows</th>
            <th class="text-end" style="width:140px;">Total Eggs</th>
            <th style="width:120px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($reports): foreach ($reports as $r): ?>
            <tr>
              <td><?= $R_DATE_COL && isset($r[$R_DATE_COL]) && $r[$R_DATE_COL]!=='' ? h($r[$R_DATE_COL]) : '—' ?></td>
              <td><?= $R_FARM_COL && isset($r[$R_FARM_COL]) && $r[$R_FARM_COL]!=='' ? h($r[$R_FARM_COL]) : '—' ?></td>
              <td><?php
                $who = $R_USER_COL && isset($r[$R_USER_COL]) ? (string)$r[$R_USER_COL] : '';
                if ($who==='') echo '—';
                else echo ctype_digit($who) ? '#'.h($who) : h($who);
              ?></td>
              <td class="text-end"><?= (int)($r['rows_cnt'] ?? 0) ?></td>
              <td class="text-end"><?= number_format((int)($r['eggs_sum'] ?? 0)) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-dark"
                   href="i102_report_view.php?id=<?= isset($r[$R_PK]) ? (int)$r[$R_PK] : 0 ?>">
                  <i class="fa-regular fa-eye me-1"></i>View
                </a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted">No reports found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="small text-muted mt-2">
        Using <code><?=h($R_TBL)?></code><?= $I_TBL ? " (items: <code>".h($I_TBL)."</code>)" : '' ?><?= $R_DATE_COL ? " • date=<code>".h($R_DATE_COL)."</code>" : " • no date column" ?><?= $R_FARM_COL ? " • farm=<code>".h($R_FARM_COL)."</code>" : "" ?><?= $R_USER_COL ? " • user=<code>".h($R_USER_COL)."</code>" : "" ?><?= ($I_TBL && $I_FK) ? " • item_fk=<code>".h($I_FK)."</code>" : "" ?><?= ($I_TBL && $I_EGGS_COL) ? " • eggs=<code>".h($I_EGGS_COL)."</code>" : "" ?>
      </div>
    </div>
  </div>
</div>

<script>
// If a Bootstrap modal is opened from this layout, avoid stacking/overlap
document.addEventListener('show.bs.modal', (ev) => {
  document.querySelectorAll('.modal.show').forEach(m => {
    if (m !== ev.target) {
      const inst = bootstrap.Modal.getInstance(m);
      if (inst) inst.hide();
    }
  });
});
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
