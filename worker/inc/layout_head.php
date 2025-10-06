<?php
/**
 * Worker Layout Head (header + sidebar) | PoultryMetrics
 * Include at the top of every worker page AFTER worker/inc/common.php.
 */

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

if (!isset($PAGE_TITLE)) $PAGE_TITLE = 'Worker';
/** current path sans query */
$__req_uri  = $_SERVER['REQUEST_URI'] ?? ($_SERVER['PHP_SELF'] ?? '');
$__req_path = parse_url($__req_uri, PHP_URL_PATH) ?? '';
$CURRENT    = strtolower(basename($__req_path));
/** base path for worker area (auto-detects /worker even if nested) */
$__dir = rtrim(str_replace('\\','/',dirname($__req_path)), '/');
$BASE_PATH = ($__dir === '' || $__dir === '/') ? '/worker' : $__dir;

/** display name */
$__me = function_exists('current_worker') ? current_worker($conn) : null;
$__display = trim((string)($__me['full_name'] ?? ($_SESSION['username'] ?? 'Worker')));

/** notifications */
$notif_count = isset($notif_count) ? (int)$notif_count : 0;
$notifs      = is_array($notifs ?? null) ? $notifs : [];

/** active-state helper (accepts single string or array of filenames) */
$__is_active = function($files) use ($CURRENT){
  foreach ((array)$files as $f){
    if (strtolower(basename((string)$f)) === $CURRENT) return 'active';
  }
  return '';
};
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
  @media (prefers-reduced-motion: reduce){
    *{scroll-behavior:auto!important; animation:none!important; transition:none!important;}
  }
  #preloader{position:fixed;inset:0;background:#0f1113;display:flex;align-items:center;justify-content:center;z-index:9999;transition:opacity .3s}
  #preloader.hidden{opacity:0;visibility:hidden}
  body{font-family:'Poppins',sans-serif;background:#f6f8fa;overflow-x:hidden}
  body.content-hidden{overflow:hidden;}

  /* Header */
  .header-area{position:fixed;inset:0 0 auto 0;height:56px;background:var(--dark);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 14px;z-index:1000;border-bottom:1px solid rgba(255,255,255,.06)}
  .header-area .logo{font-size:17px;font-weight:700;color:var(--accent);text-decoration:none;white-space:nowrap}
  .menu-trigger{cursor:pointer;color:#fff;font-size:18px;display:inline-flex;align-items:center;gap:6px;border:0;background:transparent}

  /* Sidebar */
  .sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-w);background:linear-gradient(180deg,var(--dark),var(--dark-2));
           padding-top:56px;z-index:999;overflow-y:auto;transform:translateX(0);transition:transform .25s}
  .sidebar::-webkit-scrollbar{width:6px}
  .sidebar::-webkit-scrollbar-thumb{background:#394049;border-radius:12px}
  .sidebar .brand{display:flex;align-items:center;gap:8px;padding:10px 12px;color:#fff;border-bottom:1px solid rgba(255,255,255,.06)}
  .sidebar h6{color:#aeb6bf;font-size:10px;letter-spacing:.14em;margin:10px 12px 4px;text-transform:uppercase}
  .sidebar .nav-link{color:#cfd3d7;padding:6px 10px;margin:1px 8px;border-radius:8px;display:flex;align-items:center;gap:8px;font-size:13px;line-height:1.2}
  .sidebar .nav-link i{width:18px;text-align:center;font-size:.95rem}
  .sidebar .nav-link:hover,.sidebar .nav-link.active,.sidebar .nav-link[aria-current="page"]{background:#2a3037;color:var(--accent)}

  /* Backdrop */
  .backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);opacity:0;visibility:hidden;transition:opacity .2s;z-index:998}

  /* Content */
  .content{margin-left:var(--sidebar-w);padding:68px 14px 16px;transition:margin-left .25s}
  .card{border:none;border-radius:var(--card-radius);box-shadow:var(--shadow)}
  .card-header{background:#fff;border-bottom:1px solid #eee;border-radius:var(--card-radius) var(--card-radius) 0 0}

  /* Collapse */
  body.sidebar-collapsed .sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}
  body.sidebar-collapsed .content{margin-left:0}
  @media (max-width: 992px){
    .content{margin-left:0}
    .sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}
    body.sidebar-open .sidebar{transform:translateX(0)}
    body.sidebar-open .backdrop{opacity:1;visibility:visible}
  }

  /* SweetAlert theme hooks (optional) */
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
  <script>
    (function(){
      function hidePreloader(){
        var p=document.getElementById('preloader');
        if(p && !p.classList.contains('hidden')){
          p.classList.add('hidden');
          document.body.classList.remove('content-hidden');
        }
      }
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function(){ setTimeout(hidePreloader, 150); });
      } else {
        setTimeout(hidePreloader, 150);
      }
      setTimeout(hidePreloader, 3000);
    })();
  </script>

  <!-- Header -->
  <header class="header-area" role="banner">
    <a href="<?php echo h($BASE_PATH); ?>/worker_dashboard.php" class="logo">PoultryMetrics</a>
    <div class="d-flex align-items-center gap-2">
      <!-- Notifications -->
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-light btn-icon-bell position-relative"
                data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
          <i class="fa-solid fa-bell"></i>
          <?php if ($notif_count > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark">
              <?php echo (int)$notif_count; ?>
            </span>
          <?php endif; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:280px;">
          <li class="dropdown-header small fw-semibold">Notifications</li>
          <?php if (!empty($notifs)): ?>
            <?php foreach ($notifs as $n):
              $n_url = isset($n['url'])  ? (string)$n['url']  : ($BASE_PATH.'/worker_dashboard.php?tab=notifications');
              $n_lbl = isset($n['label'])? (string)$n['label']: 'Notification';
              $n_icn = isset($n['icon']) ? (string)$n['icon'] : 'fa-bell';
            ?>
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2" href="<?php echo h($n_url); ?>">
                  <i class="fa-solid <?php echo h($n_icn); ?> opacity-75"></i>
                  <span><?php echo h($n_lbl); ?></span>
                </a>
              </li>
            <?php endforeach; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item small text-muted" href="<?php echo h($BASE_PATH); ?>/worker_dashboard.php?tab=notifications">View all</a></li>
          <?php else: ?>
            <li><div class="dropdown-item text-muted small">You're all caught up.</div></li>
          <?php endif; ?>
        </ul>
      </div>

      <!-- User -->
      <div class="dropdown">
        <button class="d-flex align-items-center text-white text-decoration-none dropdown-toggle btn btn-link p-0"
                data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
          <i class="fa-solid fa-user-circle me-2"></i>
          <small class="fw-semibold"><?php echo h($__display); ?></small>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?php echo h($BASE_PATH); ?>/profile.php">Profile</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="#" onclick="return confirmLogout()">Logout</a></li>
        </ul>
      </div>

      <button class="menu-trigger" id="menuTrigger" aria-label="Toggle sidebar" aria-controls="sidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
      </button>
    </div>
  </header>

  <!-- Sidebar -->
  <nav class="sidebar" id="sidebar" aria-label="Primary" role="navigation" tabindex="-1">
    <div class="brand"><i class="fa-solid fa-handshake-angle"></i><strong>Worker Panel</strong></div>
    <ul class="nav flex-column mb-3">
      <h6>Menu</h6>

      <!-- Overview -->
      <li>
        <a href="<?php echo h($BASE_PATH); ?>/worker_dashboard.php"
           class="nav-link <?php echo $__is_active('worker_dashboard.php'); ?>">
          <i class="fa-solid fa-gauge"></i>Overview
        </a>
      </li>

      <!-- Operations -->
      <h6 class="mt-2">Operations</h6>
      <li>
        <a href="<?php echo h($BASE_PATH); ?>/attendance.php"
           class="nav-link <?php echo $__is_active('attendance.php'); ?>">
          <i class="fa-solid fa-calendar-check"></i>Attendance
        </a>
      </li>
      <li>
        <a href="<?php echo h($BASE_PATH); ?>/daily_egg_feed_inventory_form.php"
           class="nav-link <?php echo $__is_active('daily_egg_feed_inventory_form.php'); ?>">
          <i class="fa-solid fa-table-list"></i>Form I-102 (Daily Report)
        </a>
      </li>


      <h6 class="mt-2">Session</h6>
      <li>
        <a href="#" class="nav-link" onclick="return confirmLogout()">
          <i class="fa-solid fa-right-from-bracket"></i>Logout
        </a>
      </li>
    </ul>
  </nav>

  <!-- Backdrop (mobile) -->
  <div class="backdrop" id="backdrop" aria-hidden="true"></div>

  <!-- Main starts in each worker page -->
  <main class="content" id="mainContent" role="main">

  <script>
    // Sidebar toggle with state memory + focus handling
    (function(){
      var body = document.body,
          menu = document.getElementById('menuTrigger'),
          sb   = document.getElementById('sidebar'),
          bd   = document.getElementById('backdrop'),
          KEY  = 'pm.worker.sidebar.open';

      function openSB(){
        body.classList.add('sidebar-open');
        menu && menu.setAttribute('aria-expanded','true');
        // focus trap entry
        sb && sb.focus({preventScroll:true});
        localStorage.setItem(KEY,'1');
      }
      function closeSB(){
        body.classList.remove('sidebar-open');
        menu && menu.setAttribute('aria-expanded','false');
        // return focus to trigger for a11y
        menu && menu.focus({preventScroll:true});
        localStorage.setItem(KEY,'0');
      }
      function toggleSB(){
        if (body.classList.contains('sidebar-open')) closeSB(); else openSB();
      }
      if(menu){ menu.addEventListener('click', toggleSB); menu.addEventListener('keydown', function(e){ if(e.key==='Enter' || e.key===' ') { e.preventDefault(); toggleSB(); }}); }
      if(bd){ bd.addEventListener('click', closeSB); }
      document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeSB(); });

      // restore state on small screens only; keep desktop expanded
      if (window.matchMedia('(max-width: 992px)').matches) {
        if (localStorage.getItem(KEY)==='1') openSB();
      }
    })();

    
  </script>
