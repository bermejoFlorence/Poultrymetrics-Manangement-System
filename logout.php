<?php
// logout.php — end session + redirect to login (folder-safe)

// Start session with same cookie attributes you use elsewhere
if (session_status() !== PHP_SESSION_ACTIVE) {
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

// 1) Clear all session data
$_SESSION = [];

// 2) Delete the session cookie (use current params to match path/domain)
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  // Domain can be null on some setups; coalesce carefully
  setcookie(session_name(), '', time() - 42000, $p['path'] ?? '/', $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
}

// 3) Destroy session and regenerate ID
session_destroy();
session_write_close();
if (session_status() !== PHP_SESSION_ACTIVE) {
  // Regenerate a new empty ID to prevent fixation on next request
  session_start();
  session_regenerate_id(true);
  $_SESSION = [];
}

// 4) Optional: invalidate any “remember me” / auth cookies your app uses
// setcookie('remember_token', '', time() - 3600, '/', '', false, true);

// 5) Build a safe redirect to login.php in the same app folder
// e.g., if current script is /Poultrymetrix/logout.php -> /Poultrymetrix/login.php
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$loginPath = ($base === '' || $base === '/') ? '/login.php' : ($base . '/login.php');

// 6) Redirect (with HTML fallback)
header('Location: ' . $loginPath, true, 302);
?>
<!doctype html>
<html lang="en"><meta charset="utf-8">
<title>Logging out…</title>
<meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($loginPath, ENT_QUOTES, 'UTF-8'); ?>">
<p>If you are not redirected, <a href="<?php echo htmlspecialchars($loginPath, ENT_QUOTES, 'UTF-8'); ?>">click here to continue</a>.</p>
