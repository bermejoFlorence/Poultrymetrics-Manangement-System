<?php
// /admin/reports.php — POS Management Reports (monthly + daily + inventory movement)
// - Removes payment breakdown
// - Adds monthly sales chart
// - Adds inventory movement (receipts, sold, net, on-hand)

declare(strict_types=1);

$PAGE_TITLE = 'Reports';
require_once __DIR__ . '/inc/common.php';
@date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");
mysqli_report(MYSQLI_REPORT_OFF);

/* -------------------- Helper shims -------------------- */
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $t){
    $like=$c->real_escape_string($t); $q=@$c->query("SHOW TABLES LIKE '{$like}'");
    if (!$q) return false; $ok=$q->num_rows>0; $q->free(); return $ok;
  }
}
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $t, string $col){
    $tbl=str_replace('`','``',$t); $like=$c->real_escape_string($col);
    $q=@$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$like}'");
    if (!$q) return false; $ok=$q->num_rows>0; $q->free(); return $ok;
  }
}
if (!function_exists('firstExistingCol')) {
  function firstExistingCol(mysqli $c, string $t, array $cands){
    foreach($cands as $col){ if(colExists($c,$t,$col)) return $col; }
    return null;
  }
}

/* -------------------- Admin guard -------------------- */
if (function_exists('require_admin')) { require_admin($conn); }

/* -------------------- Filters -------------------- */
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$chan = $_GET['channel'] ?? 'all'; // all|pos|online
if ($from==='' || $to===''){ $to=date('Y-m-d'); $from=date('Y-m-d', strtotime('-29 days')); }

/* -------------------- Detect sources -------------------- */
// POS
$HAS_POS_SALES = tableExists($conn,'pos_sales') || tableExists($conn,'pos_transactions');
$POS_TBL       = tableExists($conn,'pos_sales') ? 'pos_sales' : (tableExists($conn,'pos_transactions') ? 'pos_transactions' : null);
$POS_COL_ID      = $POS_TBL ? (firstExistingCol($conn,$POS_TBL,['id','sale_id','transaction_id'])) : null;
$POS_COL_TOTAL   = $POS_TBL ? (firstExistingCol($conn,$POS_TBL,['total','grand_total','amount','net_total'])) : null;
$POS_COL_CREATED = $POS_TBL ? (firstExistingCol($conn,$POS_TBL,['created_at','sale_date','date','datetime','created'])) : null;

$POS_ITEMS_TBL = null;
foreach (['pos_sale_items','pos_items','pos_sales_items','pos_transaction_items'] as $cand){
  if ($POS_TBL && tableExists($conn,$cand)) { $POS_ITEMS_TBL=$cand; break; }
}
$POS_ITEMS_FK   = $POS_ITEMS_TBL ? (firstExistingCol($conn,$POS_ITEMS_TBL,['sale_id','pos_sale_id','sales_id','transaction_id'])) : null;
$POS_ITEMS_QTY  = $POS_ITEMS_TBL ? (firstExistingCol($conn,$POS_ITEMS_TBL,['qty','quantity'])) : null;
$POS_ITEMS_UNIT = $POS_ITEMS_TBL ? (firstExistingCol($conn,$POS_ITEMS_TBL,['unit_price','price','unit','rate'])) : null;
$POS_ITEMS_TOTAL= $POS_ITEMS_TBL ? (firstExistingCol($conn,$POS_ITEMS_TBL,['line_total','subtotal','amount','total'])) : null;
$POS_ITEMS_NAME = $POS_ITEMS_TBL ? (firstExistingCol($conn,$POS_ITEMS_TBL,['name','product_name','item_name','title','description'])) : null;
$POS_ITEMS_SIZE = $POS_ITEMS_TBL ? (firstExistingCol($conn,$POS_ITEMS_TBL,['size_id','product_id','sku_id'])) : null;

// ONLINE (Customer checkout)
$HAS_ON_ORDERS  = tableExists($conn,'customer_orders');
$ON_TBL         = $HAS_ON_ORDERS ? 'customer_orders' : null;
$ON_COL_ID      = $ON_TBL ? 'order_id'    : null;
$ON_COL_TOTAL   = $ON_TBL ? 'grand_total' : null;
$ON_COL_CREATED = $ON_TBL ? 'created_at'  : null;

$ON_ITEMS_TBL   = $HAS_ON_ORDERS && tableExists($conn,'customer_order_items') ? 'customer_order_items' : null;
$ON_ITEMS_FK    = $ON_ITEMS_TBL ? 'order_id'       : null;
$ON_ITEMS_QTY   = $ON_ITEMS_TBL ? 'qty'            : null;
$ON_ITEMS_UNIT  = $ON_ITEMS_TBL ? 'price_per_tray' : null;
$ON_ITEMS_TOTAL = $ON_ITEMS_TBL ? 'subtotal'       : null;
$ON_ITEMS_SIZE  = $ON_ITEMS_TBL ? 'size_id'        : null;

if (!$POS_TBL && !$ON_TBL){
  include __DIR__ . '/inc/layout_head.php';
  echo '<div class="alert alert-warning m-3">No data sources found. Please create POS tables or use customer checkout.</div>';
  include __DIR__ . '/inc/layout_foot.php'; exit;
}

$usePOS = $POS_TBL && ($chan==='all' || $chan==='pos');
$useON  = $ON_TBL  && ($chan==='all' || $chan==='online');

/* -------------------- KPI Helpers -------------------- */
function kpi_source(mysqli $c, string $tbl, string $colTotal, string $colCreated, string $from, string $to): array {
  $fromStart=$from.' 00:00:00'; $toEnd=$to.' 23:59:59';
  $sqlT="SELECT COALESCE(SUM(`$colTotal`),0) FROM `$tbl` WHERE `$colCreated` BETWEEN ? AND ?";
  $sqlN="SELECT COUNT(*) FROM `$tbl` WHERE `$colCreated` BETWEEN ? AND ?";
  $st=$c->prepare($sqlT); $st->bind_param('ss',$fromStart,$toEnd); $st->execute();
  $total=(float)($st->get_result()->fetch_row()[0]??0); $st->close();
  $st=$c->prepare($sqlN); $st->bind_param('ss',$fromStart,$toEnd); $st->execute();
  $orders=(int)($st->get_result()->fetch_row()[0]??0); $st->close();
  return ['sales'=>$total,'orders'=>$orders];
}
function items_sold_source(mysqli $c, string $parentTbl, string $parentCreated, string $itemsTbl, string $fk, string $qtyCol, string $from, string $to): int {
  $fromStart=$from.' 00:00:00'; $toEnd=$to.' 23:59:59';
  $sql="SELECT COALESCE(SUM(i.`$qtyCol`),0)
        FROM `$itemsTbl` i
        JOIN `$parentTbl` p ON p.`$fk` = i.`$fk`
        WHERE p.`$parentCreated` BETWEEN ? AND ?";
  $st=$c->prepare($sql); if(!$st) return 0;
  $st->bind_param('ss',$fromStart,$toEnd); $st->execute();
  $n=(int)($st->get_result()->fetch_row()[0]??0); $st->close(); return $n;
}

/* -------------------- KPIs -------------------- */
$k_sales=0.0; $k_orders=0; $k_items=0;
if ($usePOS && $POS_COL_TOTAL && $POS_COL_CREATED){
  $k=kpi_source($conn,$POS_TBL,$POS_COL_TOTAL,$POS_COL_CREATED,$from,$to);
  $k_sales+=$k['sales']; $k_orders+=$k['orders'];
  if ($POS_ITEMS_TBL && $POS_ITEMS_FK && $POS_ITEMS_QTY){
    $k_items+=items_sold_source($conn,$POS_TBL,$POS_COL_CREATED,$POS_ITEMS_TBL,$POS_ITEMS_FK,$POS_ITEMS_QTY,$from,$to);
  }
}
if ($useON && $ON_COL_TOTAL && $ON_COL_CREATED){
  $k=kpi_source($conn,$ON_TBL,$ON_COL_TOTAL,$ON_COL_CREATED,$from,$to);
  $k_sales+=$k['sales']; $k_orders+=$k['orders'];
  if ($ON_ITEMS_TBL && $ON_ITEMS_QTY){
    $k_items+=items_sold_source($conn,$ON_TBL,$ON_COL_CREATED,$ON_ITEMS_TBL,$ON_ITEMS_FK,$ON_ITEMS_QTY,$from,$to);
  }
}
$k_aov = $k_orders>0 ? ($k_sales/$k_orders) : 0.0;

/* -------------------- Sales by Day -------------------- */
function daily_series(mysqli $c, string $tbl, string $colTotal, string $colCreated, string $from, string $to): array {
  $fromStart=$from.' 00:00:00'; $toEnd=$to.' 23:59:59';
  $sql="SELECT DATE(`$colCreated`) d, COALESCE(SUM(`$colTotal`),0) s
        FROM `$tbl`
        WHERE `$colCreated` BETWEEN ? AND ?
        GROUP BY DATE(`$colCreated`)
        ORDER BY d";
  $st=$c->prepare($sql); $st->bind_param('ss',$fromStart,$toEnd); $st->execute();
  $out=[]; $res=$st->get_result(); while($r=$res->fetch_assoc()){ $out[$r['d']]=(float)$r['s']; } $st->close(); return $out;
}
$trend=[];
if ($usePOS && $POS_COL_TOTAL && $POS_COL_CREATED){
  foreach(daily_series($conn,$POS_TBL,$POS_COL_TOTAL,$POS_COL_CREATED,$from,$to) as $d=>$v){ $trend[$d]=($trend[$d]??0)+$v; }
}
if ($useON && $ON_COL_TOTAL && $ON_COL_CREATED){
  foreach(daily_series($conn,$ON_TBL,$ON_COL_TOTAL,$ON_COL_CREATED,$from,$to) as $d=>$v){ $trend[$d]=($trend[$d]??0)+$v; }
}
$dates=[]; $cur=strtotime($from); $end=strtotime($to);
while($cur<=$end){ $d=date('Y-m-d',$cur); $dates[]=$d; if(!isset($trend[$d])) $trend[$d]=0.0; $cur=strtotime('+1 day',$cur); }
$trend_labels=$dates; $trend_values=array_map(fn($d)=> (float)$trend[$d], $trend_labels);

/* -------------------- Monthly Sales -------------------- */
function monthly_series(mysqli $c, string $tbl, string $colTotal, string $colCreated, string $from, string $to): array {
  $fromStart=$from.' 00:00:00'; $toEnd=$to.' 23:59:59';
  $sql="SELECT DATE_FORMAT(`$colCreated`,'%Y-%m') m, COALESCE(SUM(`$colTotal`),0) s
        FROM `$tbl`
        WHERE `$colCreated` BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(`$colCreated`,'%Y-%m')
        ORDER BY m";
  $st=$c->prepare($sql); $st->bind_param('ss',$fromStart,$toEnd); $st->execute();
  $out=[]; $res=$st->get_result(); while($r=$res->fetch_assoc()){ $out[$r['m']]=(float)$r['s']; } $st->close(); return $out;
}
$monthly=[];
if ($usePOS && $POS_COL_TOTAL && $POS_COL_CREATED){
  foreach(monthly_series($conn,$POS_TBL,$POS_COL_TOTAL,$POS_COL_CREATED,$from,$to) as $m=>$v){ $monthly[$m]=($monthly[$m]??0)+$v; }
}
if ($useON && $ON_COL_TOTAL && $ON_COL_CREATED){
  foreach(monthly_series($conn,$ON_TBL,$ON_COL_TOTAL,$ON_COL_CREATED,$from,$to) as $m=>$v){ $monthly[$m]=($monthly[$m]??0)+$v; }
}
// ensure month buckets from from..to
$monLabels=[];
$cursor = strtotime(date('Y-m-01', strtotime($from)));
$endMon = strtotime(date('Y-m-01', strtotime($to)));
while($cursor <= $endMon){
  $mkey=date('Y-m',$cursor);
  $monLabels[]=$mkey;
  if(!isset($monthly[$mkey])) $monthly[$mkey]=0.0;
  $cursor=strtotime('+1 month',$cursor);
}
$month_values = array_map(fn($m)=> (float)$monthly[$m], $monLabels);
$month_labels_h = array_map(fn($m)=> date('M Y', strtotime($m.'-01')), $monLabels);

/* -------------------- Top Products (qty) -------------------- */
function top_products_from(mysqli $c, string $parentTbl, string $parentDateCol, string $itemsTbl, string $fk, ?string $sizeCol, ?string $nameCol, string $qtyCol, string $from, string $to): array {
  if (!$itemsTbl) return [];
  $fromStart=$from.' 00:00:00'; $toEnd=$to.' 23:59:59';
  $selKey = $sizeCol ? "i.`$sizeCol` AS size_id" : "i.`$nameCol` AS iname";
  $grpKey = $sizeCol ? "i.`$sizeCol`" : "i.`$nameCol`";
  $sql="SELECT $selKey, COALESCE(SUM(i.`$qtyCol`),0) AS q
        FROM `$itemsTbl` i
        JOIN `$parentTbl` p ON p.`$fk` = i.`$fk`
        WHERE p.`$parentDateCol` BETWEEN ? AND ?
        GROUP BY $grpKey
        ORDER BY q DESC LIMIT 10";
  $st=$c->prepare($sql); if(!$st) return [];
  $st->bind_param('ss',$fromStart,$toEnd); $st->execute();
  $rows=[]; $res=$st->get_result(); while($r=$res->fetch_assoc()){ $rows[]=$r; } $st->close(); return $rows;
}
$topRows=[];
if ($usePOS && $POS_ITEMS_TBL && $POS_ITEMS_QTY && ($POS_ITEMS_SIZE || $POS_ITEMS_NAME) && $POS_ITEMS_FK && $POS_COL_CREATED){
  foreach(top_products_from($conn,$POS_TBL,$POS_COL_CREATED,$POS_ITEMS_TBL,$POS_ITEMS_FK,$POS_ITEMS_SIZE,$POS_ITEMS_NAME,$POS_ITEMS_QTY,$from,$to) as $r){ $topRows[]=$r; }
}
if ($useON && $ON_ITEMS_TBL && $ON_ITEMS_QTY && $ON_ITEMS_FK && $ON_COL_CREATED){
  foreach(top_products_from($conn,$ON_TBL,$ON_COL_CREATED,$ON_ITEMS_TBL,$ON_ITEMS_FK,$ON_ITEMS_SIZE,null,$ON_ITEMS_QTY,$from,$to) as $r){ $topRows[]=$r; }
}
$SIZE_LABELS=[];
if (tableExists($conn,'egg_sizes')){
  $rs=@$conn->query("SELECT size_id, COALESCE(NULLIF(label,''), CONCAT('Size ',size_id)) AS lbl, COALESCE(code,'') AS code FROM egg_sizes");
  if ($rs){ while($r=$rs->fetch_assoc()){ $SIZE_LABELS[(int)$r['size_id']] = trim(($r['code']?($r['code'].' - '):'').$r['lbl']); } $rs->free(); }
}
$top_final=[];
foreach($topRows as $r){
  $label=''; $q=(int)($r['q']??0);
  if (isset($r['size_id']) && $r['size_id']!==''){
    $sid=(int)$r['size_id']; $label=$SIZE_LABELS[$sid] ?? ('Size '.$sid);
  } else { $label=(string)($r['iname'] ?? 'Item'); }
  $top_final[$label] = ($top_final[$label] ?? 0) + $q;
}
arsort($top_final);
$top_labels = array_slice(array_keys($top_final), 0, 10);
$top_values = array_map(fn($k)=> (int)$top_final[$k], $top_labels);

/* -------------------- Inventory Movement -------------------- */
/* Detect a receipts table (optional) */
$REC_TBL=null; $REC_COL_SIZE=null; $REC_COL_QTY=null; $REC_COL_DATE=null;
$receiptCandidates = [
  'egg_receipts','stock_receipts','inventory_receipts','inventory_in','receipts'
];
foreach($receiptCandidates as $t){
  if (!tableExists($conn,$t)) continue;
  $sizeCol = firstExistingCol($conn,$t, ['size_id','product_id','sku_id']);
  $qtyCol  = firstExistingCol($conn,$t, ['trays_in','trays','qty','quantity','in_qty','received_qty']);
  $dateCol = firstExistingCol($conn,$t, ['created_at','date','received_at','datetime','created']);
  if ($sizeCol && $qtyCol && $dateCol){
    $REC_TBL=$t; $REC_COL_SIZE=$sizeCol; $REC_COL_QTY=$qtyCol; $REC_COL_DATE=$dateCol; break;
  }
}
/* Sum receipts per size_id in range */
$receipts = []; // size_id => qty
if ($REC_TBL){
  $fromStart=$from.' 00:00:00'; $toEnd=$to.' 23:59:59';
  $sql="SELECT `$REC_COL_SIZE` sid, COALESCE(SUM(`$REC_COL_QTY`),0) q
        FROM `$REC_TBL`
        WHERE `$REC_COL_DATE` BETWEEN ? AND ?
        GROUP BY `$REC_COL_SIZE`";
  $st=$conn->prepare($sql);
  if ($st){
    $st->bind_param('ss',$fromStart,$toEnd); $st->execute();
    $res=$st->get_result(); while($r=$res->fetch_assoc()){ $receipts[(int)$r['sid']] = (int)$r['q']; }
    $st->close();
  }
}
/* Sum sold per size_id in range (POS + Online) */
$sold = []; // size_id => qty
if ($usePOS && $POS_ITEMS_TBL && $POS_ITEMS_QTY && $POS_ITEMS_SIZE && $POS_ITEMS_FK && $POS_COL_CREATED){
  $fromStart=$from.' 00:00:00'; $toEnd=$to.' 23:59:59';
  $sql="SELECT i.`$POS_ITEMS_SIZE` sid, COALESCE(SUM(i.`$POS_ITEMS_QTY`),0) q
        FROM `$POS_ITEMS_TBL` i
        JOIN `$POS_TBL` p ON p.`$POS_ITEMS_FK` = i.`$POS_ITEMS_FK`
        WHERE p.`$POS_COL_CREATED` BETWEEN ? AND ?
        GROUP BY i.`$POS_ITEMS_SIZE`";
  $st=$conn->prepare($sql);
  if ($st){ $st->bind_param('ss',$fromStart,$toEnd); $st->execute();
    $res=$st->get_result(); while($r=$res->fetch_assoc()){ $sid=(int)$r['sid']; $sold[$sid]=($sold[$sid]??0)+(int)$r['q']; }
    $st->close();
  }
}
if ($useON && $ON_ITEMS_TBL && $ON_ITEMS_QTY && $ON_ITEMS_SIZE && $ON_ITEMS_FK && $ON_COL_CREATED){
  $fromStart=$from.' 00:00:00'; $toEnd=$to.' 23:59:59';
  $sql="SELECT i.`$ON_ITEMS_SIZE` sid, COALESCE(SUM(i.`$ON_ITEMS_QTY`),0) q
        FROM `$ON_ITEMS_TBL` i
        JOIN `$ON_TBL` o ON o.`$ON_ITEMS_FK` = i.`$ON_ITEMS_FK`
        WHERE o.`$ON_COL_CREATED` BETWEEN ? AND ?
        GROUP BY i.`$ON_ITEMS_SIZE`";
  $st=$conn->prepare($sql);
  if ($st){ $st->bind_param('ss',$fromStart,$toEnd); $st->execute();
    $res=$st->get_result(); while($r=$res->fetch_assoc()){ $sid=(int)$r['sid']; $sold[$sid]=($sold[$sid]??0)+(int)$r['q']; }
    $st->close();
  }
}
/* Current on hand from egg_stock */
$onhand=[];
if (tableExists($conn,'egg_stock') && colExists($conn,'egg_stock','size_id') && colExists($conn,'egg_stock','trays_on_hand')){
  $rs=@$conn->query("SELECT size_id, trays_on_hand FROM egg_stock");
  if ($rs){ while($r=$rs->fetch_assoc()){ $onhand[(int)$r['size_id']] = (int)$r['trays_on_hand']; } $rs->free(); }
}
/* Build movement rows for all known size_ids (from sizes or unions) */
$allSids = [];
if (tableExists($conn,'egg_sizes')){
  $rs=@$conn->query("SELECT size_id FROM egg_sizes ORDER BY 1");
  if ($rs){ while($r=$rs->fetch_row()){ $allSids[(int)$r[0]]=true; } $rs->free(); }
}
foreach ([$receipts,$sold,$onhand] as $m){ foreach(array_keys($m) as $sid){ $allSids[(int)$sid]=true; } }
ksort($allSids);

/* Export inventory CSV */
if (($_GET['export'] ?? '') === 'inventory'){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="inventory_movement_'.$from.'_to_'.$to.'.csv"');
  $out=fopen('php://output','w');
  fputcsv($out, ['Size','Receipts','Sold','Net (Rec - Sold)','On Hand (Current)']);
  foreach(array_keys($allSids) as $sid){
    $lbl = $SIZE_LABELS[$sid] ?? ('Size '.$sid);
    $rec=(int)($receipts[$sid] ?? 0);
    $sd =(int)($sold[$sid] ?? 0);
    $net=$rec - $sd;
    $oh =(int)($onhand[$sid] ?? 0);
    fputcsv($out, [$lbl,$rec,$sd,$net,$oh]);
  }
  fclose($out); exit;
}

/* -------------------- Render -------------------- */
$CURRENT = 'reports.php';
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .kpi-card .v{ font-size:22px; font-weight:800 }
  .kpi-card .lbl{ color:#6b7280; font-size:.85rem }
  .chart-wrap{ min-height:300px }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">Reports</h5>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-auto">
    <label class="form-label small mb-1">From</label>
    <input type="date" name="from" class="form-control form-control-sm" value="<?php echo h($from); ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">To</label>
    <input type="date" name="to" class="form-control form-control-sm" value="<?php echo h($to); ?>">
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Channel</label>
    <select name="channel" class="form-select form-select-sm">
      <option value="all"    <?php echo $chan==='all'?'selected':''; ?>>All</option>
      <?php if ($POS_TBL): ?><option value="pos"    <?php echo $chan==='pos'?'selected':''; ?>>POS (Walk-in)</option><?php endif; ?>
      <?php if ($ON_TBL):  ?><option value="online" <?php echo $chan==='online'?'selected':''; ?>>Online (Customer)</option><?php endif; ?>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-dark"><i class="fa-solid fa-filter me-1"></i>Filter</button>
    <a class="btn btn-sm btn-outline-secondary" href="reports.php"><i class="fa-solid fa-rotate me-1"></i>Reset</a>
  </div>
  <div class="col-auto ms-auto">
    <div class="btn-group">
      <a class="btn btn-sm btn-outline-secondary" href="?<?php
        echo h(http_build_query(array_merge($_GET,['export'=>'transactions']))); ?>">
        <i class="fa-solid fa-file-export me-1"></i> Export Transactions CSV
      </a>
      <a class="btn btn-sm btn-outline-secondary" href="?<?php
        echo h(http_build_query(array_merge($_GET,['export'=>'inventory']))); ?>">
        <i class="fa-solid fa-boxes-stacked me-1"></i> Export Inventory CSV
      </a>
    </div>
  </div>
</form>

<div class="row g-3">
  <!-- KPIs -->
  <div class="col-md-3">
    <div class="card kpi-card"><div class="card-body d-flex justify-content-between align-items-center">
      <div><div class="lbl">Sales</div><div class="v">₱<?php echo number_format($k_sales,2); ?></div></div>
      <i class="fa fa-coins text-muted"></i>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card kpi-card"><div class="card-body d-flex justify-content-between align-items-center">
      <div><div class="lbl">Orders</div><div class="v"><?php echo (int)$k_orders; ?></div></div>
      <i class="fa fa-receipt text-muted"></i>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card kpi-card"><div class="card-body d-flex justify-content-between align-items-center">
      <div><div class="lbl">Items Sold</div><div class="v"><?php echo (int)$k_items; ?></div></div>
      <i class="fa fa-cart-flatbed text-muted"></i>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card kpi-card"><div class="card-body d-flex justify-content-between align-items-center">
      <div><div class="lbl">Avg Order Value</div><div class="v">₱<?php echo number_format($k_aov,2); ?></div></div>
      <i class="fa fa-chart-line text-muted"></i>
    </div></div>
  </div>

  <!-- Charts -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><strong>Sales by Day</strong></div>
      <div class="card-body chart-wrap"><canvas id="chartDaily"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><strong>Monthly Sales</strong></div>
      <div class="card-body chart-wrap"><canvas id="chartMonthly"></canvas></div>
    </div>
  </div>

  <!-- Top products -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Top Products</strong>
        <span class="small text-muted">by quantity (top 10)</span>
      </div>
      <div class="card-body table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th style="width:48px">#</th><th>Product</th><th class="text-end">Qty</th></tr></thead>
          <tbody>
            <?php if ($top_labels): foreach($top_labels as $i=>$lbl): ?>
              <tr><td><?php echo $i+1; ?></td><td><?php echo h($lbl); ?></td><td class="text-end"><?php echo (int)$top_values[$i]; ?></td></tr>
            <?php endforeach; else: ?>
              <tr><td colspan="3" class="text-center text-muted py-4">No items in this range.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Inventory Movement -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Inventory Movement</strong>
        <span class="small text-muted">
          Range: <?php echo h($from); ?> → <?php echo h($to); ?>
          <?php if ($REC_TBL): ?> · Receipts from <code><?php echo h($REC_TBL); ?></code><?php else: ?> · Receipts not detected<?php endif; ?>
        </span>
      </div>
      <div class="card-body table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Size</th>
              <th class="text-end">Receipts</th>
              <th class="text-end">Sold</th>
              <th class="text-end">Net (Rec - Sold)</th>
              <th class="text-end">On Hand (Current)</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $totRec=0; $totSold=0; $totNet=0; $totOH=0;
              if ($allSids):
                foreach(array_keys($allSids) as $sid):
                  $lbl = $SIZE_LABELS[$sid] ?? ('Size '.$sid);
                  $rec = (int)($receipts[$sid] ?? 0);
                  $sd  = (int)($sold[$sid] ?? 0);
                  $net = $rec - $sd;
                  $oh  = (int)($onhand[$sid] ?? 0);
                  $totRec += $rec; $totSold += $sd; $totNet += $net; $totOH += $oh;
            ?>
              <tr>
                <td><?php echo h($lbl); ?></td>
                <td class="text-end"><?php echo $rec; ?></td>
                <td class="text-end"><?php echo $sd; ?></td>
                <td class="text-end <?php echo $net<0?'text-danger':''; ?>"><?php echo $net; ?></td>
                <td class="text-end"><?php echo $oh; ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No inventory data.</td></tr>
            <?php endif; ?>
          </tbody>
          <?php if ($allSids): ?>
          <tfoot>
            <tr class="table-light fw-semibold">
              <td>Totals</td>
              <td class="text-end"><?php echo $totRec; ?></td>
              <td class="text-end"><?php echo $totSold; ?></td>
              <td class="text-end <?php echo $totNet<0?'text-danger':''; ?>"><?php echo $totNet; ?></td>
              <td class="text-end"><?php echo $totOH; ?></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  // Daily sales (line)
  const dailyLabels = <?php echo json_encode(array_map(fn($d)=>date('M j', strtotime($d)), $trend_labels), JSON_UNESCAPED_SLASHES); ?>;
  const dailyData   = <?php echo json_encode(array_values($trend_values), JSON_UNESCAPED_SLASHES); ?>;
  new Chart(document.getElementById('chartDaily').getContext('2d'), {
    type: 'line',
    data: { labels: dailyLabels, datasets:[{ label:'Sales', data: dailyData, tension:.25 }] },
    options: {
      responsive:true,
      plugins:{ legend:{ display:false } },
      scales:{ y:{ ticks:{ callback:v=>'₱'+Number(v).toLocaleString() } } }
    }
  });

  // Monthly sales (bar)
  const monthLabels = <?php echo json_encode($month_labels_h, JSON_UNESCAPED_SLASHES); ?>;
  const monthData   = <?php echo json_encode(array_values($month_values), JSON_UNESCAPED_SLASHES); ?>;
  new Chart(document.getElementById('chartMonthly').getContext('2d'), {
    type: 'bar',
    data: { labels: monthLabels, datasets:[{ label:'Monthly Sales', data: monthData }] },
    options: {
      responsive:true,
      plugins:{ legend:{ display:false } },
      scales:{ y:{ ticks:{ callback:v=>'₱'+Number(v).toLocaleString() } } }
    }
  });
})();
</script>

<footer class="mt-2"><p class="mb-0 small text-muted">&copy; <?php echo date('Y'); ?> PoultryMetrics</p></footer>
<?php include __DIR__ . '/inc/layout_foot.php';
