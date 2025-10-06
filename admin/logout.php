<?php
// /admin/logout.php — only admin cookie
require_once __DIR__ . '/../includes/area_sessions.php';
pm_close_active_session(); session_name(pm_area_sess_name('admin')); session_start();
$_SESSION=[]; if (ini_get("session.use_cookies")) { $p=session_get_cookie_params();
  setcookie(session_name(), '', time()-42000, PM_COOKIE_PATH, $p['domain']??'', false, true);
} session_destroy(); header('Location: ../login.php');
