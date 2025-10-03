<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');
date_default_timezone_set($_ENV['APP_TZ'] ?? 'Europe/Rome');

/* ===========================================================
   .env LOADER (no dipendenze)
   - cerca .env in: backend/.env poi root/.env
   - popola getenv()/$_ENV/$_SERVER
   =========================================================== */
function loadEnv(): void {
  $candidates = [
    __DIR__ . '/.env',
    dirname(__DIR__) . '/.env',
  ];
  $path = null;
  foreach ($candidates as $p) { if (is_file($p)) { $path = $p; break; } }
  if (!$path) return;

  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$lines) return;

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    // Supporta KEY="value with =" e spazi
    if (!str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);

    // rimuovi eventuali quote
    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
        (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
      $v = substr($v, 1, -1);
    }

    // espansione semplice \n
    $v = str_replace(['\n', '\r'], ["\n", "\r"], $v);

    // set
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
  }
}
loadEnv();

/* ===========================================================
   Helpers ENV
   =========================================================== */
function env(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  if ($v === false) return $default;
  return $v;
}
function envBool(string $key, bool $default=false): bool {
  $v = env($key);
  if ($v === null) return $default;
  $v = strtolower(trim($v));
  return in_array($v, ['1','true','yes','on'], true);
}
function envInt(string $key, int $default=0): int {
  $v = env($key);
  if ($v === null || !is_numeric($v)) return $default;
  return (int)$v;
}
function envList(string $key): array {
  $v = env($key, '');
  if ($v === '') return [];
  $arr = preg_split('/\s*,\s*/', $v);
  return array_values(array_filter($arr, fn($x) => $x !== ''));
}

/* ===========================================================
   LOG
   =========================================================== */
$RID = substr(bin2hex(random_bytes(4)), 0, 8);
$LOG = __DIR__ . '/mail.log';
function logx(string $rid, string $m, string $file): void {
  @file_put_contents($file, '['.date('Y-m-d H:i:s')."] [$rid] $m\n", FILE_APPEND);
}

/* ===========================================================
   CONFIG da .env (ESCLUSIVAMENTE)
   =========================================================== */
$SITE_NAME = env('SITE_NAME','Sito');
$SUBJECT   = env('SUBJECT','Nuova richiesta dal sito');

$MAIL_FROM = env('MAIL_FROM','no-reply@localhost');
$TO_LIST   = envList('MAIL_TO');   // array
$CC_LIST   = envList('MAIL_CC');   // array
$BCC_LIST  = envList('MAIL_BCC');  // array

$ALLOWED_ORIGINS = envList('ALLOWED_ORIGINS');

$SMTP_ENABLED = envBool('SMTP_ENABLED', false);
$SMTP_HOST    = env('SMTP_HOST','');
$SMTP_PORT    = envInt('SMTP_PORT', 587);
$SMTP_USER    = env('SMTP_USER','');
$SMTP_PASS    = env('SMTP_PASS','');
$SMTP_SECURE  = env('SMTP_SECURE','tls'); // tls|ssl|''

$CONFIRM_ENABLED   = envBool('CONFIRM_ENABLED', false);
$CONFIRM_SUBJECT   = env('CONFIRM_SUBJECT','Abbiamo ricevuto la tua richiesta');
$CONFIRM_FROM      = env('CONFIRM_FROM', $MAIL_FROM);
$CONFIRM_FROM_NAME = env('CONFIRM_FROM_NAME', $SITE_NAME);

/* ===========================================================
   CORS
   =========================================================== */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $ALLOWED_ORIGINS, true)) {
  header("Access-Control-Allow-Origin: {$origin}");
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
  http_response_code(204); exit;
}

/* ===========================================================
   METHOD
   =========================================================== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
logx($RID, "=== Nuova richiesta ===", $LOG);
logx($RID, "METHOD={$method} URI=" . ($_SERVER['REQUEST_URI'] ?? ''), $LOG);
if ($origin) logx($RID, "Origin=$origin", $LOG);
if ($method !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Metodo non consentito. Usa POST.','rid'=>$RID]);
  logx($RID, "ERRORE: metodo non consentito", $LOG);
  exit;
}

/* ===========================================================
   INPUT
   =========================================================== */
$input = [];
if (!empty($_POST)) {
  $input = $_POST;
  logx($RID, "Input: POST form-data", $LOG);
} else {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
      $input = $json;
      logx($RID, "Input: JSON", $LOG);
    }
  }
}

/* ===========================================================
   CAMPI
   =========================================================== */
$strip = static function (?string $v): string {
  $v = (string)$v;
  return trim(str_replace(["\r","\n"], ' ', $v));
};
$name    = $strip($input['name']    ?? '');
$email   = $strip($input['email']   ?? '');
$phone   = $strip($input['phone']   ?? '');
$message = trim((string)($input['message'] ?? ''));

/* ===========================================================
   VALIDAZIONI
   =========================================================== */
$errors = [];
if (mb_strlen($name) < 2) $errors[] = 'Il nome è troppo corto.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email non valida.';
if (mb_strlen($message) < 2) $errors[] = 'Il messaggio è obbligatorio.';
if (count($TO_LIST) === 0) $errors[] = 'Destinatario non configurato (.env MAIL_TO).';

if ($errors) {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>implode(' ', $errors),'rid'=>$RID]);
  logx($RID, "Validazioni FAIL: ".implode(' | ', $errors), $LOG);
  exit;
}

/* ===========================================================
   BODY EMAIL (admin + conferma)
   =========================================================== */
$now = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))
          ->format('d/m/Y H:i');
$ip   = $_SERVER['REMOTE_ADDR']     ?? 'n/d';
$ua   = $_SERVER['HTTP_USER_AGENT'] ?? 'n/d';

$safe = fn($s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$nameSafe = $safe($name);
$emailSafe = $safe($email);
$phoneSafe = $safe($phone);
$msgHtml = nl2br($safe($message), false);

$bodyAdmin = <<<HTML
<!doctype html><html lang="it"><head><meta charset="utf-8"><title>{$SUBJECT}</title>
<meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:Arial,Helvetica,sans-serif;color:#111">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;background:#f5f5f7">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 18px rgba(0,0,0,.08)">
        <tr><td style="background:#111;color:#fff;padding:18px 24px;font-size:18px;font-weight:bold">{$SITE_NAME} — Nuova richiesta</td></tr>
        <tr><td style="padding:22px 24px">
          <p style="font-size:16px;margin:0 0 12px">Hai ricevuto una nuova richiesta dal sito.</p>
          <table role="presentation" width="100%" style="border-collapse:collapse;margin-top:12px">
            <tr><td style="width:140px;padding:8px 0;color:#666;font-size:14px">Nome</td><td style="padding:8px 0;font-size:15px"><strong>{$nameSafe}</strong></td></tr>
            <tr><td style="width:140px;padding:8px 0;color:#666;font-size:14px">Email</td><td style="padding:8px 0;font-size:15px"><a href="mailto:{$emailSafe}" style="color:#0b74de;text-decoration:none">{$emailSafe}</a></td></tr>
            <tr><td style="width:140px;padding:8px 0;color:#666;font-size:14px">Telefono</td><td style="padding:8px 0;font-size:15px">{$phoneSafe}</td></tr>
            <tr><td style="width:140px;padding:8px 0;color:#666;font-size:14px;vertical-align:top">Messaggio</td><td style="padding:8px 0;font-size:15px;line-height:1.5">{$msgHtml}</td></tr>
          </table>
          <hr style="border:none;border-top:1px solid #eee;margin:18px 0">
          <p style="margin:0;color:#777;font-size:12px">Inviato il <strong>{$now}</strong><br>IP: {$ip} — User-Agent: {$ua}</p>
        </td></tr>
        <tr><td style="background:#fafafa;color:#999;font-size:12px;padding:14px 24px;text-align:center">Email generata automaticamente dal sito <strong>{$SITE_NAME}</strong></td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

$bodyConfirm = <<<HTML
<!doctype html><html lang="it"><head><meta charset="utf-8"><title>{$CONFIRM_SUBJECT}</title>
<meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:Arial,Helvetica,sans-serif;color:#111">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;background:#f5f5f7">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 18px rgba(0,0,0,.08)">
        <tr><td style="background:#5D7D52;color:#fff;padding:18px 24px;font-size:18px;font-weight:bold">{$SITE_NAME}</td></tr>
        <tr><td style="padding:22px 24px">
          <p style="font-size:16px;margin:0 0 12px">Ciao {$nameSafe},</p>
          <p style="font-size:15px;margin:0 0 12px;line-height:1.6">ti confermiamo che abbiamo ricevuto la tua richiesta. Un nostro consulente ti contatterà il prima possibile.</p>
          <p style="font-size:15px;margin:16px 0 8px"><strong>Riepilogo</strong></p>
          <ul style="font-size:15px;margin:0 0 12px 18px;line-height:1.6">
            <li><strong>Email:</strong> {$emailSafe}</li>
            <li><strong>Telefono:</strong> {$phoneSafe}</li>
          </ul>
          <p style="font-size:15px;margin:8px 0 0"><strong>Messaggio:</strong></p>
          <div style="font-size:15px;line-height:1.6;margin-top:6px;background:#fafafa;border:1px solid #eee;border-radius:8px;padding:12px">{$msgHtml}</div>
          <p style="font-size:13px;color:#666;margin:16px 0 0">Se non hai inviato tu questa richiesta, ignora questa email.</p>
        </td></tr>
        <tr><td style="background:#fafafa;color:#999;font-size:12px;padding:14px 24px;text-align:center">© {$SITE_NAME}</td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

/* ===========================================================
   INVIO (SMTP via PHPMailer se presente) + fallback mail()
   =========================================================== */
$sentAdmin = false;
$sentConfirm = false;

// tenta PHPMailer se SMTP attivo
if ($SMTP_ENABLED) {
  $autoloads = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
  ];
  $autoload = null; foreach ($autoloads as $p) { if (is_file($p)) { $autoload = $p; break; } }

  if ($autoload) {
    try {
      require_once $autoload;
      $m = new PHPMailer\PHPMailer\PHPMailer(true);
      $m->isSMTP();
      $m->Host       = $SMTP_HOST;
      $m->SMTPAuth   = true;
      $m->Username   = $SMTP_USER ?: $MAIL_FROM;
      $m->Password   = $SMTP_PASS;
      $m->Port       = $SMTP_PORT;
      if ($SMTP_SECURE === 'ssl')        $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
      elseif ($SMTP_SECURE === 'tls')    $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

      $m->CharSet = 'UTF-8';
      $m->Encoding = 'base64';

      $m->setFrom($MAIL_FROM ?: ($SMTP_USER ?: 'no-reply@localhost'), $SITE_NAME);
      foreach ($TO_LIST as $t)  $m->addAddress($t);
      foreach ($CC_LIST as $c)  $m->addCC($c);
      foreach ($BCC_LIST as $b) $m->addBCC($b);
      $m->addReplyTo($email, $name);

      $m->isHTML(true);
      $m->Subject = $SUBJECT;
      $m->Body    = $bodyAdmin;
      $m->send();
      $sentAdmin = true;
      logx($RID, "SMTP admin: OK", $LOG);

      if ($CONFIRM_ENABLED) {
        try {
          $m->clearAllRecipients();
          $m->clearReplyTos();
          $m->setFrom($CONFIRM_FROM ?: ($MAIL_FROM ?: ($SMTP_USER ?: 'no-reply@localhost')), $CONFIRM_FROM_NAME ?: $SITE_NAME);
          $m->addAddress($email, $name);
          $m->addReplyTo($MAIL_FROM ?: ($SMTP_USER ?: 'no-reply@localhost'), $SITE_NAME);
          $m->Subject = $CONFIRM_SUBJECT;
          $m->Body    = $bodyConfirm;
          $m->send();
          $sentConfirm = true;
          logx($RID, "SMTP conferma: OK", $LOG);
        } catch (Throwable $e) {
          logx($RID, "SMTP conferma: FAIL -> ".$e->getMessage(), $LOG);
        }
      }
    } catch (Throwable $e) {
      $msg = $e->getMessage();
      logx($RID, "SMTP admin: FAIL -> $msg", $LOG);
      // continua su mail()
    }
  } else {
    logx($RID, "SMTP: vendor/autoload.php non trovato", $LOG);
  }
}

// fallback mail() per admin
if (!$sentAdmin) {
  $toHeader = implode(', ', $TO_LIST);
  $headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
    'From: ' . sprintf('"%s" <%s>', addslashes($SITE_NAME), $MAIL_FROM ?: 'no-reply@localhost'),
    'Reply-To: ' . sprintf('"%s" <%s>', addslashes($name), $email),
  ];
  if ($CC_LIST)  $headers[] = 'Cc: '.implode(', ', $CC_LIST);
  if ($BCC_LIST) $headers[] = 'Bcc: '.implode(', ', $BCC_LIST);
  $headersStr = implode("\r\n", $headers);

  $senderParam = '';
  $envSender = $MAIL_FROM ?: $SMTP_USER;
  if ($envSender && filter_var($envSender, FILTER_VALIDATE_EMAIL)) {
    $senderParam = '-f ' . preg_replace('/[^a-z0-9@\.\-_+]/i', '', $envSender);
  }

  logx($RID, "mail() admin: invio a [$toHeader]", $LOG);
  $ok = @mail($toHeader, $SUBJECT, $bodyAdmin, $headersStr, $senderParam);
  if (!$ok) $ok = @mail($toHeader, $SUBJECT, $bodyAdmin, $headersStr);
  $sentAdmin = $ok;
  logx($RID, "mail() admin: ".($ok?'OK':'FAIL'), $LOG);

  // conferma cliente (non blocca)
  if ($sentAdmin && $CONFIRM_ENABLED) {
    $h = [
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'X-Mailer: PHP/' . PHP_VERSION,
      'From: ' . sprintf('"%s" <%s>', addslashes($CONFIRM_FROM_NAME ?: $SITE_NAME), $CONFIRM_FROM ?: ($MAIL_FROM ?: 'no-reply@localhost')),
      'Reply-To: ' . sprintf('"%s" <%s>', addslashes($SITE_NAME), $MAIL_FROM ?: 'no-reply@localhost'),
    ];
    $hStr = implode("\r\n", $h);
    $senderParam2 = '';
    $env2 = $CONFIRM_FROM ?: $MAIL_FROM ?: $SMTP_USER;
    if ($env2 && filter_var($env2, FILTER_VALIDATE_EMAIL)) {
      $senderParam2 = '-f ' . preg_replace('/[^a-z0-9@\.\-_+]/i', '', $env2);
    }
    logx($RID, "mail() conferma: invio a [$email]", $LOG);
    $ok2 = @mail($email, $CONFIRM_SUBJECT, $bodyConfirm, $hStr, $senderParam2);
    if (!$ok2) $ok2 = @mail($email, $CONFIRM_SUBJECT, $bodyConfirm, $hStr);
    $sentConfirm = $ok2;
    logx($RID, "mail() conferma: ".($ok2?'OK':'FAIL'), $LOG);
  }
}

/* ===========================================================
   RESPONSE
   =========================================================== */
if ($sentAdmin) {
  echo json_encode(['ok'=>true,'message'=>'Richiesta inviata correttamente. Ti ricontatteremo al più presto!','confirm_sent'=>$CONFIRM_ENABLED ? $sentConfirm : null]);
} else {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Errore durante l’invio. Riprova tra qualche minuto.','rid'=>$RID]);
}
