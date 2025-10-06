<?php
/**
 * accountant/inc/common.php
 * - Secure session
 * - Load config.php (search upward)
 * - Ensure $conn (mysqli) via pmx_connect() if available
 * - Safe DB helpers (tableExists/colExists/firstExistingCol, scalar)
 * - Role gate: allow roles 'accountant' and 'admin'
 * - Lightweight notifications ($notif_count, $notifs) using poultrymetrics schema
 */

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
  session_start();
}
date_default_timezone_set('Asia/Manila');

/* --------- Find and require config.php upward from this folder --------- */
$__loaded = false;
$__cur    = __DIR__; // /accountant/inc
for ($i = 0; $i <= 8; $i++) {
  $cand = $__cur . DIRECTORY_SEPARATOR . 'config.php';
  if (is_file($cand)) { require_once $cand; $__loaded = true; break; }
  $parent = dirname($__cur);
  if ($parent === $__cur) break;
  $__cur = $parent;
}
if (!$__loaded) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "config.php not found relative to accountant/inc/common.php\n";
  echo "Place config.php at your project root (e.g., C:\\xampp\\htdocs\\Poultrymetrics\\config.php)\n";
  exit;
}

/* --------- Ensure $conn (mysqli) exists --------- */
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (function_exists('pmx_connect')) {
    try { $conn = pmx_connect(); } catch (Throwable $e) {
      http_response_code(500);
      exit('Database connection failed: '.$e->getMessage());
    }
  } elseif (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? DB_PORT : 3306);
    if ($conn->connect_errno) {
      http_response_code(500);
      exit('Database connection failed: ' . $conn->connect_error);
    }
    if (method_exists($conn, 'set_charset')) $conn->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
  } else {
    http_response_code(500);
    exit('Database connection ($conn) not initialized by config.php.');
  }
}
@$conn->query("SET time_zone = '+08:00'"); // MySQL session TZ

/* --------- Helpers (exception-safe, no redeclare) --------- */
if (!function_exists('h'))  { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc')){ function esc($s){ return h($s); } } // alias

if (!function_exists('scalar')){
  function scalar(mysqli $c, string $sql){
    try {
      $r = $c->query($sql);
      if (!$r) return null;
      $row = $r->fetch_row();
      $r->free();
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
      return ($r && $r->num_rows > 0);
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
      return ($r && $r->num_rows > 0);
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
$role = strtolower((string)($_SESSION['role'] ?? ''));
if (empty($_SESSION['username']) || !in_array($role, ['accountant','admin'], true)) {
  header('Location: /login.php');
  exit;
}

/* --------- Notifications (minimal & robust for poultrymetrics schema) --------- */
$notif_count = 0; 
$notifs = [];

/* Unpaid / Partially paid orders */
if (tableExists($conn,'orders') && colExists($conn,'orders','payment_status')) {
  $due = (int)(scalar($conn, "SELECT COUNT(*) FROM orders WHERE payment_status IN ('unpaid','partial')") ?? 0);
  if ($due > 0) {
    $notif_count += $due;
    $notifs[] = [
      'label' => "$due order(s) not fully paid",
      'url'   => '/admin/orders.php?payment=due',
      'icon'  => 'fa-receipt'
    ];
  }
}

/* Payments received today (count + quick link) */
if (tableExists($conn,'payments') && colExists($conn,'payments','paid_at')) {
  $payCount = (int)(scalar($conn, "SELECT COUNT(*) FROM payments WHERE DATE(paid_at)=CURDATE()") ?? 0);
  if ($payCount > 0) {
    $notif_count += 1; // one bubble for info
    $notifs[] = [
      'label' => "$payCount payment(s) received today",
      'url'   => '/admin/payments.php?date='.date('Y-m-d'),
      'icon'  => 'fa-cash-register'
    ];
  }
}

/* Attendance logs today (uses att_date per schema; falls back if older) */
if (tableExists($conn,'attendance_logs')) {
  $dateCol = firstExistingCol($conn,'attendance_logs', ['att_date','date','log_date','work_date','attendance_date'], null);
  if ($dateCol) {
    $todayLogs = (int)(scalar($conn, "SELECT COUNT(*) FROM attendance_logs WHERE `$dateCol`=CURDATE()") ?? 0);
    if ($todayLogs > 0) {
      $notif_count += 1; // informational bubble
      $notifs[] = [
        'label' => "$todayLogs attendance log(s) today",
        'url'   => '/admin/attendance.php?date='.date('Y-m-d'),
        'icon'  => 'fa-user-clock'
      ];
    }
  }
}

/* Low stock products (uses products.stock_qty and optional reorder_level) */
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
      'url'   => '/admin/products.php?filter=low',
      'icon'  => 'fa-clipboard-check'
    ];
  }
}

/* --------- Optional: CSRF helpers --------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
if (!function_exists('csrf_token')){ function csrf_token(): string { return (string)($_SESSION['csrf'] ?? ''); } }
if (!function_exists('csrf_check')) { function csrf_check(string $t): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); } }
