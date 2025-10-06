<?php
// worker/attendance.php — DTR with OT restricted to 6:00–10:00 PM

require_once __DIR__.'/inc/common.php';
require_once __DIR__.'/inc/AttendanceService.php';

require_login($conn);
$me = current_worker($conn);
if (!$me) { flash_set('error','No linked employee profile found.'); redirect(BASE_URI.'/worker/worker_dashboard.php'); }

date_default_timezone_set('Asia/Manila');

/* helpers */
if (!function_exists('fmt_hhmm_from_mins')) {
  function fmt_hhmm_from_mins(int $m): string { $m=max(0,$m); return sprintf('%02d:%02d', intdiv($m,60), $m%60); }
}
if (!function_exists('to12')) {
  function to12(?string $t): string {
    $t = trim((string)$t);
    if ($t==='') return '';
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $t)) return date('g:i A', strtotime(date('Y-m-d').' '.$t));
    $ts = strtotime($t); return $ts ? date('g:i A',$ts) : $t;
  }
}
if (!function_exists('to12hm')) { function to12hm(string $hm): string { return date('g:i A', strtotime($hm)); } }
if (!function_exists('csrf_ok')) {
  function csrf_ok($t){ return (function_exists('csrf_check') ? csrf_check((string)$t) : (isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'],$t))); }
}
if (!function_exists('csrf_field')) {
  function csrf_field(){
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8').'">';
  }
}
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $t): bool { $t=$c->real_escape_string($t); $r=@$c->query("SHOW TABLES LIKE '{$t}'"); return $r && $r->num_rows>0; }
}
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $t, string $col): bool { $t=str_replace('`','``',$t); $col=$c->real_escape_string($col); $r=@$c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'"); return $r && $r->num_rows>0; }
}

/* ensure OT columns */
if (tableExists($conn,'attendance') && colExists($conn,'attendance','employee_id') && colExists($conn,'attendance','work_date')) {
  if (!colExists($conn,'attendance','ot_allowed')) @$conn->query("ALTER TABLE `attendance` ADD COLUMN `ot_allowed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pay_batch`");
  if (!colExists($conn,'attendance','ot_in'))  @$conn->query("ALTER TABLE `attendance` ADD COLUMN `ot_in`  TIME NULL AFTER `ot_allowed`");
  if (!colExists($conn,'attendance','ot_out')) @$conn->query("ALTER TABLE `attendance` ADD COLUMN `ot_out` TIME NULL AFTER `ot_in`");
}

/* config */
$PAGE_TITLE       = 'My Attendance';
$WORK_SCHED       = $WORK_SCHED ?? ['am_in'=>'07:00','am_out'=>'11:00','pm_in'=>'13:00','pm_out'=>'17:00'];
$REG_DAILY_MINS   = $REG_DAILY_MINS ?? 480;

$eid         = (int)($me['id'] ?? 0);
$today       = date('Y-m-d');
$serverNowTs = time();

/* month */
$THIS_YEAR = (int)date('Y');
$month = (int)($_GET['m'] ?? (int)date('n'));
if ($month<1) $month=1; if ($month>12) $month=12;
$firstDay = sprintf('%04d-%02d-01', $THIS_YEAR, $month);
$daysIn   = (int)date('t', strtotime($firstDay));
$lastDay  = sprintf('%04d-%02d-%02d', $THIS_YEAR, $month, $daysIn);
$monthName= date('F', strtotime($firstDay));

/* service */
$service = new AttendanceService($conn, $WORK_SCHED, $REG_DAILY_MINS, 0, 1);
[$OT_START_HM, $OT_END_HM] = $service->otWindowHm();

/* POST */
if ($_SERVER['REQUEST_METHOD']==='POST' && csrf_ok($_POST['csrf']??'')) {
  try {
    $act  = $_POST['action'] ?? '';
    $slot = $_POST['slot'] ?? '';
    if ($act === 'log') {
      $service->punch($eid, $today, $slot, (int)($_SESSION['user_id'] ?? 0), 'web');
      $label = in_array($slot,['am_in','pm_in','ot_in'],true) ? 'Time In' : 'Time Out';
      flash_set('success', $label.' recorded.');
    } elseif ($act === 'undo_last' && method_exists($service,'undoLast')) {
      $service->undoLast($eid, $today, (int)($_SESSION['user_id'] ?? 0));
      flash_set('success','Last entry removed.');
    } else {
      if ($act !== 'log') throw new Exception('Unknown action');
    }
  } catch (Throwable $e) {
    flash_set('error', $e->getMessage());
  }
  redirect('attendance.php?m='.$month);
}

/* today row + auto-close */
$todayRow  = $service->getDay($eid, $today) ?? ['am_in'=>null,'am_out'=>null,'pm_in'=>null,'pm_out'=>null,'ot_in'=>null,'ot_out'=>null,'paid'=>0,'ot_allowed'=>0];

$nowTs   = time();
$noonTs  = strtotime($today.' 12:00:00');
$sixTs   = strtotime($today.' 18:00:00');
[$otStartTs,$otEndTs] = $service->otWindowTs($today);

if ($nowTs >= $noonTs && !empty($todayRow['am_in']) && empty($todayRow['am_out']) && empty($todayRow['paid'])) {
  $st = $conn->prepare("UPDATE attendance SET am_out='12:00:00', updated_at=NOW() WHERE employee_id=? AND work_date=? AND am_in IS NOT NULL AND (am_out IS NULL OR am_out='')");
  if ($st){ $st->bind_param('is',$eid,$today); $st->execute(); $st->close(); }
  $todayRow  = $service->getDay($eid, $today) ?? $todayRow;
}
if ($nowTs >= $sixTs && !empty($todayRow['pm_in']) && empty($todayRow['pm_out']) && empty($todayRow['paid'])) {
  $st = $conn->prepare("UPDATE attendance SET pm_out='18:00:00', updated_at=NOW() WHERE employee_id=? AND work_date=? AND pm_in IS NOT NULL AND (pm_out IS NULL OR pm_out='')");
  if ($st){ $st->bind_param('is',$eid,$today); $st->execute(); $st->close(); }
  $todayRow  = $service->getDay($eid, $today) ?? $todayRow;
}
if ($nowTs >= $otEndTs && !empty($todayRow['ot_in']) && empty($todayRow['ot_out']) && empty($todayRow['paid'])) {
  $st = $conn->prepare("UPDATE attendance SET ot_out=?, updated_at=NOW() WHERE employee_id=? AND work_date=? AND ot_in IS NOT NULL AND (ot_out IS NULL OR ot_out='')");
  $otEnd = $OT_END_HM;
  if ($st){ $st->bind_param('sis',$otEnd,$eid,$today); $st->execute(); $st->close(); }
  $todayRow  = $service->getDay($eid, $today) ?? $todayRow;
}

/* windows & button states */
$win = [
  'am_in'  => $service->windowFor($today,'am_in'),
  'am_out' => $service->windowFor($today,'am_out'),
  'pm_in'  => $service->windowFor($today,'pm_in'),
  'pm_out' => $service->windowFor($today,'pm_out'),
];
// For labels only (AM Out ends noon)
$win['am_out'] = [$win['am_out'][0], strtotime($today.' 12:00:00')];

function fmt12_ts($ts){ return date('g:i A',$ts); }
function range_label($p){ return fmt12_ts($p[0]).'–'.fmt12_ts($p[1]).' allowed'; }

$labels = [
  'am_in'  => range_label($win['am_in']),
  'am_out' => 'Until '.fmt12_ts($win['am_out'][1]),
  'pm_in'  => range_label($win['pm_in']),
  'pm_out' => 'Until '.fmt12_ts($win['pm_out'][1]),
  'ot_in'  => fmt12_ts($otStartTs).'–'.fmt12_ts($otEndTs).' allowed',
  'ot_out' => 'Until '.fmt12_ts($otEndTs),
];

$now = time();
$paid = !empty($todayRow['paid']);
$otAllowed = !empty($todayRow['ot_allowed']);

$amInEnabled  = (empty($todayRow['am_in'])  && !$paid && $now >= $win['am_in'][0]  && $now <= $win['am_in'][1]);
$amOutEnabled = (empty($todayRow['am_out']) && !empty($todayRow['am_in']) && !$paid && $now >= $win['am_out'][0] && $now <= $win['am_out'][1]);
$pmInEnabled  = (empty($todayRow['pm_in'])  && !$paid && $now >= $win['pm_in'][0]  && $now <= $win['pm_in'][1]);
$pmOutEnabled = (empty($todayRow['pm_out']) && !empty($todayRow['pm_in']) && !$paid && $now >= $win['pm_out'][0] && $now <= $win['pm_out'][1]);

// OT: needs allowed & inside 6–10 PM; PM Out not required
$otInEnabled  = ($otAllowed && empty($todayRow['ot_in'])  && !$paid && $now >= $otStartTs && $now <= $otEndTs);
$otOutEnabled = ($otAllowed && empty($todayRow['ot_out']) && !empty($todayRow['ot_in']) && !$paid && $now >= $otStartTs && $now <= $otEndTs);

$completeToday = !empty($todayRow['am_in']) && !empty($todayRow['am_out']) && !empty($todayRow['pm_in']) && !empty($todayRow['pm_out']);
$calcToday = $completeToday ? $service->computeDay($today, $todayRow) : ['regular'=>0,'deduct'=>0];
$calcTodayAny = $service->computeDay($today, $todayRow);
$todayOTm = (int)($calcTodayAny['ot'] ?? 0);

include __DIR__.'/inc/layout_head.php';
?>
<style>
  .table td,.table th{ vertical-align:middle; white-space:nowrap }
  .hint{ font-size:.78rem; color:#6b7280 }
  .clock { font-variant-numeric: tabular-nums; letter-spacing:.02em; }

  .card { border-radius: 12px; }
  .card-header { padding:.4rem .6rem; }
  .card-body { padding:.55rem; }
  .row.g-2 { --bs-gutter-x:.5rem; --bs-gutter-y:.5rem; }

  .slot-card{
    border:1px solid #e9ecef; border-radius:12px; background:#fbfbfc;
    padding:.5rem .55rem; height:100%;
  }
  .slot-title{ font-weight:700; display:flex; align-items:center; gap:.35rem; margin-bottom:.3rem; font-size:.9rem; }
  .slot-title .ico{
    width:22px;height:22px; display:inline-flex; align-items:center; justify-content:center;
    border-radius:6px; background:#f3f4f6; color:#6c757d; font-size:.8rem;
  }
  .time-box{
    background:#fff; border:1px solid #e6e8eb; border-radius:8px;
    padding:.25rem .45rem; font-weight:600; color:#111; min-height:32px; display:flex; align-items:center;
    font-size:.9rem;
  }
  .allowed{ font-size:.7rem; color:#6b7280; margin:.2rem 0 .4rem; }
  .allowed .chip{
    display:inline-flex; align-items:center; gap:.3rem; padding:.12rem .4rem;
    background:#f6f7ff; color:#374151; border-radius:999px; border:1px solid #e5e8ff;
  }
  .btn-log{ width:100%; border-radius:8px; font-weight:700; padding:.3rem .45rem; }
  .btn-sm-tight{ padding:.22rem .45rem; font-size:.82rem; border-radius:8px; }

  .metrics-wrap{ display:grid; grid-template-columns: repeat(3,1fr); gap:.5rem; }
  .metric{ border:1px solid #e9ecef; border-radius:10px; padding:.45rem; text-align:center; background:#fff; }
  .metric .k{ font-size:.75rem; color:#6b7280; }
  .metric .v{ font-weight:800; font-variant-numeric: tabular-nums; }

  .pill-ok{
    display:inline-block; padding:.12rem .42rem; border-radius:999px; background:#e8f6ee; color:#0f5132;
    border:1px solid #b8e1c7; font-size:.7rem; font-weight:700;
  }

  .table-sm > :not(caption) > * > * { padding:.32rem .45rem; }

  /* DTR Card Form (tight + printable) */
  .dtr-card-form{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 6px 16px rgba(0,0,0,.05)}
  .dtr-head{display:flex;gap:.75rem;align-items:center;justify-content:space-between;padding:.6rem .8rem;border-bottom:1px solid #eef1f4}
  .dtr-meta{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:baseline}
  .dtr-meta .title{font-weight:800}
  .dtr-meta .sub{color:#6b7280;font-size:.85rem}
  .dtr-actions{display:flex;gap:.4rem;align-items:center}
  .dtr-actions .form-select{height:28px;padding:.1rem .5rem}
  .dtr-actions .btn{padding:.2rem .55rem}
  .dtr-body{padding:.6rem .6rem .8rem}
  .dtr-form-table{width:100%;border-collapse:collapse;table-layout:fixed}
  .dtr-form-table th,.dtr-form-table td{
    border:1px solid #e9ecef; padding:.28rem .4rem; font-size:.85rem; white-space:nowrap; text-align:center; vertical-align:middle
  }
  .dtr-form-table thead th{background:#f8fafc;font-weight:700}
  .dtr-form-table .day{font-weight:800;width:50px}
  .dtr-form-table .w-narrow{width:78px}
  .dtr-form-table .w-wide{width:92px}
  .dtr-btm{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.6rem}
  .dtr-chip{border:1px dashed #e5e7eb;border-radius:10px;padding:.35rem .55rem;background:#fff;display:inline-flex;align-items:center;gap:.5rem}
  .dtr-chip .k{color:#6b7280; font-size:.83rem}
  .dtr-chip .v{font-weight:800; font-variant-numeric:tabular-nums}
  .danger .v{color:#b91c1c}
  .paid-badge{display:inline-flex;align-items:center;gap:.35rem;border-radius:999px;padding:.12rem .5rem;border:1px solid #e5e7eb;background:#f3f4f6;font-size:.78rem}
  .paid-yes{background:#e8f6ee;border-color:#b8e1c7;color:#0f5132}
  .print-note{color:#6b7280;font-size:.78rem}
  @media print{
    .no-print{display:none!important}
    .dtr-card-form{box-shadow:none}
    body{background:#fff}
  }
  .group-split { border-left:2px solid #e5e7eb !important; }
</style>

<div class="d-flex justify-content-between align-items-center mb-2">
  <div>
    <h6 class="fw-bold mb-0">My Attendance</h6>
    <div class="text-muted small">
      Today: <?=h(date('F j, Y'))?> · Schedule: <?=h('AM '.to12hm($WORK_SCHED['am_in']).'–'.to12hm($WORK_SCHED['am_out']).', PM '.to12hm($WORK_SCHED['pm_in']).'–'.to12hm($WORK_SCHED['pm_out']))?> · OT Window: <?=h(to12hm($OT_START_HM).'–'.to12hm($OT_END_HM))?>
    </div>
  </div>
  <div class="small text-muted">
    Server time (PH): <span id="liveClock" class="clock fw-semibold" data-server-ts="<?= (int)$serverNowTs * 1000 ?>"></span>
  </div>
</div>

<?php if($f=flash_pop()): ?>
  <div class="alert alert-<?= $f['t']==='success'?'success':'danger' ?> py-2 mb-2"><?= h($f['m']) ?></div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header fw-semibold py-2">Today’s Entry</div>
  <div class="card-body">
    <div class="row g-2">
      <!-- AM -->
      <div class="col-md-4">
        <div class="slot-card">
          <div class="slot-title"><span class="ico"><i class="fa-regular fa-sun"></i></span> AM</div>

          <div class="mb-2">
            <div class="hint mb-1">Time In</div>
            <div class="time-box"><?= to12($todayRow['am_in'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
            <div class="allowed"><span class="chip"><i class="fa-regular fa-clock"></i><?= h(range_label($win['am_in'])) ?></span></div>
            <form method="post" class="mb-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="am_in">
              <button class="btn btn-dark btn-sm-tight btn-log" <?= $amInEnabled?'':'disabled' ?>>Time In</button>
            </form>
          </div>

          <div>
            <div class="hint mb-1">Time Out</div>
            <div class="time-box"><?= to12($todayRow['am_out'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
            <div class="allowed"><span class="chip"><i class="fa-regular fa-clock"></i><?= h('Until '.fmt12_ts($win['am_out'][1])) ?></span></div>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="am_out">
              <button class="btn btn-outline-dark btn-sm-tight btn-log" <?= $amOutEnabled?'':'disabled' ?>>Time Out</button>
            </form>
          </div>
        </div>
      </div>

      <!-- PM -->
      <div class="col-md-4">
        <div class="slot-card">
          <div class="slot-title"><span class="ico"><i class="fa-regular fa-moon"></i></span> PM</div>

          <div class="mb-2">
            <div class="hint mb-1">Time In</div>
            <div class="time-box"><?= to12($todayRow['pm_in'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
            <div class="allowed"><span class="chip"><i class="fa-regular fa-clock"></i><?= h(range_label($win['pm_in'])) ?></span></div>
            <form method="post" class="mb-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="pm_in">
              <button class="btn btn-dark btn-sm-tight btn-log" <?= $pmInEnabled?'':'disabled' ?>>Time In</button>
            </form>
          </div>

          <div>
            <div class="hint mb-1">Time Out</div>
            <div class="time-box"><?= to12($todayRow['pm_out'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
            <div class="allowed"><span class="chip"><i class="fa-regular fa-clock"></i><?= h('Until '.fmt12_ts($win['pm_out'][1])) ?></span></div>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="pm_out">
              <button class="btn btn-outline-dark btn-sm-tight btn-log" <?= $pmOutEnabled?'':'disabled' ?>>Time Out</button>
            </form>
          </div>
        </div>
      </div>

      <!-- OT (6–10 PM) -->
      <div class="col-md-4">
        <div class="slot-card">
          <div class="slot-title">
            <span class="ico"><i class="fa-regular fa-clock"></i></span> OT
            <?php if($otAllowed): ?><span class="ms-2 pill-ok">Allowed</span><?php else: ?><span class="ms-2 hint">(not allowed today)</span><?php endif; ?>
          </div>

          <div class="mb-2">
            <div class="hint mb-1">OT In</div>
            <div class="time-box"><?= to12($todayRow['ot_in'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
            <div class="allowed"><span class="chip"><i class="fa-regular fa-clock"></i><?= h($labels['ot_in']) ?></span></div>
            <form method="post" class="mb-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="ot_in">
              <button class="btn btn-dark btn-sm-tight btn-log" <?= ($otInEnabled ? '' : 'disabled') ?>>OT In</button>
            </form>
          </div>

          <div>
            <div class="hint mb-1">OT Out</div>
            <div class="time-box"><?= to12($todayRow['ot_out'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
            <div class="allowed"><span class="chip"><i class="fa-regular fa-clock"></i><?= h($labels['ot_out']) ?></span></div>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="ot_out">
              <button class="btn btn-outline-dark btn-sm-tight btn-log" <?= ($otOutEnabled ? '' : 'disabled') ?>>OT Out</button>
            </form>
          </div>

          <div class="mt-2 hint">
            OT ends automatically at <strong><?= to12hm($OT_END_HM) ?></strong> if still open.
            <?php if($todayOTm>0): ?> <span class="ms-2">Today OT: <strong><?= fmt_hhmm_from_mins($todayOTm) ?></strong></span><?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Totals -->
      <div class="col-12">
        <div class="metrics-wrap">
          <div class="metric">
            <div class="k">Total Hours</div>
            <div class="v"><?= $completeToday ? fmt_hhmm_from_mins((int)$calcToday['regular']) : '—' ?></div>
          </div>
          <div class="metric">
            <div class="k">Deduction</div>
            <div class="v <?= ($completeToday && (int)$calcToday['deduct']>0)?'text-danger':'' ?>">
              <?= $completeToday ? fmt_hhmm_from_mins((int)$calcToday['deduct']) : '—' ?>
            </div>
          </div>
          <div class="metric">
            <div class="k">OT (hh:mm)</div>
            <div class="v"><?= $todayOTm>0 ? fmt_hhmm_from_mins($todayOTm) : '—' ?></div>
          </div>
        </div>
        <div class="mt-2 hint">
          AM Out auto-closes at <strong>12:00 PM</strong>. PM Out auto-closes at <strong>6:00 PM</strong>. OT Out auto-closes at <strong><?= to12hm($OT_END_HM) ?></strong>.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- MONTHLY -->
<div class="dtr-card-form mt-2">
  <div class="dtr-head">
    <div class="dtr-meta">
      <div class="title">Daily Time Record</div>
      <div class="sub"><?=h($monthName.' '.$THIS_YEAR)?></div>
      <div class="sub">Employee: <strong><?=h($me['full_name'] ?? ($_SESSION['username'] ?? ''))?></strong></div>
      <?php if(!empty($me['position'])): ?>
      <div class="sub">Position: <strong><?=h($me['position'])?></strong></div>
      <?php endif; ?>
    </div>
    <form class="dtr-actions no-print" method="get">
      <select name="m" class="form-select form-select-sm">
        <?php for($i=1;$i<=12;$i++): ?>
          <option value="<?=$i?>" <?=$i===$month?'selected':''?>><?=h(date('F', mktime(0,0,0,$i,1)))?></option>
        <?php endfor; ?>
      </select>
      <button class="btn btn-sm btn-dark">View</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>
    </form>
  </div>

  <div class="dtr-body">
    <div class="table-responsive">
      <table class="dtr-form-table">
        <thead>
          <tr>
            <th class="day" rowspan="2">Day</th>
            <th colspan="2">AM</th>
            <th colspan="2" class="group-split">PM</th>
            <th colspan="2" class="group-split">OT</th>
            <th class="w-wide group-split" rowspan="2" title="Inside schedule 07–11 & 13–17 only">Total (hh:mm)</th>
            <th class="w-wide" rowspan="2">Deduct (hh:mm)</th>
            <th class="w-wide" rowspan="2">OT (hh:mm)</th>
            <th class="w-narrow" rowspan="2">Paid</th>
          </tr>
          <tr>
            <th class="w-narrow">In</th>
            <th class="w-narrow">Out</th>
            <th class="w-narrow group-split">In</th>
            <th class="w-narrow">Out</th>
            <th class="w-narrow group-split">In</th>
            <th class="w-narrow">Out</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $sumReg=0; $sumDed=0; $sumOT=0;
            $monthRows = $service->listRange($eid, $firstDay, $lastDay);
            $byDate = [];
            foreach($monthRows as $r){ $byDate[$r['work_date']] = $r; }

            for($d=1;$d<=$daysIn;$d++):
              $date = sprintf('%04d-%02d-%02d', $THIS_YEAR, $month, $d);
              $r    = $byDate[$date] ?? ['work_date'=>$date];

              $am_in  = $r['am_in']  ?? '';
              $am_out = $r['am_out'] ?? '';
              $pm_in  = $r['pm_in']  ?? '';
              $pm_out = $r['pm_out'] ?? '';
              $ot_in  = $r['ot_in']  ?? '';
              $ot_out = $r['ot_out'] ?? '';

              $complete = ($am_in && $am_out && $pm_in && $pm_out);

              $calc = $service->computeDay($date,$r);
              if ($complete){ $sumReg += (int)$calc['regular']; $sumDed += (int)$calc['deduct']; }
              $sumOT += (int)($calc['ot'] ?? 0);
              $paidFlag = !empty($r['paid']);
          ?>
          <tr>
            <td class="day"><?=$d?></td>
            <td><?= $am_in  ? to12($am_in)  : '—' ?></td>
            <td><?= $am_out ? to12($am_out) : '—' ?></td>
            <td class="group-split"><?= $pm_in  ? to12($pm_in)  : '—' ?></td>
            <td><?= $pm_out ? to12($pm_out) : '—' ?></td>
            <td class="group-split"><?= $ot_in  ? to12($ot_in)  : '—' ?></td>
            <td><?= $ot_out ? to12($ot_out) : '—' ?></td>
            <td class="group-split"><?= $complete ? fmt_hhmm_from_mins((int)$calc['regular']) : '—' ?></td>
            <td class="<?= ($complete && (int)$calc['deduct']>0)?'danger':'' ?>">
              <?= $complete ? fmt_hhmm_from_mins((int)$calc['deduct']) : '—' ?>
            </td>
            <td><?= (int)($calc['ot'] ?? 0) > 0 ? fmt_hhmm_from_mins((int)$calc['ot']) : '—' ?></td>
            <td><span class="paid-badge <?=$paidFlag?'paid-yes':''?>"><?=$paidFlag?'Yes':'No'?></span></td>
          </tr>
          <?php endfor; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="7" style="text-align:right">Totals</th>
            <th class="group-split"><?= fmt_hhmm_from_mins($sumReg) ?></th>
            <th><?= fmt_hhmm_from_mins($sumDed) ?></th>
            <th><?= fmt_hhmm_from_mins($sumOT) ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="dtr-btm">
      <div class="dtr-chip">
        <span class="k">Note</span>
        <span class="v print-note">OT In/Out allowed only from <?= to12hm($OT_START_HM) ?> to <?= to12hm($OT_END_HM) ?>.</span>
      </div>
      <div class="dtr-chip">
        <span class="k">Schedule</span>
        <span class="v">07:00–11:00, 13:00–17:00 · OT <?= to12hm($OT_START_HM).'–'.to12hm($OT_END_HM) ?></span>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const el = document.getElementById('liveClock');
  if (!el) return;
  let serverMs = parseInt(el.getAttribute('data-server-ts') || '0', 10);
  if (!serverMs) return;
  function pad(n){ return (n<10?'0':'')+n; }
  function fmt12(d){
    let h=d.getHours(), m=d.getMinutes(), s=d.getSeconds(), ap=h>=12?'PM':'AM';
    h=h%12; if (h===0) h=12;
    return h+':'+pad(m)+':'+pad(s)+' '+ap;
  }
  function tick(){ el.textContent = fmt12(new Date(serverMs)); serverMs+=1000; }
  tick(); setInterval(tick,1000);
})();
</script>

<?php include __DIR__.'/inc/layout_foot.php';
