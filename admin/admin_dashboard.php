<?php


// /admin/admin_dashboard.php
declare(strict_types=1);



if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---- Simple guard (before any output): must be logged-in admin ---- */
if (empty($_SESSION['user_id']) || strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
  // Prefer /admin/login.php if it exists, else fallback to /login.php
  $loginRel = is_file(__DIR__ . '/login.php') ? 'login.php' : '../login.php';
  header('Location: ' . $loginRel);
  exit;
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* Layout head pulls common + header/sidebar and sets $conn, helpers, etc. */
include __DIR__ . '/inc/layout_head.php';

/* ---------- Extra helpers (only if not already defined by common/layout) ---------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('scalar')) {
  function scalar(mysqli $c, string $sql){
    $res = @$c->query($sql); if(!$res) return null;
    $row = $res->fetch_row(); $res->free();
    return $row ? $row[0] : null;
  }
}
if (!function_exists('table_exists')) {
  function table_exists(mysqli $c, string $tbl): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    if (!$st = $c->prepare($sql)) return false;
    $st->bind_param('s',$tbl); $st->execute(); $st->store_result();
    $ok = $st->num_rows > 0; $st->close(); return $ok;
  }
}
if (!function_exists('table_has_col')) {
  function table_has_col(mysqli $c, string $tbl, string $col): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if (!$st = $c->prepare($sql)) return false;
    $st->bind_param('ss',$tbl,$col); $st->execute(); $st->store_result();
    $ok = $st->num_rows > 0; $st->close(); return $ok;
  }
}

/* ---------- Time helpers (robust parsing) ---------- */
if (!function_exists('fmt_time_ampm')) {
  function fmt_time_ampm(?string $v): string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return '';
    $ts = preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? strtotime($v) : strtotime(date('Y-m-d').' '.$v);
    return $ts ? date('g:i A', $ts) : '';
  }
}
if (!function_exists('ts_from_date_and_val')) {
  function ts_from_date_and_val(string $date, ?string $val): ?int {
    $val = trim((string)$val);
    if ($val === '' || $val === '0000-00-00 00:00:00') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) { $ts = strtotime($val); return $ts ?: null; }
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $val)) { $ts = strtotime("$date $val"); return $ts ?: null; }
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?\s*(AM|PM)$/i', $val)) { $ts = strtotime("$date $val"); return $ts ?: null; }
    if (ctype_digit($val)) { $ts = (int)$val; if ($ts > 0 && $ts < 4102444800) return $ts; }
    $ts = strtotime("$date $val"); if ($ts) return $ts;
    $ts = strtotime($val); return $ts ?: null;
  }
}
if (!function_exists('overlap_mins')) {
  function overlap_mins(?int $a1, ?int $a2, int $b1, int $b2): int {
    if(!$a1 || !$a2 || $a2 <= $a1) return 0;
    $s = max($a1,$b1); $e = min($a2,$b2);
    return ($e > $s) ? (int)floor(($e - $s)/60) : 0;
  }
}

/* ---------- Page data ---------- */
$today     = date('Y-m-d');
$THIS_YEAR = (int)date('Y');
$month     = (int)date('n');
$firstDay  = sprintf('%04d-%02d-01',$THIS_YEAR,$month);
$lastDay   = date('Y-m-t', strtotime($firstDay));

$EMP_TBL = 'employees';
$ATT_TBL = 'attendance';

$HAS_EMP = table_exists($conn,$EMP_TBL);
$HAS_ATT = table_exists($conn,$ATT_TBL);

$EMP_PK   = $HAS_EMP && table_has_col($conn,$EMP_TBL,'employee_id') ? 'employee_id'
         : ($HAS_EMP && table_has_col($conn,$EMP_TBL,'id') ? 'id' : 'employee_id');

$EMP_FULL = $HAS_EMP && table_has_col($conn,$EMP_TBL,'full_name') ? 'full_name'
         : ($HAS_EMP && table_has_col($conn,$EMP_TBL,'name') ? 'name' : null);

$EMP_PHOTO= $HAS_EMP && table_has_col($conn,$EMP_TBL,'photo_url') ? 'photo_url'
         : ($HAS_EMP && table_has_col($conn,$EMP_TBL,'photo_path') ? 'photo_path'
         : ($HAS_EMP && table_has_col($conn,$EMP_TBL,'photo') ? 'photo' : null));

$HAS_OT_IN   = $HAS_ATT && table_has_col($conn,$ATT_TBL,'ot_in');
$HAS_OT_OUT  = $HAS_ATT && table_has_col($conn,$ATT_TBL,'ot_out');
$HAS_OT_FLAG = $HAS_ATT && table_has_col($conn,$ATT_TBL,'ot_allowed');
$HAS_PAID    = $HAS_ATT && table_has_col($conn,$ATT_TBL,'paid');

/* KPIs */
$employeeCount = $HAS_EMP ? (int)scalar($conn, "SELECT COUNT(*) FROM `$EMP_TBL`") : 0;

$presentToday = 0;
if ($HAS_ATT && table_has_col($conn,$ATT_TBL,'work_date')) {
  $otCond = '';
  if ($HAS_OT_IN)  $otCond .= " OR (ot_in  IS NOT NULL AND ot_in  NOT IN ('','00:00:00','0000-00-00 00:00:00'))";
  if ($HAS_OT_OUT) $otCond .= " OR (ot_out IS NOT NULL AND ot_out NOT IN ('','00:00:00','0000-00-00 00:00:00'))";
  $sql = "SELECT COUNT(DISTINCT employee_id)
          FROM `$ATT_TBL`
          WHERE work_date = ?
            AND (
              (am_in  IS NOT NULL AND am_in  NOT IN ('','00:00:00','0000-00-00 00:00:00')) OR
              (am_out IS NOT NULL AND am_out NOT IN ('','00:00:00','0000-00-00 00:00:00')) OR
              (pm_in  IS NOT NULL AND pm_in  NOT IN ('','00:00:00','0000-00-00 00:00:00')) OR
              (pm_out IS NOT NULL AND pm_out NOT IN ('','00:00:00','0000-00-00 00:00:00'))
              $otCond
            )";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param('s',$today); $st->execute(); $st->bind_result($presentToday);
    $st->fetch(); $st->close();
  }
}

/* Payroll (unpaid only): REG + OT */
$hourlyRate = 0.0; $otMult = 1.25;
if (table_exists($conn,'system_settings') && table_has_col($conn,'system_settings','skey')) {
  if ($st = $conn->prepare("SELECT svalue FROM system_settings WHERE skey='payroll_settings' LIMIT 1")) {
    $st->execute(); $st->bind_result($j);
    if ($st->fetch()) {
      $cfg = json_decode((string)$j, true);
      if (is_array($cfg)) {
        $hourlyRate = max(0.0, (float)($cfg['hourly_rate'] ?? $cfg['default_hourly_rate'] ?? 0));
        $otMult     = max(1.0, (float)($cfg['overtime_rate_multiplier'] ?? $otMult));
      }
    }
    $st->close();
  }
}

$sumRegMins = 0; $sumOTMins = 0;
if ($HAS_ATT && table_has_col($conn,$ATT_TBL,'work_date')) {
  $cols = ["work_date","am_in","am_out","pm_in","pm_out"];
  if ($HAS_OT_IN)   $cols[] = "ot_in";
  if ($HAS_OT_OUT)  $cols[] = "ot_out";
  if ($HAS_OT_FLAG) $cols[] = "ot_allowed";
  $wherePaid = $HAS_PAID ? " AND (paid IS NULL OR paid=0)" : "";

  $sql = "SELECT ".implode(',', $cols)." FROM `$ATT_TBL`
          WHERE work_date BETWEEN ? AND ? $wherePaid";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param('ss',$firstDay,$lastDay);
    $st->execute(); $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
      $d  = $r['work_date'];
      $ai = ts_from_date_and_val($d,$r['am_in']  ?? null);
      $ao = ts_from_date_and_val($d,$r['am_out'] ?? null);
      $pi = ts_from_date_and_val($d,$r['pm_in']  ?? null);
      $po = ts_from_date_and_val($d,$r['pm_out'] ?? null);

      $sam = strtotime("$d 07:00:00");
      $eam = strtotime("$d 11:00:00");
      $spm = strtotime("$d 13:00:00");
      $epm = strtotime("$d 17:00:00");

      $reg = 0;
      if ($ai && $ao && $ao>$ai) $reg += overlap_mins($ai,$ao,$sam,$eam);
      if ($pi && $po && $po>$pi) $reg += overlap_mins($pi,$po,$spm,$epm);
      if ($reg > 480) $reg = 480;
      $sumRegMins += $reg;

      if ($HAS_OT_IN || $HAS_OT_OUT) {
        $otAllowed = $HAS_OT_FLAG ? ((int)($r['ot_allowed'] ?? 0) === 1) : true;
        $oi = ts_from_date_and_val($d,$r['ot_in']  ?? null);
        $oo = ts_from_date_and_val($d,$r['ot_out'] ?? null);
        if ($otAllowed && $oi && $oo && $oo>$oi) {
          $otStart = strtotime("$d 18:00:00");
          $otEnd   = strtotime("$d 22:00:00");
          $sumOTMins += overlap_mins($oi,$oo,$otStart,$otEnd);
        }
      }
    }
    $st->close();
  }
}
$unpaidAmount = ($hourlyRate * ($sumRegMins/60.0)) + ($hourlyRate * $otMult * ($sumOTMins/60.0));

/* Today’s DTR */
$todayRows = [];
if ($HAS_ATT && table_has_col($conn,$ATT_TBL,'work_date')) {
  if ($HAS_EMP) {
    $selEmp = "`e`.`$EMP_PK` AS emp_id";
    $selEmp .= $EMP_FULL ? ", `e`.`$EMP_FULL` AS full_name" : ", '' AS full_name";
    $selEmp .= $EMP_PHOTO? ", `e`.`$EMP_PHOTO` AS photo"    : ", '' AS photo";
    $aCols = "a.am_in, a.am_out, a.pm_in, a.pm_out";
    $aCols .= $HAS_OT_IN  ? ", a.ot_in"  : ", '' AS ot_in";
    $aCols .= $HAS_OT_OUT ? ", a.ot_out" : ", '' AS ot_out";
    $sql = "SELECT $selEmp, $aCols
            FROM `$ATT_TBL` a
            JOIN `$EMP_TBL` e ON a.employee_id = e.`$EMP_PK`
            WHERE a.work_date=?
            ORDER BY e.`$EMP_PK` ASC";
  } else {
    $aCols = "am_in, am_out, pm_in, pm_out";
    $aCols .= $HAS_OT_IN  ? ", ot_in"  : ", '' AS ot_in";
    $aCols .= $HAS_OT_OUT ? ", ot_out" : ", '' AS ot_out";
    $sql = "SELECT employee_id AS emp_id, '' AS full_name, '' AS photo, $aCols
            FROM `$ATT_TBL`
            WHERE work_date=? ORDER BY employee_id ASC";
  }
  if ($st = $conn->prepare($sql)) {
    $st->bind_param('s',$today); $st->execute();
    $r = $st->get_result(); $todayRows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
  }
}
?>

<style>
  .kpi-card .v{ font-size:22px; font-weight:800; letter-spacing:.02em }
  .kpi-card .lbl{ color:#6b7280; font-size:.85rem }
  .table-xs td, .table-xs th{ padding:.28rem .42rem; font-size:.82rem; line-height:1.05; vertical-align:middle; white-space:nowrap }
  .avatar-28{ width:28px;height:28px;border-radius:50%; overflow:hidden; background:#f3f4f6; display:inline-flex; align-items:center; justify-content:center; font-size:.85rem }
  .avatar-28 img{ width:100%; height:100%; object-fit:cover; }
  .name-cell{ max-width:280px; overflow:hidden; text-overflow:ellipsis; }
  @media (min-width: 992px){ .name-cell{ max-width:320px; } }
  .group-split{ border-left:2px solid #e5e7eb; }
</style>

<div class="row g-3">
  <!-- KPIs -->
  <div class="col-md-4">
    <div class="card kpi-card">
      <div class="card-body d-flex align-items-center justify-content-between">
        <div>
          <div class="lbl">Employees</div>
          <div class="v"><?= (int)$employeeCount ?></div>
        </div>
        <i class="fa-solid fa-users fa-lg text-muted"></i>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card kpi-card">
      <div class="card-body d-flex align-items-center justify-content-between">
        <div>
          <div class="lbl">Present Employees Today</div>
          <div class="v"><?= (int)$presentToday ?></div>
        </div>
        <i class="fa-solid fa-user-check fa-lg text-muted"></i>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card kpi-card">
      <div class="card-body d-flex align-items-center justify-content-between">
        <div>
          <div class="lbl">Payroll This Month (Unpaid)</div>
          <div class="v">₱<?= number_format($unpaidAmount, 2) ?></div>
        </div>
        <i class="fa-solid fa-peso-sign fa-lg text-muted"></i>
      </div>
    </div>
  </div>

  <!-- DTR Today -->
  <div class="col-12">
    <div class="card">
      <div class="card-header fw-semibold py-2">DTR Today (<?= h($today) ?>)</div>
      <div class="card-body p-2 table-responsive">
        <table class="table table-xs align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:36px;">&nbsp;</th>
              <th>Employee</th>
              <th>AM In</th>
              <th>AM Out</th>
              <th class="group-split">PM In</th>
              <th>PM Out</th>
              <th class="group-split">OT In</th>
              <th>OT Out</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$todayRows): ?>
            <tr><td colspan="8" class="text-center text-muted">No data found for today.</td></tr>
          <?php else: foreach ($todayRows as $r): ?>
            <tr>
              <td>
                <div class="avatar-28 border">
                  <?php if (!empty($r['photo'])): ?>
                    <img src="<?= h($r['photo']) ?>" alt="">
                  <?php else: ?>
                    <i class="fa fa-user text-muted"></i>
                  <?php endif; ?>
                </div>
              </td>
              <td class="name-cell fw-semibold" title="<?= h($r['full_name'] ?: ('Employee #'.$r['emp_id'])) ?>">
                <?= h($r['full_name'] ?: ('Employee #'.$r['emp_id'])) ?>
              </td>
              <td><?= h(fmt_time_ampm($r['am_in']  ?? '')) ?></td>
              <td><?= h(fmt_time_ampm($r['am_out'] ?? '')) ?></td>
              <td class="group-split"><?= h(fmt_time_ampm($r['pm_in']  ?? '')) ?></td>
              <td><?= h(fmt_time_ampm($r['pm_out'] ?? '')) ?></td>
              <td class="group-split"><?= h(fmt_time_ampm($r['ot_in']  ?? '')) ?></td>
              <td><?= h(fmt_time_ampm($r['ot_out'] ?? '')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
/* Layout foot closes <main>, includes scripts, and ends the HTML */
include __DIR__ . '/inc/layout_foot.php';
