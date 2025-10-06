<?php
// /admin/i102_report_view.php — schema-aware + responsive + safe CSV export
require_once __DIR__ . '/inc/common.php'; // $conn
@date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

if (!function_exists('h')){ function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/* ----------------- helpers ----------------- */
function tableExists(mysqli $c, string $t): bool {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
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
  $t = $c->real_escape_string($t);
  $res = @$c->query("SHOW KEYS FROM `{$t}` WHERE Key_name='PRIMARY'");
  if(!$res) return null; $row = $res->fetch_assoc(); $res->free();
  return $row['Column_name'] ?? null;
}
function listLikeTables(mysqli $c, string $like): array {
  $like = $c->real_escape_string($like);
  $res = @$c->query("SHOW TABLES LIKE '%{$like}%'"); if(!$res) return [];
  $out=[]; while($r=$res->fetch_row()){ $out[]=$r[0]; } $res->free(); return $out;
}
/** choose first existing column from candidates; return null if none */
function pickCol(mysqli $c, string $tbl, array $cands): ?string {
  foreach($cands as $cc){ if(colExists($c,$tbl,$cc)) return $cc; }
  return null;
}
/** pick table from candidates or scan; don't force columns */
function pickTable(mysqli $c, array $candidates): ?string {
  foreach($candidates as $t){ if(tableExists($c,$t)) return $t; }
  foreach(['i102','report','egg'] as $kw){
    foreach(listLikeTables($c,$kw) as $t){ return $t; }
  }
  return null;
}

/* ----------------- resolve tables/ids ----------------- */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /admin/i102_reports.php'); exit; }

$rtbl_override = trim((string)($_GET['rtbl'] ?? ''));
$itbl_override = trim((string)($_GET['itbl'] ?? ''));

$R_TBL = $rtbl_override !== '' ? $rtbl_override
  : pickTable($conn, ['i102_reports','i102_report','reports_i102','i102','i102_hdr','report_headers','egg_reports']);
$I_TBL = $itbl_override !== '' ? $itbl_override
  : pickTable($conn, ['i102_report_items','i102_items','i102_item','report_items','egg_report_items','i102_details','i102_dtls']);

if (!$R_TBL || !tableExists($conn,$R_TBL)) {
  http_response_code(500);
  $hint = implode(', ', array_map('h', array_unique(array_merge(
    listLikeTables($conn,'i102'),
    listLikeTables($conn,'report'),
    listLikeTables($conn,'egg')
  ))));
  echo "Report table not found. Open <code>/admin/i102_reports.php</code> to browse, or call this as <code>?id={$id}&rtbl=your_reports_table&itbl=your_items_table</code>.<br>";
  echo "Tables that look relevant: <em>$hint</em>";
  exit;
}
$R_PK = primaryKey($conn,$R_TBL) ?: (colExists($conn,$R_TBL,'id') ? 'id' : (colExists($conn,$R_TBL,'report_id') ? 'report_id' : 'id'));

/* header columns */
$R_DATE_COL    = pickCol($conn,$R_TBL, ['report_date','date','reported_at','created_at','dt']);
$R_FARM_COL    = pickCol($conn,$R_TBL, ['farm','farm_name','site','location']);
$R_USER_STRCOL = pickCol($conn,$R_TBL, ['username','submitted_by','created_by']);
$R_USER_IDCOL  = pickCol($conn,$R_TBL, ['worker_id','user_id','submitted_by_id']);
$R_CREATED_COL = pickCol($conn,$R_TBL, ['created_at','submitted_at','timestamp','created']);

/* items linkage */
$I_FK = null;
if ($I_TBL && tableExists($conn,$I_TBL)) {
  if      (colExists($conn,$I_TBL,'report_id')) $I_FK = 'report_id';
  elseif  ($R_PK && colExists($conn,$I_TBL,$R_PK)) $I_FK = $R_PK;
  else foreach(['i102_report_id','parent_id','header_id','hdr_id'] as $cand){ if(colExists($conn,$I_TBL,$cand)){ $I_FK=$cand; break; } }
}

/* item metric columns (best-effort) */
$COL = [
  'bldg'       => $I_TBL ? pickCol($conn,$I_TBL,['bldg','building','house','house_no','bldg_no']) : null,
  'egg_am'     => $I_TBL ? pickCol($conn,$I_TBL,['egg_am','eggs_am','am_eggs','egg_am_count']) : null,
  'egg_pm'     => $I_TBL ? pickCol($conn,$I_TBL,['egg_pm','eggs_pm','pm_eggs','egg_pm_count']) : null,
  'egg_345'    => $I_TBL ? pickCol($conn,$I_TBL,['egg_345','egg345','eggs_345','egg_mid']) : null,
  'egg_total'  => $I_TBL ? pickCol($conn,$I_TBL,['egg_total','eggs_total','total_eggs','eggs']) : null,
  'feed_sacks' => $I_TBL ? pickCol($conn,$I_TBL,['feed_sacks','sacks','feed_sack_count']) : null,
  'feed_total' => $I_TBL ? pickCol($conn,$I_TBL,['feed_total','feedkg','feed_kg_total','feed_quantity']) : null,
  'beg_bal'    => $I_TBL ? pickCol($conn,$I_TBL,['beg_bal','beginning_balance','beg_balance','beg']) : null,
  'm'          => $I_TBL ? pickCol($conn,$I_TBL,['m','mortality','mortality_count']) : null,
  'rejects'    => $I_TBL ? pickCol($conn,$I_TBL,['rejects','reject','reject_count']) : null,
  'water_bag'  => $I_TBL ? pickCol($conn,$I_TBL,['water_bag','waterbag','water_bag_count']) : null,
  'cull'       => $I_TBL ? pickCol($conn,$I_TBL,['cull','culls','cull_count']) : null,
  'old'        => $I_TBL ? pickCol($conn,$I_TBL,['old','old_hens','old_count']) : null,
  'inv_total'  => $I_TBL ? pickCol($conn,$I_TBL,['inv_total','inventory_total','inv']) : null,
  'balance'    => $I_TBL ? pickCol($conn,$I_TBL,['balance','ending_balance','end_balance']) : null,
];

/* ----------------- load header ----------------- */
$hdr = null; $rows = [];

$sqlH = "SELECT * FROM `{$R_TBL}` WHERE `{$R_PK}` = ? LIMIT 1";
$stH = $conn->prepare($sqlH);
if (!$stH) { http_response_code(500); echo "Prepare failed: ".h($conn->error); exit; }
$stH->bind_param('i',$id);
$stH->execute(); $hdr = $stH->get_result()->fetch_assoc(); $stH->close();

if (!$hdr){ header('Location: /admin/i102_reports.php'); exit; }

/* ----------------- load items ----------------- */
if ($I_TBL && $I_FK) {
  $orderBy = $COL['bldg'] ? " ORDER BY `{$COL['bldg']}` ASC" : "";
  $sqlI = "SELECT * FROM `{$I_TBL}` WHERE `{$I_FK}` = ?{$orderBy}";
  $stI = $conn->prepare($sqlI);
  if ($stI) {
    $stI->bind_param('i',$id);
    $stI->execute();
    $rows = $stI->get_result()->fetch_all(MYSQLI_ASSOC);
    $stI->close();
  } else {
    $rows = [];
  }
}

/* ----------------- helpers for sums ----------------- */
function sumColDyn(array $rows, ?string $k): int {
  if (!$k) return 0; $s=0; foreach($rows as $r){ $s+=(int)($r[$k] ?? 0); } return $s;
}
function getv($arr, ?string $k, $def='—'){
  if(!$k) return $def; $v = $arr[$k] ?? null; return ($v === null || $v==='') ? $def : $v;
}
function getNum($arr, ?string $k){ return (int)($k ? ($arr[$k] ?? 0) : 0); }

/* ----------------- CSV export BEFORE any HTML output ----------------- */
$qs_keep = '';
if ($rtbl_override!=='') $qs_keep .= '&rtbl='.rawurlencode($rtbl_override);
if ($itbl_override!=='') $qs_keep .= '&itbl='.rawurlencode($itbl_override);

if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  $fnameDate = $R_DATE_COL ? (string)$hdr[$R_DATE_COL] : date('Y-m-d');
  $fnameFarm = $R_FARM_COL ? (string)$hdr[$R_FARM_COL] : 'farm';
  $fname = 'i102_'.$fnameDate.'_'.preg_replace('/\W+/','_', $fnameFarm).'.csv';
  header('Content-Disposition: attachment; filename='.$fname);
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Bldg','AM','PM','3:45','EggTotal','#Sacks','FeedTotal','BegBal','M','Rejects','WaterBag','Cull','OLD','InvTotal','Balance']);
  foreach ($rows as $r){
    fputcsv($out, [
      $COL['bldg']       ? ($r[$COL['bldg']] ?? '') : '',
      getNum($r,$COL['egg_am']),
      getNum($r,$COL['egg_pm']),
      getNum($r,$COL['egg_345']),
      getNum($r,$COL['egg_total']),
      getNum($r,$COL['feed_sacks']),
      getNum($r,$COL['feed_total']),
      getNum($r,$COL['beg_bal']),
      getNum($r,$COL['m']),
      getNum($r,$COL['rejects']),
      getNum($r,$COL['water_bag']),
      getNum($r,$COL['cull']),
      getNum($r,$COL['old']),
      getNum($r,$COL['inv_total']),
      getNum($r,$COL['balance']),
    ]);
  }
  exit;
}

/* ----------------- view ----------------- */
$PAGE_TITLE = 'I-102 Report View';
$CURRENT    = 'i102_reports.php';
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .sheet-table{ width:100%; border-collapse:collapse; table-layout:fixed; font-size:12.5px; }
  .sheet-table th,.sheet-table td{ border:1px solid #e3e7eb; padding:6px 8px; text-align:center; }
  .sheet-table thead th{ background:#f8fafc; }
  .sheet-table .bldg{ width:40px; }
  .table-responsive{ overflow-x:auto; }

  /* Keep any modal above sticky toolbars/sidebars (consistency with other pages) */
  :root{ --pm-z-backdrop: 2040; --pm-z-modal: 2050; }
  .modal-backdrop{ z-index: var(--pm-z-backdrop) !important; }
  .modal{ z-index: var(--pm-z-modal) !important; }
  body.modal-open{ overflow: hidden; }

  @media print{
    .no-print{ display:none !important; }
    .sheet-table{ font-size:11px; }
  }
</style>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
      <i class="fa-solid fa-table-list text-warning"></i>
      <strong>I-102 Report</strong>
      <span class="text-muted small">
        <?= h($R_DATE_COL ? (string)$hdr[$R_DATE_COL] : '—') ?> ·
        <?= h($R_FARM_COL ? ((string)$hdr[$R_FARM_COL] ?: '—') : '—') ?>
      </span>
    </div>
    <div class="no-print d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/admin/i102_reports.php">
        <i class="fa-solid fa-arrow-left"></i>
      </a>
      <a class="btn btn-sm btn-outline-dark" href="?id=<?= (int)$id ?>&export=csv<?= $qs_keep ?>"><i class="fa-solid fa-file-csv me-1"></i>CSV</a>
      <button class="btn btn-sm btn-outline-dark" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
    </div>
  </div>

  <div class="card-body">
    <div class="row g-2 mb-2">
      <div class="col-md-3">
        <div class="small text-muted">Date</div>
        <div class="fw-semibold"><?= h($R_DATE_COL ? (string)$hdr[$R_DATE_COL] : '—') ?></div>
      </div>
      <div class="col-md-3">
        <div class="small text-muted">Farm</div>
        <div class="fw-semibold"><?= h($R_FARM_COL ? ((string)$hdr[$R_FARM_COL] ?: '—') : '—') ?></div>
      </div>
      <div class="col-md-3">
        <div class="small text-muted">Submitted by</div>
        <div class="fw-semibold">
          <?php
            if ($R_USER_STRCOL)      echo h((string)($hdr[$R_USER_STRCOL] ?? '—'));
            elseif ($R_USER_IDCOL)   { $v = (string)($hdr[$R_USER_IDCOL] ?? ''); echo $v!=='' ? ('#'.h($v)) : '—'; }
            else                     echo '—';
          ?>
        </div>
      </div>
      <div class="col-md-3">
        <div class="small text-muted">Submitted at</div>
        <div class="fw-semibold"><?= h($R_CREATED_COL ? (string)$hdr[$R_CREATED_COL] : '—') ?></div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="sheet-table">
        <thead>
          <tr>
            <th class="bldg" rowspan="2">Bldg</th>
            <th colspan="4">Daily Egg Production</th>
            <th colspan="2">Feeds</th>
            <th colspan="8">Layer Inventory</th>
          </tr>
          <tr>
            <th>AM</th><th>PM</th><th>3:45</th><th>Total</th>
            <th># sacks</th><th>Total</th>
            <th>Beg Bal</th><th>M</th><th>Rejects</th><th>Water Bag</th><th>Cull</th><th>OLD</th><th>Total</th><th>Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="bldg"><?= h($COL['bldg'] ? (string)($r[$COL['bldg']] ?? '') : '') ?></td>
              <td><?= getNum($r,$COL['egg_am']) ?></td>
              <td><?= getNum($r,$COL['egg_pm']) ?></td>
              <td><?= getNum($r,$COL['egg_345']) ?></td>
              <td><?= getNum($r,$COL['egg_total']) ?></td>
              <td><?= getNum($r,$COL['feed_sacks']) ?></td>
              <td><?= getNum($r,$COL['feed_total']) ?></td>
              <td><?= getNum($r,$COL['beg_bal']) ?></td>
              <td><?= getNum($r,$COL['m']) ?></td>
              <td><?= getNum($r,$COL['rejects']) ?></td>
              <td><?= getNum($r,$COL['water_bag']) ?></td>
              <td><?= getNum($r,$COL['cull']) ?></td>
              <td><?= getNum($r,$COL['old']) ?></td>
              <td><?= getNum($r,$COL['inv_total']) ?></td>
              <td><?= getNum($r,$COL['balance']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="15" class="text-center text-muted">No items found.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="text-start fw-bold">TOTAL</td>
            <td><?= number_format(sumColDyn($rows,$COL['egg_am'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['egg_pm'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['egg_345'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['egg_total'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['feed_sacks'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['feed_total'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['beg_bal'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['m'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['rejects'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['water_bag'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['cull'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['old'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['inv_total'])) ?></td>
            <td><?= number_format(sumColDyn($rows,$COL['balance'])) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php
      // remarks, if available
      $remarksCol = pickCol($conn,$R_TBL,['remarks','note','notes','comment','comments']);
      if ($remarksCol && (string)($hdr[$remarksCol] ?? '') !== ''):
    ?>
      <div class="mt-3">
        <div class="small text-muted">Remarks</div>
        <div class="border rounded p-2 bg-light"><?= nl2br(h((string)$hdr[$remarksCol])) ?></div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
