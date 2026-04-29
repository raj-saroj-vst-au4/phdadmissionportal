<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_login();
$intake = active_intake();
if (!$intake) json_out([]);
$q = trim($_GET['q'] ?? '');
if ($q === '') json_out([]);
$like = "%$q%";
$where = 'intake_id=? AND (dept_reg_no LIKE ? OR name LIKE ? OR applicant_id LIKE ?)';
$params = [$intake['id'], $like, $like, $like];
if ($u['role'] === 'panel') {
    $where .= ' AND panel_code = ?';
    $params[] = $u['panel_code'];
}
$rows = all("SELECT id, dept_reg_no, name, birth_category FROM candidates WHERE $where ORDER BY dept_reg_no LIMIT 15", $params);
json_out($rows);
