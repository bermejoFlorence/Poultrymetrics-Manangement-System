<?php
// /worker/daily_egg_feed_inventory_submit.php
// Submit handler for I-102 Daily Report

require_once __DIR__ . '/inc/common.php'; // $conn, session, h(), etc.
@date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

// BASE for redirects (matches the form)
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

function intv($v){ return is_numeric($v) ? (int)$v : 0; }

// ---- CSRF ----
if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  header('Location: ' . $BASE . '/daily_egg_feed_inventory_form.php?err=Invalid+CSRF'); exit;
}

// ---- Inputs ----
$report_date = $_POST['report_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) $report_date = date('Y-m-d');
$farm        = trim((string)($_POST['farm'] ?? ''));
$remarks     = trim((string)($_POST['remarks'] ?? ''));
$payloadJson = (string)($_POST['payload'] ?? '');

$rows = [];
if ($payloadJson !== '') {
  $decoded = json_decode($payloadJson, true);
  if (json_last_error() === JSON_ERROR_NONE && !empty($decoded['rows']) && is_array($decoded['rows'])) {
    $rows = $decoded['rows'];
  }
}

// ---- Insert header ----
$conn->begin_transaction();
try {
  $worker_id = isset($_SESSION['employee_id']) ? (int)$_SESSION['employee_id'] : 0;
  $username  = (string)($_SESSION['username'] ?? '');

  $stmt = $conn->prepare("INSERT INTO i102_reports (report_date, farm, worker_id, username, remarks) VALUES (?,?,?,?,?)");
  if (!$stmt) throw new Exception($conn->error);
  $stmt->bind_param('ssiss', $report_date, $farm, $worker_id, $username, $remarks);
  $stmt->execute();
  $report_id = $stmt->insert_id;
  $stmt->close();

  // ---- Insert items (if any rows present) ----
  if (!empty($rows)) {
    $sql = "INSERT INTO i102_report_items
      (report_id,bldg,egg_am,egg_pm,egg_345,egg_total,feed_sacks,feed_total,beg_bal,m,rejects,water_bag,cull,old,inv_total,balance)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception($conn->error);

    foreach ($rows as $r) {
      $b         = intv($r['bldg'] ?? 0);
      $am        = intv($r['am'] ?? 0);
      $pm        = intv($r['pm'] ?? 0);
      $p345      = intv($r['pm345'] ?? 0);
      $egg_total = ($r['egg_total'] ?? '') === '' ? ($am + $pm + $p345) : intv($r['egg_total']);
      $sacks     = intv($r['sacks'] ?? 0);
      $feed_tot  = intv($r['feed_tot'] ?? 0);
      $beg       = intv($r['beg_bal'] ?? 0);
      $m         = intv($r['M'] ?? 0);
      $rej       = intv($r['rejects'] ?? 0);
      $wbag      = intv($r['waterbag'] ?? 0);
      $cull      = intv($r['cull'] ?? 0);
      $old       = intv($r['old'] ?? 0);
      $inv       = intv($r['inv_tot'] ?? 0);
      $bal       = intv($r['balance'] ?? 0);

      $stmt->bind_param('iiiiiiiiiiiiiiii',
        $report_id,$b,$am,$pm,$p345,$egg_total,$sacks,$feed_tot,$beg,$m,$rej,$wbag,$cull,$old,$inv,$bal
      );
      $stmt->execute();
    }
    $stmt->close();
  }

  $conn->commit();
  header('Location: ' . $BASE . '/daily_egg_feed_inventory_form.php?ok=1'); exit;

} catch (Throwable $e) {
  $conn->rollback();
  $msg = urlencode('Save failed: '.$e->getMessage());
  header('Location: ' . $BASE . '/daily_egg_feed_inventory_form.php?err=' . $msg); exit;
}
