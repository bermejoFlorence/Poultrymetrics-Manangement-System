<?php
/**
 * accountant/inc/common.php (config.php–driven, no DB_CHARSET, no pmx_connect)
 * - Secure session (SameSite/HttpOnly; Secure if HTTPS)
 * - Always require ROOT /config.php
 * - Ensure $conn (mysqli): use existing $conn or connect via DB_* constants
 * - Safe DB helpers (tableExists/colExists/firstExistingCol, scalar)
 * - Role gate: allow roles 'accountant' and 'admin'
 * - Minimal notifications ($notif_count, $notifs)
 */

@date_default_timezone_set('Asia/Manila');

/* --------- Secure session --------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  ini_set('session.use_strict_mode', '1');
  session_start();
}

/* --------- Require ROOT /config.php --------- */
$ROOT = dirname(__DIR__, 2);                 // .../Poultrymetrics
$CFG  = $ROOT . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($CFG)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "config.php not found at project root: {$CFG}\n";
  exit;
}
require_once $CFG;

/* --------- Ensure $conn (mysqli) exists — NO pmx_connect, NO DB_CHARSET --------- */
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_NAME')) {
    http_response_code(500);
    exit('Database connection not initialized by config.php (missing DB_* constants).');
  }
  mysqli_report(MYSQLI_REPORT_OFF); // switch to ERROR|STRICT temporarily if debugging
  $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
  $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
  if ($conn->connect_errno) {
    http_response_code(500);
    exit('Database connection error.');
  }
  // Hardcode utf8mb4 (do not use DB_CHARSET)
  if (method_exists($conn, 'set_charset')) { $conn->set_charset('utf8mb4'); }
}
@$conn->query("SET time_zone = '+08:00'"); // MySQL session TZ

/* --------- Helpers (no redeclare) --------- */
if (!function_exists('h'))  { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc')){ function esc($s){ return h($s); } } // alias

if (!function_exists('scalar')){
  function scalar(mysqli $c, string $sql){
    try {
      $r = $c->query($sql);
      if (!$r) return null;
      $row = $r->fetch_row();
      if ($r) $r->free();
      return $row ? $row[0] : null;
    } catch (Throwable $e) { return null; }
  }
}
if (!function_exists('tableExists')){
  function tableExists(mysqli $c, string $tbl): bool {
    if ($tbl === '') return false;
    try {
      $tbl = $c->real_escape_string($tbl);
      $r   = $c->query("SHOW TABLES LIKE '{$tbl}'");
      $ok  = ($r && $r->num_rows > 0);
      if ($r) $r->free();
      return $ok;
    } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('colExists')){
  function colExists(mysqli $c, string $tbl, string $col): bool {
    if ($tbl === '' || $col === '') return false;
    if (!tableExists($c, $tbl)) return false;
    try {
      $tbl = $c->real_escape_string($tbl);
      $col = $c->real_escape_string($col);
      $r   = $c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'");
      $ok  = ($r && $r->num_rows > 0);
      if ($r) $r->free();
      return $ok;
    } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('firstExistingCol')){
  function firstExistingCol(mysqli $c, string $tbl, array $cands, $fallback = null){
    if (!tableExists($c, $tbl)) return $fallback;
    foreach ($cands as $col) if (colExists($c, $tbl, $col)) return $col;
    return $fallback;
  }
}

/* --------- Auth: allow only accountant or admin --------- */
if (!function_exists('pmx_login_url')) {
  function pmx_login_url(): string {
    // Respect BASE_URI if defined in config.php; fallback to root /login.php
    $base = defined('BASE_URI') ? rtrim((string)BASE_URI, '/') : '';
    return ($base ?: '') . '/login.php';
  }
}
$role = strtolower((string)($_SESSION['role'] ?? ''));
if (empty($_SESSION['username']) || !in_array($role, ['accountant','admin'], true)) {
  header('Location: ' . pmx_login_url());
  exit;
}

/* --------- Notifications (minimal & schema-aware) --------- */
$notif_count = 0;
$notifs = [];

/* Unpaid / Partially paid orders */
if (tableExists($conn,'orders') && colExists($conn,'orders','payment_status')) {
  $due = (int)(scalar($conn, "SELECT COUNT(*) FROM orders WHERE payment_status IN ('unpaid','partial')") ?? 0);
  if ($due > 0) {
    $notif_count += $due;
    $notifs[] = [
      'label' => "$due order(s) not fully paid",
      'url'   => (defined('BASE_URI') ? rtrim(BASE_URI,'/') : '') . '/admin/orders.php?payment=due',
      'icon'  => 'fa-receipt'
    ];
  }
}

/* Payments received today */
if (tableExists($conn,'payments') && colExists($conn,'payments','paid_at')) {
  $payCount = (int)(scalar($conn, "SELECT COUNT(*) FROM payments WHERE DATE(paid_at)=CURDATE()") ?? 0);
  if ($payCount > 0) {
    $notif_count += 1; // info bubble
    $notifs[] = [
      'label' => "$payCount payment(s) received today",
      'url'   => (defined('BASE_URI') ? rtrim(BASE_URI,'/') : '') . '/admin/payments.php?date='.date('Y-m-d'),
      'icon'  => 'fa-cash-register'
    ];
  }
}

/* Attendance logs today (att_date/date/log_date/work_date/attendance_date) */
if (tableExists($conn,'attendance_logs')) {
  $dateCol = firstExistingCol($conn,'attendance_logs', ['att_date','date','log_date','work_date','attendance_date'], null);
  if ($dateCol) {
    $todayLogs = (int)(scalar($conn, "SELECT COUNT(*) FROM attendance_logs WHERE `$dateCol`=CURDATE()") ?? 0);
    if ($todayLogs > 0) {
      $notif_count += 1;
      $notifs[] = [
        'label' => "$todayLogs attendance log(s) today",
        'url'   => (defined('BASE_URI') ? rtrim(BASE_URI,'/') : '') . '/admin/attendance.php?date='.date('Y-m-d'),
        'icon'  => 'fa-user-clock'
      ];
    }
  }
}

/* Low stock products (products.stock_qty [and optional reorder_level]) */
if (tableExists($conn,'products') && colExists($conn,'products','stock_qty')) {
  $hasReorder = colExists($conn,'products','reorder_level');
  if ($hasReorder) {
    $low_stock = (int)(scalar($conn, "SELECT COUNT(*) FROM products WHERE status='active' AND stock_qty < reorder_level") ?? 0);
  } else {
    $DEFAULT_THRESHOLD = 10;
    $low_stock = (int)(scalar($conn, "SELECT COUNT(*) FROM products WHERE status='active' AND stock_qty < {$DEFAULT_THRESHOLD}") ?? 0);
  }
  if ($low_stock > 0) {
    $notif_count += $low_stock;
    $notifs[] = [
      'label' => "$low_stock product(s) in Low Stock",
      'url'   => (defined('BASE_URI') ? rtrim(BASE_URI,'/') : '') . '/admin/products.php?filter=low',
      'icon'  => 'fa-clipboard-check'
    ];
  }
}

/* --------- Optional: CSRF helpers --------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
if (!function_exists('csrf_token')){ function csrf_token(): string { return (string)($_SESSION['csrf'] ?? ''); } }
if (!function_exists('csrf_check')) { function csrf_check(string $t): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); } }
