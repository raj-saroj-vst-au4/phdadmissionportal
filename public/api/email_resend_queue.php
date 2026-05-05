<?php
// Build recipient queue from a comma-separated list of RMG (dept_reg_no) numbers.
// Returns JSON list of {id, name, email, dept_reg_no, ...} for use by the email send loop.
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
check_csrf();

$password = $_POST['password'] ?? '';
if ($password === '' || empty($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
    json_out(['ok'=>false,'error'=>'Incorrect password'], 401);
}

$intake = active_intake();
if (!$intake) json_out(['ok'=>false,'error'=>'No active intake'], 400);

$phase   = $_POST['phase'] ?? 'written';
$rmgRaw  = trim($_POST['rmg_list'] ?? '');
if ($rmgRaw === '') json_out(['ok'=>false,'error'=>'No RMG numbers provided'], 400);

$rmgList = array_values(array_unique(array_filter(array_map('trim',
    preg_split('/[\s,;]+/', $rmgRaw)))));
if (count($rmgList) === 0) json_out(['ok'=>false,'error'=>'No valid RMG numbers'], 400);
if (count($rmgList) > 50)  json_out(['ok'=>false,'error'=>'Limit 50 RMG numbers per resend'], 400);

$placeholders = implode(',', array_fill(0, count($rmgList), '?'));
$params = array_merge([$intake['id']], $rmgList);

if ($phase === 'written') {
    $rows = all("SELECT c.id, c.dept_reg_no, c.name, c.email, c.applicant_id, c.photo,
                        r.name room_name, a.seat_no
                 FROM candidates c
                 LEFT JOIN room_assignments a ON a.candidate_id=c.id
                 LEFT JOIN rooms r ON r.id=a.room_id
                 WHERE c.intake_id=? AND c.is_international=0
                 AND c.dept_reg_no IN ($placeholders)
                 ORDER BY c.serial_no, c.id", $params);
} elseif ($phase === 'interview' || $phase === 'final') {
    $rows = all("SELECT id, dept_reg_no, name, email FROM candidates
                 WHERE intake_id=? AND is_international=0
                 AND dept_reg_no IN ($placeholders)
                 ORDER BY serial_no, id", $params);
} else {
    json_out(['ok'=>false,'error'=>'Invalid phase'], 400);
}

$matched = array_map(fn($r) => $r['dept_reg_no'], $rows);
$missing = array_values(array_diff($rmgList, $matched));
$noEmail = [];
$recipients = [];
foreach ($rows as $r) {
    if (empty($r['email'])) $noEmail[] = $r['dept_reg_no'];
    else $recipients[] = $r;
}

if (count($recipients) === 0) {
    json_out([
      'ok' => false,
      'error' => 'No deliverable recipients matched (missing or no email).',
      'missing' => $missing,
      'no_email' => $noEmail,
    ]);
}

q('INSERT INTO email_log(intake_id,phase,recipient_count,subject,body_preview,sent_by,status)
   VALUES(?,?,?,?,?,?,?)',
  [$intake['id'], $phase, 0, $_POST['subject'] ?? '',
   mb_substr($_POST['body'] ?? '', 0, 400), $u['id'], 'sending']);
$batchId = (int)db()->lastInsertId();

$exam_dt_display = '';
if (!empty($intake['entrance_datetime'])) {
    $ts = strtotime($intake['entrance_datetime']);
    if ($ts) $exam_dt_display = date('d M Y, h:i A', $ts);
}

json_out([
  'ok' => true,
  'batch_id' => $batchId,
  'recipients' => $recipients,
  'missing' => $missing,
  'no_email' => $noEmail,
  'intake_name' => $intake['name'],
  'exam_datetime' => $exam_dt_display,
  'entrance_mode' => $intake['entrance_mode'] ?? '',
]);
