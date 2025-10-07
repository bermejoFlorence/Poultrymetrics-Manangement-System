<?php
// register.php — Customer account + store/location (STRICT schema + DEBUG)
// NOTES:
// - Set DEBUG_MODE to false when done.
// - Uses config.php for DB credentials
// - Detects users PK (user_id or id), fallback if insert_id is 0
// - Prints precise DB errors while DEBUG_MODE=true

session_start();
require_once __DIR__.'/config.php';

const DEBUG_MODE = true; // <-- set to false after fixing

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

// ------------- helpers -------------
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tableExists(mysqli $c, string $n): bool {
  $e=$c->real_escape_string($n);
  $r=@$c->query("SHOW TABLES LIKE '$e'");
  return !!($r && $r->num_rows);
}
function colExists(mysqli $c, string $t, string $col): bool {
  $tbl = $c->real_escape_string($t);
  $cl  = $c->real_escape_string($col);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$tbl}' AND COLUMN_NAME='{$cl}' LIMIT 1";
  $r = @$c->query($sql);
  $ok = ($r && $r->num_rows>0);
  if ($r) $r->close();
  return $ok;
}
function ensureCol(mysqli $c, string $table, string $col, string $ddl){
  if (!colExists($c,$table,$col)) { @$c->query("ALTER TABLE `{$table}` ADD COLUMN {$ddl}"); }
}
function users_pk(mysqli $c): string {
  $r = @$c->query("SHOW COLUMNS FROM `users` LIKE 'user_id'");
  if ($r && $r->num_rows) { $r->close(); return 'user_id'; }
  if ($r) $r->close();
  return 'id';
}
function dbg($msg){
  if (DEBUG_MODE) echo '<div style="background:#fff3cd;border:1px solid #ffeeba;padding:8px;border-radius:6px;margin:8px 0"><code>'.esc($msg).'</code></div>';
}

// ------------- schema checks (no silent fails) -------------
$problems = [];

// USERS table must exist with at least these columns:
if (!tableExists($conn,'users')) {
  $problems[] = "Table `users` does not exist.";
} else {
  $need = ['email','username','password_hash','role','status','first_name','last_name','full_name','is_active','is_approved','created_at'];
  foreach ($need as $col) if (!colExists($conn,'users',$col)) $problems[] = "Missing column users.$col";
}

// CUSTOMER PROFILES must exist with these columns:
if (!tableExists($conn,'customer_profiles')) {
  $problems[] = "Table `customer_profiles` does not exist.";
} else {
  $need = ['user_id','customer_name','store_name','first_name','last_name','created_at'];
  foreach ($need as $col) if (!colExists($conn,'customer_profiles',$col)) $problems[] = "Missing column customer_profiles.$col";
}

// ACCOUNT APPROVALS must exist with these:
if (!tableExists($conn,'account_approvals')) {
  $problems[] = "Table `account_approvals` does not exist.";
} else {
  $need = ['user_id','status','note','created_at'];
  foreach ($need as $col) if (!colExists($conn,'account_approvals',$col)) $problems[] = "Missing column account_approvals.$col";
}

// If we detect problems, show them clearly (so we know why insert fails)
if ($problems) {
  http_response_code(500);
  echo "<!doctype html><html><head><meta charset='utf-8'><title>Register | Schema Issues</title>
  <link rel='stylesheet' href='assets/css/bootstrap.min.css'></head><body class='p-3'>";
  echo "<div class='alert alert-danger'><strong>Cannot proceed:</strong> schema problems detected.</div>";
  echo "<ul class='list-group'>";
  foreach ($problems as $p) echo "<li class='list-group-item list-group-item-warning'>".esc($p)."</li>";
  echo "</ul>";
  if (DEBUG_MODE) {
    dbg("DB Error (if any): ".$conn->error);
  }
  echo "</body></html>";
  exit;
}

// Optional: try to attach FK (won’t error out page if fails)
$usersPk = users_pk($conn);
@$conn->query("ALTER TABLE `customer_profiles` DROP FOREIGN KEY `fk_cp_user`");
@$conn->query("ALTER TABLE `customer_profiles`
  ADD CONSTRAINT `fk_cp_user` FOREIGN KEY (`user_id`)
  REFERENCES `users`(`$usersPk`) ON DELETE CASCADE");

// ------------- CSRF -------------
if (empty($_SESSION['csrf_reg'])) $_SESSION['csrf_reg'] = bin2hex(random_bytes(32));
function csrf_ok($t){ return isset($_SESSION['csrf_reg']) && hash_equals($_SESSION['csrf_reg'], $t ?? ''); }

// ------------- POST handler -------------
$flash = null; $flashType='success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) {
    $flash = "Security check failed. Please refresh and try again.";
    $flashType = 'danger';
  } else {
    $username      = trim($_POST['username'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $password      = trim($_POST['password'] ?? '');

    $first_name    = trim($_POST['first_name'] ?? '');
    $middle_name   = trim($_POST['middle_name'] ?? '');
    $last_name     = trim($_POST['last_name'] ?? '');
    $full_name     = trim($first_name . ' ' . ($middle_name !== '' ? $middle_name . ' ' : '') . $last_name);

    $store_name    = trim($_POST['store_name'] ?? '');
    $store_type    = trim($_POST['store_type'] ?? '');
    $address_line  = trim($_POST['address_line'] ?? '');
    $city          = trim($_POST['city'] ?? '');
    $province      = trim($_POST['province'] ?? '');
    $postal_code   = trim($_POST['postal_code'] ?? '');
    $latitude      = trim($_POST['latitude'] ?? '');
    $longitude     = trim($_POST['longitude'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    // Validate
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
      // Duplicate check
      $pk = $usersPk;
      $dup = $conn->prepare("SELECT `$pk` FROM users WHERE email=? OR username=? LIMIT 1");
      if (!$dup) {
        $flash = "Duplicate check failed.";
        $flashType='danger';
        if (DEBUG_MODE) dbg("Prepare dup error: ".$conn->error);
      } else {
        $dup->bind_param('ss',$email,$username);
        if (!$dup->execute()) {
          $flash = "Duplicate check failed.";
          $flashType='danger';
          if (DEBUG_MODE) dbg("Exec dup error: ".$dup->error);
        } else {
          $dup->store_result();
          $exists = $dup->num_rows>0;
        }
        $dup->close();

        if (empty($flash)) {
          if (!empty($exists)) {
            $flash="An account with the same username or email already exists.";
            $flashType='danger';
          } else {
            // Transaction
            $conn->begin_transaction();
            try {
              // Users insert
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
              if (!$u) throw new Exception('Account insert prepare failed: '.$conn->error);
              $u->bind_param('ssssssss', $email, $username, $hash,
                $first_name, $middle_name, $last_name, $full_name, $phone);
              if (!$u->execute()) throw new Exception('Account insert failed: '.$u->error);
              $userId = (int)$conn->insert_id;
              $u->close();

              // Fallback if insert_id is 0
              if ($userId <= 0) {
                $pk2 = users_pk($conn);
                $sel = $conn->prepare("SELECT `$pk2` FROM users WHERE email=? LIMIT 1");
                if (!$sel) throw new Exception('Unable to obtain user id: '.$conn->error);
                $sel->bind_param('s',$email);
                $sel->execute();
                $sel->bind_result($fetched);
                $sel->fetch();
                $sel->close();
                $userId = (int)$fetched;
              }
              if ($userId <= 0) throw new Exception('Could not obtain user id after insert.');

              // Profile insert
              $customer_name = $full_name;
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
              if (!$cp) throw new Exception('Profile insert prepare failed: '.$conn->error);
              $cp->bind_param(
                'issssssssssssss',
                $userId, $customer_name, $phone, $store_name, $store_type,
                $address_line, $city, $province, $postal_code, $latitude, $longitude,
                $notes, $first_name, $middle_name, $last_name
              );
              if (!$cp->execute()) throw new Exception('Profile insert failed: '.$cp->error);
              $cp->close();

              // Approval queue
              $note = 'Self-registration: customer account';
              $a = $conn->prepare("INSERT INTO account_approvals (user_id, status, note, created_at) VALUES (?, 'pending', ?, NOW())");
              if (!$a) throw new Exception('Approval insert prepare failed: '.$conn->error);
              $a->bind_param('is',$userId,$note);
              if (!$a->execute()) throw new Exception('Approval insert failed: '.$a->error);
              $a->close();

              $conn->commit();

              // $_SESSION['user_id'] = $userId; // optional auto-login

              $flash = "Registration submitted. Your account is pending admin approval.";
              $flashType='success';
              echo "<script>setTimeout(()=>{window.location='login.php';},1800);</script>";
              $_POST=[];
            } catch (Throwable $e) {
              $conn->rollback();
              $flash = "We could not create your account. Please try again.";
              $flashType='danger';
              if (DEBUG_MODE) dbg("TX error: ".$e->getMessage());
            }
          }
        }
      }
    }
  }
}

// CSRF token
$csrf = $_SESSION['csrf_reg'];

// ------------- UI -------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PoultryMetrics | Register (Customer)</title>
  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/font-awesome.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <link href="https://fonts.googleapis.com/css?family=Poppins:400,500,600,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">
  <style>
    html, body { max-width:100%; overflow-x:hidden; }
    img { max-width:100%; height:auto; display:block; }
    .form-section-title{ display:flex; align-items:center; gap:.5rem; font-weight:800; margin: 4px 0 10px; }
    .form-section-title .fa{ color:#f5a425; }
    .form-control, .form-select{ border-radius:10px; }
    .map-wrap{ border:1px solid #e9ecef; border-radius:12px; overflow:hidden; }
    #map{ width:100%; height:360px; }
  </style>
</head>
<body>
  <div class="container py-4">
    <h2 class="mb-3">Create <em>Customer Account</em></h2>

    <?php if ($flash): ?>
      <div class="alert alert-<?php echo esc($flashType); ?> text-center"><?php echo esc($flash); ?></div>
    <?php endif; ?>

    <?php if (DEBUG_MODE): ?>
      <div class="alert alert-info small">
        <strong>DEBUG MODE ON:</strong> If registration fails, you’ll see DB details above. Turn this off after fixing.
      </div>
    <?php endif; ?>

    <form action="register.php" method="post" novalidate>
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">

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

      <div class="text-center mt-4">
        <button class="btn btn-primary px-4">Register</button>
      </div>
    </form>
  </div>

  <script src="assets/js/bootstrap.min.js"></script>
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
  </script>
</body>
</html>
