<?php
// register.php — Customer account + store/location (STRICT schema)
// - Uses hosting config.php for DB credentials
// - Detects users PK (user_id or id) and falls back if insert_id is 0
// - Creates/extends tables idempotently
// - Queues account_approvals = 'pending'

session_start();
require_once __DIR__.'/config.php';

/* ---------------- DB connect (via hosting config.php only) ---------------- */
mysqli_report(MYSQLI_REPORT_OFF);
if (!isset($conn) || !($conn instanceof mysqli)) {
  $port = defined('DB_PORT') ? DB_PORT : 3306;
  $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
  if ($conn->connect_errno) {
    http_response_code(500);
    die('DB connect error: '.$conn->connect_error);
  }
}
@$conn->set_charset('utf8mb4');
@$conn->query("SET time_zone = '+08:00'");

/* ---------------- Helpers ---------------- */
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tableExists(mysqli $c, string $n): bool {
  $e=$c->real_escape_string($n);
  $r=@$c->query("SHOW TABLES LIKE '$e'");
  return !!($r && $r->num_rows);
}
function ensureCol(mysqli $c, string $table, string $col, string $ddl){
  $tbl = $c->real_escape_string($table);
  $cl  = $c->real_escape_string($col);
  $dbRes = @$c->query("SELECT 1
                       FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = '{$tbl}'
                         AND COLUMN_NAME = '{$cl}'
                       LIMIT 1");
  $has = $dbRes && $dbRes->num_rows>0;
  if ($dbRes) $dbRes->close();
  if (!$has) { @$c->query("ALTER TABLE `{$tbl}` ADD COLUMN $ddl"); }
}
function users_pk(mysqli $c): string {
  $r = @$c->query("SHOW COLUMNS FROM `users` LIKE 'user_id'");
  if ($r && $r->num_rows) { $r->close(); return 'user_id'; }
  if ($r) $r->close();
  // default fallback
  return 'id';
}

/* ---------------- Ensure tables/columns (idempotent) ---------------- */
/* USERS (STRICT schema) */
$conn->query("
  CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(150) NOT NULL,
    `username` VARCHAR(60) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` VARCHAR(30) NOT NULL DEFAULT 'customer',
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `first_name` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100) NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(40) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uniq_users_email` (`email`),
    UNIQUE KEY `uniq_users_username` (`username`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
/* add missing columns if table existed already */
ensureCol($conn,'users','status',      " `status` VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER `role` ");
ensureCol($conn,'users','full_name',   " `full_name` VARCHAR(255) NOT NULL AFTER `last_name` ");
ensureCol($conn,'users','phone',       " `phone` VARCHAR(40) NULL AFTER `full_name` ");
ensureCol($conn,'users','is_active',   " `is_active` TINYINT(1) NOT NULL DEFAULT 0 AFTER `phone` ");
ensureCol($conn,'users','is_approved', " `is_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active` ");
ensureCol($conn,'users','created_at',  " `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_approved` ");
ensureCol($conn,'users','updated_at',  " `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at` ");

/* CUSTOMER PROFILES (STRICT schema) — create WITHOUT FK first (we’ll add FK dynamically) */
$conn->query("
  CREATE TABLE IF NOT EXISTS `customer_profiles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `customer_name` VARCHAR(160) NOT NULL,
    `phone` VARCHAR(40) NULL,
    `store_name` VARCHAR(160) NOT NULL,
    `store_type` VARCHAR(60) NULL,
    `address_line` VARCHAR(255) NULL,
    `city` VARCHAR(120) NULL,
    `province` VARCHAR(120) NULL,
    `postal_code` VARCHAR(20) NULL,
    `latitude` DECIMAL(10,7) NULL,
    `longitude` DECIMAL(10,7) NULL,
    `notes` TEXT NULL,
    `first_name` VARCHAR(100) NULL,
    `middle_name` VARCHAR(100) NULL,
    `last_name` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cp_user` (`user_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ACCOUNT APPROVALS (queue) */
$conn->query("
  CREATE TABLE IF NOT EXISTS `account_approvals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `note` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_appr_user` (`user_id`),
    KEY `idx_appr_status` (`status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Try to add FK on customer_profiles.user_id -> users.(user_id|id) (ignore errors) */
$usersPk = users_pk($conn);
@$conn->query("ALTER TABLE `customer_profiles` DROP FOREIGN KEY `fk_cp_user`");
@$conn->query("ALTER TABLE `customer_profiles`
  ADD CONSTRAINT `fk_cp_user` FOREIGN KEY (`user_id`)
  REFERENCES `users`(`$usersPk`) ON DELETE CASCADE");

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf_reg'])) $_SESSION['csrf_reg'] = bin2hex(random_bytes(32));
function csrf_ok($t){ return isset($_SESSION['csrf_reg']) && hash_equals($_SESSION['csrf_reg'], $t ?? ''); }

/* ---------------- POST handler ---------------- */
$flash = null; $flashType='success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) {
    $flash = "Security check failed. Please refresh and try again.";
    $flashType = 'danger';
  } else {
    // Account
    $username      = trim($_POST['username'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $password      = trim($_POST['password'] ?? '');

    // Full name in 3 parts
    $first_name    = trim($_POST['first_name'] ?? '');
    $middle_name   = trim($_POST['middle_name'] ?? '');
    $last_name     = trim($_POST['last_name'] ?? '');
    $full_name     = trim($first_name . ' ' . ($middle_name !== '' ? $middle_name . ' ' : '') . $last_name);

    // Store
    $store_name    = trim($_POST['store_name'] ?? '');
    $store_type    = trim($_POST['store_type'] ?? '');
    $address_line  = trim($_POST['address_line'] ?? '');
    $city          = trim($_POST['city'] ?? '');
    $province      = trim($_POST['province'] ?? '');
    $postal_code   = trim($_POST['postal_code'] ?? '');
    $latitude      = trim($_POST['latitude'] ?? '');
    $longitude     = trim($_POST['longitude'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    // ---- Validate ----
    if ($username==='' || $email==='' || $password==='' || $first_name==='' || $last_name==='' || $store_name==='') {
      $flash = "Please complete Username, Email, Password, First Name, Last Name, and Store Name.";
      $flashType='danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $flash = "Please provide a valid email address.";
      $flashType='danger';
    } elseif (strlen($password) < 8) {
      $flash = "Password must be at least 8 characters.";
      $flashType='danger';
    } else {
      // ---- Duplicate check (username/email)
      $dup = $conn->prepare("SELECT {$usersPk} FROM users WHERE email=? OR username=? LIMIT 1");
      $dup->bind_param('ss',$email,$username);
      $dup->execute(); $dup->store_result();
      $exists = $dup->num_rows>0; $dup->close();

      if ($exists){
        $flash="An account with the same username or email already exists.";
        $flashType='danger';
      } else {
        // ---------------- Transaction: users + profile + approval ----------------
        $conn->begin_transaction();
        try {
          // Users insert (STRICT columns)
          $hash = password_hash($password, PASSWORD_DEFAULT);
          $u = $conn->prepare("
            INSERT INTO users
              (email, username, password_hash, role, status,
               first_name, middle_name, last_name, full_name, phone,
               is_active, is_approved, created_at)
            VALUES
              (?, ?, ?, 'customer', 'pending',
               ?, ?, ?, ?, ?,
               0, 0, NOW())
          ");
          if (!$u) throw new Exception('Account insert prepare failed.');
          $u->bind_param('ssssssss',
            $email, $username, $hash,
            $first_name, $middle_name, $last_name, $full_name, $phone
          );
          if (!$u->execute()) throw new Exception('Account insert failed.');
          $userId = (int)$conn->insert_id;
          $u->close();

          // Fallback if insert_id is 0 (triggers/no AI or different PK)
          if ($userId <= 0) {
            $pk = users_pk($conn);
            $sel = $conn->prepare("SELECT `$pk` FROM users WHERE email=? LIMIT 1");
            if (!$sel) throw new Exception('Unable to obtain user id.');
            $sel->bind_param('s', $email);
            $sel->execute();
            $sel->bind_result($fetched);
            $sel->fetch();
            $sel->close();
            $userId = (int)$fetched;
          }
          if ($userId <= 0) throw new Exception('Could not obtain user id after insert.');

          // Customer profile insert (STRICT columns)
          $cp = $conn->prepare("
            INSERT INTO customer_profiles
              (user_id, customer_name, phone, store_name, store_type,
               address_line, city, province, postal_code, latitude, longitude,
               notes, first_name, middle_name, last_name, created_at)
            VALUES
              (?, ?, ?, ?, ?,
               ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''),
               ?, ?, ?, ?, NOW())
          ");
          if (!$cp) throw new Exception('Profile insert prepare failed.');
          $customer_name = $full_name;
          $cp->bind_param(
            'issssssssssssss',
            $userId, $customer_name, $phone, $store_name, $store_type,
            $address_line, $city, $province, $postal_code, $latitude, $longitude,
            $notes, $first_name, $middle_name, $last_name
          );
          if (!$cp->execute()) throw new Exception('Profile insert failed.');
          $cp->close();

          // Account approval queue
          $note = 'Self-registration: customer account';
          $a = $conn->prepare("INSERT INTO account_approvals (user_id, status, note, created_at) VALUES (?, 'pending', ?, NOW())");
          if (!$a) throw new Exception('Approval insert prepare failed.');
          $a->bind_param('is',$userId,$note);
          if (!$a->execute()) throw new Exception('Approval insert failed.');
          $a->close();

          $conn->commit();

          // (Optional) auto-login
          // $_SESSION['user_id'] = $userId;

          $flash = "Registration submitted. Your account is pending admin approval.";
          $flashType='success';
          echo "<script>setTimeout(()=>{window.location='login.php';},1800);</script>";
          $_POST=[];

        } catch (Throwable $e) {
          $conn->rollback();
          $flash = "We could not create your account. Please try again.";
          $flashType='danger';
        }
      }
    }
  }
}

/* ---------------- CSRF token for form ---------------- */
$csrf = $_SESSION['csrf_reg'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="Register a customer account at PoultryMetrics">
  <title>PoultryMetrics | Register (Customer)</title>

  <!-- CSS -->
  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/font-awesome.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <link href="https://fonts.googleapis.com/css?family=Poppins:400,500,600,700&display=swap" rel="stylesheet">
  <!-- Leaflet (OSM) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">

  <style>
    html, body { max-width:100%; overflow-x:hidden; }
    img { max-width:100%; height:auto; display:block; }
    .section.section-bg .cta-content{ text-align:center; color:#fff; padding:24px 16px; max-width:960px; margin:0 auto; }
    .section.section-bg .cta-content h2{ font-weight:800; line-height:1.15; font-size:clamp(1.5rem, 5vw, 2.5rem); margin-bottom:.5rem; }
    .section.section-bg .cta-content p{ font-size:clamp(.95rem, 2.6vw, 1.05rem); margin-bottom:1rem; }
    .form-section-title{ display:flex; align-items:center; gap:.5rem; font-weight:800; margin: 4px 0 10px; }
    .form-section-title .fa{ color:#f5a425; }
    .form-control, .form-select{ border-radius:10px; }
    .map-wrap{ border:1px solid #e9ecef; border-radius:12px; overflow:hidden; }
    #map{ width:100%; height:360px; }
    @media (max-width:575.98px){
      .section{ padding:48px 0; }
      #map{ height:300px; }
      .header-area .nav li a{ padding:14px 10px; }
    }
  </style>
</head>

<body>
  <!-- Preloader -->
  <div id="js-preloader" class="js-preloader">
    <div class="preloader-inner">
      <span class="dot"></span>
      <div class="dots"><span></span><span></span><span></span></div>
    </div>
  </div>

  <!-- Header -->
  <header class="header-area header-sticky">
    <div class="container">
      <div class="row"><div class="col-12">
        <nav class="main-nav">
          <a href="index.php" class="logo">Poultry<em>Metrics</em></a>
          <ul class="nav">
            <li><a href="index.php">Home</a></li>
            <li><a href="shop.php">Shop</a></li>
            <li><a href="about.html">About</a></li>
            <li><a href="contact.php">Contact</a></li>
            <li><a href="register.php" class="active">Register</a></li>
            <li><a href="login.php">Login</a></li>
          </ul>
          <a class='menu-trigger'><span>Menu</span></a>
        </nav>
      </div></div>
    </div>
  </header>

  <!-- Banner -->
  <section class="section section-bg" id="call-to-action" style="background-image:url(assets/images/banner-image-1-1920x500.jpg)">
    <div class="container">
      <div class="row"><div class="col-lg-10 offset-lg-1 text-center">
        <br><br>
        <div class="cta-content">
          <h2>Create <em>Customer Account</em></h2>
          <p>Register your account and set your shop/store location for deliveries.</p>
        </div>
      </div></div>
    </div>
  </section><br>

  <!-- Form -->
  <section class="section" id="register">
    <div class="container">
      <div class="row"><div class="col-12">
        <?php if ($flash): ?>
          <div class="alert alert-<?php echo esc($flashType); ?> text-center"><?php echo esc($flash); ?></div>
        <?php endif; ?>

        <form action="register.php" method="post" novalidate>
          <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">

          <!-- Account -->
          <div class="form-section-title mt-1"><i class="fa fa-user"></i> Account Details</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Username*</label>
              <input type="text" name="username" class="form-control" required minlength="3" maxlength="60" value="<?php echo esc($_POST['username'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email*</label>
              <input type="email" name="email" class="form-control" required maxlength="150" value="<?php echo esc($_POST['email'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control" value="<?php echo esc($_POST['phone'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Password* <small class="text-muted">(min 8)</small></label>
              <input type="password" name="password" class="form-control" required minlength="8">
            </div>
          </div>

          <hr>

          <!-- Customer Name (3 parts) -->
          <div class="form-section-title"><i class="fa fa-id-card"></i> Customer Name</div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">First Name*</label>
              <input type="text" name="first_name" class="form-control" required value="<?php echo esc($_POST['first_name'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Middle Name</label>
              <input type="text" name="middle_name" class="form-control" value="<?php echo esc($_POST['middle_name'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Last Name*</label>
              <input type="text" name="last_name" class="form-control" required value="<?php echo esc($_POST['last_name'] ?? ''); ?>">
            </div>
          </div>

          <hr>

          <!-- Customer & Store -->
          <div class="form-section-title"><i class="fa fa-store"></i> Store Details</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Store Name*</label>
              <input type="text" name="store_name" class="form-control" required value="<?php echo esc($_POST['store_name'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Store Type</label>
              <select name="store_type" class="form-select">
                <?php
                  $types = ['','Grocery','Sari-sari','Restaurant','Cafe','Bakery','Market Stall','Retail','Other'];
                  $cur = $_POST['store_type'] ?? '';
                  foreach($types as $t){
                    $label = $t==='' ? 'Select type…' : $t;
                    $sel = ($cur === $t) ? 'selected' : '';
                    echo "<option value=\"".esc($t)."\" $sel>".esc($label)."</option>";
                  }
                ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Address Line</label>
              <input type="text" name="address_line" class="form-control" placeholder="Street / Barangay" value="<?php echo esc($_POST['address_line'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">City/Municipality</label>
              <input type="text" name="city" class="form-control" value="<?php echo esc($_POST['city'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Province</label>
              <input type="text" name="province" class="form-control" value="<?php echo esc($_POST['province'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Postal Code</label>
              <input type="text" name="postal_code" class="form-control" value="<?php echo esc($_POST['postal_code'] ?? ''); ?>">
            </div>
          </div>

          <hr>

          <!-- Location -->
          <div class="form-section-title"><i class="fa fa-location-arrow"></i> Store Location</div>
          <div class="row g-3">
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="useMyLocation">
                <label class="form-check-label" for="useMyLocation">Use my current location</label>
              </div>
              <div class="mt-1 text-muted">
                <span>Lat: <strong id="latText">—</strong></span> |
                <span>Lng: <strong id="lngText">—</strong></span>
              </div>
              <input type="hidden" name="latitude" id="latitude" value="<?php echo esc($_POST['latitude'] ?? ''); ?>">
              <input type="hidden" name="longitude" id="longitude" value="<?php echo esc($_POST['longitude'] ?? ''); ?>">
            </div>
            <div class="col-12">
              <div class="map-wrap"><div id="map"></div></div>
              <small class="text-muted d-block mt-2">Tip: Toggle “Use my current location” or tap the map to drop/move the pin.</small>
            </div>
          </div>

          <div class="row g-3 mt-2">
            <div class="col-12">
              <label class="form-label">Notes (optional)</label>
              <textarea name="notes" rows="3" class="form-control" placeholder="Landmark, delivery instructions, etc."><?php echo esc($_POST['notes'] ?? ''); ?></textarea>
            </div>
          </div>

          <!-- Submit (keeps theme button shape) -->
          <div class="text-center mt-4">
            <div class="main-button d-inline-block">
              <a href="#" onclick="this.closest('form').requestSubmit(); return false;">Register</a>
            </div>
            <button type="submit" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">Register</button>
          </div>
        </form>
      </div></div>
    </div>
  </section>

  <!-- Footer -->
  <footer>
    <div class="container">
      <div class="row"><div class="col-lg-12 text-center">
        <p>&copy; <?php echo date('Y'); ?> PoultryMetrics</p>
      </div></div>
    </div>
  </footer>

  <!-- JS -->
  <script src="assets/js/jquery-2.1.0.min.js"></script>
  <script src="assets/js/popper.js"></script>
  <script src="assets/js/bootstrap.min.js"></script>
  <script src="assets/js/custom.js"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>

  <script>
  (function(){
    const mapEl = document.getElementById('map');
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const latText = document.getElementById('latText');
    const lngText = document.getElementById('lngText');
    const useMyLocation = document.getElementById('useMyLocation');

    let startLat = parseFloat(latInput.value) || 12.8797; // PH center
    let startLng = parseFloat(lngInput.value) || 121.7740;
    let startZoom = (latInput.value && lngInput.value) ? 13 : 5;

    const map = L.map(mapEl).setView([startLat, startLng], startZoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);

    let marker = null;
    function placeMarker(lat, lng){
      if (!marker){
        marker = L.marker([lat, lng], {draggable:true}).addTo(map);
        marker.on('dragend', function(e){
          const p = e.target.getLatLng();
          updateCoords(p.lat, p.lng);
        });
      } else {
        marker.setLatLng([lat, lng]);
      }
      updateCoords(lat, lng);
    }
    function updateCoords(lat, lng){
      latInput.value = Number(lat).toFixed(7);
      lngInput.value = Number(lng).toFixed(7);
      latText.textContent = latInput.value;
      lngText.textContent = lngInput.value;
    }

    if (latInput.value && lngInput.value){
      placeMarker(parseFloat(latInput.value), parseFloat(lngInput.value));
    }
    map.on('click', function(e){ placeMarker(e.latlng.lat, e.latlng.lng); });

    useMyLocation.addEventListener('change', function(){
      if (this.checked && navigator.geolocation){
        navigator.geolocation.getCurrentPosition(function(pos){
          const lat = pos.coords.latitude, lng = pos.coords.longitude;
          map.setView([lat, lng], 16); placeMarker(lat, lng);
        }, function(){
          alert('Unable to get your location. You can tap on the map instead.');
          useMyLocation.checked = false;
        }, {enableHighAccuracy:true, timeout:10000});
      }
    });
  })();

  // Preloader: don't intercept form submit anchors
  document.addEventListener("DOMContentLoaded", function () {
    const preloader = document.getElementById("js-preloader");
    function shouldIntercept(a){
      if (a.closest('.main-button') && a.closest('form')) return false; // let form submit
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
    window.addEventListener("load", function () { setTimeout(() => preloader.classList.add("loaded"), 200); });
  });
  </script>
</body>
</html>
