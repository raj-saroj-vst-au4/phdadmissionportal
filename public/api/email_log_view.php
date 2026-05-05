<?php
// Return per-recipient log for a single email batch.
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();

$batchId = (int)($_GET['batch_id'] ?? 0);
if (!$batchId) json_out(['ok'=>false,'error'=>'Missing batch_id'], 400);

$batch = one('SELECT id, intake_id, phase, recipient_count, subject, sent_at, status
              FROM email_log WHERE id=?', [$batchId]);
if (!$batch) json_out(['ok'=>false,'error'=>'Batch not found'], 404);

$rows = all('SELECT dept_reg_no, email, status, error_msg, sent_at
             FROM email_log_recipient WHERE batch_id=? ORDER BY id', [$batchId]);

$counts = ['sent'=>0, 'failed'=>0];
foreach ($rows as $r) {
    if ($r['status'] === 'sent') $counts['sent']++;
    elseif ($r['status'] === 'failed') $counts['failed']++;
}

json_out([
  'ok' => true,
  'batch' => $batch,
  'recipients' => $rows,
  'counts' => $counts,
]);
