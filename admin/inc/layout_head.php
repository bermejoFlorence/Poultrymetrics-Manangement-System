<?php
/**
 * Common Layout Head (header + sidebar) | PoultryMetrics (ADMIN)
 * Include at the TOP of every admin page. Self-sufficient, tries root /inc/common.php,
 * and enforces ADMIN-ONLY access before any HTML output.
 */
declare(strict_types=1);

/* ---------- Session (must be first) ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_strict_mode','1');
  ini_set('session.cookie_httponly','1');
  @ini_set('session.cookie_samesite','Lax');
  session_start();
}

/* ---------- Try load ROOT common (preferred) ---------- */
$rootCommon = dirname(__DIR__, 2) . '/inc/common.php';
if (is_file($rootCommon)) {
  require_once $rootCommon; // should define $conn, helpers, BASE_URI, etc.
}

/* ---------- If no $conn yet, boot minimal DB (admin-safe fallback) ---------- */
if (!isset($conn) || !($conn instanceof mysqli)) {
  // Try admin/config.php first, then root/config.php
  $cfgAdmin = dirname(__DIR__) . '/config.php';
  $cfgRoot  = dirname(__DIR__, 2) . '/config.php';
  if (is_file($cfgAdmin)) require_once $cfgAdmin;
  if (is_file($cfgRoot)  && !defined('DB_HOST')) require_once $cfgRoot;

  if (!defined('DB_HOST')) define('DB_HOST','127.0.0.1');
  if (!defined('DB_USER')) define('DB_USER','root');
  if (!defined('DB_PASS')) define('DB_PASS','');
  if (!defined('DB_NAME')) define('DB_NAME','poultrymetrics');
  if (!defined('DB_PORT')) define('DB_PORT',3306);

  mysqli_report(MYSQLI_REPORT_OFF);
  $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
  if ($conn && !$conn->connect_errno) {
    @$conn->set_charset('utf8mb4');
    @$conn->query("SET time_zone = '+08:00'");
  } else {
    // Soft fail: still allow header render, but no DB-backed widgets
    $conn = null;
  }
}

/* ---------- Base helpers (only if missing) ---------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('tableExists')) {
  function tableExists(mysqli $c, string $tbl): bool {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    if(!$st=$c->prepare($sql)) return false;
    $st->bind_param('s',$tbl); $st->execute(); $st->store_result();
    $ok = $st->num_rows > 0; $st->close(); return $ok;
  }
}
if (!function_exists('colExists')) {
  function colExists(mysqli $c, string $tbl, string $col): bool {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    if(!$st=$c->prepare($sql)) return false;
    $st->bind_param('ss',$tbl,$col); $st->execute(); $st->store_result();
    $ok = $st->num_rows > 0; $st->close(); return $ok;
  }
}
if (!function_exists('scalar')) {
  function scalar(mysqli $c, string $sql){
    $res = @$c->query($sql); if(!$res) return null;
    $row = $res->fetch_row(); $res->free();
    return $row ? $row[0] : null;
  }
}

/* ---------- BASE_URI fallback (subfolder-safe) ---------- */
if (!defined('BASE_URI')) {
  $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
  $parts  = array_values(array_filter(explode('/', $script)));
  $first  = $parts[0] ?? '';
  define('BASE_URI', ($first && strpos($first,'.php')===false) ? '/'.$first : '');
}

/* ---------- ADMIN-ONLY AUTH GUARD (no output before this) ---------- */
$role = strtolower((string)($_SESSION['role'] ?? ''));
if (empty($_SESSION['user_id']) || $role !== 'admin') {
  // Prefer /admin/login.php; fall back to /login.php
  $loginUrl = BASE_URI . (is_file(dirname(__DIR__) . '/login.php') ? '/admin/login.php' : '/login.php');
  header('Location: ' . $loginUrl);
  exit;
}

/* ---------- Page labels ---------- */
if (!isset($PAGE_TITLE)) $PAGE_TITLE = 'Admin';
if (!isset($CURRENT))    $CURRENT    = basename($_SERVER['PHP_SELF'] ?? '');

/* ---------- Logout URL ---------- */
$LOGOUT_URL = BASE_URI . '/logout.php';

/* ---------------- Active helper ---------------- */
$__is_active = function($files) use ($CURRENT){
  $files = (array)$files;
  $cur   = strtolower($CURRENT);
  foreach ($files as $f) if (strtolower(basename($f)) === $cur) return 'active';
  return '';
};

/* ---------------- Notifications (DB optional) ---------------- */
$notif_count = 0; $notifs = [];
if ($conn instanceof mysqli) {
  // Pending customer orders
  if (tableExists($conn,'customer_orders')) {
    $pending_orders = (int)scalar($conn, "SELECT COUNT(*) FROM customer_orders WHERE status='pending'");
    if ($pending_orders > 0) {
      $notif_count += $pending_orders;
      $notifs[] = ['label'=>"$pending_orders pending customer order(s)", 'url'=>'customer_orders.php?status=pending', 'icon'=>'fa-cart-shopping'];
    }
  }
  // Approval requests (table may vary)
  $approvals_tbl = null;
  if (tableExists($conn,'customer_approvals')) {
    $approvals_tbl = 'customer_approvals';
  } elseif (tableExists($conn,'account_approvals')) {
    $approvals_tbl = 'account_approvals';
  }
  if ($approvals_tbl) {
    $pending_approvals = (int)scalar($conn, "SELECT COUNT(*) FROM {$approvals_tbl} WHERE status='pending'");
    if ($pending_approvals > 0) {
      $notif_count += $pending_approvals;
      $notifs[] = ['label'=>"$pending_approvals customer approval request(s)", 'url'=>'customer_approvals.php?status=pending', 'icon'=>'fa-user-check'];
    }
  }
  // Mortality today (optional schema)
  $mortality_today = 0;
  if (tableExists($conn,'egg_collections') && colExists($conn,'egg_collections','mortality') && colExists($conn,'egg_collections','collect_date')) {
    $mortality_today = (int)scalar($conn, "SELECT COALESCE(SUM(mortality),0) FROM egg_collections WHERE collect_date=CURDATE()");
  } elseif (tableExists($conn,'egg_production') && colExists($conn,'egg_production','mortality')) {
    $dcol2 = colExists($conn,'egg_production','date') ? 'date' : (colExists($conn,'egg_production','collect_date') ? 'collect_date' : null);
    if ($dcol2) $mortality_today = (int)scalar($conn, "SELECT COALESCE(SUM(mortality),0) FROM egg_production WHERE {$dcol2}=CURDATE()");
  }
  if (!empty($mortality_today)) {
    $notif_count += 1;
    $notifs[] = ['label'=>"$mortality_today mortality recorded today", 'url'=>'egg_reports.php?date='.date('Y-m-d'), 'icon'=>'fa-heart-crack'];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title><?php echo h($PAGE_TITLE); ?> | PoultryMetrics</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<style>
  :root{
    --accent:#f5a425; --accent-700:#d98f1f;
    --dark:#1f2327; --dark-2:#242a30; --muted:#8b949e;
    --text:#e9ecef; --card-radius:14px; --shadow:0 10px 28px rgba(0,0,0,.10);
    --sidebar-w:210px;
  }
  @media (prefers-reduced-motion: reduce){ *{scroll-behavior:auto!important; animation:none!important; transition:none!important;} }
  #preloader{position:fixed;inset:0;background:#0f1113;display:flex;align-items:center;justify-content:center;z-index:1400;transition:opacity .3s}
  #preloader.hidden{opacity:0;visibility:hidden}
  body{font-family:'Poppins',sans-serif;background:#f6f8fa;overflow-x:hidden}
  body.content-hidden{ overflow:hidden; }

  /* Header */
  .header-area{position:fixed;inset:0 0 auto 0;height:56px;background:var(--dark);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 14px;z-index:1300;border-bottom:1px solid rgba(255,255,255,.06)}
  .header-area .logo{font-size:17px;font-weight:700;color:var(--accent);text-decoration:none}
  .menu-trigger{cursor:pointer;color:#fff;font-size:18px;display:inline-flex;align-items:center;gap:6px}

  /* Sidebar */
  .sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-w);background:linear-gradient(180deg,var(--dark),var(--dark-2));
           padding-top:56px;z-index:1200;overflow-y:auto;transform:translateX(0);transition:transform .25s}
  .sidebar::-webkit-scrollbar{width:6px} .sidebar::-webkit-scrollbar-thumb{background:#394049;border-radius:12px}
  .sidebar .brand{display:flex;align-items:center;gap:8px;padding:10px 12px;color:#fff;border-bottom:1px solid rgba(255,255,255,.06)}
  .sidebar h6{color:#aeb6bf;font-size:10px;letter-spacing:.14em;margin:10px 12px 4px;text-transform:uppercase}
  .sidebar .nav-link{color:#cfd3d7;padding:6px 10px;margin:1px 8px;border-radius:8px;display:flex;align-items:center;gap:8px;font-size:13px}
  .sidebar .nav-link i{width:18px;text-align:center;font-size:0.95rem}
  .sidebar .nav-link:hover,.sidebar .nav-link.active{background:#2a3037;color:var(--accent)}

  /* Backdrop (mobile) */
  .backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);opacity:0;visibility:hidden;transition:opacity .2s;z-index:1100}

  /* Content */
  .content{margin-left:var(--sidebar-w);padding:68px 14px 16px;transition:margin-left .25s}
  .card{border:none;border-radius:var(--card-radius);box-shadow:var(--shadow)}
  .card-header{background:#fff;border-bottom:1px solid #eee;border-radius:var(--card-radius) var(--card-radius) 0 0}

  /* Collapse logic */
  body.sidebar-collapsed .sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}
  body.sidebar-collapsed .content{margin-left:0}
  @media (max-width: 992px){
    .content{margin-left:0}
    .sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}
    body.sidebar-open .sidebar{transform:translateX(0)}
    body.sidebar-open .backdrop{opacity:1;visibility:visible}
  }

  /* SweetAlert theme (optional) */
  .swal-theme-popup{background: linear-gradient(180deg,#1f2327,#232a30);color: var(--text);border: 1px solid rgba(255,255,255,.06);border-radius: 14px !important;box-shadow: 0 18px 48px rgba(0,0,0,.35)}
  .swal-theme-title{ color:#fff !important; font-weight:700 !important }
  .swal-theme-html{ color:#cfd3d7 !important }
  .swal-theme-confirm.swal2-styled{ background: var(--accent) !important; color: #151a1f !important; font-weight:700 !important; border-radius: 10px !important; padding: 10px 16px !important; border: 1px solid rgba(0,0,0,.08) !important; box-shadow: 0 6px 14px rgba(245,164,37,.35) !important }
  .swal-theme-cancel.swal2-styled{ background: #343b44 !important; color: #e9ecef !important; border-radius: 10px !important; padding: 10px 14px !important; border: 1px solid rgba(255,255,255,.06) !important }

  .btn-icon-bell{ display:inline-flex; align-items:center; gap:.4rem }
  .btn-icon-bell .badge{ font-size:.65rem }
</style>
</head>
<body class="content-hidden">
  <!-- Preloader -->
  <div id="preloader" aria-hidden="true"><i class="fa-solid fa-spinner fa-spin fa-2x" style="color:var(--accent)"></i></div>

  <!-- Header -->
  <header class="header-area" role="banner">
    <a href="admin_dashboard.php" class="logo">PoultryMetrics</a>
    <div class="d-flex align-items-center gap-2">
      <!-- Notifications -->
      <div class="dropdown">
        <a href="#" class="btn btn-sm btn-outline-light btn-icon-bell position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
          <i class="fa-solid fa-bell"></i>
          <?php if ($notif_count > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark"><?php echo (int)$notif_count; ?></span>
          <?php endif; ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:280px;">
          <li class="dropdown-header small fw-semibold">Notifications</li>
          <?php if (!empty($notifs)): ?>
            <?php foreach ($notifs as $n): ?>
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2" href="<?php echo h($n['url']); ?>">
                  <i class="fa-solid <?php echo h($n['icon']); ?> opacity-75"></i>
                  <span><?php echo h($n['label']); ?></span>
                </a>
              </li>
            <?php endforeach; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item small text-muted" href="reports.php">View reports & analytics</a></li>
          <?php else: ?>
            <li><div class="dropdown-item text-muted small">You're all caught up.</div></li>
          <?php endif; ?>
        </ul>
      </div>

      <!-- User -->
      <div class="dropdown">
        <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
          <i class="fa-solid fa-user-circle me-2"></i>
          <small class="fw-semibold"><?php echo h($_SESSION['username'] ?? 'Admin'); ?></small>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="admin_profile.php">Profile</a></li>
          <li><a class="dropdown-item" href="settings.php">Settings</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
      </div>

      <!-- Burger -->
      <span class="menu-trigger"
            id="menuTrigger"
            role="button"
            tabindex="0"
            aria-label="Toggle sidebar"
            aria-controls="sidebar"
            aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
      </span>
    </div>
  </header>

  <!-- Sidebar -->
  <nav class="sidebar" id="sidebar" aria-label="Primary" role="navigation">
    <div class="brand"><i class="fa-solid fa-feather-pointed"></i><strong>PoultryMetrics</strong></div>
    <ul class="nav flex-column mb-3">
      <h6>Dashboard</h6>
      <li>
        <a href="admin_dashboard.php" class="nav-link <?php echo $__is_active('admin_dashboard.php'); ?>">
          <i class="fa-solid fa-gauge"></i>Overview
        </a>
      </li>

      <h6>User & Access</h6>
      <li><a href="users.php" class="nav-link <?php echo $__is_active('users.php'); ?>"><i class="fa-solid fa-users"></i>All Users</a></li>
      <li><a href="customer_approvals.php" class="nav-link <?php echo $__is_active('customer_approvals.php'); ?>"><i class="fa-solid fa-user-check"></i>Customer Approvals</a></li>

      <h6>Employee</h6>
      <li><a href="employees.php" class="nav-link <?php echo $__is_active('employees.php'); ?>"><i class="fa-solid fa-id-card"></i>Profile</a></li>
      <li><a href="attendance.php" class="nav-link <?php echo $__is_active('attendance.php'); ?>"><i class="fa-solid fa-calendar-check"></i>Attendance</a></li>
      <li><a href="payroll.php" class="nav-link <?php echo $__is_active('payroll.php'); ?>"><i class="fa-solid fa-wallet"></i>Payroll</a></li>

      <h6>Products & Orders</h6>
      <li><a href="products.php" class="nav-link <?php echo $__is_active('products.php'); ?>"><i class="fa-solid fa-box-open"></i>Products</a></li>
      <li><a href="customer_orders.php" class="nav-link <?php echo $__is_active('customer_orders.php'); ?>"><i class="fa-solid fa-cart-shopping"></i>Customer Orders</a></li>

      <h6>Egg Operation</h6>
      <li><a href="egg_inventory.php" class="nav-link <?php echo $__is_active('egg_inventory.php'); ?>"><i class="fa-solid fa-clipboard-list"></i>Egg Inventory</a></li>

      <h6>Feed Operation</h6>
      <li><a href="feed_inventory.php" class="nav-link <?php echo $__is_active('feed_inventory.php'); ?>"><i class="fa-solid fa-warehouse"></i>Feed Inventory</a></li>

      <h6>Daily Forms</h6>
      <li>
        <a href="i102_reports.php" class="nav-link <?php echo $__is_active(['i102_reports.php','i102_report_view.php']); ?>">
          <i class="fa-solid fa-table-list"></i>Form I-102 (Daily Reports)
        </a>
      </li>

      <h6>Reports</h6>
      <li><a href="reports.php" class="nav-link <?php echo $__is_active('reports.php'); ?>"><i class="fa-solid fa-chart-bar"></i>Analytics & Export</a></li>

      <h6>Settings</h6>
      <li><a href="settings.php" class="nav-link <?php echo $__is_active('settings.php'); ?>"><i class="fa-solid fa-gear"></i>System Settings</a></li>

      <h6 class="mt-2">Session</h6>
      <li><a href="#" class="nav-link" onclick="confirmLogout()"><i class="fa-solid fa-right-from-bracket"></i>Logout</a></li>
    </ul>
  </nav>

  <!-- Backdrop (mobile) -->
  <div class="backdrop" id="backdrop" aria-hidden="true"></div>

  <!-- Main starts in each page -->
  <main class="content" id="mainContent" role="main">

  <!-- Inline logic (kept here so header works even without footer) -->
  <script>
    (function(){
      const LOGOUT_URL = <?= json_encode($LOGOUT_URL, JSON_UNESCAPED_SLASHES) ?>;
      const LS_KEY='pm.sidebar.admin';
      const mq = window.matchMedia('(max-width: 992px)');
      const body = document.body;
      const burger = document.getElementById('menuTrigger');
      const backdrop = document.getElementById('backdrop');

      function hidePreloader(){
        var p=document.getElementById('preloader');
        if(p && !p.classList.contains('hidden')){
          p.classList.add('hidden');
          document.body.classList.remove('content-hidden');
        }
      }
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function(){ setTimeout(hidePreloader, 150); }, false);
      } else {
        setTimeout(hidePreloader, 150);
      }
      setTimeout(hidePreloader, 3000);

      try{
        const s=localStorage.getItem(LS_KEY);
        if(!mq.matches && s==='collapsed') body.classList.add('sidebar-collapsed');
      }catch(e){}

      function saveSidebarState(){
        try{ localStorage.setItem(LS_KEY, body.classList.contains('sidebar-collapsed')?'collapsed':'open'); }catch(e){}
      }
      function setBurgerExpanded(expanded){
        if (!burger) return;
        burger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      }
      function toggleSidebar(){
        if (mq.matches){
          const opening = !body.classList.contains('sidebar-open');
          body.classList.toggle('sidebar-open');
          setBurgerExpanded(opening);
        } else {
          body.classList.toggle('sidebar-collapsed');
          saveSidebarState();
          setBurgerExpanded(!body.classList.contains('sidebar-collapsed'));
        }
      }

      document.addEventListener('DOMContentLoaded', function(){
        if (burger){
          burger.addEventListener('click', function(e){ e.preventDefault(); toggleSidebar(); }, false);
          burger.addEventListener('keydown', function(e){
            if(e.key==='Enter' || e.key===' '){ e.preventDefault(); toggleSidebar(); }
          }, false);
        }
        if (backdrop){
          backdrop.addEventListener('click', function(){ body.classList.remove('sidebar-open'); setBurgerExpanded(false); }, false);
        }
        document.addEventListener('keydown', function(e){
          if (e.key === 'Escape' && mq.matches && body.classList.contains('sidebar-open')){
            body.classList.remove('sidebar-open');
            setBurgerExpanded(false);
          }
        }, false);
        window.addEventListener('resize', function(){
          if (!mq.matches) body.classList.remove('sidebar-open');
        }, {passive:true});
      }, false);
    })();
  </script>
