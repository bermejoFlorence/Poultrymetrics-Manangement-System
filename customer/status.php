<?php
// customer/status.php — Account Approval Status (schema-flexible)
$PAGE_TITLE = 'Approval Status';
$CURRENT    = 'status.php';

require_once __DIR__ . '/inc/common.php';
include __DIR__ . '/layout_head.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ── helpers ── */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('firstExistingCol')) {
  function firstExistingCol($conn,$table,$cands){ foreach($cands as $c){ if (colExists($conn,$table,$c)) return $c; } return null; }
}
function status_badge_class($s) {
  $s = strtolower(trim((string)$s));
  if ($s === 'approved' || $s === 'active' || $s === 'enabled' || $s === 'verified') return ['success','Approved'];
  if ($s === 'pending' || $s === 'created' || $s === 'submitted') return ['warning','Pending'];
  if ($s === 'rejected' || $s === 'denied' || $s === 'blocked' || $s === 'disabled') return ['danger', ucfirst($s)];
  if ($s === '' || $s === 'n/a') return ['secondary','N/A'];
  return ['secondary', ucfirst($s)];
}

/* ── current user ── */
$uid = (int)($_SESSION['user_id'] ?? $CURRENT_USER['id'] ?? 0);
if ($uid <= 0) { header('Location: ../login.php'); exit; }

/* ── detect approvals table (if any) ── */
$APR_TBL = tableExists($conn,'account_approvals') ? 'account_approvals'
        : (tableExists($conn,'customer_approvals') ? 'customer_approvals' : null);

$apr = [
  'uid_col'   => null,
  'status_col'=> null,
  'notes_col' => null,
  'time_col'  => null,
  'agent_col' => null,
  'pk_col'    => 'id'
];

if ($APR_TBL) {
  // link column (user/customer)
  foreach (['user_id','users_id','uid','customer_id','account_id'] as $c){
    if (colExists($conn,$APR_TBL,$c)) { $apr['uid_col'] = $c; break; }
  }
  // status/notes/timestamps/reviewer
  foreach (['status','approval_status','state'] as $c){ if (colExists($conn,$APR_TBL,$c)) { $apr['status_col']=$c; break; } }
  foreach (['notes','remarks','comment'] as $c){ if (colExists($conn,$APR_TBL,$c)) { $apr['notes_col']=$c; break; } }
  foreach (['created_at','submitted_at','updated_at','timestamp'] as $c){ if (colExists($conn,$APR_TBL,$c)) { $apr['time_col']=$c; break; } }
  foreach (['reviewed_by','approved_by','moderator','staff_id'] as $c){ if (colExists($conn,$APR_TBL,$c)) { $apr['agent_col']=$c; break; } }
  if (colExists($conn,$APR_TBL,'id')) $apr['pk_col']='id';
}

/* ── if approvals use customer_id, translate user→customer ── */
$fkValue = $uid;
if ($APR_TBL && $apr['uid_col']==='customer_id') {
  if (tableExists($conn,'customers')) {
    $custUserCol = firstExistingCol($conn,'customers',['user_id','uid','users_id','account_id']);
    $custPkCol   = colExists($conn,'customers','id') ? 'id' : firstExistingCol($conn,'customers',['customer_id']);
    if ($custPkCol && $custUserCol) {
      $q = "SELECT {$custPkCol} AS cid FROM customers WHERE {$custUserCol}=? ORDER BY {$custPkCol} DESC LIMIT 1";
      if ($st=$conn->prepare($q)){ $st->bind_param('i',$uid); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); if ($r) $fkValue=(int)$r['cid']; }
    }
  }
}

/* ── read user table flags first ── */
$U_TBL = tableExists($conn,'users') ? 'users' : null;
$userFlags = ['status'=>null,'is_active'=>null,'is_approved'=>null,'is_verified'=>null];

if ($U_TBL) {
  $cols = [];
  foreach (['status','is_active','is_approved','verified','is_verified','enabled','active'] as $c) {
    if (colExists($conn,$U_TBL,$c)) $cols[] = $c;
  }
  if ($cols) {
    $sel = "SELECT ".implode(',', array_map(fn($c)=>"`$c`",$cols))." FROM `$U_TBL` WHERE `id`=? LIMIT 1";
    $st = $conn->prepare($sel);
    $st->bind_param('i',$uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
      foreach($row as $k=>$v){ $userFlags[$k] = $v; }
    }
  }
}

/* ── derive status (prefer user-level flags; fall back to approvals) ── */
$derivedStatus = null;
$derivedWhen   = null;

if (!empty($userFlags['status'])) {
  $derivedStatus = strtolower((string)$userFlags['status']);
} elseif (isset($userFlags['is_active']) && $userFlags['is_active']!=='') {
  $derivedStatus = ((int)$userFlags['is_active']===1) ? 'approved' : 'pending';
} elseif (isset($userFlags['is_approved']) && $userFlags['is_approved']!=='') {
  $derivedStatus = ((int)$userFlags['is_approved']===1) ? 'approved' : 'pending';
} elseif (isset($userFlags['is_verified']) && $userFlags['is_verified']!=='') {
  $derivedStatus = ((int)$userFlags['is_verified']===1) ? 'approved' : 'pending';
}

// If still unknown, look at latest approval row
$history = [];
if (!$derivedStatus && $APR_TBL && $apr['uid_col'] && $apr['status_col']) {
  $orderBy = $apr['time_col'] ? "`{$apr['time_col']}` DESC" : "`{$apr['pk_col']}` DESC";
  $sql = "SELECT `{$apr['status_col']}` AS s".
         ($apr['time_col']? ", `{$apr['time_col']}` AS t":"").
         ($apr['notes_col']? ", `{$apr['notes_col']}` AS n":"").
         ($apr['agent_col']? ", `{$apr['agent_col']}` AS a":"").
         " FROM `{$APR_TBL}` WHERE `{$apr['uid_col']}`=? ORDER BY {$orderBy} LIMIT 1";
  if ($st=$conn->prepare($sql)){
    $st->bind_param('i',$fkValue); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if ($row){
      $derivedStatus = strtolower((string)$row['s']);
      $derivedWhen   = $row['t'] ?? null;
    }
  }
}

// Load recent history (up to 10) if approvals table exists
if ($APR_TBL && $apr['uid_col']) {
  $cols = ["`{$apr['status_col']}` AS s"];
  if ($apr['time_col'])  $cols[] = "`{$apr['time_col']}` AS t";
  if ($apr['notes_col']) $cols[] = "`{$apr['notes_col']}` AS n";
  if ($apr['agent_col']) $cols[] = "`{$apr['agent_col']}` AS a";

  $orderBy = $apr['time_col'] ? "`{$apr['time_col']}` DESC" : "`{$apr['pk_col']}` DESC";
  $sqlh = "SELECT ".implode(',', $cols)." FROM `{$APR_TBL}` WHERE `{$apr['uid_col']}`=? ORDER BY {$orderBy} LIMIT 10";
  if ($sth=$conn->prepare($sqlh)){
    $sth->bind_param('i',$fkValue); $sth->execute();
    $rh = $sth->get_result();
    while($rh && ($r=$rh->fetch_assoc())) $history[] = $r;
    $sth->close();
  }
}

/* final badge text */
[$badgeClass,$badgeLabel] = status_badge_class($derivedStatus ?? 'N/A');

?>
<div class="container-fluid">
  <div class="row g-3">
    <div class="col-12 col-lg-10 col-xl-9 mx-auto">

      <div class="card p-3 mt-2">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted">Account</div>
            <div class="fw-bold fs-5">Approval Status</div>
          </div>
          <div class="d-flex gap-2">
            <a href="customer_profile.php" class="btn btn-outline-secondary">
              <i class="fa-solid fa-id-card me-1"></i> Profile
            </a>
            <a href="customer_dashboard.php" class="btn btn-outline-secondary">
              <i class="fa-solid fa-gauge me-1"></i> Dashboard
            </a>
            <a href="shop.php" class="btn btn-dark">
              <i class="fa-solid fa-store me-1"></i> Shop
            </a>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <div class="small text-muted">Current status</div>
            <span class="badge text-bg-<?php echo $badgeClass; ?>"><?php echo h($badgeLabel); ?></span>
            <?php if ($derivedWhen): ?>
              <div class="small text-muted mt-1">Last update: <?php echo h($derivedWhen); ?></div>
            <?php endif; ?>
          </div>
          <a class="btn btn-outline-dark" href="/contact.php">
            <i class="fa-regular fa-message me-1"></i> Contact Support
          </a>
        </div>
      </div>

      <?php if ($APR_TBL && $apr['uid_col']): ?>
        <div class="card mt-3">
          <div class="card-header fw-semibold">Approval History</div>
          <div class="card-body table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Status</th>
                  <th>Notes</th>
                  <th><?php echo $apr['agent_col'] ? 'Reviewed By' : '—'; ?></th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$history): ?>
                <tr><td colspan="4" class="text-center text-muted">No approval entries yet.</td></tr>
              <?php else: foreach($history as $h): 
                [$c,$l] = status_badge_class($h['s'] ?? ''); ?>
                <tr>
                  <td><span class="badge text-bg-<?php echo $c; ?>"><?php echo h($l); ?></span></td>
                  <td class="small"><?php echo nl2br(h($h['n'] ?? '')); ?></td>
                  <td class="small"><?php echo h($h['a'] ?? ''); ?></td>
                  <td class="small"><?php echo h($h['t'] ?? ''); ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php else: ?>
        <div class="alert alert-info mt-3">
          We didn’t detect an approvals log table (<code>account_approvals</code> / <code>customer_approvals</code>).
          Your status above is based on your account flags in <code>users</code>.
        </div>
      <?php endif; ?>

      <div class="card mt-3">
        <div class="card-header fw-semibold">Tips</div>
        <div class="card-body small">
          <ul class="mb-0">
            <li>Ensure your profile details (name, phone, and address) are complete for faster approval.</li>
            <li>If your status is pending for over 24 hours, contact support with your registered email/phone.</li>
            <li>Once approved, you can start placing orders immediately.</li>
          </ul>
        </div>
      </div>

    </div>
  </div>
</div>

<?php
$PAGE_FOOT_SCRIPTS = "";
include __DIR__ . '/layout_foot.php';
