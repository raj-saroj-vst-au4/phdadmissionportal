<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
check_csrf();
$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? 'Pending';
if (!in_array($status, ['Pending','Yes','No','Doubtful'], true)) {
    json_out(['ok' => false, 'error' => 'Invalid status'], 400);
}
$cand = one('SELECT intake_id FROM candidates WHERE id=? AND is_international=0', [$id]);
if (!$cand) json_out(['ok'=>false,'error'=>'Not found'], 404);
if ((bool)setting('shortlist_frozen_' . $cand['intake_id'])) {
    json_out(['ok'=>false,'error'=>'Shortlist is frozen'], 403);
}
q('UPDATE candidates SET screening_status=? WHERE id=? AND is_international=0', [$status, $id]);
json_out(['ok' => true]);
