<?php
// db_connect.php — Hostinger
date_default_timezone_set('Asia/Manila');

$DB_HOST = 'localhost';                       // mananatiling 'localhost' sa Hostinger
$DB_NAME = 'u578970591_poultry_db';           // ← eksaktong DB name sa screenshot mo
$DB_USER = 'u578970591_poultry_db';               // ← eksaktong MySQL user
$DB_PASS = 'Poultry_db2015';  // ← password na sinet mo

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_errno) {
  http_response_code(500);
  die('DB connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
@$conn->query("SET time_zone = '+08:00'");

// Fingerprint agent (Windows) endpoint:
define('FPGRAB_ENDPOINT', 'http://YOUR_WINDOWS_PC_PUBLIC_IP_OR_DNS:8080/grab'); // hal. http://203.0.113.50:8080/grab

// Shared secret token (dapat tugma sa Windows agent)
define('FPGRAB_TOKEN', 'your-secret-token-here');