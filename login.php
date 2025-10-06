<?php
session_start();
require_once __DIR__.'/config.php';

/* ---------- DB connect (prefer pmx_connect) ---------- */
if (!isset($conn) || !($conn instanceof mysqli)) {
  if (function_exists('pmx_connect')) {
    $conn = pmx_connect();
  } else {
    $port = defined('DB_PORT') ? DB_PORT : 3306;
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
    if ($conn instanceof mysqli) { @$conn->set_charset($charset); }
  }
}
@$conn->query("SET time_zone = '+08:00'");

/* ---------- Debug switch: /login.php?debug=1 ---------- */
$DEBUG = (isset($_GET['debug']) && $_GET['debug'] === '1');

/* ---------- Helpers ---------- */
function h($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function debug_msg($on, $msg){
  if ($on) {
    echo '<div style="max-width:760px;margin:1rem auto;padding:.75rem 1rem;border:1px solid #ddd;border-radius:8px;font-family:system-ui"><strong>DEBUG:</strong> '.h($msg).'</div>';
  }
}
function tableExists($c, $tbl){
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
  $st = $c->prepare($sql);
  if(!$st) return false;
  $st->bind_param('s', $tbl);
  $st->execute();
  $st->store_result();
  $ok = $st->num_rows > 0;
  $st->close();
  return $ok;
}
function colExists($c, $tbl, $col){
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
  $st = $c->prepare($sql);
  if(!$st) return false;
  $st->bind_param('ss', $tbl, $col);
  $st->execute();
  $st->store_result();
  $ok = $st->num_rows > 0;
  $st->close();
  return $ok;
}

/* Accept password_hash + upgrade legacy md5/plain */
function verify_and_upgrade_password($conn, $usersTable, $idCol, $uid, $input, $hash, $pwdCol){
  $hash = (string)$hash;
  if ($hash === '' || $hash === '0') return false;

  if (preg_match('/^\$2y\$/', $hash) || preg_match('/^\$argon2/i', $hash)) {
    return password_verify($input, $hash);
  }
  if (strlen($hash) === 32 && ctype_xdigit($hash)) {
    if (md5($input) === strtolower($hash)) {
      $new = password_hash($input, PASSWORD_BCRYPT);
      $st = $conn->prepare("UPDATE `$usersTable` SET `$pwdCol`=? WHERE `$idCol`=?");
      if ($st) { $st->bind_param('si', $new, $uid); $st->execute(); $st->close(); }
      return true;
    }
  }
  if ($input === $hash) {
    $new = password_hash($input, PASSWORD_BCRYPT);
    $st = $conn->prepare("UPDATE `$usersTable` SET `$pwdCol`=? WHERE `$idCol`=?");
    if ($st) { $st->bind_param('si', $new, $uid); $st->execute(); $st->close(); }
    return true;
  }
  return false;
}

/* ---------- Users schema detection ---------- */
$U_TBL = 'users';
if (!tableExists($conn, $U_TBL)) { http_response_code(500); die('Users table not found.'); }

$U_ID  = colExists($conn,$U_TBL,'user_id') ? 'user_id' : (colExists($conn,$U_TBL,'id') ? 'id' : 'user_id');

$HAVE_USERNAME = (colExists($conn,$U_TBL,'username') || colExists($conn,$U_TBL,'user_name'));
$U_USER = null;
if ($HAVE_USERNAME) {
  $U_USER = colExists($conn,$U_TBL,'username') ? 'username' : 'user_name';
}

$U_MAIL  = colExists($conn,$U_TBL,'email') ? 'email' : (colExists($conn,$U_TBL,'email_address') ? 'email_address' : 'email');
$U_PASSC = colExists($conn,$U_TBL,'password_hash') ? 'password_hash' : (colExists($conn,$U_TBL,'password') ? 'password' : 'password');
$U_ROLE  = colExists($conn,$U_TBL,'role') ? 'role' : (colExists($conn,$U_TBL,'user_role') ? 'user_role' : null);

$U_STATUS   = colExists($conn,$U_TBL,'status') ? 'status' : (colExists($conn,$U_TBL,'account_status') ? 'account_status' : null);
$U_ACTIVE   = null;
if (colExists($conn,$U_TBL,'is_active')) $U_ACTIVE = 'is_active';
elseif (colExists($conn,$U_TBL,'active')) $U_ACTIVE = 'active';
elseif (colExists($conn,$U_TBL,'enabled')) $U_ACTIVE = 'enabled';

$U_APPROVED = null;
if (colExists($conn,$U_TBL,'is_approved')) $U_APPROVED = 'is_approved';
elseif (colExists($conn,$U_TBL,'approved')) $U_APPROVED = 'approved';
elseif (colExists($conn,$U_TBL,'verified')) $U_APPROVED = 'verified';
elseif (colExists($conn,$U_TBL,'is_verified')) $U_APPROVED = 'is_verified';

/* Only 4 roles allowed */
$ROLE_ALLOW = array('admin','worker','accountant','customer');
$ROLE_MAP = array(
  'administrator' => 'admin',
  'superadmin'    => 'admin',
  'super-admin'   => 'admin',
  'staff'         => 'worker',
  'acct'          => 'accountant',
  'accounting'    => 'accountant'
);
function normalize_role_simple($r){
  $r = strtolower(trim((string)$r));
  $map = array(
    'administrator'=>'admin','superadmin'=>'admin','super-admin'=>'admin',
    'staff'=>'worker','acct'=>'accountant','accounting'=>'accountant'
  );
  if (isset($map[$r])) $r = $map[$r];
  $allow = array('admin','worker','accountant','customer');
  if (!in_array($r, $allow, true)) $r = 'customer';
  return $r;
}

/* ---------- Approvals fallback (optional) ---------- */
$APR_TBL = null; $APR_UID = null; $APR_STATUS = null; $APR_TIME = null; $APR_PK = 'id';
if (tableExists($conn,'account_approvals')) $APR_TBL = 'account_approvals';
elseif (tableExists($conn,'customer_approvals')) $APR_TBL = 'customer_approvals';
if ($APR_TBL){
  foreach (array('user_id','users_id','uid','customer_id') as $c) { if (colExists($conn,$APR_TBL,$c)) { $APR_UID=$c; break; } }
  foreach (array('status','state','approval_status') as $c) { if (colExists($conn,$APR_TBL,$c)) { $APR_STATUS=$c; break; } }
  if (colExists($conn,$APR_TBL,'submitted_at')) $APR_TIME='submitted_at';
  elseif (colExists($conn,$APR_TBL,'created_at')) $APR_TIME='created_at';
  if (colExists($conn,$APR_TBL,'id')) $APR_PK='id';
}

/* ---------- POST handler ---------- */
$msg = '';
$redirect = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $loginLabel = $HAVE_USERNAME ? 'Username or Email' : 'Email';
  $login    = isset($_POST['username']) ? trim($_POST['username']) : '';
  $password = isset($_POST['password']) ? trim($_POST['password']) : '';

  if ($login === '' || $password === '') {
    $msg = "Please enter your {$loginLabel} and password.";
  } else {
    $where = array();
    $types = '';
    $binds = array();

    if ($HAVE_USERNAME) {
      $where[] = "`$U_USER`=?";
      $types  .= 's';
      $binds[] = $login;
    }
    $where[] = "`$U_MAIL`=?";
    $types  .= 's';
    $binds[] = $login;

    $sql = "SELECT `$U_ID` AS id";
    if ($HAVE_USERNAME) { $sql .= ", `$U_USER` AS username"; }
    $sql .= ", `$U_MAIL` AS email, `$U_PASSC` AS pwd";
    if ($U_ROLE)     { $sql .= ", `$U_ROLE` AS role"; }
    if ($U_STATUS)   { $sql .= ", `$U_STATUS` AS u_status"; }
    if ($U_ACTIVE)   { $sql .= ", `$U_ACTIVE` AS u_active"; }
    if ($U_APPROVED) { $sql .= ", `$U_APPROVED` AS u_approved"; }
    $sql .= " FROM `$U_TBL` WHERE ".implode(' OR ', $where)." LIMIT 1";

    $st = $conn->prepare($sql);
    if (!$st) {
      $msg = "Login error. Please try again.";
      debug_msg($DEBUG, "Prepare failed: ".$conn->error);
    } else {
      // bind
      if (count($binds) === 2) { $st->bind_param($types, $binds[0], $binds[1]); }
      else { $st->bind_param($types, $binds[0]); }
      $st->execute();
      $res = $st->get_result();
      $user = $res ? $res->fetch_assoc() : null;
      $st->close();

      if (!$user) {
        $msg = "Invalid {$loginLabel} or password.";
        debug_msg($DEBUG, "No user for: ".$login);
      } else {
        $uid   = (int)$user['id'];
        $raw   = isset($user['role']) ? (string)$user['role'] : '';
        $role  = normalize_role_simple($raw);
        $pwdDb = isset($user['pwd']) ? (string)$user['pwd'] : '';

        $ok = false;
        if ($pwdDb !== '') {
          $ok = password_verify($password, $pwdDb);
          if (!$ok) {
            $ok = verify_and_upgrade_password($conn, $U_TBL, $U_ID, $uid, $password, $pwdDb, $U_PASSC);
          }
        }

        if (!$ok) {
          $msg = "Invalid {$loginLabel} or password.";
          debug_msg($DEBUG, "Password check failed (role=".$role.")");
        } else {
          if ($U_ROLE && $role !== strtolower(trim($raw))) {
            $st2 = $conn->prepare("UPDATE `$U_TBL` SET `$U_ROLE`=? WHERE `$U_ID`=?");
            if ($st2) { $st2->bind_param('si', $role, $uid); $st2->execute(); $st2->close(); }
          }

          if (!$U_ROLE) {
            $uNameHeur = isset($user['username']) ? (string)$user['username'] : '';
            $uMailHeur = (string)$user['email'];
            if (preg_match('~^admin\b~i', $uNameHeur) || preg_match('~^admin@~i', $uMailHeur)) {
              $role = 'admin';
            }
          }

          $isStaff  = in_array($role, array('admin','worker','accountant'), true);
          $approved = $isStaff;

          if (!$isStaff) {
            if ($U_STATUS && isset($user['u_status']) && $user['u_status']!=='') {
              $v = strtolower((string)$user['u_status']);
              $approved = in_array($v, array('active','approved','enabled'), true);
            } elseif ($U_ACTIVE && isset($user['u_active'])) {
              $approved = ((int)$user['u_active']) === 1;
            } elseif ($U_APPROVED && isset($user['u_approved'])) {
              $approved = ((int)$user['u_approved']) === 1;
            } elseif ($APR_TBL && $APR_UID && $APR_STATUS) {
              $order = $APR_TIME ? "`$APR_TIME` DESC" : "`$APR_PK` DESC";
              $q = "SELECT `$APR_STATUS` AS s FROM `$APR_TBL` WHERE `$APR_UID`=? ORDER BY $order LIMIT 1";
              $st2 = $conn->prepare($q);
              if ($st2){
                $st2->bind_param('i', $uid);
                $st2->execute();
                $rs = $st2->get_result();
                $row = $rs ? $rs->fetch_assoc() : null;
                $st2->close();
                $approved = ($row && strtolower((string)$row['s']) === 'approved');
              } else {
                $approved = false;
              }
            } else {
              $approved = false;
            }
          }

          if (!$approved) {
            $msg = "Your account is pending approval. Please wait for an administrator.";
          } else {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $uid;
            $_SESSION['username'] = (isset($user['username']) && $user['username']!=='') ? $user['username'] : $user['email'];
            $_SESSION['role']     = $role ? $role : ($isStaff ? 'admin' : 'customer');

            if ($_SESSION['role'] === 'admin')      { $redirect = "admin/admin_dashboard.php"; }
            elseif ($_SESSION['role'] === 'worker') { $redirect = "worker/worker_dashboard.php"; }
            elseif ($_SESSION['role'] === 'accountant') { $redirect = "accountant/accountant_dashboard.php"; }
            else { $redirect = "customer/customer_dashboard.php"; }

            if ($DEBUG) {
              debug_msg(true, "Login OK. Redirect to: ".$redirect);
            } else {
              header("Location: ".$redirect);
              exit;
            }
          }
        }
      }
    }
  }
}

/* ---------- UI ---------- */
$loginLabel = $HAVE_USERNAME ? 'Username or Email' : 'Email';
$loginPlaceholder = $loginLabel;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="Login to PoultryMetrix">
  <title>PoultryMetrix | Login</title>
  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/font-awesome.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <div id="js-preloader" class="js-preloader">
    <div class="preloader-inner">
      <span class="dot"></span>
      <div class="dots"><span></span><span></span><span></span></div>
    </div>
  </div>

  <header class="header-area header-sticky">
    <div class="container">
      <nav class="main-nav">
        <a href="index.php" class="logo">Poultry<em>Metrics</em></a>
        <ul class="nav">
          <li><a href="index.php">Home</a></li>
          <li><a href="shop.php">Shop</a></li>
          <li><a href="about.html">About</a></li>
          <li><a href="contact.php">Contact</a></li>
          <li><a href="register.php">Register</a></li>
          <li><a href="login.php" class="active">Login</a></li>
        </ul>
        <a class='menu-trigger'><span>Menu</span></a>
      </nav>
    </div>
  </header>

  <section class="section section-bg" id="call-to-action" style="background-image: url(assets/images/banner-image-1-1920x500.jpg)">
    <div class="container">
      <div class="row">
        <div class="col-lg-10 offset-lg-1">
          <br><br>
          <div class="cta-content text-center">
            <h2>User <em>Login</em></h2>
            <p>Access your account and manage your poultry business.</p>
          </div>
        </div>
      </div>
    </div>
  </section>
  <br><br>

  <section class="section" id="login">
    <div class="container">
      <div class="row">
        <div class="col-lg-6 offset-lg-3">
          <?php if (!empty($msg)): ?>
            <div class="alert alert-danger py-2"><?php echo h($msg); ?></div>
          <?php endif; ?>
          <?php if ($DEBUG): ?>
            <div class="alert alert-info py-2">Debug mode is ON. Remove <code>?debug=1</code> to turn off.</div>
          <?php endif; ?>
          <div class="contact-form">
            <form action="login.php<?php echo $DEBUG? '?debug=1':''; ?>" method="POST" novalidate>
              <div class="row">
                <div class="col-md-12">
                  <fieldset>
                    <input name="username" type="<?php echo $HAVE_USERNAME?'text':'email'; ?>" class="form-control"
                           placeholder="<?php echo h($loginPlaceholder); ?>" required autofocus>
                  </fieldset>
                </div>
                <div class="col-md-12">
                  <fieldset>
                    <input name="password" type="password" class="form-control" placeholder="Password" required>
                  </fieldset>
                </div>
                <div class="col-md-12 text-center">
                  <fieldset>
                    <button type="submit" class="main-button">Login</button>
                  </fieldset>
                </div>
              </div>
            </form>
            <p class="text-center mt-3">Don’t have an account? <a href="register.php">Register here</a></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <footer>
    <div class="container">
      <div class="row">
        <div class="col-lg-12 text-center">
          <p>Copyright © <?php echo date('Y'); ?> PoultryMetrix</p>
        </div>
      </div>
    </div>
  </footer>

  <script src="assets/js/jquery-2.1.0.min.js"></script>
  <script src="assets/js/popper.js"></script>
  <script src="assets/js/bootstrap.min.js"></script>
  <script src="assets/js/custom.js"></script>

  <!-- Preloader Logic (no intercept on form submit) -->
  <script>
  document.addEventListener("DOMContentLoaded", function () {
    const preloader = document.getElementById("js-preloader");
    function shouldIntercept(a){
      if (a.closest('form')) return false;
      const href = a.getAttribute("href");
      if (!href || href.startsWith("#") || href.startsWith("javascript:")) return false;
      try {
        const url = new URL(href, window.location.href);
        if (url.origin !== window.location.origin) return false;
      } catch(e){ return false; }
      return true;
    }
    document.body.addEventListener("click", function(e){
      const a = e.target.closest("a"); if (!a) return;
      if (a.target === "_blank" || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
      if (!shouldIntercept(a)) return;
      e.preventDefault();
      preloader.classList.remove("loaded");
      setTimeout(() => { window.location = a.href; }, 250);
    }, true);
    window.addEventListener("load", function () {
      setTimeout(() => preloader.classList.add("loaded"), 200);
    });
  });
  </script>
</body>
</html>
