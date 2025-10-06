<?php
// ======================================================================
// admin/attendance.php — Tight & Responsive DTR (Today + Monthly)
// - Kiosk: capture (fp_grab.php) → identify (fp_identify.php) → punch
// - Identification order: biometric_id (if typed) → hash match → CLI matcher
// - Falls back to selected NAME if identification fails
// - AM/PM/OT In/Out sequence with clamping
// - Requires: admin/inc/common.php, admin/inc/AttendanceService.php
// ======================================================================

require_once __DIR__.'/inc/common.php';
require_once __DIR__.'/inc/AttendanceService.php';

/* ------- Compatibility shims (only if missing from common.php) ------- */
if (!function_exists('scalar')) {
  function scalar(mysqli $c, string $sql){
    $res = @$c->query($sql);
    if(!$res) return null;
    $row = $res->fetch_row();
    $res->free();
    return $row ? $row[0] : null;
  }
}
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $tbl): bool {
    $tbl = $c->real_escape_string($tbl);
    $res = @$c->query("SHOW TABLES LIKE '{$tbl}'");
    return $res && $res->num_rows > 0;
  }
}
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $tbl, string $col): bool {
    $tbl = str_replace('`','``',$tbl);
    $col = str_replace('`','``',$col);
    $res = @$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
  }
}
if (!function_exists('csrf_token')) {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
  function csrf_token(): string { return (string)($_SESSION['csrf'] ?? ''); }
}
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ------- Local helpers ------- */
function fmt_hhmm_from_mins(int $mins): string {
  $mins = max(0, $mins);
  $h = intdiv($mins, 60);
  $m = $mins % 60;
  return sprintf('%02d:%02d', $h, $m);
}
function ts_from_date_and_val(string $date, ?string $val): ?int {
  if (!$val || trim($val)==='') return null;
  if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/',$val)) return strtotime($date.' '.$val);
  $x = strtotime($val);
  return $x ?: null;
}
function fmt_time_ampm(?int $ts): string { return $ts ? date('g:i A',$ts) : ''; }

/* Overlap minutes between [a1,a2] and [b1,b2] (unix timestamps) */
function dtr_overlap_mins(?int $a1, ?int $a2, int $b1, int $b2): int {
  if(!$a1 || !$a2 || $a2 <= $a1) return 0;
  $s = max($a1,$b1); $e = min($a2,$b2);
  return ($e > $s) ? (int)floor(($e - $s)/60) : 0;
}

/* Sum minutes for completed AM/PM pairs inside schedule windows */
function completed_work_mins(string $date, array $row, array $sched): int {
  $ai = ts_from_date_and_val($date, $row['am_in']  ?? null);
  $ao = ts_from_date_and_val($date, $row['am_out'] ?? null);
  $pi = ts_from_date_and_val($date, $row['pm_in']  ?? null);
  $po = ts_from_date_and_val($date, $row['pm_out'] ?? null);

  $sam = strtotime("$date ".($sched['am_in']  ?? '07:00:00'));
  $eam = strtotime("$date ".($sched['am_out'] ?? '11:00:00'));
  $spm = strtotime("$date ".($sched['pm_in']  ?? '13:00:00'));
  $epm = strtotime("$date ".($sched['pm_out'] ?? '17:00:00'));

  $mins = 0;
  if ($ai && $ao && $ao > $ai) $mins += dtr_overlap_mins($ai,$ao,$sam,$eam);
  if ($pi && $po && $po > $pi) $mins += dtr_overlap_mins($pi,$po,$spm,$epm);
  return $mins;
}

/* ---------------- Page config ---------------- */
$PAGE_TITLE = 'Attendance';
$CURRENT    = 'attendance.php';

@date_default_timezone_set('Asia/Manila');

$WORK_SCHED = [
  'am_in'  => '07:00:00',
  'am_out' => '11:00:00',
  'pm_in'  => '13:00:00',
  'pm_out' => '17:00:00',
];
$REG_DAILY_MINS = 8 * 60;
$TODAY          = date('Y-m-d');
$THIS_YEAR      = (int)date('Y');
$MONTHS         = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

/* ---------------- Ensure attendance columns exist (safe) ---------------- */
if (tableExists($conn,'attendance') && colExists($conn,'attendance','employee_id') && colExists($conn,'attendance','work_date')) {
  if (!colExists($conn,'attendance','paid'))       @$conn->query("ALTER TABLE `attendance` ADD COLUMN `paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pm_out`");
  if (!colExists($conn,'attendance','paid_at'))    @$conn->query("ALTER TABLE `attendance` ADD COLUMN `paid_at` DATETIME NULL AFTER `paid`");
  if (!colExists($conn,'attendance','pay_batch'))  @$conn->query("ALTER TABLE `attendance` ADD COLUMN `pay_batch` VARCHAR(64) NULL AFTER `paid_at`");
  if (!colExists($conn,'attendance','ot_allowed')) @$conn->query("ALTER TABLE `attendance` ADD COLUMN `ot_allowed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pay_batch`");
  if (!colExists($conn,'attendance','ot_in'))      @$conn->query("ALTER TABLE `attendance` ADD COLUMN `ot_in` TIME NULL AFTER `ot_allowed`");
  if (!colExists($conn,'attendance','ot_out'))     @$conn->query("ALTER TABLE `attendance` ADD COLUMN `ot_out` TIME NULL AFTER `ot_in`");
}

/* ---------------- Employee schema discovery ---------------- */
$EMP_TBL = 'employees';
$EMP_PK  = colExists($conn,$EMP_TBL,'employee_id') ? 'employee_id'
        : (colExists($conn,$EMP_TBL,'id') ? 'id' : 'employee_id');
$COL_FULLNAME = colExists($conn,$EMP_TBL,'full_name') ? 'full_name'
             : (colExists($conn,$EMP_TBL,'name') ? 'name' : 'full_name');
$COL_POSITION = colExists($conn,$EMP_TBL,'position') ? 'position' : null;
$COL_STATUS   = colExists($conn,$EMP_TBL,'status')   ? 'status'   : null;
$COL_PHOTO    = colExists($conn,$EMP_TBL,'photo_url') ? 'photo_url'
             : (colExists($conn,$EMP_TBL,'photo_path') ? 'photo_path'
             : (colExists($conn,$EMP_TBL,'photo') ? 'photo' : null));

/* ---------------- Inputs ---------------- */
$month = (int)($_GET['m'] ?? (int)date('n'));
if ($month < 1) $month = 1; if ($month > 12) $month = 12;
$empFilter = (int)($_GET['emp'] ?? 0);
$MONTHLY_OPEN = ($empFilter > 0) || isset($_GET['m']) || isset($_GET['emp']);

/* ---------------- Service ---------------- */
$service = new AttendanceService(
  db: $conn,
  sched: $WORK_SCHED,
  standardMins: $REG_DAILY_MINS,
  graceMins: 0,
  roundTo: 1
);

/* ---------------- Core punch helpers (server-side) ---------------- */
function ensure_att_row(mysqli $db, int $eid, string $date): void {
  $q = $db->prepare("SELECT 1 FROM attendance WHERE employee_id=? AND work_date=? LIMIT 1");
  $q->bind_param('is',$eid,$date); $q->execute(); $q->store_result();
  $exists = $q->num_rows>0; $q->close();
  if (!$exists) {
    $ins = $db->prepare("INSERT INTO attendance (employee_id, work_date) VALUES (?,?)");
    $ins->bind_param('is',$eid,$date); $ins->execute(); $ins->close();
  }
}
function set_time(mysqli $db, int $eid, string $date, string $col, string $val): void {
  $allowed = ['am_in','am_out','pm_in','pm_out','ot_in','ot_out'];
  if (!in_array($col,$allowed,true)) return;
  $sql = "UPDATE attendance SET `$col`=? WHERE employee_id=? AND work_date=?";
  $st  = $db->prepare($sql);
  $st->bind_param('sis',$val,$eid,$date);
  $st->execute(); $st->close();
}
function decide_next_punch(mysqli $db, int $eid, string $date, array $sched): array {
  $st = $db->prepare("SELECT am_in,am_out,pm_in,pm_out,ot_in,ot_out FROM attendance WHERE employee_id=? AND work_date=? LIMIT 1");
  $st->bind_param('is',$eid,$date); $st->execute();
  $r = $st->get_result()->fetch_assoc(); $st->close();
  if (!$r) return [];

  // Sequence-based decision (AM → PM → OT)
  if (empty($r['am_in']))  return ['col'=>'am_in',  'label'=>'AM In'];
  if (empty($r['am_out'])) return ['col'=>'am_out', 'label'=>'AM Out', 'clampTo'=>'12:00:00'];
  if (empty($r['pm_in']))  return ['col'=>'pm_in',  'label'=>'PM In'];
  if (empty($r['pm_out'])) return ['col'=>'pm_out', 'label'=>'PM Out', 'clampTo'=>'22:00:00'];
  if (empty($r['ot_in']))  return ['col'=>'ot_in',  'label'=>'OT In'];
  if (empty($r['ot_out'])) return ['col'=>'ot_out', 'label'=>'OT Out'];

  return []; // done for today
}

/* ---------------- POST actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ===== AJAX: Punch after identification =====
  if (($_POST['action'] ?? '') === 'fp_punch') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'Bad CSRF']); exit;
    }

    $eid = (int)($_POST['employee_id'] ?? 0);
    if ($eid<=0) { echo json_encode(['ok'=>false,'error'=>'Missing employee']); exit; }

    // Ensure row exists
    ensure_att_row($conn, $eid, $TODAY);

    // Decide which column to punch
    $dec = decide_next_punch($conn, $eid, $TODAY, $WORK_SCHED);
    if (empty($dec)) {
      echo json_encode(['ok'=>true,'message'=>'No change for today']); exit;
    }

    // Time value (HH:MM:SS), with optional clamp
    $now = date('H:i:00');
    if (!empty($dec['clampTo']) && strtotime("$TODAY ".$now) > strtotime("$TODAY ".$dec['clampTo'])) {
      $now = $dec['clampTo'];
    }
    set_time($conn, $eid, $TODAY, $dec['col'], $now);

    // Get name for display
    $EMP_TBL = 'employees';
    $EMP_PK  = colExists($conn,$EMP_TBL,'employee_id') ? 'employee_id' : (colExists($conn,$EMP_TBL,'id')?'id':'employee_id');
    $COL_FULLNAME = colExists($conn,$EMP_TBL,'full_name') ? 'full_name' : (colExists($conn,$EMP_TBL,'name')?'name':'full_name');
    $nm = 'Employee';
    $q = $conn->prepare("SELECT `$COL_FULLNAME` FROM `$EMP_TBL` WHERE `$EMP_PK`=? LIMIT 1");
    $q->bind_param('i',$eid); $q->execute(); $nm = (string)($q->get_result()->fetch_row()[0] ?? 'Employee'); $q->close();

    echo json_encode([
      'ok'=>true,
      'employee_id'=>$eid,
      'label'=>$dec['label'],
      'time'=>$now,
      'message'=>sprintf('%s — %s @ %s',$nm,$dec['label'],date('g:i A', strtotime("$TODAY $now")))
    ]); exit;
  }

  // ===== Existing button actions (OT & pay toggles) =====
  if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400); die('Bad CSRF');
  }
  $action = $_POST['action'] ?? '';

  if ($action === 'toggle_ot') {
    $eid = (int)($_POST['employee_id'] ?? 0);
    $d   = trim($_POST['work_date'] ?? '');
    $to  = (int)($_POST['to'] ?? 0);
    if ($eid<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) { http_response_code(400); die('Invalid params'); }
    $st = $conn->prepare("UPDATE attendance SET ot_allowed=? WHERE employee_id=? AND work_date=?");
    $st->bind_param('iis',$to,$eid,$d);
    $st->execute(); $st->close();
    header('Location: attendance.php'); exit;
  }

  if ($action === 'toggle_paid_day') {
    $eid = (int)($_POST['employee_id'] ?? 0);
    $d   = trim($_POST['work_date'] ?? '');
    $to  = (int)($_POST['to'] ?? 0);
    if ($eid<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) { http_response_code(400); die('Invalid params'); }
    if ($to === 1) {
      $batch = bin2hex(random_bytes(8));
      $sql = "UPDATE attendance SET paid=1, paid_at=NOW(), pay_batch=? WHERE employee_id=? AND work_date=?";
      $st  = $conn->prepare($sql); $st->bind_param('sis',$batch,$eid,$d);
    } else {
      $sql = "UPDATE attendance SET paid=0, paid_at=NULL, pay_batch=NULL WHERE employee_id=? AND work_date=?";
      $st  = $conn->prepare($sql); $st->bind_param('is',$eid,$d);
    }
    $st->execute(); $st->close();
    header("Location: attendance.php?m=$month&emp=$eid"); exit;
  }

  if ($action === 'mark_month_paid' || $action === 'mark_month_unpaid') {
    $eid = (int)($_POST['employee_id'] ?? 0);
    $y   = (int)($_POST['year'] ?? $THIS_YEAR);
    $m   = (int)($_POST['month'] ?? $month);
    if ($eid<=0 || $m<1 || $m>12) { http_response_code(400); die('Invalid params'); }
    $start = sprintf('%04d-%02d-01',$y,$m);
    $end   = date('Y-m-t', strtotime($start));
    if ($action === 'mark_month_paid') {
      $batch = bin2hex(random_bytes(8));
      $sql="UPDATE attendance SET paid=1, paid_at=NOW(), pay_batch=? WHERE employee_id=? AND work_date BETWEEN ? AND ?";
      $st=$conn->prepare($sql); $st->bind_param('siss',$batch,$eid,$start,$end);
    } else {
      $sql="UPDATE attendance SET paid=0, paid_at=NULL, pay_batch=NULL WHERE employee_id=? AND work_date BETWEEN ? AND ?";
      $st=$conn->prepare($sql); $st->bind_param('iss',$eid,$start,$end);
    }
    $st->execute(); $st->close();
    header("Location: attendance.php?m=$m&emp=$eid"); exit;
  }
}

/* ---------------- Load employees (for grids & fallback dropdown) ---------------- */
$selParts = ["`$EMP_PK` AS id","`$COL_FULLNAME` AS full_name"];
if ($COL_POSITION) $selParts[] = "`$COL_POSITION` AS position";
if ($COL_STATUS)   $selParts[] = "`$COL_STATUS` AS status";
if ($COL_PHOTO)    $selParts[] = "`$COL_PHOTO` AS photo";
$emps = [];
if (tableExists($conn,$EMP_TBL) && $COL_FULLNAME) {
  $empSQL = "SELECT ".implode(',', $selParts)." FROM `$EMP_TBL` ORDER BY `$COL_FULLNAME` ASC";
  if ($res = @$conn->query($empSQL)) { $emps = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
}

/* ---------------- Today list (all employees) ---------------- */
$attToday = [];
if ($emps) {
  $ids = implode(',', array_map('intval', array_column($emps,'id')));
  if ($ids !== '') {
    $cols = "employee_id, work_date, am_in, am_out, pm_in, pm_out, ot_in, ot_out, ot_allowed, paid";
    $sql  = "SELECT $cols FROM attendance WHERE employee_id IN ($ids) AND work_date=?";
    $st = $conn->prepare($sql); $st->bind_param('s',$TODAY); $st->execute();
    $r = $st->get_result();
    while($x = $r->fetch_assoc()) $attToday[(int)$x['employee_id']] = $x;
    $st->close();
  }
}

/* ---------------- Monthly (selected employee) ---------------- */
$firstDay = sprintf('%04d-%02d-01', $THIS_YEAR, $month);
$tsFirst  = strtotime($firstDay);
$daysIn   = (int)date('t', $tsFirst);
$monthLbl = $MONTHS[$month].' '.$THIS_YEAR;

$attMonth = [];
if ($empFilter > 0) {
  $start = $firstDay;
  $end   = date('Y-m-d', strtotime($firstDay.' +'.($daysIn-1).' day'));
  $mCols = "employee_id, work_date, am_in, am_out, pm_in, pm_out, ot_in, ot_out, paid, paid_at, pay_batch, ot_allowed";
  $msql  = "SELECT $mCols FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ?";
  $st = $conn->prepare($msql); $st->bind_param('iss',$empFilter,$start,$end); $st->execute();
  $r = $st->get_result(); while($x=$r->fetch_assoc()) $attMonth[$x['work_date']] = $x; $st->close();
}

/* ---------------- Render ---------------- */
include __DIR__.'/inc/layout_head.php';
?>
<style>
  .dtr-today .table, .dtr-month .table { font-size:.86rem; }
  .dtr-today .table-sm>:not(caption)>*>*, .dtr-month .table-sm>:not(caption)>*>* { padding:.28rem .35rem; }
  .dtr-today .avatar{ width:28px; height:28px; }
  .dtr-today th, .dtr-today td, .dtr-month th, .dtr-month td { vertical-align:middle; white-space:nowrap; }
  .muted-num{ color:#6b7280; font-weight:600; font-variant-numeric:tabular-nums; }
  @media (max-width: 992px){ .dtr-pos, .m-pos { display:none; } }
  .dtr-month thead th[colspan="2"], .dtr-today thead th[colspan="2"] { text-align:center; }
  .dtr-month thead .subhead th, .dtr-today thead .subhead th { font-weight:700; }
  .group-split { border-left:2px solid #e5e7eb !important; }
  .dtr-month .sum-row td{ border-top:2px solid #e5e7eb; }
  .kiosk-status { min-height: 1.5rem; display:flex; align-items:center; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
  <div>
    <h6 class="fw-bold mb-0">Daily Time Record</h6>
    <div class="text-muted small">Today: <?=h($TODAY)?></div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-secondary" type="button"
            data-bs-toggle="collapse" data-bs-target="#monthlyWrap"
            aria-expanded="<?=$MONTHLY_OPEN?'true':'false'?>">
      <i class="fa fa-calendar me-1"></i> Monthly View
    </button>
    <button class="btn btn-sm btn-dark" onclick="window.print()"><i class="fa fa-print me-1"></i> Print</button>
  </div>
</div>

<!-- ================= FINGERPRINT KIOSK ================= -->
<div class="card mb-3">
  <div class="card-header fw-semibold py-2">
    <i class="fa fa-fingerprint me-1"></i> Fingerprint Kiosk (Identify → Punch)
  </div>
  <div class="card-body">
    <form class="row g-2" id="fp_form" onsubmit="return false;">
      <div class="col-md-5">
        <label class="form-label small">Employee (fallback selection)</label>
        <select class="form-select" id="fp_emp">
          <option value="">— Select Employee —</option>
          <?php foreach($emps as $e): ?>
            <option value="<?= (int)$e['id'] ?>">
              <?=h($e['full_name'])?><?=!empty($e['position'])?' — '.h($e['position']):''?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="small text-muted mt-1">
          Scanner will try to identify automatically. If it fails, you can pick the name here.
        </div>
      </div>

      <div class="col-md-3">
        <label class="form-label small">Biometric ID (optional)</label>
        <input class="form-control" id="fp_bioid" placeholder="employees.biometric_id">
      </div>

      <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-success w-100" id="fp_scan_btn">
          <i class="fa fa-fingerprint me-1"></i> Scan &amp; Punch
        </button>
      </div>

      <div class="col-md-2 d-flex align-items-end">
        <div id="fp_status" class="small text-muted kiosk-status"></div>
      </div>
    </form>
  </div>
</div>

<!-- ================= MONTHLY (COLLAPSIBLE) ================= -->
<div class="collapse <?=$MONTHLY_OPEN?'show':''?> mb-3" id="monthlyWrap">
  <div class="card dtr-month">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
      <span class="fw-semibold">Monthly DTR · <?=h($monthLbl)?></span>
      <form class="d-flex align-items-end gap-2" method="get">
        <div class="d-flex flex-column">
          <label class="form-label small mb-1">Month (<?=$THIS_YEAR?>)</label>
          <select name="m" class="form-select form-select-sm" style="min-width:140px">
            <?php foreach($MONTHS as $i=>$nm): ?>
              <option value="<?=$i?>" <?=$i===$month?'selected':''?>><?=h($nm)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="d-flex flex-column">
          <label class="form-label small mb-1">Employee</label>
          <select name="emp" class="form-select form-select-sm" style="min-width:220px" required>
            <option value="">— Select Employee —</option>
            <?php foreach($emps as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?=$empFilter===(int)$e['id']?'selected':''?>>
                <?=h($e['full_name'])?><?=!empty($e['position'])?' — '.h($e['position']):''?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-sm btn-dark">View</button>
      </form>
    </div>

    <div class="card-body p-2">
      <?php if ($empFilter<=0): ?>
        <div class="alert alert-warning mb-0">Select an employee to show the monthly grid.</div>
      <?php else:
        $selEmp = null; foreach($emps as $e){ if ((int)$e['id']===$empFilter){ $selEmp=$e; break; } }
      ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="rounded-circle overflow-hidden border" style="width:36px;height:36px;background:#f8f9fa;">
            <?php if (!empty($selEmp['photo'])): ?>
              <img src="<?=h($selEmp['photo'])?>" style="width:100%;height:100%;object-fit:cover" alt="">
            <?php else: ?>
              <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted">
                <i class="fa fa-user"></i>
              </div>
            <?php endif; ?>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold"><?=h($selEmp['full_name'] ?? '')?></div>
            <div class="text-muted small"><?=h($selEmp['position'] ?? '')?></div>
          </div>
          <form method="post" class="d-flex gap-2 ms-auto">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="employee_id" value="<?= (int)$empFilter ?>">
            <input type="hidden" name="year" value="<?= (int)$THIS_YEAR ?>">
            <input type="hidden" name="month" value="<?= (int)$month ?>">
            <button name="action" value="mark_month_paid" class="btn btn-sm btn-success">
              <i class="fa fa-check me-1"></i> Mark Month Paid
            </button>
            <button name="action" value="mark_month_unpaid" class="btn btn-sm btn-outline-danger">
              <i class="fa fa-undo me-1"></i> Unmark Month
            </button>
          </form>
        </div>

        <div class="table-responsive">
          <table class="table table-sm table-bordered mb-0">
            <thead class="table-light">
              <tr>
                <th rowspan="2" style="width:56px;">Day</th>
                <th colspan="2">AM</th>
                <th colspan="2" class="group-split">PM</th>
                <th colspan="2" class="group-split">OT</th>
                <th rowspan="2" class="group-split" title="Inside 07–11 & 13–17 (completed pairs)">Total<br><small>(hh:mm)</small></th>
                <th rowspan="2">Missing<br><small>(hh:mm)</small></th>
                <th rowspan="2">OT<br><small>(hh:mm)</small></th>
                <th rowspan="2">Paid</th>
                <th rowspan="2">Action</th>
              </tr>
              <tr class="subhead">
                <th>In</th><th>Out</th>
                <th class="group-split">In</th><th>Out</th>
                <th class="group-split">In</th><th>Out</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $totHours=0; $totLate=0; $totOT=0;
              for($d=1;$d<=$daysIn;$d++):
                $date = sprintf('%04d-%02d-%02d', $THIS_YEAR, $month, $d);
                $x = $attMonth[$date] ?? null;

                if ($x) {
                  $c = $service->computeDay($date,$x); // minutes: regular, deduct, ot
                  $paidFlag = (int)($x['paid'] ?? 0) === 1;
                } else {
                  $c = ['regular'=>0,'deduct'=>0,'ot'=>0];
                  $paidFlag = false;
                }

                $compMins = $x ? completed_work_mins($date, $x, $WORK_SCHED) : 0;

                $totHours += $compMins;
                $totLate  += (int)$c['deduct'];

                // Timestamps (for showing cells and deciding if OT pair exists)
                $am_in_ts  = ts_from_date_and_val($date, $x['am_in']  ?? null);
                $am_out_ts = ts_from_date_and_val($date, $x['am_out'] ?? null);
                $pm_in_ts  = ts_from_date_and_val($date, $x['pm_in']  ?? null);
                $pm_out_ts = ts_from_date_and_val($date, $x['pm_out'] ?? null);
                $ot_in_ts  = ts_from_date_and_val($date, $x['ot_in']  ?? null);
                $ot_out_ts = ts_from_date_and_val($date, $x['ot_out'] ?? null);

                // Only count/show OT when both in/out exist and out > in
                $hasOTpair = ($ot_in_ts && $ot_out_ts && $ot_out_ts > $ot_in_ts);
                $dayOT = $hasOTpair ? (int)$c['ot'] : 0;
                $totOT += $dayOT;
              ?>
              <tr>
                <td class="fw-semibold"><?=$d?></td>

                <td><?= fmt_time_ampm($am_in_ts)  ?></td>
                <td><?= fmt_time_ampm($am_out_ts) ?></td>

                <td class="group-split"><?= fmt_time_ampm($pm_in_ts)  ?></td>
                <td><?= fmt_time_ampm($pm_out_ts) ?></td>

                <td class="group-split"><?= fmt_time_ampm($ot_in_ts)  ?></td>
                <td><?= fmt_time_ampm($ot_out_ts) ?></td>

                <td class="group-split muted-num"><?= $compMins>0 ? fmt_hhmm_from_mins($compMins) : '' ?></td>
                <td class="muted-num <?= $c['deduct']>0?'text-danger fw-semibold':'' ?>">
                  <?= fmt_hhmm_from_mins((int)$c['deduct']) ?>
                </td>
                <td class="muted-num"><?= $hasOTpair ? fmt_hhmm_from_mins($dayOT) : '' ?></td>
                <td><span class="badge text-bg-<?=$paidFlag?'success':'secondary'?>"><?=$paidFlag?'Yes':'No'?></span></td>
                <td>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="action" value="toggle_paid_day">
                    <input type="hidden" name="employee_id" value="<?= (int)$empFilter ?>">
                    <input type="hidden" name="work_date" value="<?= h($date) ?>">
                    <input type="hidden" name="to" value="<?= $paidFlag?0:1 ?>">
                    <button class="btn btn-sm btn-outline-<?=$paidFlag?'danger':'success'?>">
                      <?=$paidFlag ? 'Unpaid' : 'Mark Paid'?>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endfor; ?>
            </tbody>
            <?php if ($empFilter>0): ?>
            <tfoot>
              <tr class="fw-bold sum-row">
                <td colspan="7" class="text-end">Totals</td>
                <td class="text-center group-split"><?= fmt_hhmm_from_mins($totHours) ?></td>
                <td class="text-center"><?= fmt_hhmm_from_mins((int)$totLate)  ?></td>
                <td class="text-center"><?= $totOT > 0 ? fmt_hhmm_from_mins((int)$totOT) : '' ?></td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ================= TODAY (ALL EMPLOYEES) ================= -->
<div class="card dtr-today">
  <div class="card-header fw-semibold">DTR Today (<?=h($TODAY)?>)</div>
  <div class="card-body table-responsive p-2">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th rowspan="2" style="width:36px;">&nbsp;</th>
          <th rowspan="2" class="text-start">Employee</th>
          <th rowspan="2" class="dtr-pos">Position</th>

          <th colspan="2">AM</th>
          <th colspan="2" class="group-split">PM</th>
          <th colspan="2" class="group-split">OT</th>

          <th rowspan="2" class="group-split">Total<br><small>(hh:mm)</small></th>
          <th rowspan="2">Missing<br><small>(hh:mm)</small></th>
          <th rowspan="2">OT<br><small>(hh:mm)</small></th>
          <th rowspan="2">OT?</th>
          <th rowspan="2">Toggle</th>
        </tr>
        <tr class="subhead">
          <th>In</th><th>Out</th>
          <th class="group-split">In</th><th>Out</th>
          <th class="group-split">In</th><th>Out</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$emps): ?>
          <tr><td colspan="14" class="text-center text-muted">No employees found.</td></tr>
        <?php else:
          foreach($emps as $e):
            $row = $attToday[$e['id']] ?? null;

            $am_in_ts  = ts_from_date_and_val($TODAY, $row['am_in']  ?? null);
            $am_out_ts = ts_from_date_and_val($TODAY, $row['am_out'] ?? null);
            $pm_in_ts  = ts_from_date_and_val($TODAY, $row['pm_in']  ?? null);
            $pm_out_ts = ts_from_date_and_val($TODAY, $row['pm_out'] ?? null);
            $ot_in_ts  = ts_from_date_and_val($TODAY, $row['ot_in']  ?? null);
            $ot_out_ts = ts_from_date_and_val($TODAY, $row['ot_out'] ?? null);

            if ($row) {
              $c = $service->computeDay($TODAY,$row);
              $ot_allowed=!empty($row['ot_allowed']);
              $todayCompMins = completed_work_mins($TODAY, $row, $WORK_SCHED);
            } else {
              $c = ['regular'=>0,'deduct'=>0,'ot'=>0];
              $ot_allowed=false;
              $todayCompMins = 0;
            }

            $hasOTpairToday = ($ot_in_ts && $ot_out_ts && $ot_out_ts > $ot_in_ts);
            $todayOT = $hasOTpairToday ? (int)$c['ot'] : 0;

            $badge = $ot_allowed ? 'success' : 'secondary';
            $label = $ot_allowed ? 'Yes' : 'No';
        ?>
        <tr>
          <td>
            <div class="rounded-circle overflow-hidden border avatar" style="background:#f8f9fa;">
              <?php if (!empty($e['photo'])): ?>
                <img src="<?=h($e['photo'])?>" style="width:100%;height:100%;object-fit:cover" alt="">
              <?php else: ?>
                <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted">
                  <i class="fa fa-user"></i>
                </div>
              <?php endif; ?>
            </div>
          </td>
          <td class="text-start">
            <div class="fw-semibold" title="<?=h($e['full_name'])?>"><?=h($e['full_name'])?></div>
            <?php if (!empty($e['status'])): ?>
              <div class="small text-muted"><?=ucfirst($e['status'])?></div>
            <?php endif; ?>
          </td>
          <td class="dtr-pos"><?=h($e['position'] ?? '')?></td>

          <td><?= fmt_time_ampm($am_in_ts)  ?></td>
          <td><?= fmt_time_ampm($am_out_ts) ?></td>

          <td class="group-split"><?= fmt_time_ampm($pm_in_ts)  ?></td>
          <td><?= fmt_time_ampm($pm_out_ts) ?></td>

          <td class="group-split"><?= fmt_time_ampm($ot_in_ts)  ?></td>
          <td><?= fmt_time_ampm($ot_out_ts) ?></td>

          <td class="muted-num group-split"><?= $todayCompMins>0 ? fmt_hhmm_from_mins($todayCompMins) : '' ?></td>
          <td class="muted-num <?= $c['deduct']>0?'text-danger fw-semibold':'' ?>">
            <?= fmt_hhmm_from_mins((int)$c['deduct']) ?>
          </td>
          <td class="muted-num"><?= $hasOTpairToday ? fmt_hhmm_from_mins($todayOT) : '' ?></td>
          <td><span class="badge text-bg-<?=$badge?>"><?=$label?></span></td>
          <td>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="toggle_ot">
              <input type="hidden" name="employee_id" value="<?= (int)$e['id'] ?>">
              <input type="hidden" name="work_date" value="<?= h($TODAY) ?>">
              <input type="hidden" name="to" value="<?= $ot_allowed ? 0 : 1; ?>">
              <button class="btn btn-sm btn-outline-<?=$ot_allowed?'danger':'success'?>">
                <?=$ot_allowed ? 'Disable' : 'Allow'?>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="small text-muted mt-2">
      AM Out allowed only until <strong>12:00 PM</strong>. If AM In exists and noon is reached, AM Out is auto-recorded at <strong>12:00 PM</strong>.
      PM Out allowed until <strong>10:00 PM</strong>. Days with no entries have <strong>no deduction</strong>. OT totals are shown only when both OT In and OT Out exist.
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const btn = document.getElementById('fp_scan_btn');
  const statusEl = document.getElementById('fp_status');

  async function identifyThenPunch(){
    statusEl.textContent = 'Scanning… place finger on reader';

    let template_b64 = '';
    try {
      // 1) Capture from local helper (server-side will run the PowerShell grabber)
      const r1 = await fetch('fp_grab.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ exclusive: '1', timeout: '15000', proc: 'PIV' })
      });
      const j1 = await r1.json();
      if (!j1 || !j1.ok) throw new Error(j1 && j1.error ? j1.error : 'Capture failed');
      template_b64 = j1.template_b64 || '';
      statusEl.textContent = 'Captured. Identifying…';
    } catch (e) {
      statusEl.textContent = 'Scanner failed: ' + e + ' — you can still use Name/Biometric.';
      template_b64 = ''; // continue with fallback
    }

    const empSel = document.getElementById('fp_emp');
    const bioId = (document.getElementById('fp_bioid')?.value || '').trim();

    let employee_id = 0;

    // 2) Try IDENTIFY when we have a template (or a Biometric ID string)
    if (template_b64 || bioId) {
      try {
        const r2 = await fetch('fp_identify.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ template_b64: template_b64, biometric_id: bioId })
        });
        const j2 = await r2.json();
        if (j2 && j2.ok && j2.employee_id) {
          employee_id = parseInt(j2.employee_id, 10);
          statusEl.textContent = `Matched: ${j2.name || 'Employee'} (via ${j2.via||'id'})`;
        } else {
          statusEl.textContent = (j2 && j2.error) ? j2.error : 'No match, using fallback name if chosen…';
        }
      } catch (e) {
        statusEl.textContent = 'Identify error: ' + e;
      }
    }

    // 3) Fallback to selected NAME if identify failed
    if (!employee_id) {
      const sel = (empSel?.value || '').trim();
      if (!sel) { statusEl.textContent = 'Pick a name or enroll fingerprints first.'; return; }
      employee_id = parseInt(sel, 10);
    }

    // 4) Punch
    try {
      const r3 = await fetch('attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action:'fp_punch', csrf:"<?=h(csrf_token())?>", employee_id:String(employee_id) })
      });
      const j3 = await r3.json();
      if (!j3 || !j3.ok) { statusEl.textContent = (j3 && j3.error) ? j3.error : 'Punch failed'; return; }
      statusEl.textContent = j3.message || 'OK';
      setTimeout(()=>{ window.location.reload(); }, 700);
    } catch (e) {
      statusEl.textContent = 'Punch error: ' + e;
    }
  }

  btn?.addEventListener('click', identifyThenPunch);
});
</script>

<?php include __DIR__.'/inc/layout_foot.php';
