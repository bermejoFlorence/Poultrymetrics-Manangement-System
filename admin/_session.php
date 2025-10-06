<?php
// /admin/_session.php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['username']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../login.php'); exit();
}
