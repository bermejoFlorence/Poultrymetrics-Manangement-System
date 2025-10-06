<?php
// ======================================================================
// /inc/common.php — shared bootstrap for PoultryMetrics
//  - Provides $conn (mysqli), session, helpers, auth guards
//  - Robust BASE_URI detection so redirects include /Poultrymetrics
//  - Worker + Admin compatible; flexible login redirect
//  - UTF8MB4 + Asia/Manila (+08:00)
//  - Attendance/payroll math helpers
//  - DB connection: local/hosting aware, supports /config.php overrides
// ======================================================================

@date_default_timezone_set('Asia/Manila');

// ---------- Robust BASE_URI detection (/Poultrymetrics) ----------
if (!defined('BASE_URI')) {
  $doc    = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  $projDir= rtrim(str_replace('\\','/', dirname(__DIR__)), '/'); // …/htdocs/Poultrymetrics
  $base   = '';

  if ($doc && str_starts_with($projDir, $doc)) {
    $base = substr($projDir, strlen($doc)); // -> /Poultrymetrics
  }

  if (!$base) {
    // Fallback: first segment from SCRIPT_NAME
    $sn    = $_SERVER['SCRIPT_NAME'] ?? '';
    $parts = explode('/', trim($sn, '/'));
    $base  = isset($parts[0]) ? '/'.$parts[0] : '';
  }

  if ($base === '') $base = '/Poultrymetrics'; // last-resort default
  $base = '/'.trim($base, '/');
  define('BASE_URI', $base);
}

// ---------- Session ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_strict_mode', '1');
  session_start();
}

// ---------- DB (robust + hosting/local aware) ----------
// Priority order:
//   1) /config.php or /inc/config.php that defines DB_* constants
//   2) Environment variables PMX_DB_HOST, PMX_DB_USER, etc.
//   3) Auto-hosting overrides (Hostinger) if not localhost and no config given
//   4) Local development defaults (XAMPP)
(function () {
  // 1) Allow overrides via external config file (huwag i-commit ang real creds)
  $tryFiles = [
    dirname(__DIR__) . '/config.php',  // project root/config.php (recommended sa hosting)
    __DIR__ . '/config.php',           // inc/config.php (optional)
  ];
  foreach ($tryFiles as $cfg) {
    if (is_file($cfg)) { require_once $cfg; break; }
  }

  // 2) Baseline defaults (LOCAL DEV). Magagamit lang kapag walang config/env.
  $DEFAULTS = [
    'DB_HOST' => '127.0.0.1',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'poultrymetrics',
    'DB_PORT' => 3306,
  ];

  // Helper: pick from defined constant -> env var -> defaults
  $pick = function (string $k) use ($DEFAULTS) {
    if (defined($k)) return constant($k);
    $env = getenv('PMX_' . $k);          // e.g., PMX_DB_HOST
    if ($env !== false && $env !== '') return $env;
    return $DEFAULTS[$k];
  };

  // Detect if we're NOT on localhost (likely hosting)
  $isHosting = isset($_SERVER['HTTP_HOST']) && !preg_match('~^(localhost|127\.|::1)~', $_SERVER['HTTP_HOST']);

  // Check if nothing was provided via constants or env
  $usingDefaultsOnly =
    !defined('DB_HOST') && getenv('PMX_DB_HOST') === false &&
    !defined('DB_USER') && getenv('PMX_DB_USER') === false &&
    !defined('DB_PASS') && getenv('PMX_DB_PASS') === false &&
    !defined('DB_NAME') && getenv('PMX_DB_NAME') === false &&
    !defined('DB_PORT') && getenv('PMX_DB_PORT') === false;

  // 3) If on hosting and no explicit config, use your Hostinger creds here:
  if ($isHosting && $usingDefaultsOnly) {
    // ⬇️ Palitan ayon sa actual na hPanel DB credentials mo
    define('DB_HOST', 'localhost');                         // Hostinger: 'localhost'
    define('DB_NAME', 'u578970591_poultry_db');             // exact DB name
    define('DB_USER', 'u578970591_poultry_db');             // exact DB user
    define('DB_PASS', 'Poultry_db2015');                    // DB password
    define('DB_PORT', 3306);
  }

  // 4) Final picks
  $DB_HOST = $pick('DB_HOST');
  $DB_USER = $pick('DB_USER');
  $DB_PASS = $pick('DB_PASS');
  $DB_NAME = $pick('DB_NAME');
  $DB_PORT = (int)$pick('DB_PORT');

  // 5) Connect
  mysqli_report(MYSQLI_REPORT_OFF); // Set to ERROR|STRICT kapag nagde-debug ka
  $conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
  if ($conn->connect_errno) {
    http_response_code(500);
    // DEBUG (pwede i-enable pansamantala):
    // die('Database connection failed: ' . $conn->connect_error);
    die('Database connection error. Please contact the administrator.');
  }

  // 6) Charset + Timezone
  @$conn->set_charset('utf8mb4');
  @$conn->query("SET time_zone = '+08:00'"); // Asia/Manila

  // Expose globally
  $GLOBALS['conn'] = $conn;

  // Auto-close on shutdown
  register_shutdown_function(static function () use ($conn) {
    if ($conn instanceof mysqli) { @$conn->close(); }
  });
})();

// ---------- Small generic helpers ----------
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ---------- URL builders ----------
if (!function_exists('app_url')) {
  function app_url(string $path): string {
    $base = BASE_URI === '/' ? '' : BASE_URI;   // avoid // when BASE_URI='/'
    return $base . '/' . ltrim($path, '/');
  }
}

// Normalized redirect: app-roots relative paths automatically
if (!function_exists('redirect')) {
  function redirect(string $to){
    if (!preg_match('~^(?:https?://|/)~i', $to)) {  // not absolute or root-relative
      $to = app_url($to);
    }
    header('Location: '.$to);
    exit;
  }
}

if (!function_exists('flash_set')) {
  function flash_set(string $type, string $msg){ $_SESSION['flash']=['t'=>$type,'m'=>$msg]; }
}
if (!function_exists('flash_pop')) {
  function flash_pop(){ $f=$_SESSION['flash']??null; if($f) unset($_SESSION['flash']); return $f; }
}

if (!function_exists('get_csrf')) {
  function get_csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
  }
}
if (!function_exists('csrf_ok')) {
  function csrf_ok($token): bool {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token);
  }
}
if (!function_exists('csrf_field')) {
  function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="'.h(get_csrf()).'">';
  }
}

// ---------- Schema utilities ----------
if (!function_exists('table_exists')) {
  function table_exists(mysqli $c, string $table): bool {
    $like = $c->real_escape_string($table);
    $res  = @$c->query("SHOW TABLES LIKE '{$like}'");
    if (!$res) return false; $ok = ($res->num_rows > 0); $res->free(); return $ok;
  }
}
if (!function_exists('table_has_col')) {
  function table_has_col(mysqli $c, string $table, string $col): bool {
    $tbl  = str_replace('`','``',$table);
    $like = $c->real_escape_string($col);
    $res  = @$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$like}'");
    if (!$res) return false; $ok = ($res->num_rows > 0); $res->free(); return $ok;
  }
}
if (!function_exists('first_col')) {
  function first_col(mysqli $c, string $table, array $candidates): ?string {
    foreach ($candidates as $col) if (table_has_col($c,$table,$col)) return $col;
    return null;
  }
}

// ---------- Settings (JSON-aware, safe if table missing) ----------
if (!function_exists('s_get')) {
  function s_get(mysqli $c, string $key, $default=null){
    if (!table_exists($c, 'system_settings') || !table_has_col($c,'system_settings','skey')) return $default;
    $st = $c->prepare("SELECT svalue FROM system_settings WHERE skey=? LIMIT 1");
    if(!$st) return $default;
    $st->bind_param('s',$key); $st->execute();
    $res=$st->get_result(); $row=$res->fetch_assoc(); $st->close();
    if(!$row) return $default;
    $j=json_decode((string)$row['svalue'],true);
    return (json_last_error()===JSON_ERROR_NONE) ? $j : $row['svalue'];
  }
}

// ---------- Sanitizers ----------
if (!function_exists('sanitize_status')) {
  function sanitize_status($s){ $s=strtolower(trim((string)$s)); return in_array($s,['active','inactive'],true)?$s:'active'; }
}
if (!function_exists('sanitize_gender')) {
  function sanitize_gender($g){ if($g==='') return null; $g=strtolower((string)$g); if(in_array($g,['male','female'],true)) return ucfirst($g); return $g? 'Other': null; }
}
if (!function_exists('sanitize_role')) {
  function sanitize_role($r, array $allowed){ $r=strtolower(trim((string)$r)); return in_array($r,$allowed,true)?$r:'worker'; }
}

// ---------- Users / Auth ----------
if (!function_exists('users_table_info')) {
  function users_table_info(mysqli $c): array {
    $tbl = 'users';
    $pk  = table_has_col($c,$tbl,'id') ? 'id' : (table_has_col($c,$tbl,'user_id') ? 'user_id' : 'id');
    $role= table_has_col($c,$tbl,'role') ? 'role' : null;
    $status = table_has_col($c,$tbl,'status') ? 'status' : (table_has_col($c,$tbl,'account_status') ? 'account_status' : null);
    return [$tbl,$pk,$role,$status];
  }
}
if (!function_exists('current_user')) {
  function current_user(mysqli $c): ?array {
    if (empty($_SESSION['user_id'])) return null;
    [$tbl,$pk,$role,$status] = users_table_info($c);
    $cols = "`$pk` AS id, username, email";
    if ($role)   $cols .= ", `$role` AS role";
    if ($status) $cols .= ", `$status` AS status";
    $st = $c->prepare("SELECT $cols FROM `$tbl` WHERE `$pk`=? LIMIT 1");
    if (!$st) return null;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st->bind_param('i',$uid); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    return $row ?: null;
  }
}

// ---------- Flexible login URLs & guards ----------
if (!defined('LOGIN_URL'))       define('LOGIN_URL', BASE_URI.'/login.php');
if (!defined('ADMIN_LOGIN_URL')) define('ADMIN_LOGIN_URL', BASE_URI.'/admin/login.php');

if (!function_exists('resolve_login_url')) {
  function resolve_login_url(bool $admin=false): string {
    if ($admin && defined('ADMIN_LOGIN_URL')) return ADMIN_LOGIN_URL;
    if (!$admin && defined('LOGIN_URL'))      return LOGIN_URL;

    $sn = strtolower($_SERVER['SCRIPT_NAME'] ?? '');
    if ($admin || strpos($sn, '/admin/') !== false) {
      return BASE_URI . '/admin/login.php';
    }
    return BASE_URI . '/login.php';
  }
}

if (!function_exists('require_login')) {
  function require_login(mysqli $c){
    if (!current_user($c)) {
      $login = resolve_login_url(false);
      flash_set('danger','Please log in to continue.');
      redirect($login);
    }
  }
}

if (!function_exists('require_admin')) {
  function require_admin(mysqli $c){
    $u = current_user($c);
    if (!$u || strcasecmp((string)($u['role']??''),'admin')!==0) {
      $login = resolve_login_url(true);
      flash_set('danger','Admin access required.');
      redirect($login);
    }
  }
}

// ---------- Employee linking (worker identity) ----------
if (!function_exists('employee_for_user')) {
  function employee_for_user(mysqli $c, int $user_id): ?array {
    $T = 'employees';
    if (!table_exists($c,$T)) return null;
    $PK = table_has_col($c,$T,'employee_id') ? 'employee_id'
        : (table_has_col($c,$T,'id') ? 'id' : 'employee_id');
    $cols = ["`$PK` AS id"];
    foreach (['full_name','name','username','position','photo_url','photo_path','user_id','status'] as $cc) {
      if (table_has_col($c,$T,$cc)) $cols[]="`$cc`";
    }
    $sql = "SELECT ".implode(',', $cols)." FROM `$T` WHERE user_id=? LIMIT 1";
    $st=$c->prepare($sql); if(!$st) return null;
    $st->bind_param('i',$user_id); $st->execute();
    $row=$st->get_result()->fetch_assoc(); $st->close();
    return $row ?: null;
  }
}
if (!function_exists('current_worker')) {
  function current_worker(mysqli $c): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return employee_for_user($c, (int)$_SESSION['user_id']);
  }
}

// ---------- Attendance safety (adds cols if missing) ----------
if (!function_exists('ensure_attendance_columns')) {
  function ensure_attendance_columns(mysqli $c){
    $T='attendance';
    if (!table_exists($c,$T)) return;
    if (!table_has_col($c,$T,'paid'))       @$c->query("ALTER TABLE `$T` ADD COLUMN `paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pm_out`");
    if (!table_has_col($c,$T,'paid_at'))    @$c->query("ALTER TABLE `$T` ADD COLUMN `paid_at` DATETIME NULL AFTER `paid`");
    if (!table_has_col($c,$T,'ot_allowed')) @$c->query("ALTER TABLE `$T` ADD COLUMN `ot_allowed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `paid_at`");
  }
}

// ---------- Notifications (optional; safe fallback) ----------
if (!function_exists('load_worker_notifications')) {
  function load_worker_notifications(mysqli $c, int $employee_id): array {
    if (!table_exists($c,'notifications')) return ['count'=>0, 'items'=>[]];
    $st = $c->prepare("SELECT url, label, icon FROM notifications WHERE employee_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 10");
    if (!$st) return ['count'=>0,'items'=>[]];
    $st->bind_param('i',$employee_id);
    $st->execute();
    $res = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $cnt = count($res);
    foreach ($res as &$n) {
      $n['url']   = (string)($n['url'] ?? app_url('worker/worker_dashboard.php?tab=notifications'));
      $n['label'] = (string)($n['label'] ?? 'Notification');
      $n['icon']  = (string)($n['icon'] ?? 'fa-bell');
    }
    return ['count'=>$cnt,'items'=>$res];
  }
}

// ---------- Time & attendance math ----------
if (!function_exists('dt_from_date_and_val')) {
  function dt_from_date_and_val(string $date, ?string $val): ?int {
    if (!$val || trim($val)==='') return null;
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $val)) return strtotime($date.' '.$val);
    return strtotime($val);
  }
}
if (!function_exists('to12')) {
  function to12(?string $t): string {
    if (!$t) return '';
    if (preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?/', (string)$t, $m)) {
      $hh=(int)$m[1]; $mm=$m[2]; $ampm=($hh>=12?'PM':'AM'); $hh=$hh%12; if($hh===0)$hh=12; return $hh.':'.$mm.' '.$ampm;
    }
    return '';
  }
}
if (!function_exists('mins_to_hm')) {
  function mins_to_hm(int $m): string { $m=max(0,$m); return sprintf('%d:%02d', intdiv($m,60), $m%60); }
}
if (!function_exists('overlap_mins')) {
  function overlap_mins(?int $a1, ?int $a2, int $b1, int $b2): int {
    if (!$a1 || !$a2 || $a2 <= $a1) return 0;
    $s=max($a1,$b1); $e=min($a2,$b2);
    return $e>$s ? (int)floor(($e-$s)/60) : 0;
  }
}
/**
 * day_metrics_clip:
 *  - regular: minutes worked INSIDE schedule windows only (AM overlapped + PM overlapped), max standard
 *  - missing: max(0, standard - regular)
 *  - ot_raw : minutes beyond standard (from valid am/pm pairs; payability decided elsewhere)
 */
if (!function_exists('day_metrics_clip')) {
  function day_metrics_clip(string $date, array $row, array $sched, int $standardMins): array {
    $am_in  = dt_from_date_and_val($date, $row['am_in']  ?? null);
    $am_out = dt_from_date_and_val($date, $row['am_out'] ?? null);
    $pm_in  = dt_from_date_and_val($date, $row['pm_in']  ?? null);
    $pm_out = dt_from_date_and_val($date, $row['pm_out'] ?? null);

    $sam = strtotime("$date ".($sched['am_in']??'07:00'));
    $eam = strtotime("$date ".($sched['am_out']??'11:00'));
    $spm = strtotime("$date ".($sched['pm_in']??'13:00'));
    $epm = strtotime("$date ".($sched['pm_out']??'17:00'));

    $raw = 0;
    if ($am_in && $am_out && $am_out>$am_in) $raw += (int)floor(($am_out-$am_in)/60);
    if ($pm_in && $pm_out && $pm_out>$pm_in) $raw += (int)floor(($pm_out-$pm_in)/60);

    $regular = overlap_mins($am_in,$am_out,$sam,$eam) + overlap_mins($pm_in,$pm_out,$spm,$epm);
    if ($regular > $standardMins) $regular = $standardMins;

    $missing = max(0, $standardMins - $regular);
    $ot_raw  = max(0, $raw - $standardMins);

    return [$regular,$missing,$ot_raw];
  }
}

// ---------- Defaults used by attendance/payroll ----------
if (!isset($WORK_SCHED)) {
  $WORK_SCHED = array_merge([
    'am_in'  => '07:00',
    'am_out' => '11:00',
    'pm_in'  => '13:00',
    'pm_out' => '17:00',
  ], is_array($tmp = s_get($conn,'work_schedule',null)) ? $tmp : []);
}
if (!isset($REG_DAILY_MINS)) {
  $PAYROLL_SET     = s_get($conn,'payroll_settings', ['standard_hours_per_day'=>8]);
  $REG_DAILY_MINS  = max(1, (int)($PAYROLL_SET['standard_hours_per_day'] ?? 8) * 60);
}

// ---------- Page title default ----------
if (!isset($PAGE_TITLE)) $PAGE_TITLE = 'PoultryMetrics';
