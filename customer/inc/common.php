<?php
// /customer/inc/common.php — minimal shared bootstrap (customers)

if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'poultrymetrics');

if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_strict_mode', 1);
  session_start();
}
date_default_timezone_set('Asia/Manila');

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_errno) { http_response_code(500); die('DB error'); }
@$conn->set_charset('utf8mb4');
@$conn->query("SET time_zone = '+08:00'");

// ---------- helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url){ header('Location: '.$url); exit; }

/** scalar(): return first column of first row (or null). */
function scalar(mysqli $c, string $sql, array $params = [], string $types = ''){
  $val = null;
  if (!$stmt = $c->prepare($sql)) return $val;
  if ($params) $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
  if ($stmt->execute()){
    $res = $stmt->get_result();
    if ($res){ $row = $res->fetch_row(); $val = $row ? $row[0] : null; $res->free(); }
  }
  $stmt->close();
  return $val;
}

function flash_set(string $type, string $msg){ $_SESSION['flash']=['t'=>$type,'m'=>$msg]; }
function flash_pop(){ $f=$_SESSION['flash']??null; if($f) unset($_SESSION['flash']); return $f; }

function get_csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_ok($token): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(get_csrf()).'">'; }

// ---------- schema helpers ----------
function table_exists(mysqli $c, string $table): bool {
  $like = $c->real_escape_string($table);
  $res  = @$c->query("SHOW TABLES LIKE '{$like}'");
  if (!$res) return false; $ok = ($res->num_rows > 0); $res->free(); return $ok;
}
function table_has_col(mysqli $c, string $table, string $col): bool {
  $tbl  = str_replace('`','``',$table);
  $like = $c->real_escape_string($col);
  $res  = @$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$like}'");
  if (!$res) return false; $ok = ($res->num_rows > 0); $res->free(); return $ok;
}

// ---------- auth ----------
function current_user(mysqli $c): ?array {
  if (empty($_SESSION['user_id'])) return null;
  $pk = table_has_col($c,'users','id') ? 'id' : (table_has_col($c,'users','user_id') ? 'user_id' : 'id');
  $rolecol = table_has_col($c,'users','role') ? 'role' : null;
  $cols = "`$pk` AS id, username, email";
  if ($rolecol) $cols .= ", `$rolecol` AS role";
  $st=$c->prepare("SELECT $cols FROM users WHERE `$pk`=? LIMIT 1");
  $uid=(int)$_SESSION['user_id']; $st->bind_param('i',$uid); $st->execute();
  $row=$st->get_result()->fetch_assoc(); $st->close();
  return $row ?: null;
}
function require_login(mysqli $c){
  if (!current_user($c)) {
    flash_set('danger','Please log in to continue.');
    redirect('../login.php');
  }
}

// ---------- tiny cart util ----------
function cart_count(): int {
  $n=0; if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach($_SESSION['cart'] as $q){ $n+=(int)$q; }
  }
  return $n;
}
