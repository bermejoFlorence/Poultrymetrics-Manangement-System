<?php
/**
 * PoultryMetrics – Egg Production Schema Fixer
 * - No INFORMATION_SCHEMA, no dynamic PREPARE.
 * - Uses SHOW COLUMNS / SHOW INDEX only.
 * - Standardizes `egg_production` table and migrates date into `prod_date`.
 *
 * HOW TO USE:
 * 1) Save this as /admin/tools/fix_egg_schema.php
 * 2) Visit it in your browser while logged in as admin: /admin/tools/fix_egg_schema.php
 * 3) After it says "DONE", delete the file for safety.
 */

require_once __DIR__ . '/../_session.php';   // optional guard; remove if not used
require_once __DIR__ . '/../../config.php';
@$conn->query("SET time_zone = '+08:00'");

function hasTable(mysqli $c, string $t): bool {
  $t=$c->real_escape_string($t);
  $r=@$c->query("SHOW TABLES LIKE '{$t}'");
  return !!($r && $r->num_rows);
}
function hasCol(mysqli $c, string $t, string $col): bool {
  $t=$c->real_escape_string($t); $col=$c->real_escape_string($col);
  $r=@$c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'");
  return !!($r && $r->num_rows);
}
function hasIndex(mysqli $c, string $t, string $idx): bool {
  $t=$c->real_escape_string($t); $idx=$c->real_escape_string($idx);
  $r=@$c->query("SHOW INDEX FROM `{$t}` WHERE Key_name='{$idx}'");
  return !!($r && $r->num_rows);
}
function execq(mysqli $c, string $sql): bool {
  $ok = @$c->query($sql);
  echo $ok ? "OK: $sql<br>" : "<span style='color:#c00'>ERR:</span> $sql<br><code>".h($c->error)."</code><br>";
  return $ok;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

echo "<h3>Egg Production Schema Fixer</h3>";

/* 1) Ensure table exists with standard columns */
if (!hasTable($conn,'egg_production')){
  echo "Table `egg_production` not found. Creating...<br>";
  execq($conn, "CREATE TABLE egg_production (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prod_date DATE NOT NULL,
    shift ENUM('AM','PM','DAY') DEFAULT 'DAY',
    block_code VARCHAR(32) NULL,
    small_count INT DEFAULT 0,
    medium_count INT DEFAULT 0,
    large_count INT DEFAULT 0,
    xl_count INT DEFAULT 0,
    jumbo_count INT DEFAULT 0,
    rej_crack_count INT DEFAULT 0,
    mortality INT DEFAULT 0,
    notes VARCHAR(255),
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB");
}

/* 2) Add missing columns (safe, guarded per-column) */
$adds = [
  "prod_date DATE NULL",
  "shift ENUM('AM','PM','DAY') DEFAULT 'DAY'",
  "block_code VARCHAR(32) NULL",
  "small_count INT DEFAULT 0",
  "medium_count INT DEFAULT 0",
  "large_count INT DEFAULT 0",
  "xl_count INT DEFAULT 0",
  "jumbo_count INT DEFAULT 0",
  "rej_crack_count INT DEFAULT 0",
  "mortality INT DEFAULT 0",
  "notes VARCHAR(255)",
  "created_by INT NULL",
  "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
];
foreach ($adds as $def){
  $col = trim(strtok($def, " "));
  if (!hasCol($conn,'egg_production',$col)){
    execq($conn, "ALTER TABLE egg_production ADD COLUMN $def");
  } else {
    echo "SKIP (exists): $col<br>";
  }
}

/* 3) Migrate date into prod_date from first existing legacy date col */
$dateSources = ['prod_date','date','production_date','log_date','collect_date'];
$src = null;
foreach ($dateSources as $cand){
  if (hasCol($conn,'egg_production',$cand)){ $src=$cand; break; }
}
if ($src && $src !== 'prod_date'){
  execq($conn, "UPDATE egg_production SET prod_date = IFNULL(prod_date, `$src`) WHERE prod_date IS NULL");
} else {
  echo "No legacy date column found (or already using prod_date).<br>";
}

/* 4) Finalize prod_date (NOT NULL) and fill remaining NULL with today */
execq($conn, "UPDATE egg_production SET prod_date = CURDATE() WHERE prod_date IS NULL");
execq($conn, "ALTER TABLE egg_production MODIFY prod_date DATE NOT NULL");

/* 5) Helpful indexes */
if (!hasIndex($conn,'egg_production','idx_egg_prod_date')){
  execq($conn, "CREATE INDEX idx_egg_prod_date ON egg_production(prod_date)");
} else echo "SKIP (index exists): idx_egg_prod_date<br>";

if (!hasIndex($conn,'egg_production','idx_egg_shift')){
  execq($conn, "CREATE INDEX idx_egg_shift ON egg_production(shift)");
} else echo "SKIP (index exists): idx_egg_shift<br>";

echo "<hr><strong style='color:green'>DONE.</strong> You can now use <code>admin/egg_reports.php</code>.";
