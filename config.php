<?php
// config.php — resilient MySQL connection for PoultryMetrics (XAMPP/Windows friendly)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ====== DB constants (override via env if you like) ====== */
if (!defined('DB_HOST'))    define('DB_HOST', getenv('PMX_DB_HOST') ?: '127.0.0.1'); // use 127.0.0.1 on Windows
if (!defined('DB_USER'))    define('DB_USER', getenv('PMX_DB_USER') ?: 'root');
if (!defined('DB_PASS'))    define('DB_PASS', getenv('PMX_DB_PASS') ?: '');
if (!defined('DB_NAME'))    define('DB_NAME', getenv('PMX_DB_NAME') ?: 'poultrymetrics'); // <-- fixed name
if (!defined('DB_PORT'))    define('DB_PORT', (int)(getenv('PMX_DB_PORT') ?: 3306));
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

/* ====== Live connection (lazy, with keepalive) ====== */
if (!function_exists('pmx_connect')) {
  function pmx_connect(): mysqli {
    static $cached = null;

    // reuse if alive
    if ($cached instanceof mysqli) {
      try { if ($cached->ping()) return $cached; } catch (Throwable $e) {}
      @ $cached->close();
      $cached = null;
    }

    // (re)connect
    $c = mysqli_init();
    // modest connect timeout for local dev
    $c->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

    // First try 127.0.0.1 (fast on Windows), fallback to localhost if needed
    try {
      $c->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    } catch (Throwable $e) {
      if (DB_HOST !== 'localhost') {
        $c->real_connect('localhost', DB_USER, DB_PASS, DB_NAME, DB_PORT);
      } else {
        throw $e;
      }
    }

    // Charset + session tweaks
    $c->set_charset(DB_CHARSET);
    @$c->query("SET time_zone = '+08:00'"); // Asia/Manila
    // avoid idle disconnects during local dev
    @$c->query("SET SESSION wait_timeout=28800, interactive_timeout=28800");

    $cached = $c;
    return $cached;
  }
}

/* ====== Optional helpers ====== */
if (!function_exists('pmx_query')) {
  function pmx_query(string $sql, ?string $types=null, array $params=[]) {
    $c = pmx_connect();
    if ($types !== null) {
      $st = $c->prepare($sql);
      if ($params) $st->bind_param($types, ...$params);
      $st->execute();
      return $st->get_result();
    }
    return $c->query($sql);
  }
}

/* ====== Back-compat: expose $conn (non-fatal if DB is down) ====== */
try {
  $GLOBALS['conn'] = pmx_connect(); // many legacy pages expect $conn
} catch (Throwable $e) {
  // Do NOT fatal during include; let pages handle gracefully
  $GLOBALS['conn'] = null;
  error_log("config.php: DB not available: ".$e->getMessage());
}
