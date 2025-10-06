<?php
/**
 * PoultryMetrics – Admin > Employees (Profile & Directory)
 * Save as: /admin/profile.php
 *
 * - Matches Admin Dashboard structure/styles/JS exactly
 * - CSRF on all POSTs
 * - Safer image uploads (MIME + extension + size cap)
 * - Prepared statements everywhere
 */

require_once __DIR__ . '/_session.php'; // session + role guard
require_once __DIR__ . '/../config.php';

@$conn->query("SET time_zone = '+08:00'"); // Asia/Manila

/* -------------------- Helpers (match dashboard style) -------------------- */
function tableExists(mysqli $c, string $n): bool {
  $n = $c->real_escape_string($n);
  $r = @$c->query("SHOW TABLES LIKE '{$n}'");
  return !!($r && $r->num_rows);
}
function colExists(mysqli $c, string $table, string $col): bool {
  $table = $c->real_escape_string($table);
  $col   = $c->real_escape_string($col);
  $r = @$c->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
  return !!($r && $r->num_rows);
}
function scalar(mysqli $c, string $sql) {
  $val = 0;
  if ($r = @$c->query($sql)) {
    if ($row = $r->fetch_row()) $val = $row[0] ?? 0;
    $r->free();
  }
  return $val;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function asFloat($v){ return (float)$v; }
function asInt($v){ return (int)$v; }
function ensureDir($path){ if(!is_dir($path)) @mkdir($path,0775,true); }
function extOf($name){ return strtolower(pathinfo($name, PATHINFO_EXTENSION)); }
function randName($ext){ return date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext; }

/* -------------------- CSRF & Flash -------------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$t); }
function flash_set($type,$msg){ $_SESSION['emp_flash']=['t'=>$type,'m'=>$msg]; }
function flash_pop(){ $f=$_SESSION['emp_flash']??null; if($f) unset($_SESSION['emp_flash']); return $f; }

/* -------------------- Ensure employees table -------------------- */
$createSQL = "
CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_no VARCHAR(32) NULL,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  address TEXT NULL,
  gender ENUM('Male','Female','Other') DEFAULT 'Male',
  birthdate DATE NULL,
  position VARCHAR(120) NULL,
  department VARCHAR(120) NULL,
  salary DECIMAL(12,2) DEFAULT 0.00,
  date_hired DATE NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  photo_path VARCHAR(255) NULL,
  fp_device_uid VARCHAR(64) NULL,
  fp_index VARCHAR(40) NULL,
  fp_template LONGTEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status(status),
  KEY idx_name(last_name, first_name),
  KEY idx_dept(department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
@$conn->query($createSQL);

/* -------------------- Uploads -------------------- */
$UPLOAD_DIR = __DIR__ . '/../uploads/employees/';
$PUBLIC_DIR = 'uploads/employees/';
ensureDir($UPLOAD_DIR);

function safe_upload($file, &$relPathOut): bool {
  if (empty($file['name']) || $file['error']!==UPLOAD_ERR_OK) return true; // no file = ok
  if ($file['size'] > 3*1024*1024) return false; // 3MB cap
  $ext = extOf($file['name']);
  $okExt = ['jpg','jpeg','png','webp'];
  if (!in_array($ext,$okExt,true)) return false;
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($file['tmp_name']) ?: '';
  $okMime = ['image/jpeg','image/png','image/webp'];
  if (!in_array($mime,$okMime,true)) return false;
  $fname = randName($ext);
  if (!move_uploaded_file($file['tmp_name'], $GLOBALS['UPLOAD_DIR'] . $fname)) return false;
  $relPathOut = $GLOBALS['PUBLIC_DIR'] . $fname;
  return true;
}

/* -------------------- Actions -------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF validation failed'); }
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $employee_no = trim($_POST['employee_no'] ?? '');
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $gender      = in_array($_POST['gender'] ?? 'Male', ['Male','Female','Other'], true) ? $_POST['gender'] : 'Male';
    $birthdate   = $_POST['birthdate'] ?: null;
    $position    = trim($_POST['position'] ?? '');
    $department  = trim($_POST['department'] ?? '');
    $salary      = asFloat($_POST['salary'] ?? 0);
    $date_hired  = $_POST['date_hired'] ?: null;
    $status      = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';
    $fp_device_uid = trim($_POST['fp_device_uid'] ?? '');
    $fp_index      = trim($_POST['fp_index'] ?? '');
    $fp_template   = trim($_POST['fp_template'] ?? '');

    $photo_path = null;
    if (!safe_upload($_FILES['photo'] ?? [], $photo_path)) {
      flash_set('error','Invalid photo (JPG/PNG/WebP, ≤ 3 MB).'); header('Location: profile.php'); exit;
    }

    $sql = "INSERT INTO employees
            (employee_no,first_name,middle_name,last_name,email,phone,address,gender,birthdate,position,department,salary,date_hired,status,photo_path,fp_device_uid,fp_index,fp_template)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $types = 'sssssssssssdssssss'; // 11s + d + 6s = 18
    $stmt->bind_param(
      $types,
      $employee_no,$first_name,$middle_name,$last_name,$email,$phone,$address,$gender,$birthdate,$position,$department,
      $salary,$date_hired,$status,$photo_path,$fp_device_uid,$fp_index,$fp_template
    );
    if ($stmt->execute()) {
      flash_set('success','Employee added.');
    } else {
      if ($photo_path && is_file(__DIR__.'/../'.$photo_path)) @unlink(__DIR__.'/../'.$photo_path);
      flash_set('error','Failed to add employee.');
    }
    $stmt->close();
    header('Location: profile.php'); exit;
  }

  if ($action === 'update') {
    $id = asInt($_POST['id'] ?? 0);
    if ($id<=0) { flash_set('error','Invalid employee.'); header('Location: profile.php'); exit; }

    $q = $conn->prepare("SELECT photo_path FROM employees WHERE id=?");
    $q->bind_param('i',$id); $q->execute(); $old = $q->get_result()->fetch_assoc(); $q->close();
    $old_photo = $old['photo_path'] ?? null;

    $employee_no = trim($_POST['employee_no'] ?? '');
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $gender      = in_array($_POST['gender'] ?? 'Male', ['Male','Female','Other'], true) ? $_POST['gender'] : 'Male';
    $birthdate   = $_POST['birthdate'] ?: null;
    $position    = trim($_POST['position'] ?? '');
    $department  = trim($_POST['department'] ?? '');
    $salary      = asFloat($_POST['salary'] ?? 0);
    $date_hired  = $_POST['date_hired'] ?: null;
    $status      = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';
    $fp_device_uid = trim($_POST['fp_device_uid'] ?? '');
    $fp_index      = trim($_POST['fp_index'] ?? '');
    $fp_template   = trim($_POST['fp_template'] ?? '');

    $photo_path = $old_photo;
    if (!empty($_FILES['photo']['name'])) {
      $tmpRel=null;
      if (!safe_upload($_FILES['photo'], $tmpRel)) { flash_set('error','Invalid photo (JPG/PNG/WebP, ≤ 3 MB).'); header('Location: profile.php'); exit; }
      if ($tmpRel){ $photo_path = $tmpRel; if ($old_photo && is_file(__DIR__.'/../'.$old_photo)) @unlink(__DIR__.'/../'.$old_photo); }
    }

    $sql = "UPDATE employees
            SET employee_no=?,first_name=?,middle_name=?,last_name=?,email=?,phone=?,address=?,gender=?,birthdate=?,position=?,department=?,salary=?,date_hired=?,status=?,photo_path=?,fp_device_uid=?,fp_index=?,fp_template=?
            WHERE id=?";
    $stmt = $conn->prepare($sql);
    $types = 'sssssssssssdssssssi'; // 11s + d + 6s + i
    $stmt->bind_param(
      $types,
      $employee_no,$first_name,$middle_name,$last_name,$email,$phone,$address,$gender,$birthdate,$position,$department,
      $salary,$date_hired,$status,$photo_path,$fp_device_uid,$fp_index,$fp_template,$id
    );
    if ($stmt->execute()) flash_set('success','Employee updated.');
    else flash_set('error','Failed to update employee.');
    $stmt->close();
    header('Location: profile.php'); exit;
  }

  if ($action === 'delete') {
    $id = asInt($_POST['id'] ?? 0);
    if ($id<=0) { flash_set('error','Invalid employee.'); header('Location: profile.php'); exit; }
    $q = $conn->prepare("SELECT photo_path FROM employees WHERE id=?"); $q->bind_param('i',$id); $q->execute();
    $row = $q->get_result()->fetch_assoc(); $q->close();
    $photo_path = $row['photo_path'] ?? null;

    $d = $conn->prepare("DELETE FROM employees WHERE id=?"); $d->bind_param('i',$id); $d->execute();
    if ($d->affected_rows>0) {
      if ($photo_path && is_file(__DIR__.'/../'.$photo_path)) @unlink(__DIR__.'/../'.$photo_path);
      flash_set('success','Employee deleted.');
    } else flash_set('error','Failed to delete employee.');
    $d->close();
    header('Location: profile.php'); exit;
  }
}

/* -------------------- Fetch employees -------------------- */
$employees = [];
if ($res = $conn->query("SELECT * FROM employees ORDER BY id DESC")) {
  while($r = $res->fetch_assoc()) $employees[] = $r;
  $res->free();
}
$flash = flash_pop();

/* -------------------- Notifications (copy of dashboard logic) -------------------- */
$notif_count = 0; $notifs = [];

/* Pending orders */
if (tableExists($conn,'customer_orders')) {
  $pending_orders = (int)scalar($conn, "SELECT COUNT(*) FROM customer_orders WHERE status='pending'");
  if ($pending_orders > 0) {
    $notif_count += $pending_orders;
    $notifs[] = ['label'=>"$pending_orders pending customer order(s)", 'url'=>'customer_orders.php?status=pending', 'icon'=>'fa-cart-shopping'];
  }
}

/* Pending customer approvals: support BOTH table names */
$approvals_tbl = null;
if (tableExists($conn,'customer_approvals')) $approvals_tbl = 'customer_approvals';
elseif (tableExists($conn,'account_approvals')) $approvals_tbl = 'account_approvals';
if ($approvals_tbl) {
  $pending_approvals = (int)scalar($conn, "SELECT COUNT(*) FROM {$approvals_tbl} WHERE status='pending'");
  if ($pending_approvals > 0) {
    $notif_count += $pending_approvals;
    $notifs[] = ['label'=>"$pending_approvals customer approval request(s)", 'url'=>'customer_approvals.php?status=pending', 'icon'=>'fa-user-check'];
  }
}

/* Feed batches expiring in 7 days */
if (tableExists($conn,'feed_batches') && colExists($conn,'feed_batches','expiry_date')) {
  $expiring = (int)scalar($conn, "SELECT COUNT(*) FROM feed_batches
                                   WHERE expiry_date IS NOT NULL
                                     AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
  if ($expiring > 0) {
    $notif_count += $expiring;
    $notifs[] = ['label'=>"$expiring feed batch(es) expiring soon", 'url'=>'inventory_feeds.php?filter=expiring', 'icon'=>'fa-wheat-awn'];
  }
}

/* Mortality today (if present) */
$mortality_today = 0;
if (tableExists($conn,'egg_collections') && colExists($conn,'egg_collections','mortality') && colExists($conn,'egg_collections','collect_date')) {
  $mortality_today = (int)scalar($conn, "SELECT COALESCE(SUM(mortality),0) FROM egg_collections WHERE collect_date=CURDATE()");
} elseif (tableExists($conn,'egg_production') && colExists($conn,'egg_production','mortality')) {
  $dcol2 = colExists($conn,'egg_production','date') ? 'date' : (colExists($conn,'egg_production','collect_date') ? 'collect_date' : null);
  if ($dcol2) $mortality_today = (int)scalar($conn, "SELECT COALESCE(SUM(mortality),0) FROM egg_production WHERE {$dcol2}=CURDATE()");
}
if ($mortality_today > 0) {
  $notif_count += 1; // aggregate as one alert
  $notifs[] = ['label'=>"$mortality_today mortality recorded today", 'url'=>'egg_reports.php?date='.date('Y-m-d'), 'icon'=>'fa-heart-crack'];
}

/* Feed low stock (same calc as dashboard) */
if (tableExists($conn,'feed_items')) {
  $low_stock = (int)scalar($conn, "SELECT COUNT(*) FROM (
    SELECT i.id, COALESCE(SUM(b.qty_remaining_kg),0) on_hand, i.reorder_level_kg
    FROM feed_items i
    LEFT JOIN feed_batches b ON b.feed_item_id=i.id
    GROUP BY i.id
  )x WHERE on_hand < reorder_level_kg");
  if ($low_stock > 0) {
    $notif_count += $low_stock;
    $notifs[] = ['label'=>"$low_stock feed item(s) in Low Stock", 'url'=>'feed_inventory.php', 'icon'=>'fa-clipboard-check'];
  }
}
$notif_count = max(0,$notif_count);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Employees | PoultryMetrics</title>
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
  @media (prefers-reduced-motion: reduce){ *{scroll-behavior:auto!important; animation:none!important; transition:none!important;} }

  /* Preloader */
  #preloader{position:fixed;inset:0;background:#0f1113;display:flex;align-items:center;justify-content:center;z-index:9999;transition:opacity .3s}
  #preloader.hidden{opacity:0;visibility:hidden}
  body{font-family:'Poppins',sans-serif;background:#f6f8fa;overflow-x:hidden}

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
  .sidebar .nav-link i{width:18px;text-align:center;font-size:0.95rem}
  .sidebar .nav-link:hover,.sidebar .nav-link.active{background:#2a3037;color:var(--accent)}
  .sidebar .nav-link[aria-current="page"]{background:#2a3037;color:var(--accent)}

  /* Backdrop (mobile) */
  .backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);opacity:0;visibility:hidden;transition:opacity .2s;z-index:998}

  /* Content */
  .content{margin-left:var(--sidebar-w);padding:68px 14px 16px;transition:margin-left .25s}
  .card{border:none;border-radius:var(--card-radius);box-shadow:var(--shadow)}
  .card-header{background:#fff;border-bottom:1px solid #eee;border-radius:var(--card-radius) var(--card-radius) 0 0}

  /* Collapse behavior (desktop hide) */
  body.sidebar-collapsed .sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}
  body.sidebar-collapsed .content{margin-left:0}
  @media (max-width: 992px){
    .content{margin-left:0}
    .sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}
    body.sidebar-open .sidebar{transform:translateX(0)}
    body.sidebar-open .backdrop{opacity:1;visibility:visible}
  }

  /* SweetAlert2 Theme (exactly as dashboard) */
  .swal-theme-popup{background: linear-gradient(180deg,#1f2327,#232a30);color: var(--text);border: 1px solid rgba(255,255,255,.06);border-radius: 14px !important;box-shadow: 0 18px 48px rgba(0,0,0,.35)}
  .swal-theme-title{ color:#fff !important; font-weight:700 !important }
  .swal-theme-html{ color:#cfd3d7 !important }
  .swal-theme-confirm.swal2-styled{ background: var(--accent) !important; color: #151a1f !important; font-weight:700 !important; border-radius: 10px !important; padding: 10px 16px !important; border: 1px solid rgba(0,0,0,.08) !important; box-shadow: 0 6px 14px rgba(245,164,37,.35) !important; transition: filter .12s, box-shadow .12s !important }
  .swal-theme-confirm.swal2-styled:hover{ filter: brightness(0.95) }
  .swal-theme-confirm.swal2-styled:focus{ box-shadow: 0 0 0 3px rgba(245,164,37,.35) !important }
  .swal-theme-cancel.swal2-styled{ background: #343b44 !important; color: #e9ecef !important; border-radius: 10px !important; padding: 10px 14px !important; border: 1px solid rgba(255,255,255,.06) !important }
  .swal2-icon.swal2-question{ border-color: var(--accent) !important; color: var(--accent) !important }

  .btn-icon-bell{ display:inline-flex; align-items:center; gap:.4rem }
  .btn-icon-bell .badge{ font-size:.65rem }

  /* Avatars */
  .avatar{ width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid #fff; box-shadow: 0 0 0 2px rgba(0,0,0,.05); }
  .small-muted{ font-size:.8rem; color:#6c757d; }
</style>
</head>
<body class="content-hidden">
  <!-- Preloader -->
  <div id="preloader" aria-hidden="true"><i class="fa-solid fa-spinner fa-spin fa-2x" style="color:var(--accent)"></i></div>

  <!-- Header -->
  <header class="header-area" role="banner">
    <a href="admin_dashboard.php" class="logo">PoultryMetrics</a>
    <span class="menu-trigger" id="menuTrigger" aria-label="Toggle sidebar" role="button" tabindex="0"><i class="fa-solid fa-bars"></i></span>
  </header>

  <?php $CURRENT = basename($_SERVER['PHP_SELF']); ?>
  <nav class="sidebar" id="sidebar" aria-label="Primary" role="navigation">
    <div class="brand"><i class="fa-solid fa-feather-pointed"></i><strong>PoultryMetrics</strong></div>
    <ul class="nav flex-column mb-3">

      <h6>Dashboard</h6>
      <li>
        <a href="admin_dashboard.php" class="nav-link <?php echo $CURRENT==='admin_dashboard.php'?'active':''; ?>" <?php echo $CURRENT==='admin_dashboard.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-gauge"></i>Overview
        </a>
      </li>

      <h6>User & Access</h6>
      <li>
        <a href="users.php" class="nav-link <?php echo $CURRENT==='users.php'?'active':''; ?>" <?php echo $CURRENT==='users.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-users"></i>All Users
        </a>
      </li>
      <li>
        <a href="roles.php" class="nav-link <?php echo $CURRENT==='roles.php'?'active':''; ?>" <?php echo $CURRENT==='roles.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-id-badge"></i>Roles & Permissions
        </a>
      </li>
      <li>
        <a href="customer_approvals.php" class="nav-link <?php echo $CURRENT==='customer_approvals.php'?'active':''; ?>" <?php echo $CURRENT==='customer_approvals.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-user-check"></i>Customer Approvals
        </a>
      </li>

      <h6>Employee</h6>
      <li>
        <a href="profile.php" class="nav-link <?php echo $CURRENT==='profile.php'?'active':''; ?>" <?php echo $CURRENT==='profile.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-id-card"></i>Profile
        </a>
      </li>
      <li>
        <a href="attendance.php" class="nav-link <?php echo $CURRENT==='attendance.php'?'active':''; ?>" <?php echo $CURRENT==='attendance.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-calendar-check"></i>Attendance
        </a>
      </li>
      <li>
        <a href="payroll.php" class="nav-link <?php echo $CURRENT==='payroll.php'?'active':''; ?>" <?php echo $CURRENT==='payroll.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-wallet"></i>Payroll
        </a>
      </li>

      <h6>Products & Orders</h6>
      <li>
        <a href="products.php" class="nav-link <?php echo $CURRENT==='products.php'?'active':''; ?>" <?php echo $CURRENT==='products.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-box-open"></i>Products
        </a>
      </li>
      <li>
        <a href="customer_orders.php" class="nav-link <?php echo $CURRENT==='customer_orders.php'?'active':''; ?>" <?php echo $CURRENT==='customer_orders.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-cart-shopping"></i>Customer Orders
        </a>
      </li>

      <h6>Egg Operation</h6>
      <li>
        <a href="egg_reports.php" class="nav-link <?php echo $CURRENT==='egg_reports.php'?'active':''; ?>" <?php echo $CURRENT==='egg_reports.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-egg"></i>Egg Production
        </a>
      </li>
      <li>
        <a href="egg_inventory.php" class="nav-link <?php echo $CURRENT==='egg_inventory.php'?'active':''; ?>" <?php echo $CURRENT==='egg_inventory.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-clipboard-list"></i>Egg Inventory
        </a>
      </li>

      <h6>Feed Operation</h6>
      <li>
        <a href="feed_logs.php" class="nav-link <?php echo $CURRENT==='feed_logs.php'?'active':''; ?>" <?php echo $CURRENT==='feed_logs.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-seedling"></i>Feed Distribution
        </a>
      </li>
      <li>
        <a href="feed_inventory.php" class="nav-link <?php echo $CURRENT==='feed_inventory.php'?'active':''; ?>" <?php echo $CURRENT==='feed_inventory.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-warehouse"></i>Feed Inventory
        </a>
      </li>

      <h6>Reports</h6>
      <li>
        <a href="reports.php" class="nav-link <?php echo $CURRENT==='reports.php'?'active':''; ?>" <?php echo $CURRENT==='reports.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-chart-bar"></i>Analytics & Export
        </a>
      </li>

      <h6>Settings</h6>
      <li>
        <a href="settings.php" class="nav-link <?php echo $CURRENT==='settings.php'?'active':''; ?>" <?php echo $CURRENT==='settings.php'?"aria-current='page'":''; ?>>
          <i class="fa-solid fa-gear"></i>System Settings
        </a>
      </li>

      <h6 class="mt-2">Session</h6>
      <li>
        <a href="#" class="nav-link" onclick="confirmLogout()">
          <i class="fa-solid fa-right-from-bracket"></i>Logout
        </a>
      </li>
    </ul>
  </nav>

  <!-- Mobile/backdrop for sidebar -->
  <div class="backdrop" id="backdrop" aria-hidden="true"></div>

  <!-- Main -->
  <main class="content" id="mainContent" role="main">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="fw-bold mb-0">Employees</h5>
      <div class="d-flex align-items-center gap-2">
        <!-- Notifications -->
        <div class="dropdown">
          <a href="#" class="btn btn-sm btn-outline-dark btn-icon-bell position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
            <i class="fa-solid fa-bell"></i>
            <?php if ($notif_count > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark">
              <?php echo $notif_count; ?>
            </span>
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

        <!-- Add Employee -->
        <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
          <i class="fa-solid fa-user-plus me-1"></i>Add Employee
        </button>

        <!-- User menu -->
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <i class="fa-solid fa-user-circle me-2"></i>
            <small class="fw-semibold"><?php echo h($_SESSION['username'] ?? 'Admin'); ?></small>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="profile.php">Profile</a></li>
            <li><a class="dropdown-item" href="settings.php">Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="#" onclick="confirmLogout()">Logout</a></li>
          </ul>
        </div>
      </div>
    </div>

    <!-- SweetAlert Flash -->
    <?php if ($flash): ?>
      <div id="flash" data-type="<?php echo h($flash['t']); ?>" data-msg="<?php echo h($flash['m']); ?>"></div>
    <?php endif; ?>

    <!-- Directory -->
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center justify-content-between">
        <div class="small fw-bold">Employee Directory</div>
        <div class="d-flex align-items-center gap-2">
          <input id="searchBox" type="search" class="form-control form-control-sm" placeholder="Search name, position, dept…">
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="empTable">
            <thead class="table-light">
              <tr>
                <th style="width:56px">Photo</th>
                <th>Name</th>
                <th>Position / Department</th>
                <th>Contact</th>
                <th>Status</th>
                <th>Hired</th>
                <th class="text-end" style="width:130px">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$employees): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No employees yet.</td></tr>
              <?php else: foreach ($employees as $e):
                $full = trim(($e['first_name']??'').' '.($e['middle_name']?substr($e['middle_name'],0,1).'. ':'').($e['last_name']??''));
                $photo = ($e['photo_path'] && is_file(__DIR__.'/../'.$e['photo_path']))
                          ? '../'.$e['photo_path']
                          : 'https://ui-avatars.com/api/?name='.urlencode(($e['first_name']??'').' '.($e['last_name']??'')).'&background=ddd&color=555&size=64';
              ?>
              <tr>
                <td><img src="<?php echo h($photo); ?>" class="avatar" alt="photo"></td>
                <td>
                  <div class="fw-semibold"><?php echo h($full ?: '—'); ?></div>
                  <div class="small-muted"><?php echo h($e['employee_no'] ?: ''); ?></div>
                </td>
                <td>
                  <div><?php echo h($e['position'] ?: '—'); ?></div>
                  <div class="small-muted"><?php echo h($e['department'] ?: ''); ?></div>
                </td>
                <td>
                  <div><i class="fa-solid fa-phone me-1"></i><?php echo h($e['phone'] ?: '—'); ?></div>
                  <div class="small-muted"><i class="fa-solid fa-envelope me-1"></i><?php echo h($e['email'] ?: ''); ?></div>
                </td>
                <td>
                  <span class="badge <?php echo ($e['status']==='active'?'text-bg-success':'text-bg-secondary'); ?>">
                    <?php echo ucfirst($e['status']); ?>
                  </span>
                </td>
                <td><?php echo !empty($e['date_hired']) ? date('M d, Y', strtotime($e['date_hired'])) : '—'; ?></td>
                <td class="text-end">
                  <button
                    class="btn btn-sm btn-outline-secondary me-1 btn-edit"
                    data-id="<?php echo (int)$e['id']; ?>"
                    data-employee_no="<?php echo h($e['employee_no']); ?>"
                    data-first_name="<?php echo h($e['first_name']); ?>"
                    data-middle_name="<?php echo h($e['middle_name']); ?>"
                    data-last_name="<?php echo h($e['last_name']); ?>"
                    data-email="<?php echo h($e['email']); ?>"
                    data-phone="<?php echo h($e['phone']); ?>"
                    data-address="<?php echo h($e['address']); ?>"
                    data-gender="<?php echo h($e['gender']); ?>"
                    data-birthdate="<?php echo h($e['birthdate']); ?>"
                    data-position="<?php echo h($e['position']); ?>"
                    data-department="<?php echo h($e['department']); ?>"
                    data-salary="<?php echo h($e['salary']); ?>"
                    data-date_hired="<?php echo h($e['date_hired']); ?>"
                    data-status="<?php echo h($e['status']); ?>"
                    data-fp_device_uid="<?php echo h($e['fp_device_uid']); ?>"
                    data-fp_index="<?php echo h($e['fp_index']); ?>"
                    data-fp_template="<?php echo h($e['fp_template']); ?>"
                    data-photo="<?php echo h($e['photo_path']); ?>"
                    data-bs-toggle="modal" data-bs-target="#editEmployeeModal"
                    title="Edit">
                    <i class="fa-solid fa-pen"></i>
                  </button>

                  <form method="post" action="profile.php" class="d-inline js-confirm" data-message="Delete this employee? This cannot be undone.">
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <footer class="mt-2"><p class="mb-0 small text-muted">&copy; <?php echo date('Y'); ?> PoultryMetrics</p></footer>
  </main>

  <!-- Add Modal -->
  <div class="modal fade" id="addEmployeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <form class="modal-content" method="post" action="profile.php" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fa-solid fa-user-plus me-2"></i>Add Employee</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3 text-center">
              <img id="addPreview" src="https://ui-avatars.com/api/?name=New+Employee&background=ddd&color=555&size=128" class="rounded-circle mb-2" style="width:96px;height:96px;object-fit:cover;border:2px solid #fff;box-shadow:0 0 0 2px rgba(0,0,0,.05)" alt="preview">
              <input type="file" name="photo" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp" onchange="previewImg(this,'addPreview')">
              <div class="form-text">JPG/PNG/WebP, ≤ 3 MB</div>
            </div>
            <div class="col-md-9">
              <div class="row g-2">
                <div class="col-md-4"><label class="form-label small mb-1">Employee #</label><input type="text" name="employee_no" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">First Name</label><input type="text" name="first_name" class="form-control form-control-sm" required></div>
                <div class="col-md-4"><label class="form-label small mb-1">Middle Name</label><input type="text" name="middle_name" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Last Name</label><input type="text" name="last_name" class="form-control form-control-sm" required></div>
                <div class="col-md-4"><label class="form-label small mb-1">Gender</label>
                  <select name="gender" class="form-select form-select-sm"><option>Male</option><option>Female</option><option>Other</option></select>
                </div>
                <div class="col-md-4"><label class="form-label small mb-1">Birthdate</label><input type="date" name="birthdate" class="form-control form-control-sm"></div>
                <div class="col-md-6"><label class="form-label small mb-1">Email</label><input type="email" name="email" class="form-control form-control-sm"></div>
                <div class="col-md-6"><label class="form-label small mb-1">Phone</label><input type="text" name="phone" class="form-control form-control-sm"></div>
                <div class="col-12"><label class="form-label small mb-1">Address</label><input type="text" name="address" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Position</label><input type="text" name="position" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Department</label><input type="text" name="department" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Salary (₱)</label><input type="number" step="0.01" name="salary" class="form-control form-control-sm" value="0.00"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Date Hired</label><input type="date" name="date_hired" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Status</label>
                  <select name="status" class="form-select form-select-sm"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </div>
                <div class="col-md-4"><label class="form-label small mb-1">FP Device UID</label><input type="text" name="fp_device_uid" class="form-control form-control-sm" placeholder="e.g., ZK-12345"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Finger Index</label>
                  <select name="fp_index" class="form-select form-select-sm">
                    <option value="">—</option>
                    <option>Left Thumb</option><option>Left Index</option><option>Left Middle</option><option>Left Ring</option><option>Left Little</option>
                    <option>Right Thumb</option><option>Right Index</option><option>Right Middle</option><option>Right Ring</option><option>Right Little</option>
                  </select>
                </div>
                <div class="col-12"><label class="form-label small mb-1">Fingerprint Template</label><textarea name="fp_template" rows="3" class="form-control form-control-sm" placeholder="Paste template/base64 string here"></textarea></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-dark"><i class="fa-solid fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Modal -->
  <div class="modal fade" id="editEmployeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <form class="modal-content" method="post" action="profile.php" enctype="multipart/form-data" id="editForm" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header">
          <h6 class="modal-title"><i class="fa-solid fa-user-pen me-2"></i>Edit Employee</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3 text-center">
              <img id="editPreview" src="https://ui-avatars.com/api/?name=Employee&background=ddd&color=555&size=128" class="rounded-circle mb-2" style="width:96px;height:96px;object-fit:cover;border:2px solid #fff;box-shadow:0 0 0 2px rgba(0,0,0,.05)" alt="preview">
              <input type="file" name="photo" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp" onchange="previewImg(this,'editPreview')">
              <div class="form-text">Leave empty to keep current photo</div>
            </div>
            <div class="col-md-9">
              <div class="row g-2">
                <div class="col-md-4"><label class="form-label small mb-1">Employee #</label><input type="text" name="employee_no" id="edit_employee_no" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">First Name</label><input type="text" name="first_name" id="edit_first_name" class="form-control form-control-sm" required></div>
                <div class="col-md-4"><label class="form-label small mb-1">Middle Name</label><input type="text" name="middle_name" id="edit_middle_name" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Last Name</label><input type="text" name="last_name" id="edit_last_name" class="form-control form-control-sm" required></div>
                <div class="col-md-4"><label class="form-label small mb-1">Gender</label>
                  <select name="gender" id="edit_gender" class="form-select form-select-sm"><option>Male</option><option>Female</option><option>Other</option></select>
                </div>
                <div class="col-md-4"><label class="form-label small mb-1">Birthdate</label><input type="date" name="birthdate" id="edit_birthdate" class="form-control form-control-sm"></div>
                <div class="col-md-6"><label class="form-label small mb-1">Email</label><input type="email" name="email" id="edit_email" class="form-control form-control-sm"></div>
                <div class="col-md-6"><label class="form-label small mb-1">Phone</label><input type="text" name="phone" id="edit_phone" class="form-control form-control-sm"></div>
                <div class="col-12"><label class="form-label small mb-1">Address</label><input type="text" name="address" id="edit_address" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Position</label><input type="text" name="position" id="edit_position" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Department</label><input type="text" name="department" id="edit_department" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Salary (₱)</label><input type="number" step="0.01" name="salary" id="edit_salary" class="form-control form-control-sm" value="0.00"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Date Hired</label><input type="date" name="date_hired" id="edit_date_hired" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Status</label>
                  <select name="status" id="edit_status" class="form-select form-select-sm"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </div>
                <div class="col-md-4"><label class="form-label small mb-1">FP Device UID</label><input type="text" name="fp_device_uid" id="edit_fp_device_uid" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Finger Index</label>
                  <select name="fp_index" id="edit_fp_index" class="form-select form-select-sm">
                    <option value="">—</option>
                    <option>Left Thumb</option><option>Left Index</option><option>Left Middle</option><option>Left Ring</option><option>Left Little</option>
                    <option>Right Thumb</option><option>Right Index</option><option>Right Middle</option><option>Right Ring</option><option>Right Little</option>
                  </select>
                </div>
                <div class="col-12"><label class="form-label small mb-1">Fingerprint Template</label><textarea name="fp_template" id="edit_fp_template" rows="3" class="form-control form-control-sm"></textarea></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-dark"><i class="fa-solid fa-save me-1"></i>Update</button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    // --- Preloader: same behavior as dashboard ---
    (function(){
      const hide = () => {
        const p = document.getElementById('preloader');
        if (p && !p.classList.contains('hidden')) {
          p.classList.add('hidden');
          document.body.classList.remove('content-hidden');
        }
      };
      document.addEventListener('DOMContentLoaded', ()=>{ setTimeout(hide, 150); });
      window.addEventListener('load', hide);
      setTimeout(hide, 3000); // safety fallback
    })();

    // Sidebar toggle + persistence (identical to dashboard)
    const body=document.body, mq=window.matchMedia('(max-width: 992px)');
    const menuTrigger=document.getElementById('menuTrigger'), backdrop=document.getElementById('backdrop');
    const LS_KEY='pm.sidebar';
    function applySavedSidebar(){
      try{
        const s=localStorage.getItem(LS_KEY);
        if(!mq.matches && s==='collapsed') body.classList.add('sidebar-collapsed');
      }catch(e){}
    }
    function saveSidebarState(){
      try{ localStorage.setItem(LS_KEY, body.classList.contains('sidebar-collapsed')?'collapsed':'open'); }catch(e){}
    }
    function toggleSidebar(){ if (mq.matches) body.classList.toggle('sidebar-open'); else { body.classList.toggle('sidebar-collapsed'); saveSidebarState(); } }
    applySavedSidebar();
    if (menuTrigger){ menuTrigger.addEventListener('click', toggleSidebar); menuTrigger.addEventListener('keydown', (e)=>{ if(e.key==='Enter'||e.key===' ') toggleSidebar(); }); }
    if (backdrop) backdrop.addEventListener('click', ()=> body.classList.remove('sidebar-open'));

    // Themed SweetAlert helper (exact copy)
    function themedSwal(opts){
      return Swal.fire({
        ...opts,
        iconColor:'#f5a425',
        customClass:{
          popup:'swal-theme-popup',
          title:'swal-theme-title',
          htmlContainer:'swal-theme-html',
          confirmButton:'swal-theme-confirm',
          cancelButton:'swal-theme-cancel'
        },
        buttonsStyling:false
      });
    }
    function confirmLogout(){
      themedSwal({
        title:'Logout?',
        text:'End your session securely.',
        icon:'question',
        showCancelButton:true,
        confirmButtonText:'Yes, logout',
        cancelButtonText:'Cancel'
      }).then(res=>{
        if(res.isConfirmed){
          themedSwal({ title:'Logging out...', text:'Please wait', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
          setTimeout(()=>{ window.location.href='../logout.php'; }, 800);
        }
      });
    }
    window.confirmLogout = confirmLogout;

    // Flash -> SweetAlert (dashboard style)
    (function(){
      const el=document.getElementById('flash'); if(!el) return;
      themedSwal({
        title: el.dataset.type==='success' ? 'Success' : 'Notice',
        html: el.dataset.msg || '',
        icon: el.dataset.type==='success' ? 'success' : 'info',
        confirmButtonText:'OK'
      });
    })();

    // Preview helper
    function previewImg(input, targetId){
      const file = input.files && input.files[0];
      if (!file) return;
      const url = URL.createObjectURL(file);
      document.getElementById(targetId).src = url;
    }

    // Search filter
    const searchBox = document.getElementById('searchBox');
    const table = document.getElementById('empTable');
    searchBox?.addEventListener('input', ()=>{
      const q = searchBox.value.toLowerCase();
      Array.from(table.tBodies[0].rows).forEach(tr=>{
        const text = tr.innerText.toLowerCase();
        tr.style.display = text.includes(q) ? '' : 'none';
      });
    });

    // Edit modal populate
    document.querySelectorAll('.btn-edit').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const d = btn.dataset;
        document.getElementById('edit_id').value = d.id || '';
        document.getElementById('edit_employee_no').value = d.employee_no || '';
        document.getElementById('edit_first_name').value = d.first_name || '';
        document.getElementById('edit_middle_name').value = d.middle_name || '';
        document.getElementById('edit_last_name').value = d.last_name || '';
        document.getElementById('edit_email').value = d.email || '';
        document.getElementById('edit_phone').value = d.phone || '';
        document.getElementById('edit_address').value = d.address || '';
        document.getElementById('edit_gender').value = d.gender || 'Male';
        document.getElementById('edit_birthdate').value = d.birthdate || '';
        document.getElementById('edit_position').value = d.position || '';
        document.getElementById('edit_department').value = d.department || '';
        document.getElementById('edit_salary').value = d.salary || '0.00';
        document.getElementById('edit_date_hired').value = d.date_hired || '';
        document.getElementById('edit_status').value = d.status || 'active';
        document.getElementById('edit_fp_device_uid').value = d.fp_device_uid || '';
        document.getElementById('edit_fp_index').value = d.fp_index || '';
        document.getElementById('edit_fp_template').value = d.fp_template || '';
        const ph = d.photo ? ('../'+d.photo) : ('https://ui-avatars.com/api/?name=' + encodeURIComponent((d.first_name||'')+' '+(d.last_name||'')) + '&background=ddd&color=555&size=128');
        document.getElementById('editPreview').src = ph;
      });
    });

    // Confirm-on-submit (delete)
    document.querySelectorAll('form.js-confirm').forEach(form=>{
      form.addEventListener('submit', (e)=>{
        e.preventDefault();
        const msg = form.dataset.message || 'Are you sure?';
        themedSwal({
          title:'Please confirm',
          text:msg,
          icon:'question',
          showCancelButton:true,
          confirmButtonText:'Yes, proceed',
          cancelButtonText:'Cancel'
        }).then(r=>{ if(r.isConfirmed) form.submit(); });
      });
    });
  </script>
</body>
</html>
