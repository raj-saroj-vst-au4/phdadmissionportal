<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

// Upload Excel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['excel']['name'])) {
    check_csrf();
    if ($_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
        flash_set('Please select a valid .xlsx file.', 'error');
        redirect('/phdportal/intl/');
    }
    if (!is_dir(UPLOAD_EXCEL_DIR)) mkdir(UPLOAD_EXCEL_DIR, 0775, true);
    $fn = UPLOAD_EXCEL_DIR . '/intl_' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/','_', basename($_FILES['excel']['name']));
    move_uploaded_file($_FILES['excel']['tmp_name'], $fn);
    $cmd = escapeshellcmd(PYTHON_BIN) . ' ' . escapeshellarg(EXTRACT_SCRIPT) . ' ' . escapeshellarg($fn) . ' 2>&1';
    $proc = proc_open($cmd, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) { flash_set('Extractor failed to start.', 'error'); redirect('/phdportal/intl/'); }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $rc = proc_close($proc);
    if ($rc !== 0) { flash_set('Extractor: ' . trim($err ?: $out), 'error'); redirect('/phdportal/intl/'); }
    $records = json_decode($out, true);
    if (!is_array($records)) { flash_set('Invalid data from extractor.', 'error'); redirect('/phdportal/intl/'); }
    $ins = 0; $upd = 0; $skip = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO candidates(
            intake_id, is_international, serial_no, applicant_id, dept_reg_no, name, email, gender, birth_category,
            categories_applied, qualifying_exam, qualifying_discipline, passing_year, percentage,
            cpi_grade, work_experience, research_interest_selected, research_interest_other, remark
        ) VALUES (?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            serial_no=VALUES(serial_no), name=VALUES(name), email=VALUES(email), gender=VALUES(gender),
            categories_applied=VALUES(categories_applied), qualifying_exam=VALUES(qualifying_exam),
            qualifying_discipline=VALUES(qualifying_discipline), passing_year=VALUES(passing_year),
            percentage=VALUES(percentage), cpi_grade=VALUES(cpi_grade),
            work_experience=VALUES(work_experience),
            research_interest_selected=VALUES(research_interest_selected),
            research_interest_other=VALUES(research_interest_other)');
        foreach ($records as $r) {
            $dept = trim($r['dept_reg_no'] ?? '');
            if (!$dept) { $skip++; continue; }
            $exists = one('SELECT id FROM candidates WHERE intake_id=? AND dept_reg_no=?', [$intake['id'], $dept]);
            $stmt->execute([
                $intake['id'],
                (int)($r['serial_no'] ?? 0) ?: null,
                $r['applicant_id'] ?? null,
                $dept,
                $r['name'] ?? '',
                $r['email'] ?? null,
                normalize_gender($r['gender'] ?? null),
                normalize_birth_category($r['birth_category'] ?? null),
                $r['categories_applied'] ?? null,
                $r['qualifying_exam'] ?? null,
                $r['qualifying_discipline'] ?? null,
                $r['passing_year'] ?? null,
                $r['percentage'] ?? null,
                $r['cpi_grade'] ?? null,
                $r['work_experience'] ?? null,
                $r['research_interest_selected'] ?? null,
                $r['research_interest_other'] ?? null,
                $r['remark'] ?? null,
            ]);
            if ($exists) $upd++; else $ins++;
        }
        $pdo->commit();
        flash_set("International import complete: $ins inserted, $upd updated, $skip skipped.", 'success');
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('DB error: ' . $e->getMessage(), 'error');
    }
    redirect('/phdportal/intl/');
}

$cands = all("SELECT id, serial_no, dept_reg_no, name, email, gender, birth_category, screening_status,
              panel_code, application_pdf, final_status
              FROM candidates WHERE intake_id=? AND is_international=1 ORDER BY serial_no, id", [$intake['id']]);

render_header('International Candidates', $u);
?>
<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <h1 class="text-2xl font-semibold">International Candidates</h1>
  <div class="flex gap-2 flex-wrap">
    <a href="/phdportal/intl/shortlist.php" class="btn btn-secondary text-xs">Shortlisting</a>
    <a href="/phdportal/intl/panels.php" class="btn btn-secondary text-xs">Panel Allocation</a>
    <a href="/phdportal/intl/marks.php" class="btn btn-secondary text-xs">Interview Marks</a>
    <a href="/phdportal/intl/final.php" class="btn btn-secondary text-xs">Final Selection</a>
    <a href="/phdportal/admin/email.php?phase=written&intl=1" class="btn btn-secondary text-xs">Email</a>
  </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
  <div class="card">
    <h3 class="font-semibold mb-2">Upload International Candidates Excel</h3>
    <p class="text-xs text-slate-500 mb-3">Same column format as the main intake Excel. Candidates will be marked as <strong>International</strong>.</p>
    <form method="post" enctype="multipart/form-data" class="space-y-2">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="file" name="excel" accept=".xlsx" required>
      <button class="btn btn-primary">Upload & Extract</button>
    </form>
  </div>
  <div class="card">
    <h3 class="font-semibold mb-2">Applications Upload</h3>
    <p class="text-xs text-slate-500 mb-3">Application PDFs (filename = Dept Reg No) can be uploaded in bulk.</p>
    <a href="/phdportal/admin/applications.php" class="btn btn-secondary">Open Application Uploader</a>
  </div>
</div>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr><th>Sr</th><th>Dept Reg No</th><th>Name</th><th>Email</th><th>Gender</th><th>Cat</th><th>Shortlist</th><th>Panel</th><th>Final</th><th></th></tr></thead>
<tbody>
<?php foreach ($cands as $r): ?>
<tr>
  <td><?= (int)$r['serial_no'] ?></td>
  <td class="font-mono text-xs"><a class="text-indigo-700 hover:underline" href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>"><?= h($r['dept_reg_no']) ?></a></td>
  <td><?= h($r['name']) ?></td>
  <td class="text-xs"><?= h($r['email']) ?></td>
  <td><?= h($r['gender']) ?></td>
  <td><?= category_badge($r['birth_category'] ?? '') ?></td>
  <td><?= status_badge($r['screening_status']) ?></td>
  <td class="text-xs"><?= h($r['panel_code'] ?? '—') ?></td>
  <td><?= status_badge($r['final_status']) ?></td>
  <td><a href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>" class="text-xs text-indigo-600 hover:underline">View</a></td>
</tr>
<?php endforeach; ?>
<?php if (!$cands): ?><tr><td colspan="10" class="text-center py-6 text-slate-500">No international candidates yet. Upload an Excel to begin.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
<?php render_footer(); ?>
