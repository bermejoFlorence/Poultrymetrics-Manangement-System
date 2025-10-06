<?php
// admin/employees.php — compact UI with fingerprint enroll (match-before-save)
require_once __DIR__.'/inc/common.php';
@date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");
@$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
function csrf_ok($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$t); }
function flash_set($t,$m){ $_SESSION['flash']=['t'=>$t,'m'=>$m]; }
function flash_pop(){ $f=$_SESSION['flash']??null; if($f) unset($_SESSION['flash']); return $f; }

function table_has_col(mysqli $c, string $table, string $col): bool {
  $tbl  = str_replace('`','``',$table);
  $like = $c->real_escape_string($col);
  $res  = @$c->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$like}'");
  if (!$res) return false; $ok = ($res->num_rows > 0); $res->free(); return $ok;
}
function table_exists(mysqli $c, string $table): bool {
  $like = $c->real_escape_string($table);
  $res  = @$c->query("SHOW TABLES LIKE '{$like}'"); if (!$res) return false;
  $ok = ($res->num_rows > 0); $res->free(); return $ok;
}
function sanitize_status($s){ $s=strtolower(trim((string)$s)); return in_array($s,['active','inactive'],true)?$s:'active'; }
function sanitize_gender($g){
  if($g==='') return null;
  $g = strtolower((string)$g);
  if(in_array($g,['male','female'],true)) return ucfirst($g);
  return $g ? 'Other' : null;
}
function sanitize_role($r,$allowed){ $r=strtolower(trim((string)$r)); return in_array($r,$allowed,true)?$r:'worker'; }
function split_name_parts(string $full): array {
  $full = trim(preg_replace('/\s+/', ' ', $full));
  if ($full === '') return [null,null,null];
  $parts = explode(' ', $full);
  $first = array_shift($parts); $last  = count($parts) ? array_pop($parts) : null;
  $middle = $parts ? implode(' ', $parts) : null;
  return [$first, $middle, $last];
}

/* ---- schema discovery ---- */
$EMP_TBL='employees';
$EMP_PK = table_has_col($conn,$EMP_TBL,'id') ? 'id' : (table_has_col($conn,$EMP_TBL,'employee_id') ? 'employee_id' : 'id');
$USERS_TBL='users';
$USERS_PK = table_has_col($conn,$USERS_TBL,'id') ? 'id' : (table_has_col($conn,$USERS_TBL,'user_id') ? 'user_id' : 'id');
$EMP_STATUS = table_has_col($conn,$EMP_TBL,'status') ? 'status' : (table_has_col($conn,$EMP_TBL,'emp_status') ? 'emp_status' : null);
$USR_STATUS = table_has_col($conn,$USERS_TBL,'status') ? 'status' : (table_has_col($conn,$USERS_TBL,'account_status') ? 'account_status' : null);
$HAS_PHOTO_URL  = table_has_col($conn,$EMP_TBL,'photo_url');
$HAS_PHOTO_PATH = table_has_col($conn,$EMP_TBL,'photo_path');
$PHOTO_COL = $HAS_PHOTO_URL ? 'photo_url' : ($HAS_PHOTO_PATH ? 'photo_path' : null);
$HAS_CREATED_AT= table_has_col($conn,$EMP_TBL,'created_at');
$HAS_ROLE      = table_has_col($conn,$USERS_TBL,'role');

$EMP_FULL_COL = null; foreach (['full_name','fullname','name'] as $cand) { if (table_has_col($conn,$EMP_TBL,$cand)) { $EMP_FULL_COL = $cand; break; } }
$HAS_FN = table_has_col($conn,$EMP_TBL,'first_name');
$HAS_MN = table_has_col($conn,$EMP_TBL,'middle_name');
$HAS_LN = table_has_col($conn,$EMP_TBL,'last_name');
if ($EMP_FULL_COL && !($HAS_FN || $HAS_MN || $HAS_LN)) {
  $NAME_SELECT="`$EMP_FULL_COL` AS display_full_name"; $NAME_EXPR_FOR_SEARCH="`$EMP_FULL_COL`";
} else {
  $NAME_SELECT="TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,''))) AS display_full_name";
  $NAME_EXPR_FOR_SEARCH="TRIM(CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(middle_name,''), NULLIF(last_name,'')))";
}

/* ---- config ---- */
$ALLOWED_ROLES=['admin','accountant','worker'];
$UPLOAD_DIR_DISK = realpath(__DIR__.'/assets/uploads/employees') ?: (__DIR__.'/assets/uploads/employees');
if (!is_dir($UPLOAD_DIR_DISK)) @mkdir($UPLOAD_DIR_DISK,0775,true);
$BASE_URI = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''),'/');
$PHOTO_URL_BASE = ($BASE_URI ?: '').'/assets/uploads/employees';

/* ---- helpers ---- */
function upload_photo_or_throw(array $file, int $empId, string $UPLOAD_DIR_DISK, string $PHOTO_URL_BASE): string {
  if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) throw new Exception('Photo upload failed.');
  if ($file['size'] > 2*1024*1024) throw new Exception('Photo too large (max 2MB).');
  $mime=''; if (class_exists('finfo')) { $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($file['tmp_name'])?:''; }
  if (!$mime && function_exists('exif_imagetype')) { $map2=[IMAGETYPE_JPEG=>'image/jpeg',IMAGETYPE_PNG=>'image/png',IMAGETYPE_WEBP=>'image/webp']; $t=@exif_imagetype($file['tmp_name']); $mime=$map2[$t]??''; }
  $map=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']; if (!isset($map[$mime])) throw new Exception('Invalid photo type (JPG/PNG/WEBP only).');
  $ext=$map[$mime]; foreach(['jpg','jpeg','png','webp'] as $e){ $p="$UPLOAD_DIR_DISK/emp_{$empId}.{$e}"; if(is_file($p)) @unlink($p); }
  $dest="$UPLOAD_DIR_DISK/emp_{$empId}.{$ext}"; if (!@move_uploaded_file($file['tmp_name'],$dest)) throw new Exception('Cannot save photo.');
  return "$PHOTO_URL_BASE/emp_{$empId}.{$ext}";
}

/* ---- POST actions ---- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_ok($_POST['csrf'] ?? '')) { http_response_code(400); die('Bad CSRF'); }
  $action = $_POST['action'] ?? '';

  try {
    if ($action==='create' || $action==='update') {
      $isCreate = ($action==='create');
      $id = $isCreate ? 0 : (int)($_POST['id'] ?? 0);
      if (!$isCreate && $id<=0) throw new Exception('Invalid employee.');

      $full_name = trim($_POST['full_name'] ?? '');
      $username  = trim($_POST['username'] ?? '');
      $email     = trim($_POST['email'] ?? '');
      $status_in = sanitize_status($_POST['status'] ?? 'active');
      $contact   = trim($_POST['contact_number'] ?? '');
      $position  = trim($_POST['position'] ?? '');
      $birthday  = trim($_POST['birthday'] ?? ''); $birthday = $birthday!=='' ? $birthday : null;
      $gender    = sanitize_gender($_POST['gender'] ?? '');
      $address   = trim($_POST['address'] ?? ''); $address = $address!=='' ? $address : null;
      $biometric = trim((string)($_POST['biometric_id'] ?? '')); $biometric = ($biometric==='') ? null : $biometric;

      if ($full_name==='' || $username==='' || $email==='') throw new Exception('Full name, Username, Email are required.');

      if ($isCreate) {
        $password = (string)($_POST['password'] ?? '');
        $role     = sanitize_role($_POST['role'] ?? 'worker', $ALLOWED_ROLES);
        if ($password==='') throw new Exception('Password is required.');
        // uniqueness
        $chk=$conn->prepare("SELECT 1 FROM `$USERS_TBL` WHERE username=? OR email=? LIMIT 1");
        $chk->bind_param('ss',$username,$email); $chk->execute(); $chk->store_result(); if ($chk->num_rows>0) throw new Exception('Username/email already used in users.'); $chk->close();
        $chk2=$conn->prepare("SELECT 1 FROM `$EMP_TBL` WHERE (username=? OR email=? OR (? IS NOT NULL AND ?<>'' AND biometric_id=?)) LIMIT 1");
        $chk2->bind_param('sssss',$username,$email,$biometric,$biometric,$biometric); $chk2->execute(); $chk2->store_result(); if ($chk2->num_rows>0) throw new Exception('Username/email/biometric already used in employees.'); $chk2->close();

        $conn->begin_transaction();
        // users
        $hash = password_hash($password,PASSWORD_BCRYPT);
        if ($USR_STATUS && $HAS_ROLE) {
          $u=$conn->prepare("INSERT INTO `$USERS_TBL` (username,email,password_hash,role,`$USR_STATUS`) VALUES (?,?,?,?,?)");
          $u->bind_param('sssss',$username,$email,$hash,$role,$status_in);
        } elseif ($USR_STATUS) {
          $u=$conn->prepare("INSERT INTO `$USERS_TBL` (username,email,password_hash,`$USR_STATUS`) VALUES (?,?,?,?)");
          $u->bind_param('ssss',$username,$email,$hash,$status_in);
        } elseif ($HAS_ROLE) {
          $u=$conn->prepare("INSERT INTO `$USERS_TBL` (username,email,password_hash,role) VALUES (?,?,?,?)");
          $u->bind_param('ssss',$username,$email,$hash,$role);
        } else {
          $u=$conn->prepare("INSERT INTO `$USERS_TBL` (username,email,password_hash) VALUES (?,?,?)");
          $u->bind_param('sss',$username,$email,$hash);
        }
        $u->execute(); $user_id=(int)$conn->insert_id; $u->close();

        [$fn,$mn,$ln] = split_name_parts($full_name);
        if ($HAS_FN || $HAS_MN || $HAS_LN) {
          if ($EMP_STATUS) {
            $e=$conn->prepare("INSERT INTO `$EMP_TBL` (first_name,middle_name,last_name,username,email,contact_number,position,birthday,gender,address,biometric_id,`$EMP_STATUS`,user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $e->bind_param('ssssssssssssi',$fn,$mn,$ln,$username,$email,$contact,$position,$birthday,$gender,$address,$biometric,$status_in,$user_id);
          } else {
            $e=$conn->prepare("INSERT INTO `$EMP_TBL` (first_name,middle_name,last_name,username,email,contact_number,position,birthday,gender,address,biometric_id,user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $e->bind_param('sssssssssssi',$fn,$mn,$ln,$username,$email,$contact,$position,$birthday,$gender,$address,$biometric,$user_id);
          }
        } else {
          $col = $EMP_FULL_COL ?: 'full_name';
          if ($EMP_STATUS) {
            $e=$conn->prepare("INSERT INTO `$EMP_TBL` (`$col`,username,email,contact_number,position,birthday,gender,address,biometric_id,`$EMP_STATUS`,user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $e->bind_param('ssssssssssi',$full_name,$username,$email,$contact,$position,$birthday,$gender,$address,$biometric,$status_in,$user_id);
          } else {
            $e=$conn->prepare("INSERT INTO `$EMP_TBL` (`$col`,username,email,contact_number,position,birthday,gender,address,biometric_id,user_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $e->bind_param('sssssssssi',$full_name,$username,$email,$contact,$position,$birthday,$gender,$address,$biometric,$user_id);
          }
        }
        $e->execute(); $emp_id=(int)$conn->insert_id; $e->close();

        if (!empty($_FILES['photo']['name'])) {
          $url = upload_photo_or_throw($_FILES['photo'],$emp_id,$UPLOAD_DIR_DISK,$PHOTO_URL_BASE);
          if ($PHOTO_COL) { $pu=$conn->prepare("UPDATE `$EMP_TBL` SET `$PHOTO_COL`=? WHERE `$EMP_PK`=?"); $pu->bind_param('si',$url,$emp_id); $pu->execute(); $pu->close(); }
        }

        $conn->commit();
        flash_set('success','Employee created.');
        header('Location: employees.php'); exit;
      }
      else { // update
        $newpass   = (string)($_POST['password'] ?? '');
        // unique vs other employees
        $chk=$conn->prepare("SELECT 1 FROM `$EMP_TBL` WHERE (username=? OR email=? OR (? IS NOT NULL AND ?<>'' AND biometric_id=?)) AND `$EMP_PK`<>? LIMIT 1");
        $chk->bind_param('sssssi',$username,$email,$biometric,$biometric,$biometric,$id);
        $chk->execute(); $chk->store_result(); if ($chk->num_rows>0) { $chk->close(); throw new Exception('Username/email/biometric used by another employee.'); } $chk->close();

        // linked user
        $g=$conn->prepare("SELECT user_id FROM `$EMP_TBL` WHERE `$EMP_PK`=? LIMIT 1"); $g->bind_param('i',$id); $g->execute(); $user_id=(int)($g->get_result()->fetch_row()[0] ?? 0); $g->close();
        if ($user_id>0) {
          $chkU=$conn->prepare("SELECT `$USERS_PK` FROM `$USERS_TBL` WHERE (username=? OR email=?) AND `$USERS_PK`<>? LIMIT 1");
          $chkU->bind_param('ssi',$username,$email,$user_id); $chkU->execute(); $chkU->store_result(); if ($chkU->num_rows>0) { $chkU->close(); throw new Exception('Username/email already used by another account.'); } $chkU->close();
        } else {
          $chkU2=$conn->prepare("SELECT `$USERS_PK` FROM `$USERS_TBL` WHERE (username=? OR email=?) LIMIT 1");
          $chkU2->bind_param('ss',$username,$email); $chkU2->execute(); $chkU2->store_result(); if ($chkU2->num_rows>0) { $chkU2->close(); throw new Exception('Username/email already used by another account.'); } $chkU2->close();
        }

        $conn->begin_transaction();
        [$fn,$mn,$ln] = split_name_parts($full_name);
        if ($HAS_FN || $HAS_MN || $HAS_LN) {
          if ($EMP_STATUS) {
            $e=$conn->prepare("UPDATE `$EMP_TBL` SET first_name=?, middle_name=?, last_name=?, username=?, email=?, contact_number=?, position=?, birthday=?, gender=?, address=?, biometric_id=?, `$EMP_STATUS`=? WHERE `$EMP_PK`=?");
            $e->bind_param('ssssssssssssi',$fn,$mn,$ln,$username,$email,$contact,$position,$birthday,$gender,$address,$biometric,$status_in,$id);
          } else {
            $e=$conn->prepare("UPDATE `$EMP_TBL` SET first_name=?, middle_name=?, last_name=?, username=?, email=?, contact_number=?, position=?, birthday=?, gender=?, address=?, biometric_id=? WHERE `$EMP_PK`=?");
            $e->bind_param('sssssssssssi',$fn,$mn,$ln,$username,$email,$contact,$position,$birthday,$gender,$address,$biometric,$id);
          }
        } else {
          $col = $EMP_FULL_COL ?: 'full_name';
          if ($EMP_STATUS) {
            $e=$conn->prepare("UPDATE `$EMP_TBL` SET `$col`=?, username=?, email=?, contact_number=?, position=?, birthday=?, gender=?, address=?, biometric_id=?, `$EMP_STATUS`=? WHERE `$EMP_PK`=?");
            $e->bind_param('ssssssssssi',$full_name,$username,$email,$contact,$position,$birthday,$gender,$address,$biometric,$status_in,$id);
          } else {
            $e=$conn->prepare("UPDATE `$EMP_TBL` SET `$col`=?, username=?, email=?, contact_number=?, position=?, birthday=?, gender=?, address=?, biometric_id=? WHERE `$EMP_PK`=?");
            $e->bind_param('sssssssssi',$full_name,$username,$email,$contact,$position,$birthday,$gender,$address,$biometric,$id);
          }
        }
        $e->execute(); $e->close();

        if (!empty($_FILES['photo']['name'])) {
          $url = upload_photo_or_throw($_FILES['photo'],$id,$UPLOAD_DIR_DISK,$PHOTO_URL_BASE);
          if ($PHOTO_COL) { $pu=$conn->prepare("UPDATE `$EMP_TBL` SET `$PHOTO_COL`=? WHERE `$EMP_PK`=?"); $pu->bind_param('si',$url,$id); $pu->execute(); $pu->close(); }
        }
        if (isset($_POST['remove_photo']) && $_POST['remove_photo']==='1') {
          foreach(['jpg','jpeg','png','webp'] as $eext){ $p="$UPLOAD_DIR_DISK/emp_{$id}.{$eext}"; if(is_file($p)) @unlink($p); }
          if ($PHOTO_COL) { $rp=$conn->prepare("UPDATE `$EMP_TBL` SET `$PHOTO_COL`=NULL WHERE `$EMP_PK`=?"); $rp->bind_param('i',$id); $rp->execute(); $rp->close(); }
        }

        // linked user updates
        if ($user_id>0) {
          if ($newpass!=='') {
            $hash=password_hash($newpass,PASSWORD_BCRYPT);
            if ($USR_STATUS) { $u=$conn->prepare("UPDATE `$USERS_TBL` SET username=?, email=?, password_hash=?, `$USR_STATUS`=? WHERE `$USERS_PK`=?"); $u->bind_param('ssssi',$username,$email,$hash,$status_in,$user_id); }
            else { $u=$conn->prepare("UPDATE `$USERS_TBL` SET username=?, email=?, password_hash=? WHERE `$USERS_PK`=?"); $u->bind_param('sssi',$username,$email,$hash,$user_id); }
            $u->execute(); $u->close();
          } else {
            if ($USR_STATUS) { $u=$conn->prepare("UPDATE `$USERS_TBL` SET username=?, email=?, `$USR_STATUS`=? WHERE `$USERS_PK`=?"); $u->bind_param('sssi',$username,$email,$status_in,$user_id); }
            else { $u=$conn->prepare("UPDATE `$USERS_TBL` SET username=?, email=? WHERE `$USERS_PK`=?"); $u->bind_param('ssi',$username,$email,$user_id); }
            $u->execute(); $u->close();
          }
        }

        $conn->commit();
        flash_set('success','Employee updated.');
        header('Location: employees.php'); exit;
      }
    }

    if ($action==='save_biometrics') {
      $id = (int)($_POST['id'] ?? 0);
      $biometric_id = trim((string)($_POST['biometric_id'] ?? '')); $biometric_id = $biometric_id ?: null;
      if ($id<=0 || !$biometric_id) throw new Exception('Employee and Biometric ID are required.');

      // Check duplicate biometric_id
      $chk = $conn->prepare("SELECT 1 FROM `$EMP_TBL` WHERE biometric_id=? AND `$EMP_PK`<>? LIMIT 1");
      $chk->bind_param('si',$biometric_id,$id); $chk->execute(); $chk->store_result();
      if ($chk->num_rows>0) { $chk->close(); throw new Exception('Biometric ID already used by another employee.'); }
      $chk->close();

      $conn->begin_transaction();
      $u=$conn->prepare("UPDATE `$EMP_TBL` SET biometric_id=? WHERE `$EMP_PK`=?");
      $u->bind_param('si',$biometric_id,$id); $u->execute(); $u->close();

      $HAS_BIO_TBL = table_exists($conn,'employee_biometrics');

      // If a template was captured, validate by matcher BEFORE saving (prevent fake/duplicate)
      if (!empty($_POST['template_b64'])) {
        $b64 = (string)$_POST['template_b64'];
        $bin = base64_decode($b64, true);
        if ($bin===false || strlen($bin)===0) throw new Exception('Template decode failed.');
        if (strlen($bin) > 2*1024*1024) throw new Exception('Template too large (max 2MB).');

        // call fp_match.php to ensure this template does NOT belong to someone else
        $ch = curl_init('http://localhost/Poultrymetrics/admin/fp_match.php');
        curl_setopt_array($ch, [
          CURLOPT_POST => true,
          CURLOPT_POSTFIELDS => ['probe_b64'=>$b64],
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 15,
        ]);
        $mj = curl_exec($ch);
        if ($mj===false) throw new Exception('Matcher error: '.curl_error($ch));
        curl_close($ch);

        $mm = json_decode($mj, true);
        if ($mm && !empty($mm['ok'])) {
          $matchedEmp = (int)($mm['match']['employee_id'] ?? 0);
          if ($matchedEmp>0 && $matchedEmp !== $id) {
            throw new Exception('This fingerprint is already enrolled for another employee.');
          }
        } elseif ($mm && ($mm['error'] ?? '')==='matcher_not_available') {
          throw new Exception('Matcher not available. Configure FPMATCH_CMD.');
        }
        // If matcher returns no_match, we allow save (it’s a new, unique finger)

        if ($HAS_BIO_TBL) {
          $tpl_hash = hash('sha256',$bin);
          $ins=$conn->prepare('INSERT INTO employee_biometrics (employee_id, template, tpl_hash) VALUES (?,?,?)');
          $null=NULL; $ins->bind_param('ibs',$id,$null,$tpl_hash); $ins->send_long_data(1,$bin);
          $ins->execute(); $ins->close();
        }
      }

      $conn->commit();
      flash_set('success','Biometrics saved.');
      header('Location: employees.php'); exit;
    }

    if ($action==='clear_biometrics') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new Exception('Invalid employee.');
      $conn->begin_transaction();
      $u=$conn->prepare("UPDATE `$EMP_TBL` SET biometric_id=NULL WHERE `$EMP_PK`=?");
      $u->bind_param('i',$id); $u->execute(); $u->close();
      if (table_exists($conn,'employee_biometrics')) {
        $del=$conn->prepare('DELETE FROM employee_biometrics WHERE employee_id=?');
        $del->bind_param('i',$id); $del->execute(); $del->close();
      }
      $conn->commit();
      flash_set('success','Biometrics cleared.');
      header('Location: employees.php'); exit;
    }

    if ($action==='delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new Exception('Invalid employee.');
      $conn->begin_transaction();
      $g=$conn->prepare("SELECT user_id FROM `$EMP_TBL` WHERE `$EMP_PK`=? LIMIT 1");
      $g->bind_param('i',$id); $g->execute(); $user_id=(int)($g->get_result()->fetch_row()[0] ?? 0); $g->close();
      $d=$conn->prepare("DELETE FROM `$EMP_TBL` WHERE `$EMP_PK`=?"); $d->bind_param('i',$id); $d->execute(); $d->close();
      foreach(['jpg','jpeg','png','webp'] as $eext){ $p="$UPLOAD_DIR_DISK/emp_{$id}.{$eext}"; if(is_file($p)) @unlink($p); }
      if ($user_id>0) {
        if ($USR_STATUS) { $uu=$conn->prepare("UPDATE `$USERS_TBL` SET `$USR_STATUS`='inactive' WHERE `$USERS_PK`=?"); $uu->bind_param('i',$user_id); $uu->execute(); $uu->close(); }
        else { $du=$conn->prepare("DELETE FROM `$USERS_TBL` WHERE `$USERS_PK`=?"); $du->bind_param('i',$user_id); $du->execute(); $du->close(); }
      }
      $conn->commit();
      flash_set('success','Employee deleted.');
      header('Location: employees.php'); exit;
    }

    throw new Exception('Unknown action.');
  } catch (Throwable $e) {
    @ $conn->rollback();
    flash_set('error',$e->getMessage());
    header('Location: employees.php'); exit;
  }
}

/* ---- search + list ---- */
$q = trim($_GET['q'] ?? ''); $f_status = trim($_GET['status'] ?? ''); $f_position = trim($_GET['position'] ?? '');
$page = max(1,(int)($_GET['page'] ?? 1)); $per=10; $off=($page-1)*$per;

/* ---- Positions list for dropdown (uses your positions table) ---- */
function fetch_positions(mysqli $c): array {
  $out = [];
  if (!table_exists($c,'positions') || !table_has_col($c,'positions','name')) return $out;

  $where = '';
  if (table_has_col($c,'positions','is_active')) {
    $where = "WHERE `is_active` IN (1,'1','true','TRUE','yes','YES')";
  }
  $order = [];
  if (table_has_col($c,'positions','display_order')) $order[] = '`display_order` ASC';
  $order[] = '`name` ASC';
  $orderSql = 'ORDER BY '.implode(', ',$order);

  $sql = "SELECT `id`,`name` FROM `positions` $where $orderSql";
  if ($res = @$c->query($sql)) {
    while ($r = $res->fetch_assoc()) {
      $nm = trim((string)$r['name']);
      if ($nm !== '') $out[] = ['id'=>(int)$r['id'], 'name'=>$nm];
    }
    $res->free();
  }
  return $out;
}
$POSITION_LIST = fetch_positions($conn);

/* Build WHERE */
$where=[]; $types=''; $vals=[];
if ($q!==''){
  $where[]='('.$NAME_EXPR_FOR_SEARCH.' LIKE CONCAT("%",?,"%") OR username LIKE CONCAT("%",?,"%") OR email LIKE CONCAT("%",?,"%") OR biometric_id LIKE CONCAT("%",?,"%"))';
  $types.='ssss'; array_push($vals,$q,$q,$q,$q);
}
if ($f_position!==''){
  // exact match when choosing from dropdown
  $where[]='position = ?'; $types.='s'; $vals[]=$f_position;
}
if ($f_status!=='' && $EMP_STATUS){
  $where[]="`$EMP_STATUS`=?"; $types.='s'; $vals[]=$f_status;
}
$W = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* Count + page bounds */
$c=$conn->prepare("SELECT COUNT(*) FROM `$EMP_TBL` $W"); if($types) $c->bind_param($types,...$vals); $c->execute(); $total=(int)($c->get_result()->fetch_row()[0] ?? 0); $c->close();
$pages = max(1,(int)ceil($total/$per)); if ($page>$pages) $page=$pages; $off = ($page-1)*$per;

/* Fetch */
$types2 = $types.'ii'; $vals2=$vals; array_push($vals2,$per,$off);
$photoExpr = $HAS_PHOTO_URL ? "photo_url" : ($HAS_PHOTO_PATH ? "photo_path" : "NULL");
$selCols = "`$EMP_PK` AS id, $NAME_SELECT, username, email, contact_number, position, birthday, gender, address, biometric_id, $photoExpr AS photo_url";
$selCols .= $EMP_STATUS ? ", `$EMP_STATUS` AS status" : ", NULL AS status";
$selCols .= $HAS_CREATED_AT ? ", created_at" : ", NOW() AS created_at";
$orderExpr = $HAS_CREATED_AT ? "created_at DESC, `$EMP_PK` DESC" : "`$EMP_PK` DESC";
$s=$conn->prepare("SELECT $selCols FROM `$EMP_TBL` $W ORDER BY $orderExpr LIMIT ? OFFSET ?");
$s->bind_param($types2,...$vals2); $s->execute(); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

/* Querystring (for pager links) */
$qs = http_build_query([
  'q' => $q,
  'status' => $f_status,
  'position' => $f_position
]);

include __DIR__.'/inc/layout_head.php';
?>
<style>
  .pm-compact { font-size: 14px; }
  .pm-compact .table thead th{ position:sticky; top:0; z-index:1; background:#fff; }

  :root{ --pm-z-backdrop: 2040; --pm-z-modal: 2050; }
  .modal-backdrop{ z-index: var(--pm-z-backdrop) !important; }
  .modal{ z-index: var(--pm-z-modal) !important; }
  body.modal-open{ overflow: hidden; }

  @media (max-width: 576px){
    .pm-compact { font-size: 13px; }
    .card-body.p-2{ padding: .5rem !important; }
    .table td, .table th{ vertical-align: middle; }
  }
</style>

<div class="pm-compact">
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h5 class="fw-bold mb-0">Employee Profiling</h5>
    <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#createModal"><i class="fa fa-plus me-1"></i> Add Employee</button>
  </div>

  <?php if($f=flash_pop()): ?>
    <div class="alert alert-<?= $f['t']==='success'?'success':'danger' ?> py-2"><?= htmlspecialchars($f['m']??'') ?></div>
  <?php endif; ?>

  <!-- filters -->
  <form class="row g-2 align-items-end mb-2" method="get">
    <div class="col-md-5">
      <label class="form-label small">Search</label>
      <input name="q" class="form-control" placeholder="name / username / email / biometric" value="<?=htmlspecialchars($q)?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small">Position</label>
      <?php if (!empty($POSITION_LIST)): ?>
        <select name="position" class="form-select">
          <option value="">All positions</option>
          <?php foreach ($POSITION_LIST as $p): ?>
            <option value="<?= htmlspecialchars($p['name']) ?>" <?= ($f_position === $p['name'] ? 'selected' : '') ?>>
              <?= htmlspecialchars($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input name="position" class="form-control" value="<?=htmlspecialchars($f_position)?>">
      <?php endif; ?>
    </div>
    <?php if ($EMP_STATUS): ?>
    <div class="col-md-2">
      <label class="form-label small">Status</label>
      <select name="status" class="form-select">
        <option value="">All</option>
        <option value="active"   <?=$f_status==='active'?'selected':'';?>>Active</option>
        <option value="inactive" <?=$f_status==='inactive'?'selected':'';?>>Inactive</option>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-md-2">
      <label class="form-label small d-block">&nbsp;</label>
      <button class="btn btn-outline-dark w-100">Filter</button>
    </div>
  </form>

  <div class="card">
    <div class="card-body table-responsive p-2">
      <table class="table align-middle mb-0">
        <thead>
          <tr>
            <th style="width:48px;">Photo</th>
            <th>Name / Username</th>
            <th>Email / Contact</th>
            <th>Position</th>
            <th>Birthday</th>
            <th>Gender</th>
            <th>Biometric ID</th>
            <?php if ($EMP_STATUS): ?><th>Status</th><?php endif; ?>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r):
          $statusShow = $r['status'] ?: 'active';
          $badge = strtolower($statusShow)==='active' ? 'success' : 'secondary';
          $displayName = trim((string)($r['display_full_name'] ?? '')); if ($displayName==='') $displayName = (string)$r['username'];
        ?>
          <tr>
            <td>
              <div class="rounded-circle overflow-hidden border" style="width:32px;height:32px;background:#f5f5f5">
                <?php if(!empty($r['photo_url'])): ?>
                  <img src="<?=htmlspecialchars($r['photo_url'])?>" style="width:100%;height:100%;object-fit:cover" alt="Photo">
                <?php else: ?>
                  <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted"><i class="fa fa-user"></i></div>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div class="fw-semibold"><?=htmlspecialchars($displayName)?></div>
              <div class="small text-muted">@<?=htmlspecialchars($r['username'])?></div>
            </td>
            <td>
              <div><?=htmlspecialchars($r['email'])?></div>
              <div class="small text-muted"><?=htmlspecialchars($r['contact_number'] ?? '')?></div>
            </td>
            <td><?=htmlspecialchars($r['position'] ?? '')?></td>
            <td><?=!empty($r['birthday']) ? htmlspecialchars($r['birthday']) : '—'?></td>
            <td><?=htmlspecialchars($r['gender'] ?? '—')?></td>
            <td><span class="font-monospace"><?=htmlspecialchars($r['biometric_id'] ?? '')?></span></td>
            <?php if ($EMP_STATUS): ?>
            <td><span class="badge text-bg-<?=$badge?>"><?=ucfirst($statusShow)?></span></td>
            <?php endif; ?>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-outline-success me-1"
                      data-bs-toggle="modal" data-bs-target="#bioModal"
                      data-id="<?=$r['id']?>"
                      data-name="<?=htmlspecialchars($displayName)?>"
                      data-username="<?=htmlspecialchars($r['username'])?>"
                      data-bioid="<?=htmlspecialchars($r['biometric_id'] ?? '')?>">
                <i class="fa fa-fingerprint"></i>
              </button>
              <button type="button" class="btn btn-sm btn-outline-primary me-1"
                      data-bs-toggle="modal" data-bs-target="#editModal"
                      data-id="<?=$r['id']?>"
                      data-name="<?=htmlspecialchars($displayName)?>"
                      data-username="<?=htmlspecialchars($r['username'])?>"
                      data-email="<?=htmlspecialchars($r['email'])?>"
                      data-contact="<?=htmlspecialchars($r['contact_number'] ?? '')?>"
                      data-position="<?=htmlspecialchars($r['position'] ?? '')?>"
                      data-status="<?=htmlspecialchars($statusShow)?>"
                      data-birthday="<?=htmlspecialchars($r['birthday'] ?? '')?>"
                      data-gender="<?=htmlspecialchars($r['gender'] ?? '')?>"
                      data-address="<?=htmlspecialchars($r['address'] ?? '')?>"
                      data-bioid="<?=htmlspecialchars($r['biometric_id'] ?? '')?>">
                <i class="fa fa-pen"></i>
              </button>
              <form class="d-inline js-confirm" method="post">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=$r['id']?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="<?= 9 - ($EMP_STATUS?0:1) ?>" class="text-center text-muted">No employees found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Footer with pagination -->
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span class="small text-muted">Showing <?=count($rows)?> of <?=$total?> employee(s)</span>

      <?php
        $win = 2; // pages window on each side
        $start = max(1, $page - $win);
        $end   = min($pages, $page + $win);
        if ($start > 1) $start = max(1, min($start, $pages - ($win*2)));
        if ($end   < $pages) $end = min($pages, max($end, 1 + ($win*2)));
      ?>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="?<?= htmlspecialchars($qs) ?>&page=<?= max(1,$page-1) ?>">Prev</a>
          </li>

          <?php if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="?<?= htmlspecialchars($qs) ?>&page=1">1</a></li>
            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
          <?php endif; ?>

          <?php for ($p=$start; $p<=$end; $p++): ?>
            <li class="page-item <?= $p==$page?'active':'' ?>">
              <a class="page-link" href="?<?= htmlspecialchars($qs) ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>

          <?php if ($end < $pages): ?>
            <?php if ($end < $pages-1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="?<?= htmlspecialchars($qs) ?>&page=<?= $pages ?>"><?= $pages ?></a></li>
          <?php endif; ?>

          <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
            <a class="page-link" href="?<?= htmlspecialchars($qs) ?>&page=<?= min($pages,$page+1) ?>">Next</a>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
    <form class="modal-content" method="post" enctype="multipart/form-data" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-header"><h6 class="modal-title">Add Employee</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-5">
            <div class="border rounded p-3 h-100">
              <label class="form-label small">Photo (JPG/PNG/WEBP, max 2MB)</label>
              <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,image/*" class="form-control">
              <hr>
              <label class="form-label small">Biometric ID</label>
              <input name="biometric_id" class="form-control" placeholder="Scanner/Fingerprint ID">
            </div>
          </div>
          <div class="col-md-7">
            <div class="row g-2">
              <div class="col-md-8"><label class="form-label small">Full Name</label><input name="full_name" class="form-control" required></div>
              <div class="col-md-4"><label class="form-label small">Position</label><input name="position" class="form-control"></div>
              <div class="col-md-4"><label class="form-label small">Username</label><input name="username" class="form-control" required></div>
              <div class="col-md-4"><label class="form-label small">Password</label><input name="password" type="password" class="form-control" minlength="8" required></div>
              <div class="col-md-4"><label class="form-label small">Email</label><input name="email" type="email" class="form-control" required></div>
              <div class="col-md-4"><label class="form-label small">Contact Number</label><input name="contact_number" class="form-control"></div>
              <div class="col-md-4"><label class="form-label small">Birthday</label><input name="birthday" type="date" class="form-control"></div>
              <div class="col-md-4"><label class="form-label small">Gender</label><select name="gender" class="form-select"><option value="">—</option><option>Male</option><option>Female</option><option>Other</option></select></div>
              <div class="col-12"><label class="form-label small">Address</label><textarea name="address" rows="2" class="form-control" placeholder="House/Street, Barangay, City/Province"></textarea></div>
              <div class="col-md-6"><label class="form-label small">Role (Account)</label><select name="role" class="form-select"><?php foreach(['admin','accountant','worker'] as $r): ?><option value="<?=$r?>" <?=$r==='worker'?'selected':''?>><?=ucfirst($r)?></option><?php endforeach; ?></select></div>
              <div class="col-md-6"><label class="form-label small">Status</label><select name="status" class="form-select"><option value="active" selected>Active</option><option value="inactive">Inactive</option></select></div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-dark">Create</button></div>
    </form>
  </div>
</div>

<!-- Edit Modal (with Position dropdown from positions table) -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
    <form class="modal-content" method="post" enctype="multipart/form-data" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="ed_id">
      <div class="modal-header"><h6 class="modal-title">Edit Employee</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-5">
            <div class="border rounded p-3 h-100">
              <label class="form-label small">Replace Photo (optional)</label>
              <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,image/*" class="form-control">
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" value="1" id="ed_remove_photo" name="remove_photo">
                <label class="form-check-label small" for="ed_remove_photo">Remove current photo</label>
              </div>
              <hr>
              <label class="form-label small">Biometric ID</label>
              <input name="biometric_id" id="ed_bioid" class="form-control" placeholder="Scanner/Fingerprint ID">
            </div>
          </div>
          <div class="col-md-7">
            <div class="row g-2">
              <div class="col-md-8"><label class="form-label small">Full Name</label><input name="full_name" id="ed_name" class="form-control" required></div>

              <!-- Position: text input + DB-backed select below it -->
              <div class="col-md-4">
                <label class="form-label small">Position</label>
                <input name="position" id="ed_position" class="form-control" placeholder="Type or choose below">
                <?php if (!empty($POSITION_LIST)): ?>
                  <select id="ed_position_sel" class="form-select mt-1">
                    <option value="">— choose from list —</option>
                    <?php foreach ($POSITION_LIST as $p): ?>
                      <option value="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">Choosing fills the field above.</div>
                <?php else: ?>
                  <div class="form-text text-muted">No active positions found.</div>
                <?php endif; ?>
              </div>

              <div class="col-md-4"><label class="form-label small">Username</label><input name="username" id="ed_username" class="form-control" required></div>
              <div class="col-md-4"><label class="form-label small">New Password <span class="text-muted">(optional)</span></label><input name="password" id="ed_password" type="password" class="form-control" minlength="8" placeholder="Leave blank to keep current"></div>
              <div class="col-md-4"><label class="form-label small">Email</label><input name="email" id="ed_email" type="email" class="form-control" required></div>
              <div class="col-md-4"><label class="form-label small">Contact Number</label><input name="contact_number" id="ed_contact" class="form-control"></div>
              <div class="col-md-4"><label class="form-label small">Birthday</label><input name="birthday" id="ed_birthday" type="date" class="form-control"></div>
              <div class="col-md-4"><label class="form-label small">Gender</label><select name="gender" id="ed_gender" class="form-select"><option value="">—</option><option>Male</option><option>Female</option><option>Other</option></select></div>
              <div class="col-12"><label class="form-label small">Address</label><textarea name="address" id="ed_address" class="form-control" rows="2" placeholder="House/Street, Barangay, City/Province"></textarea></div>
              <?php if ($EMP_STATUS || $USR_STATUS): ?>
              <div class="col-md-6"><label class="form-label small">Status</label><select name="status" id="ed_status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-dark">Save Changes</button></div>
    </form>
  </div>
</div>

<!-- Biometrics Modal (no vendor/serial) -->
<div class="modal fade" id="bioModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
      <input type="hidden" name="action" value="save_biometrics">
      <input type="hidden" name="id" id="bio_id">
      <input type="hidden" name="template_b64" id="bio_template_b64">

      <div class="modal-header">
        <h6 class="modal-title"><i class="fa fa-fingerprint me-2"></i>Enroll Biometrics</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <div class="small text-muted">Employee</div>
          <div class="fw-semibold" id="bio_empname">—</div>
          <div class="small text-muted">@<span id="bio_username">—</span></div>
        </div>
        <div class="row g-2">
          <div class="col-8 col-sm-8">
            <label class="form-label small">Biometric ID</label>
            <input name="biometric_id" id="bio_bioid" class="form-control" placeholder="Scanner/Device ID" required>
          </div>
          <div class="col-4 col-sm-4 d-flex align-items-end gap-1">
            <button type="button" class="btn btn-outline-secondary w-100" id="bio_sim">Simulate</button>
          </div>
          <div class="col-12 d-flex align-items-center flex-wrap gap-2">
            <button type="button" class="btn btn-success" id="bio_scan"><i class="fa fa-fingerprint me-1"></i> Scan Fingerprint</button>
            <span id="bio_scan_status" class="small text-muted"></span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="bio_clear_btn">Clear Biometrics</button>
        <button class="btn btn-dark">Save</button>
      </div>
    </form>
  </div>
</div>
<form id="bio_clear_form" method="post" style="display:none">
  <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
  <input type="hidden" name="action" value="clear_biometrics">
  <input type="hidden" name="id" id="bio_clear_id">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Ensure only one modal at a time
  document.addEventListener('show.bs.modal', (ev) => {
    document.querySelectorAll('.modal.show').forEach(m => {
      if (m !== ev.target) {
        const inst = bootstrap.Modal.getInstance(m);
        if (inst) inst.hide();
      }
    });
  });

  // confirm delete
  for (const f of document.querySelectorAll('form.js-confirm')) {
    if (f.dataset.bound==='1') continue; f.dataset.bound='1';
    f.addEventListener('submit', (e)=>{
      e.preventDefault();
      (window.Swal ? Swal.fire({title:'Please confirm',text:'Delete this employee?',icon:'question',showCancelButton:true}).then(r=>r.isConfirmed)
                   : Promise.resolve(confirm('Delete this employee?'))).then(ok=>{ if(ok) f.submit(); });
    });
  }

  // Edit modal
  document.getElementById('editModal')?.addEventListener('show.bs.modal', (ev)=>{
    const b = ev.relatedTarget || document.activeElement; if (!b?.getAttribute) return;
    const get = (k)=> b.getAttribute(k)||'';
    document.getElementById('ed_id').value        = get('data-id');
    document.getElementById('ed_name').value      = get('data-name');
    document.getElementById('ed_username').value  = get('data-username');
    document.getElementById('ed_email').value     = get('data-email');
    document.getElementById('ed_contact').value   = get('data-contact');
    document.getElementById('ed_position').value  = get('data-position') || '';
    const st = document.getElementById('ed_status'); if (st) st.value = (get('data-status')||'active').toLowerCase();
    const bd = get('data-birthday');
    document.getElementById('ed_birthday').value  = bd && bd!=='0000-00-00' ? bd.substring(0,10) : '';
    document.getElementById('ed_gender').value    = get('data-gender');
    document.getElementById('ed_address').value   = get('data-address');
    document.getElementById('ed_bioid').value     = get('data-bioid');
    const pw = document.getElementById('ed_password'); if (pw) pw.value='';
    const rm = document.getElementById('ed_remove_photo'); if (rm) rm.checked=false;

    // Preselect dropdown (if exists)
    const posSel = document.getElementById('ed_position_sel');
    if (posSel) {
      const current = (get('data-position') || '').toLowerCase();
      let matched = false;
      for (const opt of posSel.options) {
        if ((opt.value || '').toLowerCase() === current && opt.value !== '') {
          opt.selected = true; matched = true; break;
        }
      }
      if (!matched) posSel.selectedIndex = 0;
    }
  });

  // Keep text input in sync when choosing from dropdown
  const posSel = document.getElementById('ed_position_sel');
  posSel?.addEventListener('change', ()=>{
    const t = document.getElementById('ed_position');
    if (t) t.value = posSel.value || '';
  });

  // Bio modal
  const bioModal = document.getElementById('bioModal');
  bioModal?.addEventListener('show.bs.modal', (ev)=>{
    const b = ev.relatedTarget || document.activeElement; if (!b?.getAttribute) return;
    const get = (k)=> b.getAttribute(k)||'';
    const id = get('data-id');
    document.getElementById('bio_id').value = id;
    document.getElementById('bio_clear_id').value = id;
    document.getElementById('bio_empname').textContent = get('data-name') || '(no name)';
    document.getElementById('bio_username').textContent = get('data-username') || '';
    document.getElementById('bio_bioid').value = get('data-bioid') || '';
    document.getElementById('bio_template_b64').value = '';
    document.getElementById('bio_scan_status').textContent='';
  });
  document.getElementById('bio_sim')?.addEventListener('click', ()=>{
    const el = document.getElementById('bio_bioid'); if (el) el.value = 'BIO-' + String(Date.now()).slice(-6);
  });
  document.getElementById('bio_clear_btn')?.addEventListener('click', ()=>{
    (window.Swal ? Swal.fire({title:'Clear biometrics?', text:'This will remove the Biometric ID and any stored templates.', icon:'warning', showCancelButton:true}).then(r=>r.isConfirmed)
                 : Promise.resolve(confirm('Clear biometrics?'))).then(ok=>{ if(ok) document.getElementById('bio_clear_form').submit(); });
  });

  // Scan (via fp_grab)
  const scanBtn   = document.getElementById('bio_scan');
  const tplField  = document.getElementById('bio_template_b64');
  const bioIdEl   = document.getElementById('bio_bioid');
  const statusEl  = document.getElementById('bio_scan_status');

  scanBtn?.addEventListener('click', async ()=>{
    statusEl.textContent = 'Scanning… place finger on reader';
    try {
      const r = await fetch('fp_grab.php', { method:'POST', body: new URLSearchParams({exclusive:'1',timeout:'15000',proc:'PIV'}) });
      const j = await r.json();
      if (!j || !j.ok) { alert('Capture failed: '+(j?.error||'unknown')); statusEl.textContent='Failed'; return; }
      tplField.value = j.template_b64 || '';
      statusEl.textContent = 'OK'+(j.format?(' — '+j.format):'')+' ('+(j.approx_bytes||'?')+' bytes)';
      if (!bioIdEl.value) bioIdEl.value = 'AUTO-' + String(Date.now()).slice(-6);
    } catch (e) {
      alert('Helper error: '+e); statusEl.textContent='Error';
    }
  });
});
</script>
<?php include __DIR__.'/inc/layout_foot.php'; ?>
