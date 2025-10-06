<?php
// admin/bio_punch.php
declare(strict_types=1);
@date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

require_once __DIR__.'/inc/common.php';

// ---- Schedule windows (used to decide which slot to fill) ----
$WORK_SCHED = [
  'am_in'  => '07:00:00',
  'am_out' => '12:00:00',
  'pm_in'  => '13:00:00',
  'pm_out' => '22:00:00', // allow late OT punches
];

function now_time(): string { return date('H:i:s'); }
function today(): string { return date('Y-m-d'); }

function decide_slot(array $row, array $sched): string {
  // Returns which column to set next: am_in, am_out, pm_in, pm_out, ot_in, ot_out, or ''
  $t = now_time();

  $has = fn($k)=> (isset($row[$k]) && trim((string)$row[$k])!=='');
  $leq = fn($a,$b)=> strcmp($a,$b) <= 0;

  if (!$has('am_in')  && $leq($t,$sched['am_out'])) return 'am_in';
  if ($has('am_in') && !$has('am_out') && $leq($t,$sched['am_out'])) return 'am_out';
  if (!$has('pm_in')) return 'pm_in';
  if ($has('pm_in') && !$has('pm_out') && $leq($t,$sched['pm_out'])) return 'pm_out';
  if (!$has('ot_in')) return 'ot_in';
  if ($has('ot_in') && !$has('ot_out')) return 'ot_out';
  return '';
}

try {
  // 1) Grab a template
  $ch = curl_init('http://localhost/Poultrymetrics/admin/fp_grab.php');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['exclusive'=>'1','timeout'=>'15000','proc'=>'PIV'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
  ]);
  $grab = curl_exec($ch);
  if ($grab===false) throw new Exception('fp_grab error: '.curl_error($ch));
  curl_close($ch);

  $gj = json_decode($grab, true);
  if (!$gj || empty($gj['ok']) || empty($gj['template_b64'])) {
    throw new Exception('Grab failed: '.($gj['error'] ?? 'unknown'));
  }

  // 2) Match to DB
  $ch = curl_init('http://localhost/Poultrymetrics/admin/fp_match.php');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['probe_b64'=>$gj['template_b64']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
  ]);
  $mj = curl_exec($ch);
  if ($mj===false) throw new Exception('fp_match error: '.curl_error($ch));
  curl_close($ch);

  $mm = json_decode($mj, true);
  if (!$mm || empty($mm['ok'])) {
    if (!empty($mm['error']) && $mm['error']==='matcher_not_available') {
      throw new Exception('Matcher not available. Configure FPMATCH_CMD/FPMATCH_OK and enable exec().');
    }
    throw new Exception('No fingerprint match found.');
  }

  $empId = (int)$mm['match']['employee_id'];
  $name  = (string)$mm['match']['full_name'];

  // 3) Upsert attendance for today
  $date = today();
  $row = [
    'am_in'=>null,'am_out'=>null,'pm_in'=>null,'pm_out'=>null,'ot_in'=>null,'ot_out'=>null,
  ];
  $st = $conn->prepare("SELECT am_in,am_out,pm_in,pm_out,ot_in,ot_out FROM attendance WHERE employee_id=? AND work_date=? LIMIT 1");
  $st->bind_param('is',$empId,$date); $st->execute();
  $res = $st->get_result();
  if ($cur = $res->fetch_assoc()) $row = array_merge($row,$cur);
  $st->close();

  $slot = decide_slot($row, $WORK_SCHED);
  if ($slot==='') {
    echo json_encode(['ok'=>true,'message'=>"No slot to fill for $name — already complete."]); exit;
  }

  $now = now_time();
  if ($cur) {
    $sql = "UPDATE attendance SET $slot=? WHERE employee_id=? AND work_date=?";
    $st = $conn->prepare($sql); $st->bind_param('sis',$now,$empId,$date); $st->execute(); $st->close();
  } else {
    $cols = 'employee_id,work_date,'.$slot;
    $sql  = "INSERT INTO attendance ($cols) VALUES (?,?,?)";
    $st = $conn->prepare($sql); $st->bind_param('iss',$empId,$date,$now); $st->execute(); $st->close();
  }

  echo json_encode(['ok'=>true,'employee_id'=>$empId,'name'=>$name,'slot'=>$slot,'time'=>$now]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
