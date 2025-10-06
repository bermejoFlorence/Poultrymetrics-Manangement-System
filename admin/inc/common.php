<?php
// /admin/inc/common.php — ADMIN-ONLY bootstrap (no session_name change)
declare(strict_types=1);

/* Load project config */
$PM_ROOT = realpath(__DIR__ . '/../../');
if ($PM_ROOT && is_file($PM_ROOT.'/config.php')) {
  require_once $PM_ROOT.'/config.php';
}

/* Base helpers */
if (!function_exists('pm_detect_base_uri')) {
  function pm_detect_base_uri(): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $parts  = array_values(array_filter(explode('/', $script)));
    if (!$parts) return '';
    $first = $parts[0];
    if (strpos($first, '.php') !== false) return '';
    return '/'.$first;
  }
}
if (!defined('BASE_URI')) define('BASE_URI', pm_detect_base_uri());
if (!function_exists('pm_is_https')) {
  function pm_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
    return false;
  }
}
if (!function_exists('pm_base_url')) {
  function pm_base_url(): string {
    $scheme = pm_is_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme.'://'.$host.BASE_URI;
  }
}
if (!defined('BASE_URL')) define('BASE_URL', pm_base_url());

/* Session (IMPORTANT: keep default PHPSESSID to match login.php) */
if (session_status() !== PHP_SESSION_ACTIVE) {
  @ini_set('session.use_strict_mode', '1');
  @ini_set('session.cookie_httponly', '1');
  @ini_set('session.cookie_secure', pm_is_https() ? '1' : '0');
  @ini_set('session.cookie_samesite', 'Lax');
  // DO NOT change session_name here — must match login.php
  session_start();
}
date_default_timezone_set('Asia/Manila');

/* DB connect */
mysqli_report(MYSQLI_REPORT_OFF);
if (!isset($conn) || !($conn instanceof mysqli)) {
  $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
}
if ($conn->connect_errno) { http_response_code(500); die('DB connection failed: '.$conn->connect_error); }
@$conn->set_charset('utf8mb4');
@$conn->query("SET time_zone = '+08:00'");

/* Helpers */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('redirect')) {
  function redirect(string $url){ header('Location: '.$url); exit; }
}
if (!function_exists('flash_set')) {
  function flash_set(string $type, string $msg){ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
}
if (!function_exists('flash_get')) {
  function flash_get(){ $f=$_SESSION['flash']??null; if($f) unset($_SESSION['flash']); return $f; }
}

/* Schema utils */
if (!function_exists('table_has_col')) {
  function table_has_col(mysqli $c, string $t, string $col): bool {
    $st=$c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    if(!$st) return false; $st->bind_param('ss',$t,$col); $st->execute(); $st->store_result();
    $ok=$st->num_rows>0; $st->close(); return $ok;
  }
}
if (!function_exists('first_col')) {
  function first_col(mysqli $c, string $t, array $cands): ?string {
    foreach($cands as $x) if (table_has_col($c,$t,$x)) return $x;
    return null;
  }
}

/* Roles: limit to admin | worker | accountant | customer */
if (!function_exists('normalize_role')) {
  function normalize_role(?string $r): string {
    $r = strtolower(trim((string)$r));
    $map = ['administrator'=>'admin','superadmin'=>'admin','super-admin'=>'admin','staff'=>'worker','acct'=>'accountant','accounting'=>'accountant'];
    if (isset($map[$r])) $r = $map[$r];
    $allow = ['admin','worker','accountant','customer'];
    return in_array($r,$allow,true)?$r:'customer';
  }
}

/* Users & auth */
if (!function_exists('users_table_info')) {
  function users_table_info(mysqli $c): array {
    $tbl='users';
    $pk   = table_has_col($c,$tbl,'user_id') ? 'user_id' : (table_has_col($c,$tbl,'id') ? 'id' : 'user_id');
    $role = first_col($c,$tbl,['role','user_role']);
    $uname= first_col($c,$tbl,['username','user_name']);
    $email= first_col($c,$tbl,['email','email_address']);
    return [$tbl,$pk,$role,$uname,$email];
  }
}
if (!function_exists('current_user')) {
  function current_user(mysqli $c): ?array {
    if (empty($_SESSION['user_id'])) return null;
    [$tbl,$pk,$role,$uname,$email] = users_table_info($c);
    $cols="`$pk` AS id";
    if ($role)  $cols.=", `$role` AS role";
    if ($uname) $cols.=", `$uname` AS username";
    if ($email) $cols.=", `$email` AS email";
    $st=$c->prepare("SELECT $cols FROM `$tbl` WHERE `$pk`=? LIMIT 1");
    if(!$st) return null;
    $uid=(int)$_SESSION['user_id']; $st->bind_param('i',$uid); $st->execute();
    $row=$st->get_result()->fetch_assoc(); $st->close();
    if(!$row) return null;
    $row['role']=normalize_role($row['role']??'');
    return $row;
  }
}

/* Where to send for login */
$adminLoginRel = '/admin/login.php';
if (!is_file($PM_ROOT.'/admin/login.php')) $adminLoginRel = '/login.php';
if (!defined('ADMIN_LOGIN_URL')) define('ADMIN_LOGIN_URL', BASE_URI.$adminLoginRel);

/* Guard: require admin except on exempt pages */
$__basename = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
$__exempt = in_array($__basename, ['login.php','forgot.php','reset_password.php','seed_admin.php','install.php'], true)
            || defined('PM_ALLOW_GUEST');

if (!$__exempt) {
  $u = current_user($conn);
  if (!$u || ($u['role']??'')!=='admin') {
    flash_set('danger','Admin access required.');
    redirect(ADMIN_LOGIN_URL);
  }
}
