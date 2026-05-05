<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log'])) {
    check_csrf();
    $lid = (int)$_POST['log_id'];
    $log = one('SELECT * FROM upload_logs WHERE id=?', [$lid]);
    if ($log) {
        $path = UPLOAD_EXCEL_DIR . '/' . $log['filename'];
        if (is_file($path)) @unlink($path);
        q('DELETE FROM upload_logs WHERE id=?', [$lid]);
        flash_set('Upload log deleted (' . $log['filename'] . ')', 'success');
    }
    redirect('/phdportal/admin/upload.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['excel']['name'])) {
    check_csrf();
    if (!$intake) {
        flash_set('No active intake. Create and activate one first.', 'error');
        redirect('/phdportal/admin/intakes.php');
    }
    if ($_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
        flash_set('Please select a valid .xlsx file.', 'error');
        redirect('/phdportal/admin/upload.php');
    }
    if (!is_dir(UPLOAD_EXCEL_DIR)) mkdir(UPLOAD_EXCEL_DIR, 0775, true);
    $fn = UPLOAD_EXCEL_DIR . '/' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/','_', basename($_FILES['excel']['name']));
    move_uploaded_file($_FILES['excel']['tmp_name'], $fn);

    // Invoke Python extractor
    $cmd = escapeshellcmd(PYTHON_BIN) . ' ' . escapeshellarg(EXTRACT_SCRIPT) . ' ' . escapeshellarg($fn) . ' 2>&1';
    $descr = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($cmd, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) {
        flash_set('Failed to run extractor.', 'error');
        redirect('/phdportal/admin/upload.php');
    }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc = proc_close($proc);

    if ($rc !== 0) {
        flash_set('Extractor failed: ' . trim($err ?: $out), 'error');
        redirect('/phdportal/admin/upload.php');
    }
    $records = json_decode($out, true);
    if (!is_array($records)) {
        flash_set('Extractor returned invalid JSON.', 'error');
        redirect('/phdportal/admin/upload.php');
    }

    // Insert / upsert rows
    $ins = 0; $upd = 0; $skip = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO candidates(
            intake_id, serial_no, applicant_id, dept_reg_no, name, email, gender, birth_category,
            ews, disabled, cfti, iit_btech, categories_applied, academic_record, qualifying_exam,
            exam_status, qualifying_discipline, passing_year, percentage, original_percentage,
            original_percentage_out_of, cpi_grade, gate_score, gate_year, gate_regn, work_experience,
            fellowship, research_interest_selected, research_interest_other, remark
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            serial_no=VALUES(serial_no), applicant_id=VALUES(applicant_id), name=VALUES(name),
            email=VALUES(email), gender=VALUES(gender), birth_category=VALUES(birth_category),
            ews=VALUES(ews), disabled=VALUES(disabled), cfti=VALUES(cfti), iit_btech=VALUES(iit_btech),
            categories_applied=VALUES(categories_applied), academic_record=VALUES(academic_record),
            qualifying_exam=VALUES(qualifying_exam), exam_status=VALUES(exam_status),
            qualifying_discipline=VALUES(qualifying_discipline), passing_year=VALUES(passing_year),
            percentage=VALUES(percentage), original_percentage=VALUES(original_percentage),
            original_percentage_out_of=VALUES(original_percentage_out_of), cpi_grade=VALUES(cpi_grade),
            gate_score=VALUES(gate_score), gate_year=VALUES(gate_year), gate_regn=VALUES(gate_regn),
            work_experience=VALUES(work_experience), fellowship=VALUES(fellowship),
            research_interest_selected=VALUES(research_interest_selected),
            research_interest_other=VALUES(research_interest_other),
            remark=COALESCE(NULLIF(VALUES(remark),""), remark)');

        foreach ($records as $r) {
            $dept = trim($r['dept_reg_no'] ?? '');
            if (!$dept) { $skip++; continue; }
            $exists = one('SELECT id FROM candidates WHERE intake_id=? AND dept_reg_no=? AND is_international=0', [$intake['id'],$dept]);
            $stmt->execute([
                $intake['id'],
                (int)($r['serial_no'] ?? 0) ?: null,
                $r['applicant_id'] ?? null,
                $dept,
                mb_strtoupper(trim($r['name'] ?? ''), 'UTF-8'),
                $r['email'] ?? null,
                normalize_gender($r['gender'] ?? null),
                normalize_birth_category($r['birth_category'] ?? null),
                $r['ews'] ?? null,
                $r['disabled'] ?? null,
                $r['cfti'] ?? null,
                $r['iit_btech'] ?? null,
                normalize_categories_applied($r['categories_applied'] ?? null),
                $r['academic_record'] ?? null,
                $r['qualifying_exam'] ?? null,
                $r['exam_status'] ?? null,
                $r['qualifying_discipline'] ?? null,
                $r['passing_year'] ?? null,
                $r['percentage'] ?? null,
                $r['original_percentage'] ?? null,
                $r['original_percentage_out_of'] ?? null,
                $r['cpi_grade'] ?? null,
                $r['gate_score'] ?? null,
                $r['gate_year'] ?? null,
                $r['gate_regn'] ?? null,
                $r['work_experience'] ?? null,
                $r['fellowship'] ?? null,
                $r['research_interest_selected'] ?? null,
                $r['research_interest_other'] ?? null,
                $r['remark'] ?? null,
            ]);
            if ($exists) $upd++; else $ins++;
        }
        q('INSERT INTO upload_logs(intake_id, filename, rows_inserted, rows_updated, rows_skipped, uploaded_by) VALUES(?,?,?,?,?,?)',
            [$intake['id'], basename($fn), $ins, $upd, $skip, $u['id']]);
        $pdo->commit();
        $result = compact('ins','upd','skip');
        flash_set("Upload complete: $ins inserted, $upd updated, $skip skipped.", 'success');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('DB error: ' . $e->getMessage(), 'error');
    }
    redirect('/phdportal/admin/upload.php');
}

$logs = all('SELECT l.*, u.username FROM upload_logs l LEFT JOIN users u ON u.id=l.uploaded_by ORDER BY l.id DESC LIMIT 10');

render_header('Upload Excel', $u);
?>
<h1 class="text-2xl font-semibold mb-5">Upload Master Excel File</h1>
<div class="grid grid-cols-1 md:grid-cols-3 gap-5">
  <div class="card md:col-span-2">
    <p class="text-sm text-slate-600 mb-3">
      Upload the master .xlsx file. Data is extracted and mapped to the active intake
      (<strong><?= $intake ? h($intake['name']) : 'none' ?></strong>). Re-uploading with the same
      Dept Reg. No. will update existing records.
    </p>
    <form method="post" enctype="multipart/form-data" class="space-y-3">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div>
        <label class="block text-sm font-medium mb-1">Excel File (.xlsx)</label>
        <input type="file" name="excel" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      </div>
      <button class="btn btn-primary">Upload & Extract</button>
    </form>
  </div>
  <div class="card">
    <h3 class="font-semibold mb-2">Expected columns</h3>
    <p class="text-xs text-slate-500 mb-2">(match by name - case-insensitive)</p>
    <ul class="text-xs text-slate-600 space-y-0.5 list-disc ml-4">
      <li>Dept Reg. No. <em>(required)</em></li><li>Name <em>(required)</em></li>
      <li>Gender, Birth Category, EWS, Disabled?</li>
      <li>Categories Applied to</li><li>Qualifying Exam / Discipline</li>
      <li>GATE Score/Year/Regn</li><li>Research Interests (selected / other)</li>
      <li>Remark (optional)</li>
    </ul>
  </div>
</div>

<div class="card mt-6">
  <h3 class="font-semibold mb-3">Recent Uploads</h3>
  <table class="data-table w-full">
    <thead><tr><th>Date</th><th>Intake</th><th>File</th><th>Inserted</th><th>Updated</th><th>Skipped</th><th>By</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($logs as $lg):
      $ic = one('SELECT name FROM intakes WHERE id=?', [$lg['intake_id']]); ?>
      <tr>
        <td><?= h($lg['uploaded_at']) ?></td>
        <td><?= h($ic['name'] ?? '—') ?></td>
        <td class="font-mono text-xs"><?= h($lg['filename']) ?></td>
        <td class="text-green-700 font-semibold"><?= (int)$lg['rows_inserted'] ?></td>
        <td class="text-indigo-700"><?= (int)$lg['rows_updated'] ?></td>
        <td class="text-slate-500"><?= (int)$lg['rows_skipped'] ?></td>
        <td><?= h($lg['username'] ?? '') ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete this upload log and its stored file? Candidate records already imported will remain.');">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="delete_log" value="1">
            <input type="hidden" name="log_id" value="<?= (int)$lg['id'] ?>">
            <button class="btn btn-danger text-xs">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="8" class="text-center py-4 text-slate-500">No uploads yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
