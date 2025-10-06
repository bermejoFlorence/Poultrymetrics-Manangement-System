<?php
// ======================================================================
// /customer/inc/common.php — minimal shared bootstrap (customers)
//  - Provides $conn (mysqli), session, helpers, auth guard
//  - UTF8MB4 + Asia/Manila (+08:00)
//  - DB connection: local/hosting aware, supports /config.php overrides
//  - Tiny cart util
// ======================================================================

@date_default_timezone_set('Asia/Manila');

// ---------- Session ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_strict_mode', '1');
  session_start();
}

// ---------- DB (robust + hosting/local aware) ----------
// Priority order:
//   1) /config.php or /customer/inc/config.php that defines DB_* constants
//   2) Environment variables PMX_DB_HOST, PMX_DB_USER, etc.
//   3) Auto-hosting overrides (Hostinger) if not localhost and no config given
//   4) Local development defaults (XAMPP)
(function () {
  // 1) Allow overrides via external config file (huwag i-commit ang real creds)
  $tryFiles = [
    dirname(__DIR__, 2) . '/config.php', // project root/config.php (recommended sa hosting)
    __DIR__ . '/config.php',             // /customer/inc/config.php (optional)
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

// ---------- helpers ----------
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('redirect')) {
  function redirect(string $url){ header('Location: '.$url); exit; }
}

/** scalar(): return first column of first row (or null). */
if (!function_exists('scalar')) {
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
}

if (!function_exists('flash_set')) {
  function flash_set(string $type, string $msg){ $_SESSION['flash']=['t'=>$type,'m'=>$msg]; }
}
if (!function_exists('flash_pop')) {
  function flash_pop(){ $f=$_SESSION['flash']??null; if($f) unset($_SESSION['flash']); return $f; }
}

if (!function_exists('get_csrf')) {
  function get_csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
}
if (!function_exists('csrf_ok')) {
  function csrf_ok($token): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token); }
}
if (!function_exists('csrf_field')) {
  function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(get_csrf()).'">'; }
}

// ---------- schema helpers ----------
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

// ---------- auth ----------
if (!function_exists('current_user')) {
  function current_user(mysqli $c): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $pk = table_has_col($c,'users','id') ? 'id' : (table_has_col($c,'users','user_id') ? 'user_id' : 'id');
    $rolecol = table_has_col($c,'users','role') ? 'role' : null;
    $cols = "`$pk` AS id, username, email";
    if ($rolecol) $cols .= ", `$rolecol` AS role";
    $st=$c->prepare("SELECT $cols FROM users WHERE `$pk`=? LIMIT 1");
    if (!$st) return null;
    $uid=(int)($_SESSION['user_id'] ?? 0);
    $st->bind_param('i',$uid); $st->execute();
    $row=$st->get_result()->fetch_assoc(); $st->close();
    return $row ?: null;
  }
}
if (!function_exists('require_login')) {
  function require_login(mysqli $c){
    if (!current_user($c)) {
      flash_set('danger','Please log in to continue.');
      // Customer area lives at /customer/, login page expected at /login.php (root)
      redirect('../login.php');
    }
  }
}

// ---------- tiny cart util ----------
if (!function_exists('cart_count')) {
  function cart_count(): int {
    $n=0; if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
      foreach($_SESSION['cart'] as $q){ $n+=(int)$q; }
    }
    return $n;
  }
}
