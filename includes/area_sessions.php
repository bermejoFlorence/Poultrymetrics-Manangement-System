<?php
// /includes/area_sessions.php — final
if (!defined('PM_COOKIE_PATH')) define('PM_COOKIE_PATH', '/'); // universal path

if (!function_exists('pm_app_base_path')) {
  function pm_app_base_path(): string { return '/'; }
}

function pm_norm_slot(?string $slot): ?string {
  if ($slot === null) return null;
  $s = preg_replace('/[^a-z0-9_-]/i','', $slot);
  return $s === '' ? null : strtolower($s);
}
function pm_area_sess_name(string $area, ?string $slot=null): string {
  $base = $area === 'admin'      ? 'PM_ADMIN_SESSID'
        : ($area === 'worker'    ? 'PM_WORKER_SESSID'
        : ($area === 'accountant'? 'PM_ACCOUNTANT_SESSID'
        : ($area === 'customer'  ? 'PM_CUSTOMER_SESSID' : 'PM_AREA_SESSID')));
  $slot = pm_norm_slot($slot);
  return ($area === 'customer' && $slot) ? ($base.'_'.strtoupper($slot)) : $base;
}
function pm_close_active_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
}
function start_area_session(string $area, ?string $slot=null): void {
  $name   = pm_area_sess_name($area, $slot);
  $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
  pm_close_active_session(); session_name($name);
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(['lifetime'=>0,'path'=>PM_COOKIE_PATH,'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
  } else {
    session_set_cookie_params(0, PM_COOKIE_PATH.'; samesite=Lax', '', $secure, true);
  }
  session_start();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
function require_role(string $role, string $redir='../login.php'): void {
  $okRole = strtolower($_SESSION['role'] ?? '') === strtolower($role);
  $okUser = !empty($_SESSION['user_id']);
  if (!$okRole || !$okUser) { header("Location: $redir"); exit(); }
}
function require_any_role(array $roles, string $redir='../login.php'): void {
  $r = strtolower($_SESSION['role'] ?? '');
  $okUser = !empty($_SESSION['user_id']);
  foreach ($roles as $ok) if ($r === strtolower($ok) && $okUser) return;
  header("Location: $redir"); exit();
}
function issue_area_session(string $area, array $userPayload, ?string $slot=null): void {
  $name   = pm_area_sess_name($area, $slot);
  $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
  pm_close_active_session(); session_name($name);
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(['lifetime'=>0,'path'=>PM_COOKIE_PATH,'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
  } else {
    session_set_cookie_params(0, PM_COOKIE_PATH.'; samesite=Lax', '', $secure, true);
  }
  session_start();
  foreach ($userPayload as $k=>$v) $_SESSION[$k] = $v;
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  session_write_close();
}
function seed_all_area_sessions(array $userPayload): void {
  foreach (['admin','worker','accountant','customer'] as $area) issue_area_session($area, $userPayload);
}
