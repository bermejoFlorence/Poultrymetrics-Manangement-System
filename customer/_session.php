<?php
// /customer/_session.php — simple guard for customer area
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['username']) || !in_array($role, ['customer','user'], true)) {
  header('Location: ../login.php');
  exit();
}
