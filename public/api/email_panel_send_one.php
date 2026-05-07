<?php
// Send one Panel Coordinator Invite email and update the batch counter.
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/mailer.php';
$u = require_admin();
check_csrf();

$intake = active_intake();
if (!$intake) json_out(['ok'=>false,'error'=>'No active intake'], 400);

$batchId = (int)($_POST['batch_id'] ?? 0);
$userId  = (int)($_POST['user_id'] ?? 0);
$plainPw = (string)($_POST['password'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$body    = $_POST['body'] ?? '';

if (!$userId || !$batchId || !$subject || !$body || $plainPw === '') {
    json_out(['ok'=>false,'error'=>'Missing parameters'], 400);
}

$tgt = one("SELECT u.id, u.username, u.full_name, u.email, u.panel_code, p.area panel_area
            FROM users u
            LEFT JOIN panels p ON p.code = u.panel_code
            WHERE u.id=? AND u.role='panel' AND u.active=1", [$userId]);
if (!$tgt || !$tgt['email']) json_out(['ok'=>false,'error'=>'No email for user']);

// Build the absolute login URL from the request so deployments at any
// scheme/host work without extra config.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$loginUrl = $scheme . '://' . $host . '/phdportal/login.php';

$placeholders = ['{{full_name}}','{{username}}','{{password}}','{{login_url}}','{{panel_code}}','{{panel_area}}','{{intake}}','{{name}}'];
$values       = [$tgt['full_name'], $tgt['username'], $plainPw, $loginUrl,
                 $tgt['panel_code'] ?? '', $tgt['panel_area'] ?? '', $intake['name'], $tgt['full_name']];

$subject  = str_replace($placeholders, $values, $subject);
$personal = str_replace($placeholders, $values, $body);
$html     = nl2br(h($personal));

$res = send_mail($tgt['email'], $tgt['full_name'], $subject, $html, []);

if ($res['ok']) {
    q('UPDATE email_log SET recipient_count = recipient_count + 1 WHERE id=?', [$batchId]);
}
// candidate_id holds the user_id and dept_reg_no holds the username — keeps the
// existing batch-view UI usable without a schema change.
q('INSERT INTO email_log_recipient(batch_id,candidate_id,dept_reg_no,email,status,error_msg)
   VALUES(?,?,?,?,?,?)',
  [$batchId, $tgt['id'], $tgt['username'], $tgt['email'],
   $res['ok'] ? 'sent' : 'failed',
   $res['ok'] ? null : mb_substr((string)$res['error'], 0, 500)]);

json_out([
    'ok' => $res['ok'],
    'error' => $res['error'],
    'email' => $tgt['email'],
]);
