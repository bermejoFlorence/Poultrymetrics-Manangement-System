<?php
// worker/worker_dashboard.php
require_once __DIR__.'/inc/common.php';
require_once __DIR__.'/inc/AttendanceService.php';

require_login($conn);
$me = current_worker($conn);
if (!$me) {
  flash_set('error','No linked employee profile found.');
  redirect(BASE_URI.'/worker/attendance.php');
}

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('to12')) {
  function to12(?string $t): string {
    $t = trim((string)$t);
    if ($t==='') return '';
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) return date('g:i A', strtotime(date('Y-m-d').' '.$t));
    $ts = strtotime($t); return $ts ? date('g:i A',$ts) : $t;
  }
}
function to12hm(string $hm): string { return date('g:i A', strtotime($hm)); }

/* ------------ page config / schedule ------------ */
$PAGE_TITLE = 'Overview';
$WORK_SCHED       = $WORK_SCHED ?? ['am_in'=>'07:00','am_out'=>'11:00','pm_in'=>'13:00','pm_out'=>'17:00'];
$REG_DAILY_MINS   = $REG_DAILY_MINS ?? 480;

$eid         = (int)$me['id'];
$today       = date('Y-m-d');
$serverNowTs = time();

/* ------------ services ------------ */
$service = new AttendanceService(
  db: $conn,
  sched: $WORK_SCHED,
  standardMins: $REG_DAILY_MINS,
  graceMins: 5,
  roundTo: 1
);

/* ------------ data: today ------------ */
$todayRow  = $service->getDay($eid, $today) ?? ['am_in'=>null,'am_out'=>null,'pm_in'=>null,'pm_out'=>null,'paid'=>0,'ot_allowed'=>0];
$calcToday = $service->computeDay($today, $todayRow);

/* ------------ data: current month ------------ */
$THIS_YEAR = (int)date('Y');
$month     = (int)date('n');
$firstDay  = sprintf('%04d-%02d-01', $THIS_YEAR, $month);
$daysIn    = (int)date('t', strtotime($firstDay));
$lastDay   = sprintf('%04d-%02d-%02d', $THIS_YEAR, $month, $daysIn);
$monthName = date('F', strtotime($firstDay));

$monthRows = $service->listRange($eid, $firstDay, $lastDay);
$sumReg=0; $sumDed=0; $sumOT=0;
foreach($monthRows as $r){
  $c = $service->computeDay($r['work_date'], $r);
  $sumReg += (int)$c['regular'];
  $sumDed += (int)$c['deduct'];
  $sumOT  += (int)$c['ot'];
}

/* ------------ windows (for showing quick-log availability) ------------ */
$wAmIn  = $service->windowFor($today,'am_in');
$wAmOut = $service->windowFor($today,'am_out');
$wPmIn  = $service->windowFor($today,'pm_in');
$wPmOut = $service->windowFor($today,'pm_out');

/* Enforce your rule visually: AM OUT cutoff = 12:00 PM; the attendance.php will also auto-close at noon */
$noonTs   = strtotime($today.' 12:00:00');
$wAmOut   = [$wAmOut[0], $noonTs];

$now = time();
$amInEnabled  = ($now >= $wAmIn[0]  && $now <= $wAmIn[1]  && empty($todayRow['am_in'])  && empty($todayRow['paid']));
$amOutEnabled = ($now >= $wAmOut[0] && $now <= $wAmOut[1] && empty($todayRow['am_out']) && !empty($todayRow['am_in']) && empty($todayRow['paid']));
$pmInEnabled  = ($now >= $wPmIn[0]  && $now <= $wPmIn[1]  && empty($todayRow['pm_in'])  && empty($todayRow['paid']));
$pmOutEnabled = ($now >= $wPmOut[0] && $now <= $wPmOut[1] && empty($todayRow['pm_out']) && !empty($todayRow['pm_in']) && empty($todayRow['paid']));

/* ------------ pretty schedule text (12h) ------------ */
$schedText = 'AM '.to12hm($WORK_SCHED['am_in']).'–'.to12hm($WORK_SCHED['am_out']).', '.
             'PM '.to12hm($WORK_SCHED['pm_in']).'–'.to12hm($WORK_SCHED['pm_out']);

include __DIR__.'/inc/layout_head.php';
?>
<style>
  .greet-card{ background:linear-gradient(180deg,#232a31,#1f242a); color:#e9eef3; border:0; border-radius:14px; }
  .greet-card .chip{ display:inline-flex; gap:.4rem; align-items:center; border:1px solid rgba(255,255,255,.15); padding:.25rem .55rem; border-radius:999px; font-size:.8rem }
  .metric{ border:1px solid #e9ecef; border-radius:12px; padding:.8rem }
  .metric .v{ font-weight:700; font-variant-numeric: tabular-nums; }
  .timebox{ background:#fff; border:1px solid #e9ecef; border-radius:10px; padding:.45rem .65rem; min-height:40px; display:flex; align-items:center; }
  .hint{ font-size:.8rem; color:#6b7280 }
  .btn-q{ width:100%; border-radius:10px; }
</style>

<div class="row g-3">
  <!-- Greeting / header -->
  <div class="col-12">
    <div class="card greet-card shadow-sm">
      <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
          <div class="mb-1">Welcome back,</div>
          <h4 class="mb-1 fw-bold"><?= h($me['full_name'] ?? ($_SESSION['username'] ?? 'Worker')) ?></h4>
          <div class="chip"><i class="fa-regular fa-calendar"></i> Today: <?= h(date('F j, Y')) ?></div>
          <div class="chip ms-1"><i class="fa-regular fa-clock"></i> Schedule: <?= h($schedText) ?></div>
        </div>
        <div class="text-end">
          <div class="small">Server time (PH)</div>
          <div id="liveClock" class="fs-5 fw-semibold" data-server-ts="<?= (int)$serverNowTs * 1000 ?>"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Today at a glance -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header fw-semibold"><i class="fa-regular fa-clipboard me-1"></i> Today at a Glance</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-6">
            <div class="hint mb-1">AM In</div>
            <div class="timebox"><?= to12($todayRow['am_in'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
          </div>
          <div class="col-6">
            <div class="hint mb-1">AM Out</div>
            <div class="timebox"><?= to12($todayRow['am_out'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
          </div>
          <div class="col-6">
            <div class="hint mb-1">PM In</div>
            <div class="timebox"><?= to12($todayRow['pm_in'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
          </div>
          <div class="col-6">
            <div class="hint mb-1">PM Out</div>
            <div class="timebox"><?= to12($todayRow['pm_out'] ?? '') ?: '<span class="text-muted">— not recorded —</span>' ?></div>
          </div>
        </div>

        <hr>

        <div class="row g-2 text-center">
          <div class="col-4">
            <div class="metric">
              <div class="hint">Regular</div>
              <div class="v"><?= (int)$calcToday['regular'] ?> m</div>
            </div>
          </div>
          <div class="col-4">
            <div class="metric">
              <div class="hint">Deduction</div>
              <div class="v <?= $calcToday['deduct']>0?'text-danger':'' ?>"><?= (int)$calcToday['deduct'] ?> m</div>
            </div>
          </div>
          <div class="col-4">
            <div class="metric">
              <div class="hint">OT</div>
              <div class="v"><?= (int)$calcToday['ot'] ?> m</div>
            </div>
          </div>
        </div>

        <div class="mt-3 d-flex flex-wrap gap-2">
          <a class="btn btn-outline-secondary" href="attendance.php"><i class="fa-regular fa-clock me-1"></i> Open Attendance</a>
          <a class="btn btn-outline-secondary" href="daily_egg_feed_inventory_form.php"><i class="fa-regular fa-rectangle-list me-1"></i> Form I-102</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick log (posts to attendance.php so all rules stay centralized) -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header fw-semibold"><i class="fa-solid fa-bolt me-1"></i> Quick Log</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-6">
            <div class="hint mb-1">AM In (allowed <?= date('g:i A',$wAmIn[0]) ?>–<?= date('g:i A',$wAmIn[1]) ?>)</div>
            <form method="post" action="attendance.php">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="am_in">
              <button class="btn btn-dark btn-q" <?= $amInEnabled?'':'disabled' ?>>Log AM In</button>
            </form>
          </div>
          <div class="col-6">
            <div class="hint mb-1">AM Out (until <?= date('g:i A',$wAmOut[1]) ?>)</div>
            <form method="post" action="attendance.php">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="am_out">
              <button class="btn btn-outline-dark btn-q" <?= $amOutEnabled?'':'disabled' ?>>Log AM Out</button>
            </form>
          </div>
          <div class="col-6">
            <div class="hint mb-1">PM In (allowed <?= date('g:i A',$wPmIn[0]) ?>–<?= date('g:i A',$wPmIn[1]) ?>)</div>
            <form method="post" action="attendance.php">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="pm_in">
              <button class="btn btn-dark btn-q" <?= $pmInEnabled?'':'disabled' ?>>Log PM In</button>
            </form>
          </div>
          <div class="col-6">
            <div class="hint mb-1">PM Out (allowed <?= date('g:i A',$wPmOut[0]) ?>–<?= date('g:i A',$wPmOut[1]) ?>)</div>
            <form method="post" action="attendance.php">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="log">
              <input type="hidden" name="slot" value="pm_out">
              <button class="btn btn-outline-dark btn-q" <?= $pmOutEnabled?'':'disabled' ?>>Log PM Out</button>
            </form>
          </div>
        </div>

        <div class="mt-3">
          <form method="post" action="attendance.php" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="undo_last">
            <?php $hasAny = ($todayRow['am_in']||$todayRow['am_out']||$todayRow['pm_in']||$todayRow['pm_out']); ?>
            <button class="btn btn-outline-secondary" <?= ($hasAny && empty($todayRow['paid']))?'':'disabled' ?>>
              <i class="fa-solid fa-rotate-left me-1"></i> Undo last entry
            </button>
          </form>
        </div>

        <div class="mt-2 hint">
          AM Out is auto-recorded at <strong>12:00 PM</strong> if AM In exists and noon is reached.
          For detailed view or monthly grid, open the Attendance page.
        </div>
      </div>
    </div>
  </div>

  <!-- Monthly snapshot -->
  <div class="col-12">
    <div class="card">
      <div class="card-header fw-semibold"><i class="fa-regular fa-calendar me-1"></i> <?= h($monthName.' '.$THIS_YEAR) ?> · Summary</div>
      <div class="card-body row g-3">
        <div class="col-md-3">
          <div class="metric text-center">
            <div class="hint">Regular</div>
            <div class="v"><?= (int)$sumReg ?> m</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="metric text-center">
            <div class="hint">Deduction</div>
            <div class="v <?= $sumDed>0?'text-danger':'' ?>"><?= (int)$sumDed ?> m</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="metric text-center">
            <div class="hint">Overtime</div>
            <div class="v"><?= (int)$sumOT ?> m</div>
          </div>
        </div>
        <div class="col-md-3 d-flex align-items-center justify-content-center">
          <a class="btn btn-outline-dark" href="attendance.php"><i class="fa-regular fa-eye me-1"></i> View Monthly DTR</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Live clock (server-driven, PH)
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
