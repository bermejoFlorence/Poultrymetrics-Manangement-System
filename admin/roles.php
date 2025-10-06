<?php
// /admin/roles.php
// PoultryMetrics – Admin > Roles & Permissions
// Uses inc/common.php + inc/layout_head.php + inc/layout_foot.php (consistent with dashboard)

$PAGE_TITLE = 'Roles & Permissions';
require_once __DIR__ . '/inc/common.php';

/* ---------- CSRF + flash ---------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
function csrf_valid($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t ?? ''); }
function flash_set($type,$msg){ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function flash_get(){ $x=$_SESSION['flash']??null; unset($_SESSION['flash']); return $x; }

/* ---------- Small helper: list tables via SHOW TABLES (no INFORMATION_SCHEMA) ---------- */
function listTablesViaShow(mysqli $c): array {
  $out=[]; if ($r=@$c->query("SHOW TABLES")) { while($row=$r->fetch_array(MYSQLI_NUM)){ $out[]=$row[0]; } $r->free(); }
  return $out;
}

/* ---------- Detect tables / columns ---------- */
$HAS_ROLES_TBL = tableExists($conn,'roles');
$HAS_PERMS_TBL = tableExists($conn,'permissions');

/* Roles columns (use 3-arg firstExistingCol; no extra defaults) */
$ROLE_ID_COL      = $HAS_ROLES_TBL ? firstExistingCol($conn,'roles',['id','role_id']) : null;
$ROLE_NAME_COL    = $HAS_ROLES_TBL ? firstExistingCol($conn,'roles',['name','role_name','slug']) : null;
$ROLE_DESC_COL    = $HAS_ROLES_TBL ? firstExistingCol($conn,'roles',['description','details']) : null;
$ROLE_CREATED_COL = $HAS_ROLES_TBL ? firstExistingCol($conn,'roles',['created_at','created','created_on','date_created']) : null;

/* Permissions columns */
$PERM_ID_COL   = $HAS_PERMS_TBL ? firstExistingCol($conn,'permissions',['id','permission_id']) : null;
$PERM_NAME_COL = $HAS_PERMS_TBL ? firstExistingCol($conn,'permissions',['name','code','key']) : null;
$PERM_MOD_COL  = $HAS_PERMS_TBL ? firstExistingCol($conn,'permissions',['module','group','category']) : null;
$PERM_DESC_COL = $HAS_PERMS_TBL ? firstExistingCol($conn,'permissions',['description','details']) : null;

/* Users table role column? */
$USERS_HAS_ROLE = tableExists($conn,'users') && colExists($conn,'users','role');

/* Role↔Permission join table (role_id + permission_id) */
$JOIN_TBL = null;
if ($HAS_ROLES_TBL && $HAS_PERMS_TBL){
  foreach (['role_permissions','roles_permissions','permission_role','role_permission','permissions_roles'] as $jt) {
    if (tableExists($conn,$jt) && colExists($conn,$jt,'role_id') && colExists($conn,$jt,'permission_id')) { $JOIN_TBL=$jt; break; }
  }
  if (!$JOIN_TBL){
    foreach (listTablesViaShow($conn) as $t) {
      if (colExists($conn,$t,'role_id') && colExists($conn,$t,'permission_id')) { $JOIN_TBL=$t; break; }
    }
  }
}

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_valid($_POST['csrf'] ?? '')) { http_response_code(400); die('CSRF validation failed'); }
  $action = $_POST['action'] ?? '';

  if ($action==='create_role') {
    if (!$HAS_ROLES_TBL || !$ROLE_NAME_COL) { flash_set('error','No roles table or role name column found.'); header('Location: roles.php'); exit(); }
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($name===''){ flash_set('error','Role name is required.'); header('Location: roles.php'); exit(); }

    $stmt=$conn->prepare("SELECT 1 FROM roles WHERE $ROLE_NAME_COL=? LIMIT 1");
    $stmt->bind_param('s',$name); $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows>0){ $stmt->close(); flash_set('error','Role name already exists.'); header('Location: roles.php'); exit(); }
    $stmt->close();

    $cols = [$ROLE_NAME_COL]; $vals=['?']; $types='s'; $binds=[$name];
    if ($ROLE_DESC_COL){ $cols[]=$ROLE_DESC_COL; $vals[]='?'; $types.='s'; $binds[]=$desc; }
    if ($ROLE_CREATED_COL){ $cols[]=$ROLE_CREATED_COL; $vals[]='NOW()'; }

    $sql = "INSERT INTO roles (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
    $stmt=$conn->prepare($sql); if (substr_count($sql,'?')>0){ $stmt->bind_param($types, ...$binds); }
    if ($stmt->execute()) flash_set('success','Role created.'); else flash_set('error','Create failed: '.$conn->error);
    $stmt->close(); header('Location: roles.php'); exit();

  } elseif ($action==='update_role') {
    if (!$HAS_ROLES_TBL || !$ROLE_NAME_COL || !$ROLE_ID_COL) { flash_set('error','Roles schema incomplete.'); header('Location: roles.php'); exit(); }
    $rid  = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($rid<=0 || $name===''){ flash_set('error','Invalid role data.'); header('Location: roles.php'); exit(); }

    $stmt=$conn->prepare("SELECT 1 FROM roles WHERE $ROLE_NAME_COL=? AND $ROLE_ID_COL<>? LIMIT 1");
    $stmt->bind_param('si',$name,$rid); $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows>0){ $stmt->close(); flash_set('error','Role name already taken.'); header('Location: roles.php'); exit(); }
    $stmt->close();

    $sets=[]; $types='si'; $binds=[ $name, $rid ]; $sets[]="$ROLE_NAME_COL=?";
    if ($ROLE_DESC_COL){ $sets[]="$ROLE_DESC_COL=?"; $types='ssi'; $binds=[ $name, $desc, $rid ]; }

    $sql = "UPDATE roles SET ".implode(', ',$sets)." WHERE $ROLE_ID_COL=?";
    $stmt=$conn->prepare($sql); $stmt->bind_param($types, ...$binds);
    if ($stmt->execute()) flash_set('success','Role updated.'); else flash_set('error','Update failed: '.$conn->error);
    $stmt->close(); header('Location: roles.php'); exit();

  } elseif ($action==='delete_role') {
    if (!$HAS_ROLES_TBL || !$ROLE_ID_COL) { flash_set('error','No roles table found.'); header('Location: roles.php'); exit(); }
    $rid = (int)($_POST['id'] ?? 0); if ($rid<=0){ flash_set('error','Invalid role.'); header('Location: roles.php'); exit(); }

    // Prevent deleting a role still assigned to users (if users.role exists)
    $roleName = '';
    if ($ROLE_NAME_COL){
      $q=$conn->prepare("SELECT $ROLE_NAME_COL AS n FROM roles WHERE $ROLE_ID_COL=? LIMIT 1");
      $q->bind_param('i',$rid); $q->execute(); $roleName=$q->get_result()->fetch_assoc()['n'] ?? ''; $q->close();
    }
    if ($USERS_HAS_ROLE && $roleName!==''){
      $esc = $conn->real_escape_string($roleName);
      $cnt = (int)scalar($conn, "SELECT COUNT(*) FROM users WHERE role='$esc'");
      if ($cnt>0){ flash_set('error','Cannot delete: users are assigned to this role.'); header('Location: roles.php'); exit(); }
    }

    if ($JOIN_TBL && tableExists($conn,$JOIN_TBL) && colExists($conn,$JOIN_TBL,'role_id')){
      $stmt=$conn->prepare("DELETE FROM `$JOIN_TBL` WHERE role_id=?"); $stmt->bind_param('i',$rid); $stmt->execute(); $stmt->close();
    }
    $stmt=$conn->prepare("DELETE FROM roles WHERE $ROLE_ID_COL=?"); $stmt->bind_param('i',$rid);
    if ($stmt->execute()) flash_set('success','Role deleted.'); else flash_set('error','Delete failed: '.$conn->error);
    $stmt->close(); header('Location: roles.php'); exit();

  } elseif ($action==='save_permissions') {
    if (!$HAS_ROLES_TBL || !$HAS_PERMS_TBL || !$JOIN_TBL) { flash_set('error','Permissions mapping is not supported by your schema.'); header('Location: roles.php'); exit(); }
    $rid = (int)($_POST['role_id'] ?? 0); $perm_ids = array_map('intval', $_POST['permission_ids'] ?? []);
    if ($rid<=0){ flash_set('error','Invalid role.'); header('Location: roles.php'); exit(); }

    $stmt=$conn->prepare("DELETE FROM `$JOIN_TBL` WHERE role_id=?"); $stmt->bind_param('i',$rid); $stmt->execute(); $stmt->close();
    if ($perm_ids){
      $stmt=$conn->prepare("INSERT INTO `$JOIN_TBL`(role_id,permission_id) VALUES(?,?)");
      foreach($perm_ids as $pid){ $stmt->bind_param('ii',$rid,$pid); $stmt->execute(); }
      $stmt->close();
    }
    flash_set('success','Permissions updated.'); header('Location: roles.php?role_id='.$rid); exit();
  }
}

/* ---------- Notifications for header (same pattern as dashboard) ---------- */
$notif_count = 0; $notifs = [];
if (tableExists($conn,'customer_orders')) {
  $pending_orders = (int)scalar($conn, "SELECT COUNT(*) FROM customer_orders WHERE status='pending'");
  if ($pending_orders>0){ $notif_count += $pending_orders; $notifs[] = ['label'=>"$pending_orders pending customer order(s)", 'url'=>'customer_orders.php?status=pending', 'icon'=>'fa-cart-shopping']; }
}
$approvals_tbl = tableExists($conn,'customer_approvals') ? 'customer_approvals' : (tableExists($conn,'account_approvals') ? 'account_approvals' : null);
if ($approvals_tbl){
  $pending_approvals = (int)scalar($conn, "SELECT COUNT(*) FROM {$approvals_tbl} WHERE status='pending'");
  if ($pending_approvals>0){ $notif_count += $pending_approvals; $notifs[] = ['label'=>"$pending_approvals customer approval request(s)", 'url'=>'customer_approvals.php?status=pending', 'icon'=>'fa-user-check']; }
}
if (tableExists($conn,'feed_batches') && colExists($conn,'feed_batches','expiry_date')) {
  $expiring = (int)scalar($conn, "SELECT COUNT(*) FROM feed_batches WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
  if ($expiring>0){ $notif_count += $expiring; $notifs[] = ['label'=>"$expiring feed batch(es) expiring soon", 'url'=>'inventory_feeds.php?filter=expiring', 'icon'=>'fa-wheat-awn']; }
}
if (tableExists($conn,'feed_items')) {
  $low_stock = (int)scalar($conn, "SELECT COUNT(*) FROM (
    SELECT i.id, COALESCE(SUM(b.qty_remaining_kg),0) on_hand, i.reorder_level_kg
    FROM feed_items i LEFT JOIN feed_batches b ON b.feed_item_id=i.id
    GROUP BY i.id
  ) x WHERE on_hand < reorder_level_kg");
  if ($low_stock>0){ $notif_count += $low_stock; $notifs[]=['label'=>"$low_stock feed item(s) in Low Stock", 'url'=>'feed_inventory.php', 'icon'=>'fa-clipboard-check']; }
}
if (tableExists($conn,'egg_collections') && colExists($conn,'egg_collections','mortality') && colExists($conn,'egg_collections','collect_date')) {
  $mortality_today = (int)scalar($conn,"SELECT COALESCE(SUM(mortality),0) FROM egg_collections WHERE collect_date=CURDATE()");
  if ($mortality_today>0){ $notif_count += 1; $notifs[]=['label'=>"$mortality_today mortality recorded today", 'url'=>'egg_reports.php?date='.date('Y-m-d'), 'icon'=>'fa-heart-crack']; }
}

/* ---------- Load data for UI ---------- */
$roles = []; $roleId = isset($_GET['role_id']) ? (int)$_GET['role_id'] : 0;
if ($HAS_ROLES_TBL && $ROLE_NAME_COL){
  // Build select columns safely
  $cols = [];
  if ($ROLE_ID_COL)      $cols[] = "$ROLE_ID_COL AS id";
  $cols[] = "$ROLE_NAME_COL AS name";
  if ($ROLE_DESC_COL)    $cols[] = "$ROLE_DESC_COL AS description";
  if ($ROLE_CREATED_COL) $cols[] = "$ROLE_CREATED_COL AS created_at";
  $sql = "SELECT ".implode(', ',$cols)." FROM roles ORDER BY name ASC";
  if ($res=$conn->query($sql)){ while($r=$res->fetch_assoc()){ $roles[]=$r; } $res->free(); }
  if ($roleId<=0 && $roles && isset($roles[0]['id'])) { $roleId = (int)$roles[0]['id']; }
}

/* Users by role count (if possible) */
$userCounts = [];
if ($USERS_HAS_ROLE){
  if ($HAS_ROLES_TBL && $ROLE_NAME_COL && $roles){
    foreach($roles as $r){
      $name = $conn->real_escape_string($r['name']);
      $userCounts[$r['name']] = (int)scalar($conn,"SELECT COUNT(*) FROM users WHERE role='$name'");
    }
  } else {
    if ($res=$conn->query("SELECT role, COUNT(*) c FROM users GROUP BY role")){
      while($r=$res->fetch_assoc()){ $userCounts[$r['role']] = (int)$r['c']; }
      $res->free();
    }
  }
}

/* Permissions (grouped) + current selections for chosen role */
$permissions = []; $currentRolePerms = [];
if ($HAS_PERMS_TBL && $PERM_ID_COL && $PERM_NAME_COL){
  $cols = "$PERM_ID_COL AS id, $PERM_NAME_COL AS name";
  if ($PERM_MOD_COL)  $cols .= ", $PERM_MOD_COL AS module";
  if ($PERM_DESC_COL) $cols .= ", $PERM_DESC_COL AS description";
  $sql = "SELECT $cols FROM permissions ORDER BY ".($PERM_MOD_COL ? "module ASC, name ASC" : "name ASC");
  if ($res=$conn->query($sql)){
    while($r=$res->fetch_assoc()){
      $group = trim($r['module'] ?? '') ?: 'General';
      $permissions[$group][] = $r;
    }
    $res->free();
  }
  if ($JOIN_TBL && $roleId>0){
    $stmt=$conn->prepare("SELECT permission_id FROM `$JOIN_TBL` WHERE role_id=?");
    $stmt->bind_param('i',$roleId); $stmt->execute();
    $rs=$stmt->get_result(); while($row=$rs->fetch_assoc()){ $currentRolePerms[(int)$row['permission_id']] = true; } $stmt->close();
  }
}

$flash = flash_get();

/* ---------- Layout head (consistent header/sidebar) ---------- */
$CURRENT = 'roles.php';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="fw-bold mb-0">Roles & Permissions</h5>
  <div class="d-flex align-items-center gap-2">
    <?php if ($HAS_ROLES_TBL && $ROLE_NAME_COL): ?>
      <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#createRoleModal">
        <i class="fa fa-plus me-1"></i> New Role
      </button>
    <?php endif; ?>
  </div>
</div>

<?php if (!$HAS_ROLES_TBL || !$ROLE_NAME_COL): ?>
  <div class="alert alert-warning d-flex align-items-start">
    <i class="fa fa-triangle-exclamation me-2 mt-1"></i>
    <div>
      <strong>No usable <code>roles</code> table detected.</strong>
      <div class="small text-muted">You can still view roles currently used by users (from <code>users.role</code>), but full role management requires a <code>roles</code> table with a name column.</div>
    </div>
  </div>
<?php endif; ?>

<div class="row g-2">
  <!-- Roles List -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="small fw-bold">Roles</span>
        <?php if ($HAS_ROLES_TBL && $ROLE_NAME_COL): ?>
          <span class="badge text-bg-light"><?php echo count($roles); ?></span>
        <?php else: ?>
          <span class="badge text-bg-light">users.role</span>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php if ($HAS_ROLES_TBL && $ROLE_NAME_COL && $roles): foreach($roles as $r): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center <?php echo (isset($r['id']) && $roleId===(int)$r['id'])?'active':''; ?>">
              <div class="d-flex align-items-center gap-2">
                <a href="<?php echo isset($r['id']) ? ('?role_id='.(int)$r['id']) : '#'; ?>" class="<?php echo (isset($r['id']) && $roleId===(int)$r['id'])?'text-white':'text-dark'; ?> text-decoration-none">
                  <i class="fa fa-user-shield me-1"></i><?php echo h($r['name']); ?>
                </a>
                <?php if ($USERS_HAS_ROLE): ?>
                  <span class="badge text-bg-secondary"><?php echo (int)($userCounts[$r['name']] ?? 0); ?></span>
                <?php endif; ?>
              </div>
              <div class="d-flex align-items-center gap-2">
                <?php if ($ROLE_ID_COL): ?>
                <button class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="modal" data-bs-target="#editRoleModal"
                        data-id="<?php echo (int)($r['id'] ?? 0); ?>"
                        data-name="<?php echo h($r['name']); ?>"
                        data-desc="<?php echo h($r['description'] ?? ''); ?>">
                  <i class="fa fa-pen"></i>
                </button>
                <form method="post" action="roles.php" class="d-inline js-confirm" data-message="Delete role '<?php echo h($r['name']); ?>'?">
                  <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
                  <input type="hidden" name="action" value="delete_role">
                  <input type="hidden" name="id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                </form>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; else: ?>
            <?php if ($USERS_HAS_ROLE): ?>
              <?php $distinct=[]; if($res=$conn->query("SELECT role, COUNT(*) c FROM users GROUP BY role ORDER BY role ASC")){ while($x=$res->fetch_assoc()){ $distinct[]=$x; } $res->free(); } ?>
              <?php foreach($distinct as $dr): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <span><i class="fa fa-tag me-1"></i><?php echo h($dr['role']); ?></span>
                  <span class="badge text-bg-secondary"><?php echo (int)$dr['c']; ?></span>
                </li>
              <?php endforeach; ?>
              <?php if (!$distinct): ?><li class="list-group-item text-muted">No roles found in users.</li><?php endif; ?>
            <?php else: ?>
              <li class="list-group-item text-muted">Neither <code>roles</code> table nor <code>users.role</code> column exists.</li>
            <?php endif; ?>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <!-- Permissions Panel -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span class="small fw-bold">Permissions</span>
        <?php if ($HAS_PERMS_TBL && $JOIN_TBL && $HAS_ROLES_TBL && $ROLE_ID_COL && $roleId>0): ?>
          <span class="badge text-bg-light">role #<?php echo (int)$roleId; ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$HAS_PERMS_TBL): ?>
          <div class="alert alert-info"><i class="fa fa-circle-info me-1"></i>No <code>permissions</code> table detected. You can still manage role names on the left.</div>
        <?php elseif (!$JOIN_TBL || !$HAS_ROLES_TBL || !$ROLE_ID_COL): ?>
          <div class="alert alert-info"><i class="fa fa-circle-info me-1"></i>Permissions exist, but no link table (<code>role_id</code> + <code>permission_id</code>) or missing <code>roles</code> id/name columns. Create a join table (e.g., <code>role_permissions(role_id INT, permission_id INT)</code>) to enable mapping.</div>
          <div class="table-responsive">
            <table class="table">
              <thead><tr><th>#</th><th>Permission</th><th>Module</th><th>Description</th></tr></thead>
              <tbody>
                <?php $idx=1; foreach($permissions as $group=>$perms): foreach($perms as $p): ?>
                  <tr>
                    <td><?php echo $idx++; ?></td>
                    <td><?php echo h($p['name']); ?></td>
                    <td><?php echo h($group); ?></td>
                    <td class="small text-muted"><?php echo h($p['description'] ?? ''); ?></td>
                  </tr>
                <?php endforeach; endforeach; ?>
                <?php if (!$permissions): ?>
                  <tr><td colspan="4" class="text-muted text-center">No permissions found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <form method="post" action="roles.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="role_id" value="<?php echo (int)$roleId; ?>">

            <?php if ($roleId<=0): ?>
              <div class="alert alert-warning">Select a role on the left to edit its permissions.</div>
            <?php else: ?>
              <div class="row g-2">
                <?php foreach($permissions as $group=>$perms): ?>
                  <div class="col-md-6">
                    <div class="border rounded-3 p-2">
                      <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fa fa-layer-group me-1"></i><?php echo h($group); ?></h6>
                        <div class="btn-group btn-group-sm">
                          <button class="btn btn-outline-secondary" type="button" onclick="permGroupToggle(this,true)">All</button>
                          <button class="btn btn-outline-secondary" type="button" onclick="permGroupToggle(this,false)">None</button>
                        </div>
                      </div>
                      <hr class="my-2">
                      <?php foreach($perms as $p): $checked = isset($currentRolePerms[(int)$p['id']]); ?>
                        <div class="form-check mb-1">
                          <input class="form-check-input" type="checkbox" name="permission_ids[]" value="<?php echo (int)$p['id']; ?>" id="perm<?php echo (int)$p['id']; ?>" <?php echo $checked?'checked':''; ?>>
                          <label class="form-check-label" for="perm<?php echo (int)$p['id']; ?>">
                            <?php echo h($p['name']); ?>
                            <?php if (!empty($p['description'])): ?><span class="text-muted small">– <?php echo h($p['description']); ?></span><?php endif; ?>
                          </label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if (!$permissions): ?>
                  <div class="col-12"><div class="alert alert-secondary">No permissions defined.</div></div>
                <?php endif; ?>
              </div>

              <div class="mt-3 d-flex justify-content-end gap-2">
                <button class="btn btn-outline-secondary" type="button" onclick="toggleAllPerms(true)">Select All</button>
                <button class="btn btn-outline-secondary" type="button" onclick="toggleAllPerms(false)">Unselect All</button>
                <button class="btn btn-dark" type="submit"><i class="fa fa-save me-1"></i> Save Changes</button>
              </div>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Create Role Modal -->
<?php if ($HAS_ROLES_TBL && $ROLE_NAME_COL): ?>
<div class="modal fade" id="createRoleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="roles.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
      <input type="hidden" name="action" value="create_role">
      <div class="modal-header"><h6 class="modal-title">New Role</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label small">Role name</label><input name="name" class="form-control" required placeholder="e.g., manager"></div>
        <div class="mb-2"><label class="form-label small">Description</label><textarea name="description" class="form-control" rows="2" placeholder="What can this role do?"></textarea></div>
      </div>
      <div class="modal-footer"><button class="btn btn-dark">Create</button></div>
    </form>
  </div>
</div>

<!-- Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="roles.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
      <input type="hidden" name="action" value="update_role">
      <input type="hidden" name="id" id="edit_role_id">
      <div class="modal-header"><h6 class="modal-title">Edit Role</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label small">Role name</label><input id="edit_role_name" name="name" class="form-control" required></div>
        <div class="mb-2"><label class="form-label small">Description</label><textarea id="edit_role_desc" name="description" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer"><button class="btn btn-dark">Save</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
/* ---------- Page-specific JS ---------- */
$PAGE_FOOT_SCRIPTS = "<script>
  // Prefill Edit Role modal
  document.getElementById('editRoleModal')?.addEventListener('show.bs.modal', (ev)=>{
    const btn = ev.relatedTarget; if(!btn) return;
    document.getElementById('edit_role_id').value   = btn.dataset.id||'';
    document.getElementById('edit_role_name').value = btn.dataset.name||'';
    document.getElementById('edit_role_desc').value = btn.dataset.desc||'';
  });

  // Confirm helper (uses themedSwal from layout_foot)
  document.querySelectorAll('form.js-confirm').forEach(form=>{
    form.addEventListener('submit',(e)=>{
      e.preventDefault();
      const msg=form.dataset.message||'Are you sure?';
      themedSwal({ title:'Please confirm', text:msg, icon:'question', showCancelButton:true, confirmButtonText:'Yes, proceed', cancelButtonText:'Cancel' })
        .then(r=>{ if(r.isConfirmed) form.submit(); });
    });
  });

  // Permission toggles
  function toggleAllPerms(state){ document.querySelectorAll('input[name=\"permission_ids[]\"]').forEach(cb=>cb.checked=state); }
  function permGroupToggle(el,state){ const g=el.closest('.border'); if(!g) return; g.querySelectorAll('input[name=\"permission_ids[]\"]').forEach(cb=>cb.checked=state); }
  window.toggleAllPerms = toggleAllPerms; window.permGroupToggle = permGroupToggle;
</script>";

if ($flash) {
  $ttl = ($flash['type']==='success') ? 'Success' : 'Notice';
  $ico = ($flash['type']==='success') ? 'success' : 'info';
  $html = $flash['msg'];
  $PAGE_FOOT_SCRIPTS .= "<script>themedSwal({ title: ".json_encode($ttl).", html: ".json_encode($html).", icon: ".json_encode($ico).", confirmButtonText:'OK' });</script>";
}

/* ---------- Layout foot ---------- */
include __DIR__ . '/inc/layout_foot.php';
