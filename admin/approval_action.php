<?php
// admin/approval_action.php
session_start();
require_once '../config.php';

// Require admin
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit('Not logged in'); }
$adminId = (int)$_SESSION['user_id'];

// Helpers to detect columns
function dbName(mysqli $c){ $r=$c->query("SELECT DATABASE()"); $d=$r?($r->fetch_row()[0]??''):''; if($r) $r->close(); return $d; }
function colExists(mysqli $c, $table, $col){
  $db = dbName($c);
  $stmt = $c->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $stmt->bind_param('sss', $db, $table, $col);
  $stmt->execute(); $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();
  return $ok;
}

$approvalId = (int)($_POST['approval_id'] ?? 0);
$action     = $_POST['action'] ?? '';        // 'approve' | 'reject'
$reason     = trim($_POST['reason'] ?? '');

if ($approvalId <= 0 || !in_array($action, ['approve','reject'], true)) {
  http_response_code(400); exit('Bad request');
}

// Get the approval row and linked user
$stmt = $conn->prepare("SELECT user_id, status FROM account_approvals WHERE id=?");
$stmt->bind_param('i', $approvalId);
$stmt->execute();
$res = $stmt->get_result();
$app = $res->fetch_assoc();
$stmt->close();

if (!$app) { http_response_code(404); exit('Approval not found'); }
if ($app['status'] !== 'pending') { http_response_code(409); exit('Already decided'); }

$userId = (int)$app['user_id'];

// Build decision update
if ($action === 'approve') {
  $sql = "UPDATE account_approvals
          SET status='approved', decided_by=?, decided_at=NOW(),
              note = CONCAT(COALESCE(note,''), CASE WHEN note IS NULL OR note='' THEN '' ELSE ' | ' END, 'Approved')
          WHERE id=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ii', $adminId, $approvalId);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();

  if ($ok) {
    // Flip common user flags if present
    $set = [];
    $types = '';
    $vals = [];

    if (colExists($conn, 'users', 'status')) { $set[] = "status=?";      $types.='s'; $vals[]='active'; }
    if (colExists($conn, 'users', 'is_active')) { $set[] = "is_active=?"; $types.='i'; $vals[]=1; }
    if (colExists($conn, 'users', 'is_approved')) { $set[] = "is_approved=?"; $types.='i'; $vals[]=1; }
    if (empty($set)) { echo 'OK'; exit; }

    $q = "UPDATE users SET ".implode(',', $set)." WHERE id=?";
    $types .= 'i'; $vals[] = $userId;
    $stmt = $conn->prepare($q);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $stmt->close();
    echo 'OK';
  } else {
    http_response_code(500); echo 'No change';
  }

} else { // reject
  $reason = $reason !== '' ? $reason : 'No reason specified';
  $sql = "UPDATE account_approvals
          SET status='rejected', decided_by=?, decided_at=NOW(),
              note = CONCAT(COALESCE(note,''), CASE WHEN note IS NULL OR note='' THEN '' ELSE ' | ' END, 'Rejected: ', ?)
          WHERE id=?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('isi', $adminId, $reason, $approvalId);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();
  echo $ok ? 'OK' : 'No change';
}
