<?php
// Build recipient queue for a given phase. Returns JSON list of {id, name, email, dept_reg_no}.
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
check_csrf();

// Require admin password re-confirmation before starting a send batch.
$password = $_POST['password'] ?? '';
if ($password === '' || empty($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
    json_out(['ok'=>false,'error'=>'Incorrect password'], 401);
}

$intake = active_intake();
if (!$intake) json_out(['ok'=>false,'error'=>'No active intake'], 400);

$phase = $_POST['phase'] ?? 'written';
$is_intl = 0;

if ($phase === 'written') {
    $rows = all("SELECT c.id, c.dept_reg_no, c.name, c.email, c.applicant_id, c.photo,
                        r.name room_name, a.seat_no
                 FROM candidates c
                 LEFT JOIN room_assignments a ON a.candidate_id=c.id
                 LEFT JOIN rooms r ON r.id=a.room_id
                 WHERE c.intake_id=? AND c.is_international=? AND c.screening_status='Yes'
                 AND c.email IS NOT NULL AND c.email <> ''
                 ORDER BY c.serial_no, c.id", [$intake['id'], $is_intl]);
} elseif ($phase === 'interview') {
    $rows = all("SELECT id, dept_reg_no, name, email FROM candidates
                 WHERE intake_id=? AND is_international=? AND screening_status='Yes'
                 AND written_marks IS NOT NULL AND passed_cutoff=1
                 AND email IS NOT NULL AND email <> ''
                 ORDER BY serial_no, id", [$intake['id'], $is_intl]);
} elseif ($phase === 'final') {
    $rows = all("SELECT id, dept_reg_no, name, email FROM candidates
                 WHERE intake_id=? AND is_international=? AND final_status='Selected'
                 AND email IS NOT NULL AND email <> ''
                 ORDER BY serial_no, id", [$intake['id'], $is_intl]);
} else {
    json_out(['ok'=>false,'error'=>'Invalid phase'], 400);
}

// Create a log row so we can update the counter as mails go out
q('INSERT INTO email_log(intake_id,phase,recipient_count,subject,body_preview,sent_by,status)
   VALUES(?,?,?,?,?,?,?)',
  [$intake['id'], $phase, 0, $_POST['subject'] ?? '', mb_substr($_POST['body'] ?? '', 0, 400), $u['id'], 'sending']);
$batchId = (int)db()->lastInsertId();

$exam_dt_display = '';
if (!empty($intake['entrance_datetime'])) {
    $ts = strtotime($intake['entrance_datetime']);
    if ($ts) $exam_dt_display = date('d M Y, h:i A', $ts);
}

json_out([
  'ok' => true,
  'batch_id' => $batchId,
  'recipients' => $rows,
  'intake_name' => $intake['name'],
  'exam_datetime' => $exam_dt_display,
  'entrance_mode' => $intake['entrance_mode'] ?? '',
]);
