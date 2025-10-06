<?php
session_start();
include '../config.php';

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: ../login.php");
  exit();
}

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------- Inputs / Filters ----------
$per_page   = max(5, min(200, (int)($_GET['per_page'] ?? 20)));
$page       = max(1, (int)($_GET['page'] ?? 1));
$start_date = trim($_GET['start_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');
$product_id = (int)($_GET['product_id'] ?? 0);
$customer_id= (int)($_GET['customer_id'] ?? 0);
$export     = ($_GET['export'] ?? '') === 'csv';

// validate dates (YYYY-MM-DD)
$validDate = function($d){
  if ($d === '') return true;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};
if (!$validDate($start_date) || !$validDate($end_date)) {
  http_response_code(400);
  die('Invalid date format. Use YYYY-MM-DD');
}

// ---------- Options for filters ----------
$products = [];
if ($res = $conn->query("SELECT id, name FROM products ORDER BY name ASC")) {
  while ($row = $res->fetch_assoc()) $products[] = $row;
  $res->free();
}
$customers = [];
if ($res = $conn->query("SELECT id, username FROM users ORDER BY username ASC")) {
  while ($row = $res->fetch_assoc()) $customers[] = $row;
  $res->free();
}

// ---------- Build WHERE & params ----------
$where = [];
$params = [];
$types  = '';

if ($start_date !== '') { $where[] = "DATE(s.sale_date) >= ?"; $params[] = $start_date; $types .= 's'; }
if ($end_date   !== '') { $where[] = "DATE(s.sale_date) <= ?"; $params[] = $end_date;   $types .= 's'; }
if ($product_id > 0)    { $where[] = "s.product_id = ?";       $params[] = $product_id; $types .= 'i'; }
if ($customer_id > 0)   { $where[] = "s.customer_id = ?";      $params[] = $customer_id;$types .= 'i'; }

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---------- Count for pagination ----------
$total_rows = 0;
$sql_count = "SELECT COUNT(*) 
              FROM sales s
              JOIN products p ON s.product_id = p.id
              LEFT JOIN users u ON s.customer_id = u.id
              $where_sql";
if ($stmt = $conn->prepare($sql_count)) {
  if ($types) $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->bind_result($total_rows);
  $stmt->fetch();
  $stmt->close();
}
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$offset = ($page - 1) * $per_page;

// ---------- Totals (respecting filters) ----------
$tot_qty = 0; $tot_gross = 0.0;
$sql_total = "SELECT COALESCE(SUM(s.quantity),0), COALESCE(SUM(s.total),0)
              FROM sales s
              JOIN products p ON s.product_id = p.id
              LEFT JOIN users u ON s.customer_id = u.id
              $where_sql";
if ($stmt = $conn->prepare($sql_total)) {
  if ($types) $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->bind_result($tot_qty, $tot_gross);
  $stmt->fetch();
  $stmt->close();
}

// ---------- Fetch page of sales ----------
$sales = [];
$sql = "SELECT s.id, s.product_id, s.quantity, s.price, s.total, s.customer_id, s.sale_date,
               p.name AS product_name, u.username AS customer_name
        FROM sales s
        JOIN products p ON s.product_id = p.id
        LEFT JOIN users u ON s.customer_id = u.id
        $where_sql
        ORDER BY s.sale_date DESC, s.id DESC
        LIMIT ? OFFSET ?";
$params_with_page = $params;
$types_with_page  = $types . 'ii';
$params_with_page[] = $per_page;
$params_with_page[] = $offset;

if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param($types_with_page, ...$params_with_page);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) $sales[] = $row;
  $stmt->close();
}

// ---------- CSV export ----------
if ($export) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=sales_export_'.date('Ymd_His').'.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Product','Quantity','Price','Total','Customer','Sale Date']);
  // For export we want ALL rows that match filters, not just the current page
  $sql_exp = "SELECT p.name, s.quantity, s.price, s.total, COALESCE(u.username,'Guest') AS customer, s.sale_date
              FROM sales s
              JOIN products p ON s.product_id = p.id
              LEFT JOIN users u ON s.customer_id = u.id
              $where_sql
              ORDER BY s.sale_date DESC, s.id DESC";
  if ($stmt = $conn->prepare($sql_exp)) {
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($pname, $qty, $price, $total, $cust, $sdate);
    while ($stmt->fetch()) {
      fputcsv($out, [$pname, $qty, number_format((float)$price, 2, '.', ''), number_format((float)$total, 2, '.', ''), $cust, $sdate]);
    }
    $stmt->close();
  }
  fclose($out);
  exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sales | PoultryMetrix</title>
  <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* Preloader */
    #preloader { position: fixed; top:0; left:0; width:100%; height:100%; background:#111;
      display:flex; justify-content:center; align-items:center; z-index:9999; transition: opacity 0.5s ease; }
    #preloader.hidden { opacity: 0; visibility: hidden; }

    body.content-hidden main.content, body.content-hidden header.header-area, body.content-hidden nav.sidebar { opacity:0; transition: opacity 0.8s ease-in-out; }
    body main.content, body header.header-area, body nav.sidebar { opacity: 1; }

    body {font-family:'Poppins',sans-serif;background:#f8f9fa;overflow-x:hidden;}
    .header-area {background:#212529;padding:10px 15px;color:#fff;display:flex;justify-content:space-between;align-items:center;position:fixed;top:0;left:0;right:0;z-index:1000;}
    .header-area .logo {font-size:18px;font-weight:700;color:#f5a425;text-decoration:none;}
    .menu-trigger {display:none;cursor:pointer;color:#fff;font-size:20px;}
    .sidebar {position:fixed;top:0;left:0;height:100vh;width:200px;background:#212529;padding-top:60px;transition:transform 0.3s ease;z-index:999;overflow-y:auto;}
    .sidebar::-webkit-scrollbar{width:5px;}
    .sidebar::-webkit-scrollbar-thumb{background:#444;border-radius:10px;}
    .sidebar h4{color:#f5a425;font-weight:600;font-size:16px;padding-left:12px;}
    .sidebar a{color:#adb5bd;text-decoration:none;display:block;padding:8px 15px;font-size:14px;border-radius:5px;}
    .sidebar a.active,.sidebar a:hover{background:#343a40;color:#f5a425;}
    .content {margin-left:200px;padding:70px 15px 15px 15px;transition:margin-left 0.3s ease;}
    footer {padding:12px;background:#fff;text-align:center;border-top:1px solid #dee2e6;margin-top:20px;font-size:13px;}
    @media(max-width:768px){
      .menu-trigger{display:block;}
      .content{margin-left:0;padding-top:70px;}
      .sidebar.closed{transform:translateX(-210px);}
      .sidebar.open{transform:translateX(0);}
    }
    .filters .form-control, .filters .form-select { font-size: .9rem; }
    .pagination .page-link { border-radius: 8px; }
    tfoot td { font-weight: 700; background: #fff; position: sticky; bottom: 0; }
  </style>
</head>
<body class="content-hidden">

<!-- Preloader -->
<div id="preloader"><i class="fa fa-spinner fa-spin fa-3x" style="color:#f5a425;"></i></div>

<!-- Header -->
<header class="header-area">
  <a href="admin_dashboard.php" class="logo">Bocago Poultry Farm</a>
  <span class="menu-trigger"><i class="fa fa-bars"></i></span>
</header>

<!-- Sidebar -->
<nav class="sidebar closed" id="sidebarMenu">
  <div class="p-2">
    <h4 class="text-white mb-3">Poultry<em>Metrix</em></h4>
    <ul class="nav flex-column mb-2">
      <li><a href="admin_dashboard.php" class="nav-link"><i class="fa fa-home me-2"></i> Dashboard</a></li>
      <li><a href="egg_production.php" class="nav-link"><i class="fa fa-egg me-2"></i> Egg Production</a></li>
      <li><a href="feed_management.php" class="nav-link"><i class="fa fa-wheat-awn me-2"></i> Feed Management</a></li>
      <li><a href="employees.php" class="nav-link"><i class="fa fa-people-group me-2"></i> Employees</a></li>
      <li><a href="users.php" class="nav-link"><i class="fa fa-users me-2"></i> Users</a></li>
      <li><a href="products.php" class="nav-link"><i class="fa fa-box-open me-2"></i> Manage Products</a></li>
      <li><a href="inventory.php" class="nav-link"><i class="fa fa-boxes-stacked me-2"></i> Inventory</a></li>
      <li><a href="sales.php" class="nav-link active"><i class="fa fa-chart-line me-2"></i> Sales</a></li>
      <li><a href="#" onclick="confirmLogout()" class="nav-link"><i class="fa fa-right-from-bracket me-2"></i> Logout</a></li>
    </ul>
  </div>
</nav>

<!-- Main Content -->
<main class="content">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold">Sales Records</h4>
    <div class="d-flex align-items-center">
      <img src="https://via.placeholder.com/36" class="rounded-circle me-2" alt="user">
      <small class="fw-semibold text-dark"><?php echo e($_SESSION['username']); ?></small>
    </div>
  </div>

  <!-- Filters -->
  <form class="row g-2 mb-3 filters" method="GET">
    <div class="col-sm-6 col-md-2">
      <input type="date" name="start_date" value="<?php echo e($start_date); ?>" class="form-control" placeholder="Start date">
    </div>
    <div class="col-sm-6 col-md-2">
      <input type="date" name="end_date" value="<?php echo e($end_date); ?>" class="form-control" placeholder="End date">
    </div>
    <div class="col-sm-6 col-md-3">
      <select name="product_id" class="form-select">
        <option value="0">All products</option>
        <?php foreach ($products as $p): ?>
          <option value="<?php echo (int)$p['id']; ?>" <?php echo ($product_id===(int)$p['id'])?'selected':''; ?>>
            <?php echo e($p['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6 col-md-3">
      <select name="customer_id" class="form-select">
        <option value="0">All customers</option>
        <?php foreach ($customers as $u): ?>
          <option value="<?php echo (int)$u['id']; ?>" <?php echo ($customer_id===(int)$u['id'])?'selected':''; ?>>
            <?php echo e($u['username']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6 col-md-1">
      <select name="per_page" class="form-select">
        <?php foreach ([10,20,50,100,200] as $n): ?>
          <option value="<?php echo $n; ?>" <?php echo $per_page===$n?'selected':''; ?>><?php echo $n; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6 col-md-1 d-grid">
      <button class="btn btn-dark">Filter</button>
    </div>
    <div class="col-12 col-md-2 d-grid d-md-flex justify-content-md-end">
      <a class="btn btn-outline-primary w-100" href="?<?php
        $q = $_GET; $q['export']='csv'; echo e(http_build_query($q));
      ?>"><i class="fa fa-file-csv me-2"></i>Export CSV</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle text-center bg-white">
      <thead class="table-dark">
        <tr>
          <th>Product</th>
          <th>Quantity</th>
          <th>Price</th>
          <th>Total</th>
          <th>Customer</th>
          <th>Sale Date</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$sales): ?>
          <tr><td colspan="6" class="text-muted py-4">No sales match your filters.</td></tr>
        <?php else: ?>
          <?php foreach ($sales as $s): ?>
          <tr>
            <td class="text-start"><?php echo e($s['product_name']); ?></td>
            <td><?php echo (int)$s['quantity']; ?></td>
            <td>₱<?php echo number_format((float)$s['price'],2); ?></td>
            <td class="fw-semibold">₱<?php echo number_format((float)$s['total'],2); ?></td>
            <td><?php echo e($s['customer_name'] ?? 'Guest'); ?></td>
            <td><small><?php echo e($s['sale_date']); ?></small></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="text-end">Totals:</td>
          <td><?php echo (int)$tot_qty; ?></td>
          <td></td>
          <td>₱<?php echo number_format((float)$tot_gross,2); ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <nav aria-label="Page navigation">
    <ul class="pagination justify-content-center">
      <?php
        $q = $_GET;
        $renderPageLink = function($p, $label=null, $disabled=false, $active=false) use (&$q){
          $q['page'] = $p;
          $href = '?'.e(http_build_query($q));
          $label = $label ?? $p;
          $cls = 'page-item';
          if ($disabled) $cls .= ' disabled';
          if ($active) $cls .= ' active';
          echo '<li class="'.$cls.'"><a class="page-link" href="'.$href.'">'.$label.'</a></li>';
        };
        $renderPageLink(max(1,$page-1), '«', $page<=1);
        for ($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++){
          $renderPageLink($p, (string)$p, false, $p===$page);
        }
        $renderPageLink(min($total_pages,$page+1), '»', $page>=$total_pages);
      ?>
    </ul>
  </nav>
  <?php endif; ?>

  <!-- Footer -->
  <footer class="mt-3">
    <p class="mb-0">&copy; <?php echo date('Y'); ?> PoultryMetrix - Bocago Poultry Farm</p>
  </footer>
</main>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Preloader fade
window.addEventListener('load', () => {
  setTimeout(() => {
    document.getElementById('preloader').classList.add('hidden');
    document.body.classList.remove('content-hidden');
  }, 300);
});

// Burger toggle
document.querySelector('.menu-trigger')
  .addEventListener('click', () => document.getElementById('sidebarMenu').classList.toggle('open'));

// Logout with SweetAlert
function confirmLogout() {
  Swal.fire({
    title: 'Logout?',
    text: "Are you sure you want to logout?",
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#f5a425',
    cancelButtonColor: '#6c757d',
    confirmButtonText: 'Yes, logout'
  }).then((res) => {
    if (res.isConfirmed) {
      Swal.fire({ title: 'Logging out...', text: 'Please wait', showConfirmButton: false, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
      setTimeout(() => { window.location.href = '../logout.php'; }, 1200);
    }
  });
}
</script>
</body>
</html>
