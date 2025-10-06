<?php
// /public_html/admin/fp_grab.php — proxy to Windows agent (no exec on Linux)
declare(strict_types=1);
@date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

try {
  if (!defined('FPGRAB_ENDPOINT')) throw new Exception('FPGRAB_ENDPOINT not set in config.php');

  $exclusive = isset($_POST['exclusive']) && $_POST['exclusive'] !== '0';
  $timeout   = max(1000, (int)($_POST['timeout'] ?? 15000));
  $proc      = preg_replace('/[^A-Za-z0-9_\-]/','', (string)($_POST['proc'] ?? 'PIV')) ?: 'PIV';

  $fields = [
    'exclusive' => $exclusive ? '1' : '0',
    'timeout'   => (string)$timeout,
    'proc'      => $proc,
  ];
  if (defined('FPGRAB_TOKEN')) $fields['token'] = FPGRAB_TOKEN;

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => FPGRAB_ENDPOINT,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($fields),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => (int)ceil(($timeout/1000) + 5),
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) throw new Exception('Agent unreachable: '.curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($code !== 200) throw new Exception("Agent HTTP $code: $resp");
  $j = json_decode($resp, true);
  if (!is_array($j) || empty($j['ok'])) throw new Exception('Agent returned invalid JSON: '.$resp);

  if (!empty($j['template_b64'])) {
    $len = strlen($j['template_b64']);
    $pad = substr($j['template_b64'], -2)==='==' ? 2 : (substr($j['template_b64'], -1)==='=' ? 1 : 0);
    $j['approx_bytes'] = (int)floor(($len * 3) / 4) - $pad;
  }

  echo json_encode($j);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
