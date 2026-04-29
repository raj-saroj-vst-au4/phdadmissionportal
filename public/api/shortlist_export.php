<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
$intake = active_intake();
if (!$intake) json_out([]);
$status = $_GET['status'] ?? 'Yes';
if (!in_array($status, ['Yes','No','Pending','Doubtful'], true)) json_out([]);

if ($status === 'No') {
    $und = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND screening_status IN ('Pending','Doubtful')", [$intake['id']])['c'];
    if ($und > 0) {
        json_out(['error' => "Cannot export Rejected list: $und candidate(s) still have Pending/Doubtful shortlist status. Resolve all shortlist decisions first."], 400);
    }
    $missing = all("SELECT dept_reg_no FROM candidates WHERE intake_id=? AND screening_status='No' AND (remark IS NULL OR TRIM(remark)='') ORDER BY serial_no, id", [$intake['id']]);
    if ($missing) {
        $names = array_slice(array_column($missing, 'dept_reg_no'), 0, 10);
        $extra = count($missing) - count($names);
        $msg = 'Cannot export Rejected list: ' . count($missing) . ' rejected candidate(s) have no remark — '
             . implode(', ', $names) . ($extra ? " … and $extra more" : '')
             . '. Add a remark on each rejected candidate before exporting.';
        json_out(['error' => $msg], 400);
    }
}

$rows = all("SELECT serial_no, dept_reg_no, name, birth_category, gender, categories_applied,
             research_interest_selected, written_marks, remark
             FROM candidates WHERE intake_id=? AND screening_status=?
             ORDER BY serial_no, id", [$intake['id'], $status]);
json_out($rows);
