<?php
// Mark an email batch as finished.
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
check_csrf();
$batchId = (int)($_POST['batch_id'] ?? 0);
$status  = $_POST['status'] ?? 'sent';
if (!$batchId) json_out(['ok'=>false], 400);
q('UPDATE email_log SET status=? WHERE id=?', [$status, $batchId]);
json_out(['ok'=>true]);
