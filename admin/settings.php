<?php
// /admin/settings.php
// PoultryMetrics — Settings (DB-backed Positions, compact UI, safe delete, apply rates)

require_once __DIR__ . '/inc/common.php'; // session, $conn, guards, layout helpers
@$conn->query("SET time_zone = '+08:00'");
mysqli_report(MYSQLI_REPORT_OFF);

$PAGE_TITLE = 'Settings';

/* -------------------- Small helpers -------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_has_col(mysqli $c, string $table, string $col): bool {
  $tbl = str_replace('`','``',$table);
  $like = $c->real_escape_string($col);
  $sql = "SHOW COLUMNS FROM `{$tbl}` LIKE '{$like}'";
  if (!$res = @$c->query($sql)) return false;
  $ok = ($res->num_rows > 0);
  $res->free();
  return $ok;
}

/* -------------------- Ensure settings + positions tables exist -------------------- */
$conn->query("
  CREATE TABLE IF NOT EXISTS system_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    skey VARCHAR(100) NOT NULL UNIQUE,
    svalue TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
  CREATE TABLE IF NOT EXISTS positions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* -------------------- Settings helpers -------------------- */
function s_get(string $key, $default=null) {
  global $conn;
  $st = $conn->prepare("SELECT svalue FROM system_settings WHERE skey=? LIMIT 1");
  if(!$st){ return $default; }
  $st->bind_param('s',$key); $st->execute();
  $res = $st->get_result(); $row = $res->fetch_assoc(); $st->close();
  if (!$row) return $default;
  $val = $row['svalue'];
  $j = json_decode($val, true);
  return (json_last_error()===JSON_ERROR_NONE ? $j : $val);
}
function s_set(string $key, $value): bool {
  global $conn;
  $val = (is_array($value) || is_object($value)) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
  $st = $conn->prepare("
    INSERT INTO system_settings (skey, svalue)
    VALUES (?,?)
    ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=CURRENT_TIMESTAMP
  ");
  if(!$st){ return false; }
  $st->bind_param('ss',$key,$val);
  $ok = $st->execute();
  $st->close();
  return $ok;
}

/* -------------------- CSRF + Flash -------------------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$t); }
function flash_set($t,$m){ $_SESSION['flash']=['t'=>$t,'m'=>$m]; }
function flash_pop(){ $f=$_SESSION['flash']??null; if($f) unset($_SESSION['flash']); return $f; }

/* -------------------- Load current settings -------------------- */
$general = s_get('general_settings', [
  'company_name' => 'PoultryMetrics',
  'address'      => '',
  'phone'        => '',
]);

$payroll = s_get('payroll_settings', [
  'standard_hours_per_day'   => 8,
  'overtime_rate_multiplier' => 1.25,
]);

$notifications = s_get('notification_settings', [
  'notify_orders'    => true,
  'notify_approvals' => true,
  'notify_lowstock'  => true,
  'feature_customers'=> true,
  'feature_feed'     => true,
  'feature_eggs'     => true,
]);

$timecfg = s_get('time_settings', [
  'time_format' => '12h',
  'late_cutoff' => '09:00',
]);

/* -------------------- Fetch positions from DB -------------------- */
$pos_rows = [];
$res = $conn->query("SELECT id, name, daily_rate, is_active FROM positions ORDER BY name ASC");
if ($res) { $pos_rows = $res->fetch_all(MYSQLI_ASSOC); $res->close(); }

/* -------------------- POST Handlers -------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF failed'); }
  $act = $_POST['action'] ?? '';

  try {
    if ($act === 'save_general') {
      $company = trim($_POST['company_name'] ?? '');
      $address = trim($_POST['address'] ?? '');
      $phone   = trim($_POST['phone'] ?? '');
      s_set('general_settings', compact('company','address','phone'));
      flash_set('success','General settings saved.');
    }

    elseif ($act === 'save_positions') {
      // Bulk upsert positions (NO nested delete forms here)
      $ids    = $_POST['pos_id']   ?? [];
      $names  = $_POST['pos_name'] ?? [];
      $rates  = $_POST['pos_rate'] ?? [];
      $acts   = $_POST['pos_active'] ?? [];

      $conn->begin_transaction();
      for ($i=0; $i<count($names); $i++){
        $id   = (int)($ids[$i] ?? 0);
        $name = trim((string)$names[$i]);
        if ($name==='') continue;
        $rate = (float)str_replace([','],'',$rates[$i] ?? 0);
        if ($rate < 0) $rate = 0;
        // Note: checkbox array keyed by id or '0' for new row
        $active = 0;
        if ($id > 0) { $active = isset($acts[$id]) ? 1 : 0; }
        else         { $active = isset($acts['0']) ? 1 : 0; }

        if ($id > 0) {
          $st = $conn->prepare("UPDATE positions SET name=?, daily_rate=?, is_active=? WHERE id=?");
          if (!$st) throw new Exception('Failed to update position. '.$conn->error);
          $st->bind_param('sdii',$name,$rate,$active,$id);
          if(!$st->execute()){
            if ((int)$conn->errno === 1062) throw new Exception('Duplicate position name: '.$name);
            throw new Exception('Update failed: '.$conn->error);
          }
          $st->close();
        } else {
          $st = $conn->prepare("INSERT INTO positions (name, daily_rate, is_active) VALUES (?,?,?)");
          if (!$st) throw new Exception('Failed to add position. '.$conn->error);
          $st->bind_param('sdi',$name,$rate,$active);
          if(!$st->execute()){
            if ((int)$conn->errno === 1062) throw new Exception('Duplicate position name: '.$name);
            throw new Exception('Insert failed: '.$conn->error);
          }
          $st->close();
        }
      }
      $conn->commit();
      flash_set('success','Positions saved.');
    }

    elseif ($act === 'delete_position') {
      // Standalone hidden form (NOT nested): safe even after Cancel
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new Exception('Invalid position.');
      $st = $conn->prepare("DELETE FROM positions WHERE id=?");
      if(!$st) throw new Exception('Delete failed. '.$conn->error);
      $st->bind_param('i',$id);
      $ok = $st->execute(); $err = $conn->error; $st->close();
      if ($ok) flash_set('success','Position deleted.');
      else throw new Exception('Delete failed. '.$err);
    }

    elseif ($act === 'save_payroll') {
      $std_hours = (int)($_POST['standard_hours_per_day'] ?? 8);
      if ($std_hours<=0) $std_hours = 8;
      $ot_mult   = (float)($_POST['overtime_rate_multiplier'] ?? 1.25);
      if ($ot_mult<=0) $ot_mult = 1.25;
      s_set('payroll_settings', [
        'standard_hours_per_day'=>$std_hours,
        'overtime_rate_multiplier'=>$ot_mult,
      ]);
      flash_set('success','Payroll settings saved.');
    }

    elseif ($act === 'save_notifications') {
      $notify_orders     = isset($_POST['notify_orders']);
      $notify_approvals  = isset($_POST['notify_approvals']);
      $notify_lowstock   = isset($_POST['notify_lowstock']);
      $feature_customers = isset($_POST['feature_customers']);
      $feature_feed      = isset($_POST['feature_feed']);
      $feature_eggs      = isset($_POST['feature_eggs']);
      s_set('notification_settings', compact(
        'notify_orders','notify_approvals','notify_lowstock',
        'feature_customers','feature_feed','feature_eggs'
      ));
      flash_set('success','Notification & module settings saved.');
    }

    elseif ($act === 'save_time') {
      $fmt = ($_POST['time_format'] ?? '12h') === '24h' ? '24h' : '12h';
      $cut = trim($_POST['late_cutoff'] ?? '09:00');
      if (!preg_match('/^\d{2}:\d{2}$/',$cut)) $cut = '09:00';
      s_set('time_settings', ['time_format'=>$fmt, 'late_cutoff'=>$cut]);
      flash_set('success','Time & attendance settings saved.');
    }

    elseif ($act === 'apply_pos_rates') {
      // Harden: verify columns, create employees.daily_rate if missing, show real SQL errors
      $EMP_TBL = 'employees';

      if (!table_has_col($conn, $EMP_TBL, 'position')) {
        throw new Exception('Cannot apply: column employees.position was not found.');
      }
      if (!table_has_col($conn, $EMP_TBL, 'daily_rate')) {
        if (!$conn->query("ALTER TABLE `employees` ADD COLUMN `daily_rate` DECIMAL(10,2) NULL DEFAULT NULL")) {
          throw new Exception('Failed to add employees.daily_rate: '.$conn->error);
        }
      }

      // Verify positions table shape (add is_active if missing for consistency)
      $chk = $conn->query("SHOW TABLES LIKE 'positions'");
      if (!$chk || $chk->num_rows===0) throw new Exception('Cannot apply: positions table not found.');
      if (!table_has_col($conn, 'positions', 'name') || !table_has_col($conn, 'positions', 'daily_rate')) {
        throw new Exception('Cannot apply: positions.name or positions.daily_rate missing.');
      }
      if (!table_has_col($conn, 'positions', 'is_active')) {
        $conn->query("ALTER TABLE `positions` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1");
      }

      // Do the update (trim both sides to avoid whitespace mismatches)
      $sql = "
        UPDATE `employees` e
        JOIN `positions` p
          ON TRIM(e.`position`) = TRIM(p.`name`)
        SET e.`daily_rate` = p.`daily_rate`
        WHERE p.`is_active` = 1
      ";
      if (!$conn->query($sql)) {
        throw new Exception('Failed to apply rates to employees: '.$conn->error);
      }

      $affected = $conn->affected_rows;
      flash_set('success', 'Applied position daily rates to employees. Rows updated: '.$affected);
    }

    else {
      throw new Exception('Unknown action.');
    }

  } catch (Throwable $e) {
    @ $conn->rollback();
    flash_set('error','Save failed: '.$e->getMessage());
  }

  header('Location: settings.php'); exit;
}

/* -------------------- View -------------------- */
include __DIR__ . '/inc/layout_head.php';
$f = flash_pop();
?>
<style>
  /* tighter spacing */
  .form-label.small { margin-bottom: .15rem; }
  .table td, .table th { vertical-align: middle; }
  .nowrap { white-space: nowrap; }
</style>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h6 class="fw-bold mb-0">Settings</h6>
  <form method="post" class="mb-0">
    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
    <input type="hidden" name="action" value="apply_pos_rates">
    <button class="btn btn-sm btn-outline-dark">Apply rates to employees</button>
  </form>
</div>

<?php if($f): ?>
  <div class="alert alert-<?php echo $f['t']==='success'?'success':'danger'; ?> py-2 mb-2"><?php echo h($f['m']); ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header border-0 pb-0">
    <ul class="nav nav-tabs card-header-tabs" id="stTabs" role="tablist">
      <li class="nav-item" role="presentation"><button class="nav-link active py-2 px-3" data-bs-toggle="tab" data-bs-target="#tabGeneral" type="button" role="tab">General</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link py-2 px-3" data-bs-toggle="tab" data-bs-target="#tabPositions" type="button" role="tab">Positions & Rates</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link py-2 px-3" data-bs-toggle="tab" data-bs-target="#tabPayroll" type="button" role="tab">Payroll</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link py-2 px-3" data-bs-toggle="tab" data-bs-target="#tabNotif" type="button" role="tab">Notifications</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link py-2 px-3" data-bs-toggle="tab" data-bs-target="#tabTime" type="button" role="tab">Time & Attendance</button></li>
    </ul>
  </div>

  <div class="card-body">
    <div class="tab-content">

      <!-- General -->
      <div class="tab-pane fade show active" id="tabGeneral" role="tabpanel">
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
          <input type="hidden" name="action" value="save_general">
          <div class="col-md-4">
            <label class="form-label small">Company</label>
            <input name="company_name" class="form-control form-control-sm" value="<?php echo h($general['company_name'] ?? ''); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small">Phone</label>
            <input name="phone" class="form-control form-control-sm" value="<?php echo h($general['phone'] ?? ''); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small">Address</label>
            <input name="address" class="form-control form-control-sm" value="<?php echo h($general['address'] ?? ''); ?>">
          </div>
          <div class="col-12 mt-1">
            <button class="btn btn-dark btn-sm">Save</button>
          </div>
        </form>
      </div>

      <!-- Positions & Rates (DB) -->
      <div class="tab-pane fade" id="tabPositions" role="tabpanel">
        <!-- ONE OUTER FORM ONLY (save); delete uses a hidden separate form below -->
        <form method="post" id="posForm" class="mb-0">
          <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
          <input type="hidden" name="action" value="save_positions">

          <div class="table-responsive">
            <table class="table table-sm align-middle mb-2" id="posTable">
              <thead class="table-light">
                <tr>
                  <th style="width:4%;">#</th>
                  <th style="width:42%;">Position</th>
                  <th style="width:18%;" class="text-end">Daily Rate (₱)</th>
                  <th style="width:18%;" class="text-end">Late / min (₱)</th>
                  <th style="width:12%;">Active</th>
                  <th style="width:6%;" class="text-end">—</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $stdMins = max(1, (int)($payroll['standard_hours_per_day'] ?? 8) * 60);
                $rownum=1;
                foreach($pos_rows as $p):
                  $perMin = ((float)$p['daily_rate'] > 0) ? ((float)$p['daily_rate'] / $stdMins) : 0;
                ?>
                <tr>
                  <td class="text-muted small"><?php echo $rownum++; ?></td>
                  <td>
                    <input type="hidden" name="pos_id[]" value="<?php echo (int)$p['id']; ?>">
                    <input name="pos_name[]" class="form-control form-control-sm" value="<?php echo h($p['name']); ?>" required>
                  </td>
                  <td>
                    <input name="pos_rate[]" class="form-control form-control-sm text-end pos-rate" value="<?php echo number_format((float)$p['daily_rate'],2,'.',''); ?>" inputmode="decimal" required>
                  </td>
                  <td>
                    <input class="form-control form-control-sm text-end pos-permin" value="<?php echo number_format($perMin, 4, '.', ''); ?>" readonly>
                  </td>
                  <td>
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" name="pos_active[<?php echo (int)$p['id']; ?>]" <?php echo ((int)$p['is_active']===1?'checked':''); ?>>
                    </div>
                  </td>
                  <td class="text-end">
                    <!-- Delete button (type=button) triggers hidden form confirm -->
                    <button type="button"
                            class="btn btn-outline-danger btn-sm btn-pos-del"
                            data-id="<?php echo (int)$p['id']; ?>"
                            data-name="<?php echo h($p['name']); ?>"
                            data-message="Delete this position?">
                      &times;
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>

                <!-- Blank template row for adding -->
                <tr>
                  <td class="text-muted small"><?php echo $rownum; ?></td>
                  <td>
                    <input type="hidden" name="pos_id[]" value="0">
                    <input name="pos_name[]" class="form-control form-control-sm" placeholder="e.g., New Position">
                  </td>
                  <td><input name="pos_rate[]" class="form-control form-control-sm text-end pos-rate" value="0.00" inputmode="decimal"></td>
                  <td><input class="form-control form-control-sm text-end pos-permin" value="0.0000" readonly></td>
                  <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="pos_active[0]" checked></div></td>
                  <td class="text-end">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="posAddRow">Add</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center">
            <div class="small text-muted">
              Late / min = Daily Rate ÷ (Standard Hours × 60). Current standard: <strong><?php echo (int)($payroll['standard_hours_per_day'] ?? 8); ?></strong> hr/day.
            </div>
            <button class="btn btn-dark btn-sm">Save</button>
          </div>
        </form>

        <!-- Hidden standalone DELETE form (prevents nested-form issues) -->
        <form method="post" id="posDeleteForm" class="d-none">
          <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
          <input type="hidden" name="action" value="delete_position">
          <input type="hidden" name="id" id="posDeleteId" value="">
        </form>
      </div>

      <!-- Payroll -->
      <div class="tab-pane fade" id="tabPayroll" role="tabpanel">
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
          <input type="hidden" name="action" value="save_payroll">

          <div class="col-md-3">
            <label class="form-label small">Standard Hours / Day</label>
            <input type="number" min="1" max="24" name="standard_hours_per_day" class="form-control form-control-sm"
                   value="<?php echo (int)($payroll['standard_hours_per_day'] ?? 8); ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label small">OT Rate Multiplier</label>
            <input type="number" step="0.01" min="1" name="overtime_rate_multiplier" class="form-control form-control-sm"
                   value="<?php echo h((string)($payroll['overtime_rate_multiplier'] ?? 1.25)); ?>">
          </div>

          <div class="col-12 mt-1"><button class="btn btn-dark btn-sm">Save</button></div>
        </form>
      </div>

      <!-- Notifications -->
      <div class="tab-pane fade" id="tabNotif" role="tabpanel">
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
          <input type="hidden" name="action" value="save_notifications">

          <div class="col-md-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="n1" name="notify_orders"    <?php echo !empty($notifications['notify_orders'])?'checked':''; ?>><label class="form-check-label small" for="n1">New customer orders</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="n2" name="notify_approvals" <?php echo !empty($notifications['notify_approvals'])?'checked':''; ?>><label class="form-check-label small" for="n2">Customer approvals</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="n3" name="notify_lowstock"  <?php echo !empty($notifications['notify_lowstock'])?'checked':''; ?>><label class="form-check-label small" for="n3">Feed low stock</label></div>
          </div>

          <div class="col-md-3">
            <label class="form-label small">Modules</label>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="m1" name="feature_customers" <?php echo !empty($notifications['feature_customers'])?'checked':''; ?>><label class="form-check-label small" for="m1">Customers</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="m2" name="feature_feed"      <?php echo !empty($notifications['feature_feed'])?'checked':''; ?>><label class="form-check-label small" for="m2">Feed</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="m3" name="feature_eggs"      <?php echo !empty($notifications['feature_eggs'])?'checked':''; ?>><label class="form-check-label small" for="m3">Eggs</label></div>
          </div>

          <div class="col-12 mt-1"><button class="btn btn-dark btn-sm">Save</button></div>
        </form>
      </div>

      <!-- Time & Attendance -->
      <div class="tab-pane fade" id="tabTime" role="tabpanel">
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
          <input type="hidden" name="action" value="save_time">

          <div class="col-md-3">
            <label class="form-label small">Time Format</label>
            <select name="time_format" class="form-select form-select-sm">
              <option value="12h" <?php echo ($timecfg['time_format'] ?? '12h')==='12h'?'selected':''; ?>>12-hour (01:23 PM)</option>
              <option value="24h" <?php echo ($timecfg['time_format'] ?? '12h')==='24h'?'selected':''; ?>>24-hour (13:23)</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label small">Late Cutoff (HH:MM)</label>
            <input name="late_cutoff" class="form-control form-control-sm" value="<?php echo h($timecfg['late_cutoff'] ?? '09:00'); ?>">
          </div>

          <div class="col-12 mt-1"><button class="btn btn-dark btn-sm">Save</button></div>
        </form>
      </div>

    </div>
  </div>
</div>

<script>
// Positions: compute per-minute on rate change, add row inline, safe delete via hidden form
(function(){
  const stdMins = <?php echo max(1, (int)($payroll['standard_hours_per_day'] ?? 8) * 60); ?>;
  const tbl = document.getElementById('posTable');
  const addBtn = document.getElementById('posAddRow');

  function computeRow(tr){
    const rateEl = tr.querySelector('.pos-rate');
    const perEl  = tr.querySelector('.pos-permin');
    if (!rateEl || !perEl) return;
    let rate = parseFloat((rateEl.value||'0').replace(/,/g,'')); if (isNaN(rate)) rate = 0;
    const perMin = (rate > 0) ? (rate / stdMins) : 0;
    perEl.value = perMin.toFixed(4);
  }

  tbl.addEventListener('input', (e)=>{
    if (e.target.classList.contains('pos-rate')) {
      computeRow(e.target.closest('tr'));
    }
  });

  addBtn?.addEventListener('click', ()=>{
    const tbody = tbl.querySelector('tbody');
    const idx = tbody.querySelectorAll('tr').length + 1;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="text-muted small">${idx}</td>
      <td>
        <input type="hidden" name="pos_id[]" value="0">
        <input name="pos_name[]" class="form-control form-control-sm" placeholder="e.g., New Position">
      </td>
      <td><input name="pos_rate[]" class="form-control form-control-sm text-end pos-rate" value="0.00" inputmode="decimal"></td>
      <td><input class="form-control form-control-sm text-end pos-permin" value="0.0000" readonly></td>
      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="pos_active[0]" checked></div></td>
      <td class="text-end">—</td>
    `;
    tbody.appendChild(tr);
  });

  // Safe delete: separate hidden form + confirm
  document.querySelectorAll('.btn-pos-del').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.getAttribute('data-id');
      const name = btn.getAttribute('data-name') || 'this position';
      const msg = btn.getAttribute('data-message') || 'Delete this position?';
      const ask = window.themedSwal
        ? themedSwal({title:'Please confirm', text:msg, icon:'warning', showCancelButton:true, confirmButtonText:'Delete', cancelButtonText:'Cancel'})
            .then(r=>r.isConfirmed)
        : Promise.resolve(confirm(msg + '\n\n' + name));
      ask.then(go=>{
        if (!go) return;
        const form = document.getElementById('posDeleteForm');
        document.getElementById('posDeleteId').value = id;
        form.submit(); // not nested; safe
      });
    });
  });

  // compute existing rows once
  tbl.querySelectorAll('tbody tr').forEach(computeRow);
})();
</script>

<?php include __DIR__ . '/inc/layout_foot.php';
