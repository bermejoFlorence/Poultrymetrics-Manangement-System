<?php
// /customer/profile.php
declare(strict_types=1);

$PAGE_TITLE = 'My Profile';
$CURRENT    = 'profile.php';

require_once __DIR__ . '/inc/common.php';
require_login($conn);
date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");

/* ---------------- Ensure schema ---------------- */
function ensure_customers_schema(mysqli $c): void {
  if (!function_exists('table_exists') || !function_exists('table_has_col')) return;

  if (!table_exists($c, 'customers')) {
    @$c->query("
      CREATE TABLE `customers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `name` VARCHAR(255) NULL,
        `first_name` VARCHAR(100) NULL,
        `middle_name` VARCHAR(100) NULL,
        `last_name` VARCHAR(100) NULL,
        `phone` VARCHAR(50) NULL,
        `address` VARCHAR(255) NULL,
        `address_line` VARCHAR(255) NULL,
        `barangay` VARCHAR(100) NULL,
        `city` VARCHAR(120) NULL,
        `province` VARCHAR(120) NULL,
        `postal_code` VARCHAR(20) NULL,
        `latitude` DECIMAL(10,6) NULL,
        `longitude` DECIMAL(10,6) NULL,
        `delivery_instructions` VARCHAR(500) NULL,
        `updated_at` DATETIME NULL,
        KEY (`user_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
  }
  $needCols = [
    'user_id' => "INT NOT NULL",
    'name' => "VARCHAR(255) NULL",
    'phone' => "VARCHAR(50) NULL",
    'address' => "VARCHAR(255) NULL",
    'barangay' => "VARCHAR(100) NULL",
    'city' => "VARCHAR(120) NULL",
    'province' => "VARCHAR(120) NULL",
    'postal_code' => "VARCHAR(20) NULL",
    'latitude' => "DECIMAL(10,6) NULL",
    'longitude' => "DECIMAL(10,6) NULL",
    'delivery_instructions' => "VARCHAR(500) NULL",
    'updated_at' => "DATETIME NULL",
  ];
  foreach ($needCols as $col => $ddl) {
    if (!table_has_col($c, 'customers', $col)) {
      @$c->query("ALTER TABLE `customers` ADD COLUMN `{$col}` {$ddl}");
    }
  }
  // Unique index to allow upsert by user_id
  $hasUnique = false;
  if ($res = @$c->query("SHOW INDEX FROM `customers` WHERE Key_name='uniq_user_id'")) {
    $hasUnique = (bool)$res->num_rows; $res->free();
  }
  if (!$hasUnique) {
    @ $c->query("ALTER TABLE `customers` ADD UNIQUE KEY `uniq_user_id` (`user_id`)");
  }
}

ensure_customers_schema($conn);

/* ---------------- Load current profile + fallbacks ---------------- */
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { http_response_code(403); die('Forbidden'); }

$profile = [
  'name' => '',
  'phone' => '',
  'address' => '',
  'barangay' => '',
  'city' => '',
  'province' => '',
  'postal_code' => '',
  'latitude' => '',
  'longitude' => '',
  'delivery_instructions' => '',
];

// 1) Try customers table
$sel = $conn->prepare("
  SELECT
    COALESCE(NULLIF(name,''), TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))) AS name,
    phone,
    COALESCE(NULLIF(address,''), address_line) AS address,
    barangay, city, province, postal_code,
    latitude, longitude, delivery_instructions
  FROM customers
  WHERE user_id=? LIMIT 1
");
if ($sel) {
  $sel->bind_param('i', $uid);
  $sel->execute();
  if ($r = $sel->get_result()->fetch_assoc()) {
    foreach ($profile as $k => $_) {
      if (array_key_exists($k, $r) && $r[$k] !== null) $profile[$k] = (string)$r[$k];
    }
  }
  $sel->close();
}

// 2) Fallbacks from users table/session if no customers row yet
if ($profile['name'] === '') {
  // Try users.username/email
  if (function_exists('current_user')) {
    $u = current_user($conn);
    if ($u) {
      $profile['name'] = (string)($u['username'] ?? ($u['email'] ?? ''));
    }
  }
  // Or session hint
  if ($profile['name'] === '' && !empty($_SESSION['customer_name'])) {
    $profile['name'] = (string)$_SESSION['customer_name'];
  }
}

/* ---------------- Handle POST (save) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(403); die('Bad CSRF'); }

  // sanitize
  $name   = mb_substr(trim((string)($_POST['name']   ?? '')), 0, 255);
  $phone  = mb_substr(trim((string)($_POST['phone']  ?? '')), 0, 50);
  $addr   = mb_substr(trim((string)($_POST['address']?? '')), 0, 255);
  $brgy   = mb_substr(trim((string)($_POST['barangay']?? '')), 0, 100);
  $city   = mb_substr(trim((string)($_POST['city']   ?? '')), 0, 120);
  $prov   = mb_substr(trim((string)($_POST['province']?? '')), 0, 120);
  $postal = mb_substr(trim((string)($_POST['postal_code'] ?? '')), 0, 20);
  $latStr = trim((string)($_POST['latitude']  ?? ''));
  $lonStr = trim((string)($_POST['longitude'] ?? ''));
  $notes  = mb_substr(trim((string)($_POST['delivery_instructions'] ?? '')), 0, 500);

  // Upsert with NULLIF for lat/lon
  $sql = "
    INSERT INTO customers
      (user_id, name, phone, address, barangay, city, province, postal_code, latitude, longitude, delivery_instructions, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, NOW())
    ON DUPLICATE KEY UPDATE
      name=VALUES(name),
      phone=VALUES(phone),
      address=VALUES(address),
      barangay=VALUES(barangay),
      city=VALUES(city),
      province=VALUES(province),
      postal_code=VALUES(postal_code),
      latitude=VALUES(latitude),
      longitude=VALUES(longitude),
      delivery_instructions=VALUES(delivery_instructions),
      updated_at=NOW()
  ";
  $st = $conn->prepare($sql);
  if (!$st) {
    flash_set('danger', 'Database error (prepare): '.$conn->error);
    header('Location: profile.php'); exit;
  }
  $ok = $st->bind_param('issssssssss',
    $uid, $name, $phone, $addr, $brgy, $city, $prov, $postal, $latStr, $lonStr, $notes
  );
  if (!$ok) {
    $st->close();
    flash_set('danger', 'Database error (bind): '.$conn->error);
    header('Location: profile.php'); exit;
  }
  $ok = $st->execute();
  $err = $ok ? null : $st->error;
  $st->close();

  if ($ok) {
    // reflect back into page defaults
    $profile = array_merge($profile, [
      'name'=>$name,'phone'=>$phone,'address'=>$addr,'barangay'=>$brgy,'city'=>$city,
      'province'=>$prov,'postal_code'=>$postal,'latitude'=>$latStr,'longitude'=>$lonStr,
      'delivery_instructions'=>$notes
    ]);
    flash_set('success','Profile saved.');
  } else {
    flash_set('danger','Could not save profile: '.$err);
  }
  header('Location: profile.php'); exit;
}

/* ---------------- Render ---------------- */
$uhead = function_exists('current_user') ? current_user($conn) : null;
include __DIR__ . '/inc/layout_head.php';
?>
<style>
  .small-muted{ color:#94a3b8 }
  #map { width:100%; height: 280px; border-radius: 12px; border:1px solid #e5e7eb; }
</style>

<?php if ($f = flash_pop()): ?>
  <div id="flash" data-type="<?= h($f['t']) ?>" data-msg="<?= h($f['m']) ?>"></div>
<?php endif; ?>

<div class="container-fluid py-2">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">My Profile</h5>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-body">
          <form method="post" id="profileForm">
            <?= csrf_field() ?>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" value="<?= h($profile['name']) ?>" maxlength="255">
              </div>
              <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= h($profile['phone']) ?>" maxlength="50">
              </div>

              <div class="col-12">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control" value="<?= h($profile['address']) ?>" maxlength="255" placeholder="Street / House no.">
              </div>

              <div class="col-md-6">
                <label class="form-label">Barangay</label>
                <input type="text" name="barangay" class="form-control" value="<?= h($profile['barangay']) ?>" maxlength="100">
              </div>
              <div class="col-md-6">
                <label class="form-label">City / Municipality</label>
                <input type="text" name="city" class="form-control" value="<?= h($profile['city']) ?>" maxlength="120">
              </div>

              <div class="col-md-6">
                <label class="form-label">Province</label>
                <input type="text" name="province" class="form-control" value="<?= h($profile['province']) ?>" maxlength="120">
              </div>
              <div class="col-md-6">
                <label class="form-label">Postal Code</label>
                <input type="text" name="postal_code" class="form-control" value="<?= h($profile['postal_code']) ?>" maxlength="20">
              </div>

              <div class="col-md-6">
                <label class="form-label">Latitude <span class="small-muted">(optional)</span></label>
                <div class="input-group">
                  <input type="text" id="latInput" name="latitude" class="form-control" value="<?= h($profile['latitude']) ?>" placeholder="e.g. 14.5995">
                  <button class="btn btn-outline-secondary" type="button" id="btnLocate"><i class="fa-solid fa-location-crosshairs"></i></button>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Longitude <span class="small-muted">(optional)</span></label>
                <input type="text" id="lonInput" name="longitude" class="form-control" value="<?= h($profile['longitude']) ?>" placeholder="e.g. 120.9842">
              </div>

              <div class="col-12">
                <label class="form-label">Delivery Instructions <span class="small-muted">(optional)</span></label>
                <input type="text" name="delivery_instructions" class="form-control" value="<?= h($profile['delivery_instructions']) ?>" maxlength="500" placeholder="Landmarks, notes">
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button class="btn btn-dark"><i class="fa fa-save me-1"></i> Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Map & preview -->
    <div class="col-lg-4">
      <div class="card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Map Preview</h6>
            <a id="openMapA" href="#" target="_blank" rel="noopener" class="small" style="display:none;">
              <i class="fa-solid fa-map-location-dot me-1"></i>Open map
            </a>
          </div>
          <div id="map" data-lat="<?= h($profile['latitude']) ?>" data-lon="<?= h($profile['longitude']) ?>"></div>
          <div class="small-muted mt-2">
            Tip: If you don’t know the exact coordinates, fill your address then click <em>Use my location</em> to pin your current spot.
          </div>
          <div class="mt-2">
            <a id="searchAddrA" class="small" target="_blank" rel="noopener" href="#">
              Search this address on OpenStreetMap
            </a>
          </div>
        </div>
      </div>

      <?php if ($uhead): ?>
      <div class="card mt-3">
        <div class="card-body">
          <h6 class="mb-2">Account</h6>
          <div class="small-muted">Username / Email</div>
          <div class="fw-semibold"><?= h($uhead['username'] ?? ($uhead['email'] ?? '')) ?></div>
          <div class="small text-muted mt-2">User ID: <?= (int)($uhead['id'] ?? $uid) ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Leaflet (OSM) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>

<script>
(function(){
  // SweetAlert flash (if any) via layout_foot helper already handles #flash

  const latEl = document.getElementById('latInput');
  const lonEl = document.getElementById('lonInput');
  const mapDiv = document.getElementById('map');
  const openMapA = document.getElementById('openMapA');
  const searchAddrA = document.getElementById('searchAddrA');

  // Build address string for search link
  function addrString(){
    const form = document.getElementById('profileForm');
    const parts = [
      (form.address?.value || '').trim(),
      (form.barangay?.value || '').trim(),
      (form.city?.value || '').trim(),
      (form.province?.value || '').trim(),
      (form.postal_code?.value || '').trim(),
      'Philippines'
    ].filter(Boolean);
    return parts.join(', ');
  }

  function updateSearchLink(){
    const q = addrString();
    searchAddrA.href = 'https://www.openstreetmap.org/search?query=' + encodeURIComponent(q);
  }

  updateSearchLink();
  document.getElementById('profileForm').addEventListener('input', function(e){
    if (['address','barangay','city','province','postal_code'].includes(e.target.name)) updateSearchLink();
  });

  // Map init
  let lat0 = parseFloat(mapDiv.dataset.lat);
  let lon0 = parseFloat(mapDiv.dataset.lon);
  let hasCoords = Number.isFinite(lat0) && Number.isFinite(lon0);

  const map = L.map('map', { zoomControl:true, scrollWheelZoom:false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution:'&copy; OpenStreetMap contributors'
  }).addTo(map);

  let marker = null;
  function setMarker(lat, lon, zoom){
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
    if (marker) marker.remove();
    marker = L.marker([lat, lon]).addTo(map);
    map.setView([lat, lon], zoom || 16);
    // Update "Open map" deep link
    openMapA.style.display = 'inline';
    openMapA.href = 'https://www.openstreetmap.org/?mlat='+encodeURIComponent(lat)+'&mlon='+encodeURIComponent(lon)+'#map=17/'+lat+'/'+lon;
  }

  if (hasCoords){
    setMarker(lat0, lon0, 16);
  } else {
    // Default view over PH
    map.setView([12.8797, 121.7740], 5);
    openMapA.style.display = 'none';
  }

  function tryUpdateFromInputs(){
    const la = parseFloat(latEl.value);
    const lo = parseFloat(lonEl.value);
    if (Number.isFinite(la) && Number.isFinite(lo)) {
      setMarker(la, lo, map.getZoom() < 10 ? 16 : map.getZoom());
    }
  }

  latEl.addEventListener('change', tryUpdateFromInputs);
  lonEl.addEventListener('change', tryUpdateFromInputs);
  latEl.addEventListener('blur', tryUpdateFromInputs);
  lonEl.addEventListener('blur', tryUpdateFromInputs);

  // Use my location (geolocation)
  const btnLocate = document.getElementById('btnLocate');
  btnLocate.addEventListener('click', function(){
    if (!navigator.geolocation) {
      alert('Geolocation is not supported by your browser.');
      return;
    }
    btnLocate.disabled = true;
    navigator.geolocation.getCurrentPosition(function(pos){
      const la = pos.coords.latitude;
      const lo = pos.coords.longitude;
      latEl.value = la.toFixed(6);
      lonEl.value = lo.toFixed(6);
      setMarker(la, lo, 17);
      btnLocate.disabled = false;
    }, function(err){
      alert('Could not get your location: ' + (err && err.message ? err.message : 'Unknown error'));
      btnLocate.disabled = false;
    }, { enableHighAccuracy:true, timeout:10000, maximumAge:0 });
  });
})();
</script>

<?php include __DIR__ . '/inc/layout_foot.php';
