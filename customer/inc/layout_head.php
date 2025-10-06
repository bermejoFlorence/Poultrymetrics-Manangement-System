<?php
/**
 * Customer Layout Head (header + sidebar)
 */
declare(strict_types=1);

/* Ensure common + session */
$common = dirname(__DIR__, 1) . '/inc/common.php';
if (is_file($common)) require_once $common;
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* Safe shims */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* Page vars */
if (!isset($PAGE_TITLE)) $PAGE_TITLE = 'Customer';
if (!isset($CURRENT))    $CURRENT    = basename($_SERVER['PHP_SELF'] ?? '');

/* Base-aware URLs */
$BASE = defined('BASE_URI') ? BASE_URI : '';
$LOGOUT_URL = rtrim($BASE,'/') . '/logout.php';

/* Active helper */
$__is_active = function($files) use ($CURRENT){
  $files = (array)$files;
  $cur   = strtolower($CURRENT);
  foreach ($files as $f) {
    if (strtolower(basename($f)) === $cur) return 'active';
  }
  return '';
};

/* Cart count (session-based) */
$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
  foreach ($_SESSION['cart'] as $q) $cartCount += max(0,(int)$q);
}

/* Username (if any) */
$who = $_SESSION['username'] ?? ($_SESSION['customer_name'] ?? 'Customer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title><?php echo h($PAGE_TITLE); ?> | PoultryMetrics</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

<style>
  :root{
    --accent:#f5a425; --accent-700:#d98f1f;
    --dark:#1f2327; --dark-2:#242a30; --muted:#8b949e;
    --text:#e9ecef; --card-radius:14px; --shadow:0 10px 28px rgba(0,0,0,.10);
    --sidebar-w:210px;
  }
  body{font-family:'Poppins',sans-serif;background:#f6f8fa;overflow-x:hidden}
  body.content-hidden{ overflow:hidden; }

  /* Header */
  .header-area{position:fixed;inset:0 0 auto 0;height:56px;background:var(--dark);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 14px;z-index:1000;border-bottom:1px solid rgba(255,255,255,.06)}
  .header-area .logo{font-size:17px;font-weight:700;color:var(--accent);text-decoration:none}
  .menu-trigger{cursor:pointer;color:#fff;font-size:18px;display:flex;align-items:center;gap:6px}

  /* Sidebar */
  .sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-w);background:linear-gradient(180deg,var(--dark),var(--dark-2));
           padding-top:56px;z-index:999;overflow-y:auto;transform:translateX(0);transition:transform .25s}
  .sidebar::-webkit-scrollbar{width:6px} .sidebar::-webkit-scrollbar-thumb{background:#394049;border-radius:12px}
  .sidebar .brand{display:flex;align-items:center;gap:8px;padding:10px 12px;color:#fff;border-bottom:1px solid rgba(255,255,255,.06)}
  .sidebar h6{color:#aeb6bf;font-size:10px;letter-spacing:.14em;margin:10px 12px 4px;text-transform:uppercase}
  .sidebar .nav-link{color:#cfd3d7;padding:6px 10px;margin:1px 8px;border-radius:8px;display:flex;align-items:center;gap:8px;font-size:13px}
  .sidebar .nav-link i{width:18px;text-align:center;font-size:.95rem}
  .sidebar .nav-link:hover,.sidebar .nav-link.active{background:#2a3037;color:var(--accent)}
  .sidebar .nav-link[aria-current="page"]{background:#2a3037;color:var(--accent)}

  /* Backdrop (mobile) */
  .backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);opacity:0;visibility:hidden;transition:opacity .2s;z-index:998}

  /* Content */
  .content{margin-left:var(--sidebar-w);padding:68px 14px 16px;transition:margin-left .25s}
  .card{border:none;border-radius:var(--card-radius);box-shadow:var(--shadow)}
  .card-header{background:#fff;border-bottom:1px solid #eee;border-radius:var(--card-radius) var(--card-radius) 0 0}

  /* Collapse states */
  body.sidebar-collapsed .sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}
  body.sidebar-collapsed .content{margin-left:0}
  @media (max-width: 992px){
    .content{margin-left:0}
    .sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}
    body.sidebar-open .sidebar{transform:translateX(0)}
    body.sidebar-open .backdrop{opacity:1;visibility:visible}
  }

  /* Preloader */
  #preloader{position:fixed;inset:0;background:#0f1113;display:flex;align-items:center;justify-content:center;z-index:9999;transition:opacity .3s}
  #preloader.hidden{opacity:0;visibility:hidden}

  .btn-icon{ display:inline-flex; align-items:center; gap:.4rem }
  .btn-icon .badge{ font-size:.65rem }
</style>
</head>
<body class="content-hidden">

  <!-- Preloader -->
  <div id="preloader" aria-hidden="true"><i class="fa-solid fa-spinner fa-spin fa-2x" style="color:var(--accent)"></i></div>

  <!-- Header -->
  <header class="header-area" role="banner">
    <a href="<?php echo h($BASE.'/customer_dashboard.php'); ?>" class="logo">PoultryMetrics</a>
    <div class="d-flex align-items-center gap-2">

      <a href="<?php echo h($BASE.'orders.php'); ?>" class="btn btn-sm btn-outline-light btn-icon" title="My Orders">
        <i class="fa-solid fa-receipt"></i>
      </a>

      <a href="<?php echo h($BASE.'cart.php'); ?>" class="btn btn-sm btn-outline-light btn-icon position-relative" title="My Cart">
        <i class="fa-solid fa-shopping-cart"></i>
        <?php if ($cartCount > 0): ?>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark"><?php echo (int)$cartCount; ?></span>
        <?php endif; ?>
      </a>

      <!-- User -->
      <div class="dropdown">
        <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
          <i class="fa-solid fa-user-circle me-2"></i>
          <small class="fw-semibold"><?php echo h($who); ?></small>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?php echo h($BASE.'customer_dashboard.php'); ?>">Dashboard</a></li>
          <li><a class="dropdown-item" href="<?php echo h($BASE.'orders.php'); ?>">Orders</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="#" onclick="confirmLogout()">Logout</a></li>
        </ul>
      </div>

      <span class="menu-trigger" id="menuTrigger" aria-label="Toggle sidebar" role="button" tabindex="0"><i class="fa-solid fa-bars"></i></span>
    </div>
  </header>

  <!-- Sidebar -->
  <nav class="sidebar" id="sidebar" aria-label="Primary" role="navigation">
    <div class="brand"><i class="fa-solid fa-egg"></i><strong>Customer</strong></div>
    <ul class="nav flex-column mb-3">
      <h6>Menu</h6>
      <li>
        <a href="<?php echo h($BASE.'customer_dashboard.php'); ?>" class="nav-link <?php echo $__is_active('customer_dashboard.php'); ?>">
          <i class="fa-solid fa-gauge"></i>Dashboard
        </a>
      </li>
      <li>
        <a href="<?php echo h($BASE.'shop.php'); ?>" class="nav-link <?php echo $__is_active('shop.php'); ?>">
          <i class="fa-solid fa-store"></i>Shop
        </a>
      </li>
      <li>
        <a href="<?php echo h($BASE.'cart.php'); ?>" class="nav-link <?php echo $__is_active('cart.php'); ?>">
          <i class="fa-solid fa-shopping-cart"></i>Cart
        </a>
      </li>
      <li>
        <a href="<?php echo h($BASE.'orders.php'); ?>" class="nav-link <?php echo $__is_active('orders.php'); ?>">
          <i class="fa-solid fa-receipt"></i>Orders
        </a>
      </li>
      <li>
  <a href="<?php echo h($BASE.'profile.php'); ?>" class="nav-link <?php echo $__is_active('profile.php'); ?>">
    <i class="fa-solid fa-id-card"></i>Profile
  </a>
</li>


      <h6 class="mt-2">Session</h6>
      <li><a href="#" class="nav-link" onclick="confirmLogout()"><i class="fa-solid fa-right-from-bracket"></i>Logout</a></li>
    </ul>
  </nav>

  <!-- Backdrop (mobile) -->
  <div class="backdrop" id="backdrop" aria-hidden="true"></div>

  <!-- Main starts; page content should follow -->
  <main class="content" id="mainContent" role="main">
