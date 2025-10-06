<?php
// ======================================================================
// /admin/payroll.php — Payroll (Monthly) with Half-day Credits & Safe Deductions
//
// EXACT RULES:
//  • If only AM is complete → credit AM only (4h). Vice-versa for PM.
//  • Deduct only for a half (AM/PM) that is fully absent (no IN and no OUT).
//  • If there is a TIME IN but NO TIME OUT → DO NOT deduct.
//  • If there are no punches at all → DO NOT deduct.
//  • Daily rate is pro-rated over FIXED 8 hours (480 minutes).
//  • OT is from OT IN/OUT, counted only beyond 8h and only if allowed.
//  • Modal z-index bumped so it won’t overlap with sidebar/header.
// ======================================================================
require_once __DIR__.'/inc/common.php';
@date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

/* ---------- helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_has_col(mysqli $c, string $table, string $col): bool {
  $tbl = str_replace('`','``',$table);
  $like = $c->real_escape_string($col);
  $res = @$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$like}'");
  if (!$res) return false;
  $ok = ($res->num_rows > 0);
  $res->free();
  return $ok;
}
function first_col(mysqli $c, string $table, array $cands): ?string {
  foreach ($cands as $col) if (table_has_col($c,$table,$col)) return $col;
  return null;
}
function dt_from_date_and_val(string $date, ?string $val): ?int {
  if (!$val || trim($val)==='') return null;
  if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?(\s*[AP]M)?$/i', $val)) return strtotime($date.' '.$val);
  if (preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) return strtotime($val);
  return strtotime($date.' '.$val) ?: strtotime($val);
}
function mins_to_hm(int $m): string { $m=max(0,$m); return sprintf('%d:%02d', intdiv($m,60), $m%60); }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

/* ---------- settings ---------- */
function s_get(mysqli $c, string $key, $default=null){
  $st=$c->prepare("SELECT svalue FROM system_settings WHERE skey=? LIMIT 1");
  if(!$st) return $default; $st->bind_param('s',$key); $st->execute();
  $res=$st->get_result(); $row=$res->fetch_assoc(); $st->close();
  if(!$row) return $default;
  $j=json_decode((string)$row['svalue'],true);
  return (json_last_error()===JSON_ERROR_NONE) ? $j : $row['svalue'];
}

$PAGE_TITLE = 'Payroll';
$THIS_YEAR  = (int)date('Y');
$MONTHS = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

$WORK_SCHED = array_merge([
  'am_in'  => '07:00',
  'am_out' => '11:00',
  'pm_in'  => '13:00',
  'pm_out' => '17:00',
], is_array($tmp = s_get($conn,'work_schedule',null)) ? $tmp : []);

$PAYROLL_SET = s_get($conn,'payroll_settings', [
  'standard_hours_per_day'   => 8,
  'overtime_rate_multiplier' => 1.25,
  'ot_default_allow'         => false,
  'ot_requires_approval'     => true,
]);

// DAILY RATE = 8 HOURS (fixed)
$REG_DAILY_MINS       = 8 * 60; // 480
$OT_MULTIPLIER        = (float)($PAYROLL_SET['overtime_rate_multiplier'] ?? 1.25);
$OT_DEFAULT_ALLOW     = !empty($PAYROLL_SET['ot_default_allow']);
$OT_REQUIRES_APPROVAL = !empty($PAYROLL_SET['ot_requires_approval']);

/* ---------- schema ---------- */
$EMP_TBL = 'employees';
$EMP_PK  = table_has_col($conn,$EMP_TBL,'employee_id')
            ? 'employee_id'
            : (table_has_col($conn,$EMP_TBL,'id') ? 'id' : 'employee_id');
$COL_FULLNAME = first_col($conn,$EMP_TBL,['full_name','name']) ?? 'full_name';
$COL_POSITION = first_col($conn,$EMP_TBL,['position']);
$COL_STATUS   = first_col($conn,$EMP_TBL,['status']);
$COL_PHOTO    = first_col($conn,$EMP_TBL,['photo_url','photo','photo_path']);
$COL_DAILY    = first_col($conn,$EMP_TBL,['daily_rate','rate_daily','salary_daily','base_rate']);

$ATT_TBL = 'attendance';
$ATT_HAS = table_has_col($conn,$ATT_TBL,'employee_id') && table_has_col($conn,$ATT_TBL,'work_date');
$A_STATE = $ATT_HAS && table_has_col($conn,$ATT_TBL,'state') ? 'state' : null;
$A_OT_ALLOWED = $ATT_HAS && table_has_col($conn,$ATT_TBL,'ot_allowed')
                ? 'ot_allowed'
                : ($ATT_HAS && table_has_col($conn,$ATT_TBL,'ot_approved') ? 'ot_approved' : null);

/* ---------- ensure required columns exist (also OT pair) ---------- */
if ($ATT_HAS) {
  if (!table_has_col($conn,$ATT_TBL,'paid'))     @$conn->query("ALTER TABLE `attendance` ADD COLUMN `paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pm_out`");
  if (!table_has_col($conn,$ATT_TBL,'paid_at'))  @$conn->query("ALTER TABLE `attendance` ADD COLUMN `paid_at` DATETIME NULL AFTER `paid`");
  if (!table_has_col($conn,$ATT_TBL,'pay_batch'))@$conn->query("ALTER TABLE `attendance` ADD COLUMN `pay_batch` VARCHAR(64) NULL AFTER `paid_at`");
  if (!table_has_col($conn,$ATT_TBL,'ot_in'))    @$conn->query("ALTER TABLE `attendance` ADD COLUMN `ot_in` TIME NULL AFTER `pm_out`");
  if (!table_has_col($conn,$ATT_TBL,'ot_out'))   @$conn->query("ALTER TABLE `attendance` ADD COLUMN `ot_out` TIME NULL AFTER `ot_in`");
  if (!$A_OT_ALLOWED) { @$conn->query("ALTER TABLE `attendance` ADD COLUMN `ot_allowed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pay_batch`"); $A_OT_ALLOWED='ot_allowed'; }
}

/* ---------- inputs ---------- */
$month     = (int)($_GET['m'] ?? (int)date('n'));
if ($month<1) $month=1; if ($month>12) $month=12;
$year      = $THIS_YEAR;
$empFilter = (int)($_GET['emp'] ?? 0);
$dayFrom   = (int)($_GET['d1'] ?? 1);
$dayTo     = (int)($_GET['d2'] ?? 0);
$doCsv     = (isset($_GET['export']) && $_GET['export']==='csv');

$firstDay = sprintf('%04d-%02d-01',$year,$month);
$tsFirst  = strtotime($firstDay);
$daysIn   = (int)date('t', $tsFirst);
if ($dayFrom<1) $dayFrom=1;
if ($dayTo<=0 || $dayTo>$daysIn) $dayTo=$daysIn;
if ($dayFrom>$dayTo) $dayFrom=$dayTo;
$monthLbl = $MONTHS[$month].' '.$year;
$start    = sprintf('%04d-%02d-%02d',$year,$month,$dayFrom);
$end      = sprintf('%04d-%02d-%02d',$year,$month,$dayTo);

/* ---------- POST: paid & OT controls ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); die('Bad CSRF'); }
  $act = $_POST['action'] ?? '';

  // Period paid (employee)
  if ($act === 'mark_paid_period_emp' || $act === 'unmark_paid_period_emp') {
    $e=(int)($_POST['employee_id']??0); $from=trim($_POST['from']??''); $to=trim($_POST['to']??'');
    if ($e>0 && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) {
      if ($act==='mark_paid_period_emp') {
        $batch = bin2hex(random_bytes(8));
        $st=$conn->prepare("UPDATE `$ATT_TBL` SET paid=1, paid_at=NOW(), pay_batch=? WHERE employee_id=? AND work_date BETWEEN ? AND ?");
        $st->bind_param('siss',$batch,$e,$from,$to);
      } else {
        $st=$conn->prepare("UPDATE `$ATT_TBL` SET paid=0, paid_at=NULL, pay_batch=NULL WHERE employee_id=? AND work_date BETWEEN ? AND ?");
        $st->bind_param('iss',$e,$from,$to);
      }
      $st->execute(); $st->close();
    }
    header("Location: payroll.php?m=$month&emp=$empFilter&d1=$dayFrom&d2=$dayTo"); exit;
  }

  // Day paid
  if ($act === 'mark_paid_day' || $act === 'unmark_paid_day') {
    $eid=(int)($_POST['employee_id']??0); $d=trim($_POST['work_date']??'');
    if ($eid>0 && preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) {
      if ($act==='mark_paid_day') {
        $batch = bin2hex(random_bytes(8));
        $st=$conn->prepare("UPDATE `$ATT_TBL` SET paid=1, paid_at=NOW(), pay_batch=? WHERE employee_id=? AND work_date=?");
        $st->bind_param('sis',$batch,$eid,$d);
      } else {
        $st=$conn->prepare("UPDATE `$ATT_TBL` SET paid=0, paid_at=NULL, pay_batch=NULL WHERE employee_id=? AND work_date=?");
        $st->bind_param('is',$eid,$d);
      }
      $st->execute(); $st->close();
    }
    header("Location: payroll.php?m=$month&emp=$empFilter&d1=$dayFrom&d2=$dayTo"); exit;
  }

  // Day OT allowed
  if ($act === 'mark_ot_day' || $act === 'unmark_ot_day') {
    $eid=(int)($_POST['employee_id']??0); $d=trim($_POST['work_date']??'');
    if ($eid>0 && preg_match('/^\d{4}-\d{2}-\d{2}$/',$d) && $A_OT_ALLOWED) {
      if ($act==='mark_ot_day') { $st=$conn->prepare("UPDATE `$ATT_TBL` SET `$A_OT_ALLOWED`=1 WHERE employee_id=? AND work_date=?"); }
      else                      { $st=$conn->prepare("UPDATE `$ATT_TBL` SET `$A_OT_ALLOWED`=0 WHERE employee_id=? AND work_date=?"); }
      $st->bind_param('is',$eid,$d); $st->execute(); $st->close();
    }
    header("Location: payroll.php?m=$month&emp=$empFilter&d1=$dayFrom&d2=$dayTo"); exit;
  }
}

/* ---------- load employees ---------- */
$sel = ["`$EMP_PK` AS id","`$COL_FULLNAME` AS full_name"];
if ($COL_POSITION) $sel[]="`$COL_POSITION` AS position";
if ($COL_STATUS)   $sel[]="`$COL_STATUS` AS status";
if ($COL_PHOTO)    $sel[]="`$COL_PHOTO` AS photo";
if ($COL_DAILY)    $sel[]="`$COL_DAILY` AS daily_rate";
$empSQL="SELECT ".implode(',',$sel)." FROM `$EMP_TBL`";
if ($empFilter>0) $empSQL.=" WHERE `$EMP_PK`=".(int)$empFilter;
$empSQL.=" ORDER BY `$COL_FULLNAME` ASC";
$emps=[]; if ($res=@$conn->query($empSQL)){ $emps=$res->fetch_all(MYSQLI_ASSOC); $res->free(); }
$empIds=array_map(fn($e)=>(int)$e['id'],$emps);
$empIdList=$empIds?implode(',',$empIds):'0';

/* ---------- load attendance (includes ot_in/ot_out) ---------- */
$attRows=[];
if ($empIds && $ATT_HAS) {
  $cols="employee_id, work_date, am_in, am_out, pm_in, pm_out, ot_in, ot_out, paid, paid_at, pay_batch";
  if ($A_STATE)      $cols.=", `$A_STATE` AS state";
  if ($A_OT_ALLOWED) $cols.=", `$A_OT_ALLOWED` AS ot_allowed";
  $sql="SELECT $cols FROM `$ATT_TBL` WHERE employee_id IN ($empIdList) AND work_date BETWEEN ? AND ?";
  $st=$conn->prepare($sql); $st->bind_param('ss',$start,$end); $st->execute();
  $r=$st->get_result();
  while($x=$r->fetch_assoc()){ $attRows[(int)$x['employee_id']][$x['work_date']]=$x; }
  $st->close();
}

/* ---------- rate resolver ---------- */
function get_daily_rate(mysqli $conn, array $emp, ?string $posCol, ?string $empDailyCol): float {
  if ($empDailyCol && isset($emp['daily_rate']) && is_numeric($emp['daily_rate'])) {
    $r=(float)$emp['daily_rate']; if ($r>0) return $r;
  }
  if ($posCol && !empty($emp['position'])) {
    $p=$conn->prepare("SELECT daily_rate FROM positions WHERE name=? LIMIT 1");
    if ($p){ $p->bind_param('s',$emp['position']); $p->execute(); $res=$p->get_result()->fetch_row(); $p->close();
      if ($res){ $r=(float)$res[0]; if ($r>0) return $r; }
    }
  }
  return 0.0;
}

/* ---------- core day math (HALF CREDIT + SAFE DEDUCTION) ---------- */
/**
 *  - Credit fixed 4h for each completed half (AM pair and/or PM pair).
 *  - If only AM is complete → credit 4h; vice-versa for PM.
 *  - Deduct 4h ONLY if the half is fully absent (no IN and no OUT).
 *  - If a half has IN but NO OUT → NO deduction.
 *  - If no punches at all → NO deduction.
 *  - OT from OT pair only; count beyond 8h and if allowed.
 */
function day_metrics_pairs(string $date, array $row, int $standardMins, bool $otRequiresApproval, bool $otDefaultAllow): array {
  // parse times
  $am_in  = dt_from_date_and_val($date, $row['am_in']  ?? null);
  $am_out = dt_from_date_and_val($date, $row['am_out'] ?? null);
  $pm_in  = dt_from_date_and_val($date, $row['pm_in']  ?? null);
  $pm_out = dt_from_date_and_val($date, $row['pm_out'] ?? null);
  $ot_in  = dt_from_date_and_val($date, $row['ot_in']  ?? null);
  $ot_out = dt_from_date_and_val($date, $row['ot_out'] ?? null);

  // completion & presence flags
  $amComplete = ($am_in && $am_out && $am_out > $am_in);
  $pmComplete = ($pm_in && $pm_out && $pm_out > $pm_in);
  $amHasAny   = (bool)$am_in || (bool)$am_out;
  $pmHasAny   = (bool)$pm_in || (bool)$pm_out;

  // credit fixed halves (4h each)
  $half = (int)($standardMins/2); // 240
  $credited = 0;
  if ($amComplete) $credited += $half;
  if ($pmComplete) $credited += $half;
  if ($credited > $standardMins) $credited = $standardMins;

  // raw totals for OT gating (only from completed halves + OT pair)
  $raw_basic = 0;
  if ($amComplete) $raw_basic += (int)floor(($am_out - $am_in)/60);
  if ($pmComplete) $raw_basic += (int)floor(($pm_out - $pm_in)/60);
  $raw_otpair = ($ot_in && $ot_out && $ot_out > $ot_in) ? (int)floor(($ot_out - $ot_in)/60) : 0;
  $raw_total  = $raw_basic + $raw_otpair;

  // SAFE deduction:
  //  - fully absent half (no IN and no OUT) => deduct 4h
  //  - has IN but no OUT => NO deduction
  $dedAm = $amHasAny ? 0 : $half;
  $dedPm = $pmHasAny ? 0 : $half;

  // If totally no punches, keep ZERO deduction
  if (!$amHasAny && !$pmHasAny) {
    $dedAm = 0; $dedPm = 0;
  }

  $missing = max(0, min($standardMins, $dedAm + $dedPm));

  // OT gate
  $day_ot_allowed = false;
  if (isset($row['ot_allowed'])) {
    $day_ot_allowed = (int)$row['ot_allowed'] === 1;
  } elseif (isset($row['state']) && is_string($row['state'])) {
    $day_ot_allowed = (stripos($row['state'],'overtime') !== false);
  } else {
    $day_ot_allowed = $otDefaultAllow && !$otRequiresApproval;
  }

  // OT beyond full day; cap at OT pair minutes when approval is required
  $beyond = max(0, $raw_total - $standardMins);
  $ot = $day_ot_allowed ? ($otRequiresApproval ? min($beyond, $raw_otpair) : $beyond) : 0;

  return [$raw_total, $credited, $missing, $ot, $day_ot_allowed, $raw_otpair];
}

/* ---------- payroll (exclude PAID) ---------- */
$rows=[];
foreach ($emps as $e) {
  $eid = (int)$e['id'];
  $rateDaily = get_daily_rate($conn,$e,$COL_POSITION,$COL_DAILY);
  $perMin    = $rateDaily>0 ? ($rateDaily/$REG_DAILY_MINS) : 0.0;

  $totReg=0; $totMissing=0; $totOT=0; $paidDays=0;

  $tsStart=strtotime($start); $tsEnd=strtotime($end);
  $detail=[];
  for ($ts=$tsStart; $ts<=$tsEnd; $ts+=86400) {
    $d = date('Y-m-d',$ts);
    $r = $attRows[$eid][$d] ?? null;

    // If there is no attendance row at all → no credit & no deduction (skip)
    if (!$r) continue;

    $paid = (int)($r['paid']??0)===1;
    [$raw,$reg,$missing,$ot,$otAllowed,$otPair] =
      day_metrics_pairs($d,$r,$REG_DAILY_MINS,$OT_REQUIRES_APPROVAL,$OT_DEFAULT_ALLOW);

    $detail[(int)date('j',$ts)] = [
      'date'=>$d,
      'am_in'=>$r['am_in']??'','am_out'=>$r['am_out']??'',
      'pm_in'=>$r['pm_in']??'','pm_out'=>$r['pm_out']??'',
      'ot_in'=>$r['ot_in']??'','ot_out'=>$r['ot_out']??'',
      'raw'=>$raw, 'reg'=>$reg, 'missing'=>$missing, 'ot'=>$ot, 'ot_pair'=>$otPair,
      'ot_allowed'=>$otAllowed?1:0, 'paid'=>$paid?1:0, 'paid_at'=>$r['paid_at']??null
    ];

    if ($paid) { $paidDays++; continue; }
    $totReg    += $reg;
    $totMissing+= $missing;
    $totOT     += $ot;
  }

  // MONEY (Daily rate / 8h)
  $basic     = $perMin * $totReg;
  $otPay     = ($OT_MULTIPLIER * $perMin) * $totOT;
  $deduct    = $perMin * $totMissing;
  $total     = $basic + $otPay - $deduct;

  $rows[] = [
    'id'=>$eid,
    'full_name'=>$e['full_name'],
    'position'=>$e['position']??'',
    'photo'=>$e['photo']??'',
    'status'=>$e['status']??'',
    'daily_rate'=>$rateDaily,
    'tot_hours'=>$totReg/60,       // credited hours (0, 4 or 8+ including OT beyond)
    'tot_missing_m'=>$totMissing,  // 0, 240, 480 depending on fully absent halves
    'tot_ot_m'=>$totOT,
    'basic_pay'=>$basic,
    'ot_pay'=>$otPay,
    'deduct'=>$deduct,
    'total'=>$total,
    'daily'=>$detail,
    'paid_days'=>$paidDays,
  ];
}

/* ---------- CSV (unpaid only) ---------- */
if ($doCsv) {
  header('Content-Type: text/csv; charset=utf-8');
  header("Content-Disposition: attachment; filename=payroll_{$year}_{$month}_d{$dayFrom}-{$dayTo}_UNPAID_ONLY.csv");
  $out=fopen('php://output','w');
  fputcsv($out,['Period',$monthLbl,"Days $dayFrom–$dayTo (Unpaid only)"]);
  fputcsv($out,[]);
  fputcsv($out,['Employee','Position','Daily Rate (8h)','Credited Hours','Missing (m)','OT (m)','Basic Pay','OT Pay','Deduct','Total','Paid Days Excluded']);
  foreach($rows as $r){
    fputcsv($out,[
      $r['full_name'],$r['position'],number_format($r['daily_rate'],2),
      number_format($r['tot_hours'],2),(int)$r['tot_missing_m'],(int)$r['tot_ot_m'],
      number_format($r['basic_pay'],2),number_format($r['ot_pay'],2),
      number_format($r['deduct'],2),number_format($r['total'],2),(int)$r['paid_days']
    ]);
  }
  fclose($out); exit;
}

/* ---------- render ---------- */
include __DIR__.'/inc/layout_head.php';
?>
<style>
  .payroll-table th, .payroll-table td { vertical-align: middle; }
  .payroll-table thead th { white-space: nowrap; }
  .form-label.small { margin-bottom: .15rem; }
  @media print { .no-print { display:none !important; } }
  :root{ --pm-z-backdrop: 2040; --pm-z-modal: 2050; }
  .modal-backdrop{ z-index: var(--pm-z-backdrop) !important; }
  .modal{ z-index: var(--pm-z-modal) !important; }
  body.modal-open{ overflow: hidden; }
</style>

<div class="d-flex justify-content-between align-items-center mb-2">
  <div>
    <h5 class="fw-bold mb-0">Payroll</h5>
    <div class="text-muted small">Period: <?php echo h($monthLbl); ?> · Days <?php echo (int)$dayFrom; ?>–<?php echo (int)$dayTo; ?> (<?php echo $THIS_YEAR; ?>)</div>
    <div class="text-muted small">Daily rate is pro-rated over <strong>8 hours</strong>. Totals exclude days already <span class="badge text-bg-success">Paid</span>.</div>
  </div>
  <div class="d-flex gap-2 no-print">
    <a class="btn btn-outline-secondary" href="?m=<?php echo $month; ?>&emp=<?php echo $empFilter; ?>&d1=<?php echo $dayFrom; ?>&d2=<?php echo $dayTo; ?>&export=csv">
      <i class="fa fa-file-csv me-1"></i> CSV
    </a>
    <button class="btn btn-dark" onclick="window.print()"><i class="fa fa-print me-1"></i> Print</button>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header fw-semibold">View</div>
  <div class="card-body">
    <form class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small">Month (<?php echo $THIS_YEAR; ?>)</label>
        <select name="m" class="form-select form-select-sm">
          <?php foreach($MONTHS as $i=>$nm): ?>
            <option value="<?php echo $i; ?>" <?php echo ($i===$month)?'selected':''; ?>><?php echo h($nm); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small">Employee (optional)</label>
        <select name="emp" class="form-select form-select-sm">
          <option value="">— All —</option>
          <?php foreach($emps as $e): ?>
            <option value="<?php echo (int)$e['id']; ?>" <?php echo ($empFilter===(int)$e['id'])?'selected':''; ?>>
              <?php echo h($e['full_name']); ?><?php echo !empty($e['position'])?' — '.h($e['position']):''; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small">From day</label>
        <input name="d1" type="number" min="1" max="<?php echo (int)$daysIn; ?>" value="<?php echo (int)$dayFrom; ?>" class="form-control form-control-sm">
      </div>
      <div class="col-md-2">
        <label class="form-label small">To day</label>
        <input name="d2" type="number" min="1" max="<?php echo (int)$daysIn; ?>" value="<?php echo (int)$dayTo; ?>" class="form-control form-control-sm">
      </div>
      <div class="col-md-1">
        <button class="btn btn-dark btn-sm w-100">View</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-sm table-hover payroll-table">
      <thead class="table-light">
        <tr>
          <th>Employee</th>
          <th>Position</th>
          <th class="text-end">Daily Rate</th>
          <th class="text-center">Credited Hours</th>
          <th class="text-center">Deduction (m)</th>
          <th class="text-center">OT (m)</th>
          <th class="text-end">Basic Pay</th>
          <th class="text-end">OT Pay</th>
          <th class="text-end">Deduct</th>
          <th class="text-end">Total</th>
          <th class="text-end no-print">Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="11" class="text-center text-muted">No data for this period.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle overflow-hidden border" style="width:28px;height:28px;background:#f8f9fa;">
                  <?php if (!empty($r['photo'])): ?>
                    <img src="<?php echo h($r['photo']); ?>" style="width:100%;height:100%;object-fit:cover" alt="">
                  <?php else: ?>
                    <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted"><i class="fa fa-user"></i></div>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="fw-semibold"><?php echo h($r['full_name']); ?></div>
                  <?php if (!empty($r['status'])): ?><div class="small text-muted"><?php echo ucfirst($r['status']); ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <td><?php echo h($r['position']); ?></td>
            <td class="text-end"><?php echo number_format($r['daily_rate'],2); ?></td>
            <td class="text-center"><?php echo number_format($r['tot_hours'],2); ?></td>
            <td class="text-center"><?php echo (int)$r['tot_missing_m']; ?></td>
            <td class="text-center"><?php echo (int)$r['tot_ot_m']; ?></td>
            <td class="text-end"><?php echo number_format($r['basic_pay'],2); ?></td>
            <td class="text-end"><?php echo number_format($r['ot_pay'],2); ?></td>
            <td class="text-end text-danger"><?php echo $r['deduct']? number_format($r['deduct'],2): '—'; ?></td>
            <td class="text-end fw-semibold"><?php echo number_format($r['total'],2); ?></td>
            <td class="text-end no-print">
              <button class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="modal" data-bs-target="#detailModal"
                      data-id="<?php echo (int)$r['id']; ?>"
                      data-name="<?php echo h($r['full_name']); ?>"
                      data-position="<?php echo h($r['position']); ?>"
                      data-month="<?php echo h($monthLbl); ?> (Days <?php echo (int)$dayFrom; ?>–<?php echo (int)$dayTo; ?>)"
                      data-daily="<?php echo number_format($r['daily_rate'],2); ?>"
                      data-from="<?php echo h($start); ?>"
                      data-to="<?php echo h($end); ?>"
                      data-detail='<?php echo json_encode($r['daily'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'>
                <i class="fa fa-list"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($rows):
        $sumTotal = array_sum(array_map(fn($x)=>$x['total'],$rows));
        $sumBasic = array_sum(array_map(fn($x)=>$x['basic_pay'],$rows));
        $sumOT    = array_sum(array_map(fn($x)=>$x['ot_pay'],$rows));
        $sumDed   = array_sum(array_map(fn($x)=>$x['deduct'],$rows));
      ?>
      <tfoot>
        <tr class="fw-bold">
          <td colspan="6" class="text-end">Totals (Unpaid only)</td>
          <td class="text-end"><?php echo number_format($sumBasic,2); ?></td>
          <td class="text-end"><?php echo number_format($sumOT,2); ?></td>
          <td class="text-end"><?php echo number_format($sumDed,2); ?></td>
          <td class="text-end"><?php echo number_format($sumTotal,2); ?></td>
          <td class="no-print"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header align-items-start">
        <div>
          <h6 class="modal-title">Payroll Detail</h6>
          <div class="small text-muted" id="dm_header">—</div>
        </div>
        <div class="ms-auto d-flex gap-2">
          <form id="dm_period_mark" method="post" class="no-print" onsubmit="return confirm('Mark this period as PAID?');">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
            <input type="hidden" name="action" value="mark_paid_period_emp">
            <input type="hidden" name="employee_id" id="dm_period_emp">
            <input type="hidden" name="from" id="dm_period_from">
            <input type="hidden" name="to" id="dm_period_to">
            <button class="btn btn-sm btn-success"><i class="fa fa-check me-1"></i> Mark Period Paid</button>
          </form>
          <form id="dm_period_unmark" method="post" class="no-print" onsubmit="return confirm('Unmark PAID for this period?');">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
            <input type="hidden" name="action" value="unmark_paid_period_emp">
            <input type="hidden" name="employee_id" id="dm_period_emp2">
            <input type="hidden" name="from" id="dm_period_from2">
            <input type="hidden" name="to" id="dm_period_to2">
            <button class="btn btn-sm btn-outline-danger"><i class="fa fa-undo me-1"></i> Unmark Period</button>
          </form>
        </div>
        <button class="btn-close ms-2" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-sm table-bordered mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th rowspan="2" style="vertical-align:middle;">Day</th>
                <th colspan="2" class="text-center">AM</th>
                <th colspan="2" class="text-center">PM</th>
                <th colspan="2" class="text-center">OT</th>
                <th rowspan="2" style="vertical-align:middle;">Reg (hh:mm)</th>
                <th rowspan="2" style="vertical-align:middle;">Missing (hh:mm)</th>
                <th rowspan="2" style="vertical-align:middle;">OT (hh:mm)</th>
                <th rowspan="2" style="vertical-align:middle;">OT Allowed</th>
                <th rowspan="2" style="vertical-align:middle;">Paid</th>
                <th class="no-print" rowspan="2" style="vertical-align:middle;">Actions</th>
              </tr>
              <tr>
                <th class="text-center">In</th>
                <th class="text-center">Out</th>
                <th class="text-center">In</th>
                <th class="text-center">Out</th>
                <th class="text-center">In</th>
                <th class="text-center">Out</th>
              </tr>
            </thead>
            <tbody id="dm_tbody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- Hidden forms -->
<form id="dm_paid_form" method="post" class="d-none">
  <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
  <input type="hidden" name="action" id="dm_paid_action">
  <input type="hidden" name="employee_id" id="dm_paid_emp">
  <input type="hidden" name="work_date" id="dm_paid_date">
</form>
<form id="dm_ot_form" method="post" class="d-none">
  <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
  <input type="hidden" name="action" id="dm_ot_action">
  <input type="hidden" name="employee_id" id="dm_ot_emp">
  <input type="hidden" name="work_date" id="dm_ot_date">
</form>

<script>
(function(){
  function to12(t){
    if(!t) return '';
    let m = String(t).match(/(\d{1,2}):(\d{2})(?::\d{2})?/);
    if(!m) return '';
    let hh = parseInt(m[1], 10);
    const mm = m[2];
    const ampm = hh >= 12 ? 'PM' : 'AM';
    hh = hh % 12; if (hh === 0) hh = 12;
    return `${hh}:${mm} ${ampm}`;
  }
  const modal = document.getElementById('detailModal');

  modal?.addEventListener('show.bs.modal', (ev)=>{
    const b = ev.relatedTarget; if (!b) return;
    const empId = b.getAttribute('data-id')||'';
    const name  = b.getAttribute('data-name')||'';
    const pos   = b.getAttribute('data-position')||'';
    const month = b.getAttribute('data-month')||'';
    const daily = b.getAttribute('data-daily')||'';
    const dFrom = b.getAttribute('data-from')||'';
    const dTo   = b.getAttribute('data-to')||'';
    const detail= JSON.parse(b.getAttribute('data-detail')||'{}');

    document.getElementById('dm_header').textContent =
      `${name}${pos?' — '+pos:''} · ${month} · Daily Rate (8h): ₱ ${daily}`;

    (document.getElementById('dm_period_emp') || {}).value = empId;
    (document.getElementById('dm_period_from') || {}).value = dFrom;
    (document.getElementById('dm_period_to') || {}).value = dTo;
    (document.getElementById('dm_period_emp2') || {}).value = empId;
    (document.getElementById('dm_period_from2') || {}).value = dFrom;
    (document.getElementById('dm_period_to2') || {}).value = dTo;

    const tb = document.getElementById('dm_tbody');
    tb.innerHTML = '';
    const minsToHm = (m)=>{ m=Math.max(0,parseInt(m||0,10)); const h=Math.floor(m/60), mm=m%60; return (h<10?'0':'')+h+':'+(mm<10?'0':'')+mm; };
    const days = Object.keys(detail).map(k=>parseInt(k,10)).sort((a,b)=>a-b);

    for (const d of days) {
      const row = detail[d] || {};
      const paid = parseInt(row.paid||0,10)===1;
      const ot   = parseInt(row.ot_allowed||0,10)===1;

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="fw-semibold">${d}</td>
        <td>${to12(row.am_in)}</td>
        <td>${to12(row.am_out)}</td>
        <td>${to12(row.pm_in)}</td>
        <td>${to12(row.pm_out)}</td>
        <td>${to12(row.ot_in)}</td>
        <td>${to12(row.ot_out)}</td>
        <td>${row.reg ? minsToHm(row.reg) : ''}</td>
        <td>${row.missing ? minsToHm(row.missing) : '00:00'}</td>
        <td>${row.ot ? minsToHm(row.ot) : '00:00'}</td>
        <td>${ot ? '<span class="badge text-bg-primary">Yes</span>' : '<span class="badge text-bg-secondary">No</span>'}</td>
        <td>${paid ? '<span class="badge text-bg-success">Paid</span>' : '<span class="badge text-bg-secondary">No</span>'}</td>
        <td class="no-print d-flex flex-wrap gap-1">
          <button type="button" class="btn btn-sm ${paid?'btn-outline-danger':'btn-success'} dm-mark-paid"
                  data-date="${row.date}" data-emp="${empId}" data-action="${paid?'unmark':'mark'}">
            ${paid ? '<i class="fa fa-undo me-1"></i>Unmark Paid' : '<i class="fa fa-check me-1"></i>Mark Paid' }
          </button>
          <button type="button" class="btn btn-sm ${ot?'btn-outline-warning':'btn-outline-primary'} dm-mark-ot"
                  data-date="${row.date}" data-emp="${empId}" data-action="${ot?'unmark':'mark'}">
            ${ot ? '<i class="fa fa-ban me-1"></i>Disable OT' : '<i class="fa fa-clock me-1"></i>Allow OT'}
          </button>
        </td>
      `;
      tb.appendChild(tr);
    }

    // Wire up action buttons
    tb.querySelectorAll('button.dm-mark-paid').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const emp = btn.getAttribute('data-emp');
        const d   = btn.getAttribute('data-date');
        const act = btn.getAttribute('data-action')==='mark' ? 'mark_paid_day' : 'unmark_paid_day';
        if (!confirm((act==='mark_paid_day'?'Mark':'Unmark')+' this day as paid?')) return;
        document.getElementById('dm_paid_emp').value = emp;
        document.getElementById('dm_paid_date').value = d;
        document.getElementById('dm_paid_action').value = act;
        document.getElementById('dm_paid_form').submit();
      });
    });

    tb.querySelectorAll('button.dm-mark-ot').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const emp = btn.getAttribute('data-emp');
        const d   = btn.getAttribute('data-date');
        const act = btn.getAttribute('data-action')==='mark' ? 'mark_ot_day' : 'unmark_ot_day';
        if (!confirm((act==='mark_ot_day'?'Allow':'Disable')+' OT for this day?')) return;
        document.getElementById('dm_ot_emp').value = emp;
        document.getElementById('dm_ot_date').value = d;
        document.getElementById('dm_ot_action').value = act;
        document.getElementById('dm_ot_form').submit();
      });
    });
  });
})();
</script>

<?php include __DIR__.'/inc/layout_foot.php';
