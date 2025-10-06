<?php
// admin/fp_match.php
declare(strict_types=1);
@date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

require_once __DIR__.'/inc/common.php';

// ---- Configure matcher executable here (or via environment) ----
$FPMATCH_CMD = getenv('FPMATCH_CMD') ?: 'C:\\xampp\\FpMatch.exe {probe} {ref}'; // MUST exist
$FPMATCH_OK  = getenv('FPMATCH_OK')  ?: '0'; // exit code that means "match"
$SCORE_TOKEN = getenv('FPMATCH_SCORE_LINE') ?: 'score='; // optional score prefix from stdout

function matcher_available(string $cmdTpl): bool {
  $path = trim(str_replace(['{probe}','{ref}'],'', $cmdTpl));
  $first = strtok($path, " \t");
  return (bool)($first && is_file($first));
}

try {
  if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); } }

  $probe_b64 = (string)($_POST['probe_b64'] ?? '');
  if ($probe_b64==='') throw new Exception('Missing probe_b64');

  $probe = base64_decode($probe_b64, true);
  if ($probe===false || strlen($probe)===0) throw new Exception('Invalid probe base64');

  if (!matcher_available($FPMATCH_CMD)) {
    echo json_encode(['ok'=>false,'error'=>'matcher_not_available']); exit;
  }

  // Fetch all enrolled templates
  $rows = [];
  if ($res = @$conn->query("SELECT b.id, b.employee_id, b.template, e.full_name
                            FROM employee_biometrics b
                            JOIN employees e ON e.employee_id = b.employee_id")) {
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $res->free();
  }

  if (!$rows) { echo json_encode(['ok'=>false,'error'=>'no_templates']); exit; }

  // Prepare temp files
  $tmpDir = sys_get_temp_dir();
  $probePath = tempnam($tmpDir, 'probe_');
  file_put_contents($probePath, $probe);

  $matched = null;
  $score   = null;

  foreach ($rows as $r) {
    $refPath = tempnam($tmpDir, 'ref_');
    file_put_contents($refPath, $r['template']);

    $cmd = str_replace(['{probe}','{ref}'], [escapeshellarg($probePath), escapeshellarg($refPath)], $FPMATCH_CMD);
    $out=[]; $ret=0; exec($cmd.' 2>&1', $out, $ret);

    // Optional score parse
    if (!$score && $SCORE_TOKEN) {
      foreach ($out as $line) {
        $pos = stripos($line, $SCORE_TOKEN);
        if ($pos !== false) { $score = trim(substr($line, $pos + strlen($SCORE_TOKEN))); break; }
      }
    }

    @unlink($refPath);

    if ((string)$ret === (string)$FPMATCH_OK) {
      $matched = [
        'employee_id' => (int)$r['employee_id'],
        'full_name'   => (string)$r['full_name'],
        'biometric_id'=> (int)$r['id'],
        'score'       => $score,
      ];
      break;
    }
  }

  @unlink($probePath);

  if (!$matched) {
    echo json_encode(['ok'=>false,'error'=>'no_match','score'=>$score]); exit;
  }

  echo json_encode(['ok'=>true,'match'=>$matched,'score'=>$score]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
