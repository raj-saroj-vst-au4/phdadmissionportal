<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
check_csrf();
$id = (int)($_POST['id'] ?? 0);
$field = $_POST['field'] ?? '';
$value = $_POST['value'] ?? '';
$allowed = [
    'final_status' => ['Pending','Selected','Not Selected','Waitlisted'],
    'final_category' => array_merge([''], FINAL_CATEGORIES),
    'birth_category_number' => null, // free text
];
if (!array_key_exists($field, $allowed)) json_out(['ok'=>false,'error'=>'Bad field'], 400);
if ($allowed[$field] !== null && !in_array($value, $allowed[$field], true)) {
    json_out(['ok'=>false,'error'=>'Bad value'], 400);
}
$cand = one('SELECT intake_id FROM candidates WHERE id=?', [$id]);
if (!$cand) json_out(['ok'=>false,'error'=>'Not found'], 404);
if ((bool)setting('final_frozen_' . $cand['intake_id'])) {
    json_out(['ok'=>false,'error'=>'Final selection is frozen'], 403);
}
$sql = "UPDATE candidates SET `$field` = ? WHERE id = ?";
q($sql, [$value === '' ? null : $value, $id]);
json_out(['ok'=>true]);
