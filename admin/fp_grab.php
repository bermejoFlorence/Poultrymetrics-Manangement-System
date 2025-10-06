<?php
// admin/fp_grab.php
declare(strict_types=1);
@date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

try {
  $exe = 'C:\\xampp\\FpGrab.exe'; // <- adjust if needed
  if (!is_file($exe)) throw new Exception('FpGrab.exe not found at '.$exe);

  $exclusive = isset($_POST['exclusive']) && $_POST['exclusive']!=='0';
  $timeout   = (int)($_POST['timeout'] ?? 15000);
  if ($timeout < 1000) $timeout = 15000;
  $proc      = preg_replace('/[^A-Za-z0-9_\-]/','', (string)($_POST['proc'] ?? 'PIV'));
  if ($proc==='') $proc='PIV';

  $args = [];
  if ($exclusive) $args[]='-exclusive';
  $args[]='-timeout '.$timeout;
  $args[]='-proc '.$proc;

  $cmd = escapeshellarg($exe).' '.implode(' ', $args);
  $out = []; $ret = 0;
  exec($cmd.' 2>&1', $out, $ret);
  $raw = trim(implode("\n", $out));

  // Expected the EXE to print JSON like: {"ok":true,"format":"ANSI-378","template_b64":"..."}
  $j = json_decode($raw, true);
  if (!is_array($j) || empty($j['ok'])) {
    throw new Exception('Grab failed or invalid output. Raw: '.$raw);
  }

  // add byte size hint
  if (!empty($j['template_b64'])) {
    $len = strlen($j['template_b64']);
    $bytes = (int)floor(($len * 3) / 4) - (substr($j['template_b64'], -2)==='==' ? 2 : (substr($j['template_b64'], -1)==='=' ? 1 : 0));
    $j['approx_bytes'] = $bytes;
  }

  echo json_encode($j);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
