<?php
// Build recipient queue for the Panel Coordinator Invite flow.
// Mirrors the data-source used on /admin/panels.php: every active `role='panel'`
// user with a panel_code assignment AND an email on file. For each one we
// generate a fresh random password, replace their password_hash, and return the
// plaintext password to the browser so it can be templated into the outgoing
// email via email_panel_send_one.php. The plaintext is never persisted.
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
check_csrf();

// Admin password re-confirmation.
$password = $_POST['password'] ?? '';
if ($password === '' || empty($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
    json_out(['ok'=>false,'error'=>'Incorrect password'], 401);
}

$intake = active_intake();
if (!$intake) json_out(['ok'=>false,'error'=>'No active intake'], 400);

// Source: same query basis as /admin/panels.php (Panel Members listing) and
// /admin/users.php (panel-role users). Active panel users with an email get an
// invite; the panel_code / area are joined in for template substitution and
// may be null/empty for users not yet assigned to a panel.
$rows = all("SELECT u.id, u.username, u.full_name, u.email,
                    COALESCE(u.panel_code,'') panel_code,
                    COALESCE(p.area, u.panel_area, '') panel_area
             FROM users u
             LEFT JOIN panels p ON p.code = u.panel_code
             WHERE u.role='panel' AND u.active=1
             AND u.email IS NOT NULL AND u.email<>''
             ORDER BY u.panel_code, u.full_name");

// Generate per-user temp password, persist its hash, attach plaintext to the
// response payload (only). 12 chars from an unambiguous alphabet.
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
$alphaLen = strlen($alphabet);
$genPassword = function () use ($alphabet, $alphaLen): string {
    $out = '';
    for ($i = 0; $i < 12; $i++) $out .= $alphabet[random_int(0, $alphaLen - 1)];
    return $out;
};

foreach ($rows as &$r) {
    $pw = $genPassword();
    q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), (int)$r['id']]);
    $r['password'] = $pw;
}
unset($r);

// Open a batch row so the per-recipient log + UI counter work the same as for
// candidate sends. body_preview is truncated; the live (placeholder-substituted)
// body never gets logged.
q('INSERT INTO email_log(intake_id,phase,recipient_count,subject,body_preview,sent_by,status)
   VALUES(?,?,?,?,?,?,?)',
  [$intake['id'], 'panel_invite', 0, $_POST['subject'] ?? '', mb_substr($_POST['body'] ?? '', 0, 400), $u['id'], 'sending']);
$batchId = (int)db()->lastInsertId();

json_out([
  'ok' => true,
  'batch_id' => $batchId,
  'recipients' => $rows,
  'intake_name' => $intake['name'],
]);
