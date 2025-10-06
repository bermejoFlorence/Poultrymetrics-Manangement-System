<?php
/**
 * Accountant Layout Head (header + sidebar) | PoultryMetrics (ACCOUNTANT)
 * - Robust common include + DB fallback (subfolder-safe)
 * - Accountant-guard (also allows admin)
 * - Notifications (unpaid payroll, pending orders, overdue invoices)
 * - Consistent UI with Admin layout (preloader, burger, sidebar state)
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

/* ---------- If no $conn yet, boot minimal DB (safe fallback) ---------- */
if (!isset($conn) || !($conn instanceof mysqli)) {
  // Try /accountant/config.php then root /config.php
  $cfgAcct = dirname(__DIR__) . '/config.php';
  $cfgRoot = dirname(__DIR__, 2) . '/config.php';
  if (is_file($cfgAcct)) require_once $cfgAcct;
  if (is_file($cfgRoot) && !defined('DB_HOST')) require_once $cfgRoot;

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
    $conn = null; // Soft-fail; UI still renders
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

/* ---------- BASE_URI + BASE (subfolder-safe) ---------- */
if (!defined('BASE_URI')) {
  $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
  $parts  = array_values(array_filter(explode('/', $script)));
  $first  = $parts[0] ?? '';
  define('BASE_URI', ($first && strpos($first,'.php')===false) ? '/'.$first : '');
}
$BASE = BASE_URI . '/accountant';

/* ---------- ACCOUNTANT-ONLY AUTH GUARD (admins also allowed) ---------- */
$role = strtolower((string)($_SESSION['role'] ?? ''));
if (empty($_SESSION['user_id']) || !in_array($role, ['accountant','admin'], true)) {
  $loginUrl = BASE_URI . (is_file(dirname(__DIR__) . '/login.php') ? '/accountant/login.php' : '/login.php');
  header('Location: ' . $loginUrl);
  exit;
}

/* ---------- Page labels ---------- */
if (!isset($PAGE_TITLE)) $PAGE_TITLE = 'Accountant';
if (!isset($CURRENT))    $CURRENT    = basename($_SERVER['PHP_SELF'] ?? '');

/* ---------- Logout URL ---------- */
$LOGOUT_URL = BASE_URI . '/logout.php';

/* ---------- Active helper ---------- */
$__is_active = function($files) use ($CURRENT){
  $files = (array)$files;
  $cur   = strtolower($CURRENT);
  foreach ($files as $f) if (strtolower(basename($f)) === $cur) return 'active';
  return '';
};

/* ---------- Notifications (optional DB) ---------- */
$notif_count = 0; $notifs = [];
if ($conn instanceof mysqli) {
  // Unpaid payroll
  if (tableExists($conn,'payroll')) {
    $unpaid = (int)(scalar($conn, "SELECT COUNT(*) FROM payroll WHERE status='unpaid'") ?? 0);
    if ($unpaid > 0) { $notif_count += $unpaid; $notifs[] = ['label'=>"$unpaid unpaid payroll item(s)", 'url'=>'payroll.php?status=unpaid', 'icon'=>'fa-wallet']; }
  }
  // Pending customer orders
  if (tableExists($conn,'customer_orders')) {
    $pending_orders = (int)(scalar($conn, "SELECT COUNT(*) FROM customer_orders WHERE status='pending'") ?? 0);
    if ($pending_orders > 0) { $notif_count += $pending_orders; $notifs[] = ['label'=>"$pending_orders pending customer order(s)", 'url'=>'customer_orders.php?status=pending', 'icon'=>'fa-cart-shopping']; }
  }
  // Overdue invoices (if invoices table exists)
  if (tableExists($conn,'invoices')) {
    $overdue = (int)(scalar($conn, "SELECT COUNT(*) FROM invoices WHERE status<>'paid' AND due_date IS NOT NULL AND due_date<CURDATE()") ?? 0);
    if ($overdue > 0) { $notif_count += $overdue; $notifs[] = ['label'=>"$overdue overdue invoice(s)", 'url'=>'invoices.php?filter=overdue', 'icon'=>'fa-file-invoice-dollar']; }
  }
}

/* ---------- Who's logged in ---------- */
$who = $_SESSION['username'] ?? ($_SESSION['accountant_name'] ?? 'Accountant');
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
  --accent:#10b981; --accent-700:#059669;
  --dark:#1f2327; --dark-2:#242a30; --muted:#8b949e;
  --text:#e9ecef; --card-radius:14px; --shadow:0 10px 28px rgba(0,0,0,.10);
  --sidebar-w:220px;
}
@media (prefers-reduced-motion: reduce){ *{scroll-behavior:auto!important; animation:none!important; transition:none!important;} }
body{font-family:'Poppins',sans-serif;background:#f6f8fa;overflow-x:hidden}
body.content-hidden{ overflow:hidden; }

/* Header */
.header-area{position:fixed;inset:0 0 auto 0;height:56px;background:var(--dark);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 14px;z-index:1300;border-bottom:1px solid rgba(255,255,255,.06)}
.header-area .logo{font-size:17px;font-weight:700;color:var(--accent);text-decoration:none}
.menu-trigger{cursor:pointer;color:#fff;font-size:18px;display:inline-flex;align-items:center;gap:6px}
.btn-icon-bell{ display:inline-flex; align-items:center; gap:.4rem }
.btn-icon-bell .badge{ font-size:.65rem }

/* Sidebar */
.sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-w);background:linear-gradient(180deg,var(--dark),var(--dark-2));
         padding-top:56px;z-index:1200;overflow-y:auto;transform:translateX(0);transition:transform .25s}
.sidebar::-webkit-scrollbar{width:6px}
.sidebar::-webkit-scrollbar-thumb{background:#394049;border-radius:12px}
.sidebar .brand{display:flex;align-items:center;gap:8px;padding:10px 12px;color:#fff;border-bottom:1px solid rgba(255,255,255,.06)}
.sidebar h6{color:#aeb6bf;font-size:10px;letter-spacing:.14em;margin:10px 12px 4px;text-transform:uppercase}
.sidebar .nav-link{color:#cfd3d7;padding:6px 10px;margin:1px 8px;border-radius:8px;display:flex;align-items:center;gap:8px;font-size:13px}
.sidebar .nav-link i{width:18px;text-align:center;font-size:.95rem}
.sidebar .nav-link:hover,.sidebar .nav-link.active{background:#2a3037;color:var(--accent)}
.sidebar .nav-link[aria-current="page"]{background:#2a3037;color:var(--accent)}

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

/* Preloader */
#preloader{position:fixed;inset:0;background:#0f1113;display:flex;align-items:center;justify-content:center;z-index:1400;transition:opacity .3s}
#preloader.hidden{opacity:0;visibility:hidden}
</style>
</head>
<body class="content-hidden">

<!-- Preloader -->
<div id="preloader" aria-hidden="true"><i class="fa-solid fa-spinner fa-spin fa-2x" style="color:var(--accent)"></i></div>

<!-- Header -->
<header class="header-area" role="banner">
  <a href="<?php echo h($BASE.'/accountant_dashboard.php'); ?>" class="logo">PoultryMetrics — Accountant</a>
  <div class="d-flex align-items-center gap-2">

    <!-- Notifications -->
    <div class="dropdown">
      <a href="#" class="btn btn-sm btn-outline-light btn-icon-bell position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
        <i class="fa-solid fa-bell"></i>
        <?php if ($notif_count > 0): ?>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-success text-dark"><?php echo (int)$notif_count; ?></span>
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

    <!-- Quick link -->
    <a href="<?php echo h($BASE.'/accountant_dashboard.php'); ?>" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-gauge"></i></a>

    <!-- User -->
    <div class="dropdown">
      <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
        <i class="fa-solid fa-user-circle me-2"></i>
        <small class="fw-semibold"><?php echo h($who); ?></small>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="accountant_profile.php">Profile</a></li>
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
  <div class="brand"><i class="fa-solid fa-calculator"></i><strong>Accountant</strong></div>
  <ul class="nav flex-column mb-3">
    <h6>Dashboard</h6>
    <li>
      <a href="accountant_dashboard.php" class="nav-link <?php echo $__is_active('accountant_dashboard.php'); ?>">
        <i class="fa-solid fa-gauge"></i>Overview
      </a>
    </li>

    <h6>Sales & Orders</h6>
    <li><a href="customer_orders.php" class="nav-link <?php echo $__is_active('customer_orders.php'); ?>"><i class="fa-solid fa-cart-shopping"></i>Customer Orders</a></li>
   
    <h6 class="mt-2">Session</h6>
    <li><a href="#" class="nav-link" onclick="confirmLogout()"><i class="fa-solid fa-right-from-bracket"></i>Logout</a></li>
  </ul>
</nav>

<!-- Backdrop (mobile) -->
<div class="backdrop" id="backdrop" aria-hidden="true"></div>

<!-- Main starts in each page -->
<main class="content" id="mainContent" role="main">

<!-- Inline logic -->
<script>
(function(){
  const LOGOUT_URL = <?= json_encode($LOGOUT_URL, JSON_UNESCAPED_SLASHES) ?>;
  const LS_KEY='pm.sidebar.accountant';
  const mq = window.matchMedia('(max-width: 992px)');
  const body = document.body;
  const burger = document.getElementById('menuTrigger');
  const backdrop = document.getElementById('backdrop');

  // Safe confirmLogout (define if missing)
  if (typeof window.confirmLogout !== 'function') {
    window.confirmLogout = function(){
      if (window.Swal && Swal.fire){
        Swal.fire({
          title: 'Logout?',
          text: 'You will be signed out of your session.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Logout',
          cancelButtonText: 'Cancel',
          customClass: {
            popup: 'swal-theme-popup',
            title: 'swal-theme-title',
            htmlContainer: 'swal-theme-html',
            confirmButton: 'swal-theme-confirm',
            cancelButton: 'swal-theme-cancel'
          }
        }).then((r)=>{ if(r.isConfirmed) location.href = logout.php; });
      } else {
        if (confirm('Are you sure you want to logout?')) location.href = logout.php;
      }
    };
  }

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
