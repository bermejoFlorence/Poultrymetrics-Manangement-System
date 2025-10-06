<?php
// ======================================================================
// admin/fp_identify.php — Identify a fingerprint template against DB
// Returns JSON:
//   { ok:true, employee_id:123, name:"Full Name", via:"hash|cli", score:... }
// or
//   { ok:false, error:"..." }
//
// Requirements:
// - Table: employee_biometrics(employee_id, template LONGBLOB, tpl_hash VARCHAR(64), vendor, device_serial)
// - Table: employees (id/employee_id PK + name/full_name columns)
// - Optional: External CLI matcher (set environment variable FPMATCH_CMD)
//     Example: put in Apache/PHP environment or .bat wrapper:
//       FPMATCH_CMD=C:\xampp\FpMatch.exe "{probe}" "{ref}"
//     Expected: exit code 0 = match, non-zero = not match
//     Optional: FPMATCH_OK=0 (override), FPMATCH_SCORE_LINE="score="
// ======================================================================

require_once __DIR__.'/inc/common.php';
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);

/* ---------- tiny helpers (safe even if common.php has its own) ---------- */
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $tbl, string $col): bool {
    $tbl = str_replace('`','``',$tbl);
    $col = str_replace('`','``',$col);
    $res = @$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'");
    return $res && $res->num_rows>0;
  }
}
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $tbl): bool {
    $tbl = $c->real_escape_string($tbl);
    $res = @$c->query("SHOW TABLES LIKE '{$tbl}'");
    return $res && $res->num_rows>0;
  }
}
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
}

/* ---------- config & schema discovery ---------- */
$EMP_TBL = 'employees';
$EMP_PK  = colExists($conn,$EMP_TBL,'employee_id') ? 'employee_id'
        : (colExists($conn,$EMP_TBL,'id') ? 'id' : 'employee_id');
$EMP_NAME = colExists($conn,$EMP_TBL,'full_name') ? 'full_name'
         : (colExists($conn,$EMP_TBL,'name') ? 'name'
         : (colExists($conn,$EMP_TBL,'fullname') ? 'fullname' : null));

$HAS_BIO_TBL = tableExists($conn,'employee_biometrics')
            && colExists($conn,'employee_biometrics','employee_id')
            && colExists($conn,'employee_biometrics','tpl_hash')
            && colExists($conn,'employee_biometrics','template');

if (!$HAS_BIO_TBL) {
  echo json_encode(['ok'=>false,'error'=>'Biometrics table is missing required columns.']); exit;
}

/* ---------- input ---------- */
$tpl_b64 = (string)($_POST['template_b64'] ?? '');
$bioid   = trim((string)($_POST['biometric_id'] ?? '')); // not required, but helpful to short-circuit
if ($tpl_b64==='') {
  echo json_encode(['ok'=>false, 'error'=>'template_b64 is required']); exit;
}

/* ---------- normalize & hash ---------- */
$tpl_bin = base64_decode($tpl_b64, true);
if ($tpl_bin===false || strlen($tpl_bin)===0) {
  echo json_encode(['ok'=>false,'error'=>'Invalid template']); exit;
}
if (strlen($tpl_bin) > 2*1024*1024) {
  echo json_encode(['ok'=>false,'error'=>'Template too large']); exit;
}
$probe_hash = hash('sha256',$tpl_bin);

/* ---------- 0) Fast-path: biometric_id direct (if provided) ---------- */
if ($bioid !== '' && colExists($conn,$EMP_TBL,'biometric_id')) {
  $st = $conn->prepare("SELECT * FROM `$EMP_TBL` WHERE biometric_id=? LIMIT 1");
  $st->bind_param('s',$bioid);
  $st->execute();
  if ($emp = $st->get_result()->fetch_assoc()) {
    $st->close();
    $name = $EMP_NAME ? ($emp[$EMP_NAME] ?? 'Employee') : 'Employee';
    echo json_encode(['ok'=>true,'employee_id'=>(int)$emp[$EMP_PK],'name'=>$name,'via'=>'biometric_id']); exit;
  }
  $st->close();
}

/* ---------- 1) Exact hash match (same captured template) ---------- */
$st = $conn->prepare("SELECT employee_id FROM employee_biometrics WHERE tpl_hash=? ORDER BY id DESC LIMIT 1");
$st->bind_param('s',$probe_hash);
$st->execute();
$eid = (int)($st->get_result()->fetch_row()[0] ?? 0);
$st->close();

if ($eid>0) {
  // found exact hash
  $nm = 'Employee';
  if ($EMP_NAME) {
    $q = $conn->prepare("SELECT `$EMP_NAME` FROM `$EMP_TBL` WHERE `$EMP_PK`=? LIMIT 1");
    $q->bind_param('i',$eid); $q->execute();
    $nm = (string)($q->get_result()->fetch_row()[0] ?? 'Employee'); $q->close();
  }
  echo json_encode(['ok'=>true,'employee_id'=>$eid,'name'=>$nm,'via'=>'hash','score'=>100]); exit;
}

/* ---------- 2) Vendor CLI matcher (optional) ---------- */
/*
  Set environment variable FPMATCH_CMD to a command line that accepts:
    {probe} {reference}
  and returns exit code 0 when MATCH, non-zero when NOT MATCH.
  Example (nearly pseudocode):
    FPMATCH_CMD=C:\Tools\FpMatch.exe "{probe}" "{ref}"
  Optionally:
    FPMATCH_OK=0
    FPMATCH_SCORE_LINE=score=
*/
$CLI = getenv('FPMATCH_CMD') ?: '';
$OK  = (int)(getenv('FPMATCH_OK') ?: 0);
$SCORE_KEY = getenv('FPMATCH_SCORE_LINE') ?: 'score=';

if ($CLI !== '') {
  $tmpDir = sys_get_temp_dir();
  $probePath = $tmpDir.'/probe_'.bin2hex(random_bytes(6)).'.ansi378';
  file_put_contents($probePath, $tpl_bin);

  // Pull candidates (can be optimized by vendor/device_serial filters)
  $cand = [];
  $res = $conn->query("SELECT id, employee_id, template FROM employee_biometrics ORDER BY id DESC");
  while($row = $res->fetch_assoc()){
    $cand[] = $row;
  }
  $res?->free();

  // Iterate – return first MATCH
  foreach ($cand as $c) {
    $refPath = $tmpDir.'/ref_'.$c['id'].'_'.bin2hex(random_bytes(3)).'.ansi378';
    @file_put_contents($refPath, $c['template']);

    $cmd = str_replace(['{probe}','{ref}'], [escapeshellarg($probePath), escapeshellarg($refPath)], $CLI);

    // If user didn’t add quotes, make a best-effort:
    if (strpos($CLI,'{probe}')===false || strpos($CLI,'{ref}')===false) {
      // Assume "exe %probe% %ref%" style:
      $cmd = $CLI.' '.escapeshellarg($probePath).' '.escapeshellarg($refPath);
    }

    $out = [];
    $ret = 1;
    @exec($cmd . ' 2>&1', $out, $ret);

    // optional score parse
    $score = null;
    if ($out && $SCORE_KEY && is_array($out)) {
      foreach($out as $line){
        $p = stripos($line, $SCORE_KEY);
        if ($p !== false) {
          $val = trim(substr($line, $p + strlen($SCORE_KEY)));
          if (is_numeric($val)) $score = (float)$val;
          break;
        }
      }
    }

    @unlink($refPath);

    if ((int)$ret === $OK) {
      $eid = (int)$c['employee_id'];
      $nm = 'Employee';
      if ($EMP_NAME) {
        $q = $conn->prepare("SELECT `$EMP_NAME` FROM `$EMP_TBL` WHERE `$EMP_PK`=? LIMIT 1");
        $q->bind_param('i',$eid); $q->execute();
        $nm = (string)($q->get_result()->fetch_row()[0] ?? 'Employee'); $q->close();
      }
      @unlink($probePath);
      echo json_encode(['ok'=>true,'employee_id'=>$eid,'name'=>$nm,'via'=>'cli','score'=>$score]); exit;
    }
  }
  @unlink($probePath);
}

/* ---------- 3) Last-resort weak similarity (testing only) ---------- */
/* WARNING: This is NOT biometric matching; it just helps during setup. */
$probe_head = substr($tpl_b64, 0, 600);
$res = $conn->query("SELECT id, employee_id, TO_BASE64(SUBSTRING(template,1,450)) AS head FROM employee_biometrics ORDER BY id DESC");
$bestEid = 0; $bestSim=0;
while($row = $res->fetch_assoc()){
  $sim = 0;
  similar_text($probe_head, (string)$row['head'], $sim);
  if ($sim > $bestSim) { $bestSim=$sim; $bestEid=(int)$row['employee_id']; }
}
$res?->free();
if ($bestEid>0 && $bestSim>=90) {
  $nm = 'Employee';
  if ($EMP_NAME) {
    $q = $conn->prepare("SELECT `$EMP_NAME` FROM `$EMP_TBL` WHERE `$EMP_PK`=? LIMIT 1");
    $q->bind_param('i',$bestEid); $q->execute();
    $nm = (string)($q->get_result()->fetch_row()[0] ?? 'Employee'); $q->close();
  }
  echo json_encode(['ok'=>true,'employee_id'=>$bestEid,'name'=>$nm,'via'=>'approx','score'=>round($bestSim,2)]);
  exit;
}

echo json_encode(['ok'=>false,'error'=>'No match found. Add CLI matcher or enroll more templates.']);
