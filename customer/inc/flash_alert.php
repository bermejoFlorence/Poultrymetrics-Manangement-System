<?php
// inc/flash_alert.php — one-shot flash banner with the green style in your screenshot
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!defined('PM_ALERT_CSS_DONE')) {
  define('PM_ALERT_CSS_DONE', true);
  ?>
  <style>
    /* Reusable banner style (green success like your screenshot) */
    .pm-alert{
      margin: 8px 8px 0 8px;
      padding: 10px 12px;
      border-radius: 6px;
      display:flex; align-items:center; gap:.5rem;
      font-size:.95rem;
    }
    .pm-alert-success{
      background:#d9efe2; border:1px solid #c8e6d0; color:#0f5132;
    }
    .pm-alert-info{
      background:#e7f1ff; border:1px solid #cfe2ff; color:#084298;
    }
    .pm-alert .fa-solid{ opacity:.95; }
  </style>
  <?php
}

$ft = $_SESSION['flash_type'] ?? null;
$fm = $_SESSION['flash_msg']  ?? null;

// one-shot consumption
unset($_SESSION['flash_type'], $_SESSION['flash_msg']);

if ($fm):
  $isSuccess = ($ft === 'success' || $ft === 'ok' || $ft === null);
  $classes = $isSuccess ? 'pm-alert pm-alert-success' : 'pm-alert pm-alert-info';
  $icon    = $isSuccess ? 'fa-circle-check' : 'fa-circle-info';
  ?>
  <div class="<?php echo $classes; ?>">
    <i class="fa-solid <?php echo $icon; ?>"></i>
    <div><?php echo htmlspecialchars($fm, ENT_QUOTES, 'UTF-8'); ?></div>
  </div>
<?php endif; ?>
