<?php
// admin/egg_reports.php — Daily Sheet (AM | PM | 3:45 PM | Total)
// + Buildings integration
// + Export CSV (?export=csv)
// + Print/PDF view (?print=1)
//
// This page auto-ensures egg_production schema and reads optional buildings list.

require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/../config.php';
@$conn->query("SET time_zone = '+08:00'");

/* ---------------- helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function q(mysqli $c,string $sql){ return @$c->query($sql); }
function colExists(mysqli $c,string $t,string $col): bool{
  $t=$c->real_escape_string($t); $col=$c->real_escape_string($col);
  $r=@$c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'"); return !!($r && $r->num_rows);
}
function tableExists(mysqli $c,string $t): bool{
  $t=$c->real_escape_string($t);
  $r=@$c->query("SHOW TABLES LIKE '{$t}'"); return !!($r && $r->num_rows);
}
function getShiftEnum(mysqli $c): array{
  $r=@$c->query("SHOW COLUMNS FROM egg_production LIKE 'shift'");
  if($r && ($row=$r->fetch_assoc()) && preg_match("/^enum\\((.*)\\)$/i",$row['Type'],$m)){
    return array_map(fn($s)=>trim($s," '"), explode(',', $m[1]));
  }
  return [];
}

/* ---------------- ensure egg_production schema ---------------- */
if(!tableExists($conn,'egg_production')){
  q($conn,"CREATE TABLE egg_production(
    id INT AUTO_INCREMENT PRIMARY KEY,
    prod_date DATE NOT NULL,
    shift ENUM('AM','PM','PM2','DAY') DEFAULT 'DAY',
    block_code VARCHAR(64) NULL,
    eggs_collected INT DEFAULT 0,
    rej_crack_count INT DEFAULT 0,
    mortality INT DEFAULT 0,
    notes VARCHAR(255),
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(prod_date), INDEX(shift), INDEX(block_code)
  ) ENGINE=InnoDB");
}
if(!colExists($conn,'egg_production','eggs_collected')) q($conn,"ALTER TABLE egg_production ADD COLUMN eggs_collected INT DEFAULT 0");
if(!colExists($conn,'egg_production','block_code'))    q($conn,"ALTER TABLE egg_production ADD COLUMN block_code VARCHAR(64) NULL");
$enum = getShiftEnum($conn);
$wanted = array_unique(array_merge($enum ?: [], ['AM','PM','PM2','DAY']));
$quoted = implode(',', array_map(fn($v)=>"'".$conn->real_escape_string($v)."'", $wanted));
q($conn,"ALTER TABLE egg_production MODIFY shift ENUM($quoted) DEFAULT 'DAY'");
q($conn,"UPDATE egg_production SET prod_date=CURDATE() WHERE prod_date IS NULL");
q($conn,"ALTER TABLE egg_production MODIFY prod_date DATE NOT NULL");

/* ---------------- CSRF ---------------- */
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

/* ---------------- input ---------------- */
$sheetDate = $_GET['date'] ?? date('Y-m-d');
$doExport  = ($_GET['export'] ?? '') === 'csv';
$doPrint   = isset($_GET['print']);

/* ---------------- buildings list ---------------- */
$BUILDINGS=[];
if (tableExists($conn,'buildings')){
  $r=$conn->query("SELECT code FROM buildings WHERE is_active=1 ORDER BY sort_order, code");
  if($r) while($row=$r->fetch_assoc()) $BUILDINGS[]=$row['code'];
}
if (!$BUILDINGS){
  $r=$conn->query("SELECT DISTINCT block_code FROM egg_production WHERE block_code IS NOT NULL AND block_code<>'' ORDER BY block_code*1, block_code");
  if($r) while($row=$r->fetch_assoc()) $BUILDINGS[]=$row['block_code'];
}
if (!$BUILDINGS){
  $BUILDINGS = array_map('strval', range(1,14));
  $BUILDINGS[]='17'; $BUILDINGS[]='18-ngaran'; $BUILDINGS[]='19-ngaran'; $BUILDINGS[]='20-ngaran';
}

/* ---------------- read existing for the date ---------------- */
$existing=[];
$st=$conn->prepare("SELECT block_code, shift, eggs_collected FROM egg_production WHERE prod_date=?");
$st->bind_param('s',$sheetDate);
if($st->execute()){
  $rs=$st->get_result();
  while($row=$rs->fetch_assoc()){
    $existing[$row['block_code'] ?: ''][$row['shift'] ?: 'DAY'] = (int)$row['eggs_collected'];
  }
}

/* ---------------- totals ---------------- */
$totAM=$totPM=$totPM2=$grand=0;
foreach($BUILDINGS as $b){
  $am  = (int)($existing[$b]['AM']  ?? 0);
  $pm  = (int)($existing[$b]['PM']  ?? 0);
  $pm2 = (int)($existing[$b]['PM2'] ?? 0);
  $totAM += $am; $totPM += $pm; $totPM2 += $pm2; $grand += ($am+$pm+$pm2);
}

/* ---------------- export CSV ---------------- */
if ($doExport) {
  // Build rows
  $rows = [];
  $rows[] = ['Daily Egg Production', '', '', '', ''];
  $rows[] = ['Date', $sheetDate, '', '', ''];
  $rows[] = ['Bldg #','AM','PM','3:45 PM','Total'];

  foreach($BUILDINGS as $b){
    $am=(int)($existing[$b]['AM']??0);
    $pm=(int)($existing[$b]['PM']??0);
    $pm2=(int)($existing[$b]['PM2']??0);
    $rows[] = [$b, $am, $pm, $pm2, $am+$pm+$pm2];
  }
  $rows[] = ['TOTAL', $totAM, $totPM, $totPM2, $grand];

  // Output headers
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="egg_daily_'.$sheetDate.'.csv"');
  // BOM for Excel
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  foreach ($rows as $r) fputcsv($out, $r);
  fclose($out);
  exit;
}

/* ---------------- save bulk (from main view) ---------------- */
if (($_POST['action'] ?? '')==='bulk_save'){
  if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    $_SESSION['flash']=['t'=>'err','m'=>'Invalid CSRF token.']; header("Location: egg_reports.php?date=$sheetDate"); exit;
  }
  $date=$_POST['sheet_date'] ?? date('Y-m-d');
  $block=$_POST['block'] ?? []; $am=$_POST['am'] ?? []; $pm=$_POST['pm'] ?? []; $pm2=$_POST['pm2'] ?? [];
  $uid=(int)($_SESSION['user_id'] ?? 0);

  $conn->begin_transaction();
  try{
    for($i=0;$i<count($block);$i++){
      $b=trim($block[$i] ?? ''); if($b==='') continue;
      foreach ([['AM',$am], ['PM',$pm], ['PM2',$pm2]] as [$shift,$arr]) {
        $val = (int)max(0, $arr[$i] ?? 0);
        $find=$conn->prepare("SELECT id FROM egg_production WHERE prod_date=? AND block_code=? AND shift=? LIMIT 1");
        $find->bind_param('sss',$date,$b,$shift); $find->execute();
        $rid=(int)($find->get_result()->fetch_assoc()['id'] ?? 0);
        if($rid){
          $upd=$conn->prepare("UPDATE egg_production SET eggs_collected=?, created_by=? WHERE id=?");
          $upd->bind_param('iii',$val,$uid,$rid); if(!$upd->execute()) throw new Exception($conn->error);
        }else{
          $ins=$conn->prepare("INSERT INTO egg_production(prod_date,shift,block_code,eggs_collected,created_by) VALUES (?,?,?,?,?)");
          $ins->bind_param('sssii',$date,$shift,$b,$val,$uid); if(!$ins->execute()) throw new Exception($conn->error);
        }
      }
    }
    $conn->commit();
    $_SESSION['flash']=['t'=>'ok','m'=>'Daily sheet saved.'];
  }catch(Exception $e){
    $conn->rollback();
    $_SESSION['flash']=['t'=>'err','m'=>'Save failed: '.$e->getMessage()];
  }
  header("Location: egg_reports.php?date=$date"); exit;
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ---------------- PRINT VIEW ---------------- */
if ($doPrint){
  $preparedBy = $_SESSION['username'] ?? '________________';
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Print - Daily Egg Production</title>
    <style>
      @media print { @page { size: A4 portrait; margin: 10mm; } }
      body{ font-family: Arial, Helvetica, sans-serif; color:#222; margin:0; background:#fff; }
      .wrap{ max-width: 800px; margin: 0 auto; padding: 12px; }
      h2{ margin: 0 0 6px; }
      .meta{ display:flex; justify-content:space-between; margin-bottom:8px; }
      table{ width:100%; border-collapse:collapse; font-size:13px }
      th,td{ border:1px solid #333; padding:6px 8px }
      thead th{ background:#e6eefc; text-transform:uppercase }
      tfoot td{ background:#fff7cc; font-weight:bold }
      .sig{ margin-top:16px; display:flex; gap:24px; align-items:center }
      .line{ flex:0 0 260px; border-bottom:1px solid #333; height:18px }
      .right{ text-align:right }
    </style>
  </head>
  <body onload="setTimeout(()=>window.print(), 150)">
    <div class="wrap">
      <h2>Daily Egg Production</h2>
      <div class="meta">
        <div><strong>Date:</strong> <?php echo h($sheetDate); ?></div>
        <div><strong>Prepared By:</strong> <?php echo h($preparedBy); ?></div>
      </div>
      <table>
        <thead>
          <tr><th>Bldg #</th><th>AM</th><th>PM</th><th>3:45 PM</th><th>Total</th></tr>
        </thead>
        <tbody>
          <?php foreach($BUILDINGS as $b):
            $am=(int)($existing[$b]['AM']??0);
            $pm=(int)($existing[$b]['PM']??0);
            $pm2=(int)($existing[$b]['PM2']??0);
          ?>
          <tr>
            <td><?php echo h($b); ?></td>
            <td class="right"><?php echo number_format($am); ?></td>
            <td class="right"><?php echo number_format($pm); ?></td>
            <td class="right"><?php echo number_format($pm2); ?></td>
            <td class="right"><?php echo number_format($am+$pm+$pm2); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="right"><strong>TOTAL:</strong></td>
            <td class="right"><strong><?php echo number_format($totAM); ?></strong></td>
            <td class="right"><strong><?php echo number_format($totPM); ?></strong></td>
            <td class="right"><strong><?php echo number_format($totPM2); ?></strong></td>
            <td class="right"><strong><?php echo number_format($grand); ?></strong></td>
          </tr>
        </tfoot>
      </table>

      <div class="sig">
        <div>Prepared By:</div><div class="line"></div>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* ---------------- normal page (interactive) ---------------- */
$CURRENT = basename($_SERVER['PHP_SELF']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daily Egg Production | PoultryMetrics</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
  :root{ --accent:#f5a425; --dark:#1f2327; --dark-2:#242a30; --grid:#d9dde3; --bg:#f6f8fa; --card-radius:14px; --sidebar-w:210px; --shadow:0 10px 28px rgba(0,0,0,.10); }
  body{font-family:'Poppins',sans-serif;background:var(--bg);overflow-x:hidden}
  .header-area{position:fixed;inset:0 0 auto 0;height:56px;background:var(--dark);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 14px;z-index:1000;border-bottom:1px solid rgba(255,255,255,.06)}
  .header-area .logo{font-size:17px;font-weight:700;color:#f5a425;text-decoration:none}
  .menu-trigger{cursor:pointer;color:#fff;font-size:18px;display:flex;align-items:center;gap:6px}
  .sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-w);background:linear-gradient(180deg,#1f2327,#242a30);padding-top:56px;z-index:999;overflow-y:auto}
  .sidebar .brand{display:flex;align-items:center;gap:8px;padding:10px 12px;color:#fff;border-bottom:1px solid rgba(255,255,255,.06)}
  .sidebar h6{color:#aeb6bf;font-size:10px;letter-spacing:.14em;margin:10px 12px 4px;text-transform:uppercase}
  .sidebar .nav-link{color:#cfd3d7;padding:6px 10px;margin:1px 8px;border-radius:8px;display:flex;align-items:center;gap:8px;font-size:13px}
  .sidebar .nav-link:hover,.sidebar .nav-link.active{background:#2a3037;color:#f5a425}
  .content{margin-left:var(--sidebar-w);padding:68px 14px 16px}

  .sheet{background:#fff;border-radius:var(--card-radius);box-shadow:var(--shadow);padding:12px}
  .sheet-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
  .sheet-title h6{margin:0;font-weight:800}
  .sheet table{width:100%;border-collapse:separate;border-spacing:0}
  .sheet th,.sheet td{border:1px solid var(--grid);padding:6px 8px;font-size:13px;background:#fff}
  .sheet thead th{background:#eef6ff;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
  .sheet tfoot td{background:#fffbe6;font-weight:800}
  .num{text-align:right;font-variant-numeric:tabular-nums}
  .tbl-input{width:100%;border:0;background:transparent;text-align:right;outline:none}
  .tbl-input:focus{box-shadow: inset 0 0 0 2px #cfe2ff;border-radius:6px}
  .bldg{width:120px}
</style>
</head>
<body>
<header class="header-area">
  <a href="admin_dashboard.php" class="logo">PoultryMetrics</a>
  <div class="d-flex align-items-center gap-2">
    <a href="buildings.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-house-chimney"></i></a>
  </div>
</header>

<nav class="sidebar">
  <div class="brand"><i class="fa-solid fa-feather-pointed"></i><strong>PoultryMetrics</strong></div>
  <ul class="nav flex-column mb-3">
    <h6>Dashboard</h6>
    <li><a href="admin_dashboard.php" class="nav-link"><i class="fa-solid fa-gauge"></i>Overview</a></li>
    <h6>Egg Operation</h6>
    <li><a href="egg_reports.php" class="nav-link active" aria-current="page"><i class="fa-solid fa-egg"></i>Daily Egg Production</a></li>
    <li><a href="buildings.php" class="nav-link"><i class="fa-solid fa-house-chimney"></i>Buildings</a></li>
  </ul>
</nav>

<main class="content">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="fw-bold mb-0">Daily Egg Production</h5>
    <form class="d-flex align-items-center" method="get" style="gap:8px">
      <small class="text-muted">Date</small>
      <input type="date" name="date" class="form-control form-control-sm" value="<?php echo h($sheetDate); ?>">
      <a class="btn btn-sm btn-outline-secondary" href="buildings.php">Manage Buildings</a>
      <button class="btn btn-sm btn-outline-dark">Load</button>
      <a class="btn btn-sm btn-success" href="?date=<?php echo h($sheetDate); ?>&export=csv"><i class="fa-solid fa-file-csv me-1"></i>Export CSV</a>
      <a class="btn btn-sm btn-primary" target="_blank" href="?date=<?php echo h($sheetDate); ?>&print=1"><i class="fa-solid fa-print me-1"></i>Print / PDF</a>
    </form>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['t']==='ok'?'success':'danger'; ?> py-2"><?php echo h($flash['m']); ?></div>
  <?php endif; ?>

  <div class="sheet">
    <div class="sheet-title">
      <h6>Daily Egg Production</h6>
      <small>AM, PM, 3:45 PM</small>
    </div>

    <form method="post">
      <input type="hidden" name="action" value="bulk_save">
      <input type="hidden" name="csrf_token" value="<?php echo h($CSRF); ?>">
      <input type="hidden" name="sheet_date" value="<?php echo h($sheetDate); ?>">

      <div class="table-responsive">
        <table class="table-sm w-100">
          <thead>
            <tr>
              <th class="bldg">Bldg #</th>
              <th class="text-center">AM</th>
              <th class="text-center">PM</th>
              <th class="text-center">3:45 PM</th>
              <th class="text-center">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($BUILDINGS as $b):
              $vAM=(int)($existing[$b]['AM']??0);
              $vPM=(int)($existing[$b]['PM']??0);
              $vPM2=(int)($existing[$b]['PM2']??0);
              $rowTot=$vAM+$vPM+$vPM2;
            ?>
            <tr>
              <td>
                <input type="hidden" name="block[]" value="<?php echo h($b); ?>">
                <?php echo h($b); ?>
              </td>
              <td class="num"><input class="tbl-input" type="number" min="0" name="am[]"  value="<?php echo $vAM;  ?>"  oninput="recalc()"></td>
              <td class="num"><input class="tbl-input" type="number" min="0" name="pm[]"  value="<?php echo $vPM;  ?>"  oninput="recalc()"></td>
              <td class="num"><input class="tbl-input" type="number" min="0" name="pm2[]" value="<?php echo $vPM2; ?>" oninput="recalc()"></td>
              <td class="num"><span class="row-total"><?php echo number_format($rowTot); ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td class="text-end"><strong>TOTAL:</strong></td>
              <td class="num"><strong id="totAM"><?php echo number_format($totAM); ?></strong></td>
              <td class="num"><strong id="totPM"><?php echo number_format($totPM); ?></strong></td>
              <td class="num"><strong id="totPM2"><?php echo number_format($totPM2); ?></strong></td>
              <td class="num"><strong id="grand"><?php echo number_format($grand); ?></strong></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="d-flex justify-content-end mt-2">
        <button class="btn btn-dark"><i class="fa-solid fa-save me-1"></i>Save Sheet</button>
      </div>
    </form>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function recalc(){
    let totAM=0, totPM=0, totPM2=0, grand=0;
    document.querySelectorAll('tbody tr').forEach(tr=>{
      const am  = Number(tr.querySelector('input[name="am[]"]').value||0);
      const pm  = Number(tr.querySelector('input[name="pm[]"]').value||0);
      const pm2 = Number(tr.querySelector('input[name="pm2[]"]').value||0);
      const rowTot = am+pm+pm2;
      tr.querySelector('.row-total').textContent = rowTot.toLocaleString();
      totAM+=am; totPM+=pm; totPM2+=pm2; grand+=rowTot;
    });
    document.getElementById('totAM').textContent = totAM.toLocaleString();
    document.getElementById('totPM').textContent = totPM.toLocaleString();
    document.getElementById('totPM2').textContent= totPM2.toLocaleString();
    document.getElementById('grand').textContent = grand.toLocaleString();
  }
  recalc();
</script>
</body>
</html>
