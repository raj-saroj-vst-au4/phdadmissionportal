<?php
// Send one email in the current batch and update counter.
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/mailer.php';
$u = require_admin();
check_csrf();

$intake = active_intake();
if (!$intake) json_out(['ok'=>false,'error'=>'No active intake'], 400);

$batchId = (int)($_POST['batch_id'] ?? 0);
$candId  = (int)($_POST['cand_id'] ?? 0);
$subject = trim($_POST['subject'] ?? '');
$body    = $_POST['body'] ?? '';
$attB64  = $_POST['attachment'] ?? '';
$attName = trim($_POST['attachment_name'] ?? '');

if (!$candId || !$batchId || !$subject || !$body) {
    json_out(['ok'=>false,'error'=>'Missing parameters'], 400);
}

$c = one('SELECT id,name,email,dept_reg_no FROM candidates WHERE id=? AND intake_id=? AND is_international=0', [$candId, $intake['id']]);
if (!$c || !$c['email']) json_out(['ok'=>false,'error'=>'No email for candidate']);

$placeholders = ['{{name}}','{{dept_reg_no}}','{{intake}}'];
$values       = [$c['name'], $c['dept_reg_no'], $intake['name']];
$subject      = str_replace($placeholders, $values, $subject);
$personal     = str_replace($placeholders, $values, $body);
$html         = nl2br(h($personal));

$attachments = [];
if ($attB64 !== '' && $attName !== '') {
    $bin = base64_decode($attB64, true);
    if ($bin === false) json_out(['ok'=>false,'error'=>'Invalid attachment encoding']);
    $attachments[] = ['data' => $bin, 'name' => $attName, 'mime' => 'application/pdf'];
}

$res = send_mail($c['email'], $c['name'], $subject, $html, $attachments);

if ($res['ok']) {
    q('UPDATE email_log SET recipient_count = recipient_count + 1 WHERE id=?', [$batchId]);
}
json_out([
    'ok' => $res['ok'],
    'error' => $res['error'],
    'email' => $c['email'],
]);
