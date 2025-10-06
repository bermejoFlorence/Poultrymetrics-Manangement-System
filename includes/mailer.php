<?php
// /includes/mailer.php
declare(strict_types=1);

/**
 * Safe + smart PHPMailer loader
 * - Composer v6 (vendor/autoload.php)
 * - Manual v6 (/libs/PHPMailer/src)
 * - Manual v5 (/libs/PHPMailer/class.phpmailer.php, class.smtp.php)
 * - If nothing found, pm_send_mail() returns a clean error (no fatal)
 */

// ----- Try Composer autoload first -----
$phpmailer_version = 0; // 6 (namespaced) | 5 (legacy) | 0 (not available)
$loaded_from = '';

// common autoload locations
$autoload_candidates = [
  __DIR__ . '/../vendor/autoload.php',
  dirname(__DIR__) . '/vendor/autoload.php',
  __DIR__ . '/vendor/autoload.php',
  (isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '\\/').'/' : '').'vendor/autoload.php',
  getcwd() . '/vendor/autoload.php',
];

foreach ($autoload_candidates as $cand) {
  if (is_file($cand)) {
    require_once $cand;
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer', true)) {
      $phpmailer_version = 6;
      $loaded_from = 'composer';
      break;
    }
  }
}

// ----- Manual v6 fallback: /libs/PHPMailer/src -----
if ($phpmailer_version === 0) {
  $src = __DIR__ . '/../libs/PHPMailer/src';
  $ph  = $src . '/PHPMailer.php';
  $sm  = $src . '/SMTP.php';
  $ex  = $src . '/Exception.php';
  if (is_file($ph) && is_file($sm) && is_file($ex)) {
    require_once $ex;
    require_once $sm;
    require_once $ph;
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer', false)) {
      $phpmailer_version = 6;
      $loaded_from = 'libs-v6';
    }
  }
}

// ----- Manual v5 fallback: /libs/PHPMailer (legacy, non-namespaced) -----
if ($phpmailer_version === 0) {
  $legacy = __DIR__ . '/../libs/PHPMailer';
  $v5a = $legacy . '/class.phpmailer.php';
  $v5b = $legacy . '/class.smtp.php';
  $autoload_v5 = $legacy . '/PHPMailerAutoload.php';
  if (is_file($autoload_v5)) {
    require_once $autoload_v5;
  } else {
    if (is_file($v5a)) require_once $v5a;
    if (is_file($v5b)) require_once $v5b;
  }
  if (class_exists('PHPMailer', false)) {
    $phpmailer_version = 5;
    $loaded_from = 'libs-v5';
  }
}

// ----- Load mail config (SMTP_* constants) -----
if (!defined('SMTP_HOST')) {
  $cfg = __DIR__ . '/../mail_config.php';
  if (is_file($cfg)) {
    require_once $cfg;
  }
}

/**
 * Utility: is mailer available?
 */
function pm_mailer_available(): array {
  global $phpmailer_version, $loaded_from;
  $has_class = ($phpmailer_version === 6 && class_exists('\\PHPMailer\\PHPMailer\\PHPMailer', false))
            || ($phpmailer_version === 5 && class_exists('PHPMailer', false));
  $has_cfg = defined('SMTP_HOST') && defined('SMTP_USER') && defined('SMTP_PASS') && defined('SMTP_PORT');
  return [
    'available' => $has_class && $has_cfg,
    'version'   => $phpmailer_version,
    'source'    => $loaded_from,
    'has_class' => $has_class,
    'has_cfg'   => $has_cfg,
  ];
}

/**
 * Send an email (works for PHPMailer v6 or v5). Returns ['success'=>bool, 'error'?:string]
 *
 * @param array{
 *   to: array<int,array{0:string,1?:string}>,
 *   subject: string,
 *   body: string,
 *   alt_body?: string,
 *   is_html?: bool,
 *   reply_to?: array{0:string,1?:string}
 * } $opts
 */
function pm_send_mail(array $opts): array {
  $avail = pm_mailer_available();
  if (!$avail['available']) {
    error_log('[MAIL] PHPMailer not available. Details: '.json_encode($avail));
    return ['success' => false, 'error' => 'PHPMailer not installed/configured'];
  }

  global $phpmailer_version;

  try {
    if ($phpmailer_version === 6) {
      // Namespaced v6
      $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
      $mail->isSMTP();
      $mail->Host       = SMTP_HOST;
      $mail->SMTPAuth   = true;
      $mail->Username   = SMTP_USER;
      $mail->Password   = SMTP_PASS;
      $mail->Port       = SMTP_PORT;
      $mail->CharSet    = 'UTF-8';

      $secure = strtolower((string)(defined('SMTP_SECURE') ? SMTP_SECURE : 'tls'));
      if ($secure === 'ssl' || $secure === 'smtps') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;    // 465
      } else {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; // 587
      }

      if (defined('MAIL_DEBUG') && MAIL_DEBUG) {
        $mail->SMTPDebug   = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        $mail->Debugoutput = 'error_log';
      }

      $fromEmail = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : SMTP_USER;
      $fromName  = defined('MAIL_FROM_NAME')  ? MAIL_FROM_NAME  : '';
      $mail->setFrom($fromEmail, $fromName);

      foreach ((array)($opts['to'] ?? []) as $to) {
        if (!empty($to[0])) $mail->addAddress($to[0], $to[1] ?? '');
      }

      if (!empty($opts['reply_to'][0])) {
        $mail->addReplyTo($opts['reply_to'][0], $opts['reply_to'][1] ?? '');
      }

      $mail->isHTML($opts['is_html'] ?? true);
      $mail->Subject = (string)($opts['subject'] ?? '');
      $mail->Body    = (string)($opts['body'] ?? '');
      $mail->AltBody = (string)($opts['alt_body'] ?? strip_tags($opts['body'] ?? ''));

      $mail->send();
      return ['success' => true];

    } elseif ($phpmailer_version === 5) {
      // Legacy v5 (non-namespaced)
      $mail = new \PHPMailer(true);
      $mail->isSMTP();
      $mail->Host       = SMTP_HOST;
      $mail->SMTPAuth   = true;
      $mail->Username   = SMTP_USER;
      $mail->Password   = SMTP_PASS;
      $mail->Port       = SMTP_PORT;
      $mail->CharSet    = 'UTF-8';

      $secure = strtolower((string)(defined('SMTP_SECURE') ? SMTP_SECURE : 'tls'));
      $mail->SMTPSecure = ($secure === 'ssl' || $secure === 'smtps') ? 'ssl' : 'tls';

      if (defined('MAIL_DEBUG') && MAIL_DEBUG) {
        $mail->SMTPDebug   = 2;           // v5 style
        $mail->Debugoutput = 'error_log';
      }

      $fromEmail = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : SMTP_USER;
      $fromName  = defined('MAIL_FROM_NAME')  ? MAIL_FROM_NAME  : '';
      $mail->setFrom($fromEmail, $fromName);

      foreach ((array)($opts['to'] ?? []) as $to) {
        if (!empty($to[0])) $mail->addAddress($to[0], $to[1] ?? '');
      }

      if (!empty($opts['reply_to'][0])) {
        $mail->addReplyTo($opts['reply_to'][0], $opts['reply_to'][1] ?? '');
      }

      $mail->isHTML(!isset($opts['is_html']) ? true : (bool)$opts['is_html']);
      $mail->Subject = (string)($opts['subject'] ?? '');
      $mail->Body    = (string)($opts['body'] ?? '');
      $mail->AltBody = (string)($opts['alt_body'] ?? strip_tags($opts['body'] ?? ''));

      $mail->send();
      return ['success' => true];
    }

    // Shouldn’t reach here
    return ['success' => false, 'error' => 'Unknown PHPMailer version'];

  } catch (\Exception $e) {
    // v6 throws PHPMailer\Exception; v5 throws \phpmailerException but it extends \Exception
    $err = method_exists($mail ?? null, 'ErrorInfo') ? ($mail->ErrorInfo ?? $e->getMessage()) : $e->getMessage();
    return ['success' => false, 'error' => $err];
  }
}
