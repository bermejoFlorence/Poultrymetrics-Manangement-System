<?php
require 'config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: text/plain');
try {
  $db = pmx_connect();
  echo "engine: ";
  $r = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='products'")->fetch_assoc();
  var_export($r); echo "\n";

  $db->query("SELECT COUNT(*) FROM products"); // <— if 2006 here → table likely corrupt or very large BLOBs
  echo "COUNT(*) OK\n";
} catch (Throwable $e) {
  echo "TABLE PROBE ERROR [{$e->getCode()}] ".$e->getMessage()."\n";
}
