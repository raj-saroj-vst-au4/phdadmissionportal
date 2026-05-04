<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$id = (int)($_GET['id'] ?? 0);
$c = one('SELECT * FROM candidates WHERE id=? AND is_international=0', [$id]);
if (!$c) { http_response_code(404); echo 'Not found'; exit; }

// Application PDF upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_app'])) {
    check_csrf();
    if (!empty($_FILES['app_pdf']['name']) && $_FILES['app_pdf']['error'] === UPLOAD_ERR_OK) {
        if (!is_dir(UPLOAD_APP_DIR)) mkdir(UPLOAD_APP_DIR, 0775, true);
        $fname = preg_replace('/[^A-Za-z0-9_-]/', '_', $c['dept_reg_no']) . '.pdf';
        $dest = UPLOAD_APP_DIR . '/' . $fname;
        move_uploaded_file($_FILES['app_pdf']['tmp_name'], $dest);
        q('UPDATE candidates SET application_pdf=? WHERE id=?', [$fname, $id]);
        flash_set('Application PDF uploaded.', 'success');
    }
    redirect('/phdportal/admin/candidate.php?id=' . $id);
}

// Main profile save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['upload_app'])) {
    check_csrf();
    $panelCode = $_POST['panel_code'] ?: null;
    $panelArea = null;
    if ($panelCode) {
        $p = one('SELECT area FROM panels WHERE code=?', [$panelCode]);
        $panelArea = $p['panel_area'] ?? $p['area'] ?? null;
    }
    $revised = normalize_categories_applied(trim((string)($_POST['revised_categories_applied'] ?? '')));
    $revised = ($revised === '' || $revised === null) ? null : $revised;
    q('UPDATE candidates SET remark=?, screening_status=?, panel_code=?, panel_area=?, revised_categories_applied=? WHERE id=?',
        [$_POST['remark'] ?? null, $_POST['screening_status'] ?? 'Pending',
         $panelCode, $panelArea, $revised, $id]);
    flash_set('Candidate updated', 'success');
    redirect('/phdportal/admin/candidate.php?id=' . $id);
}

// Refresh candidate after any update
$c = one('SELECT * FROM candidates WHERE id=? AND is_international=0', [$id]);
$panels = all('SELECT * FROM panels ORDER BY area');
$marks = all('SELECT m.*, u.full_name panel_name FROM interview_marks m JOIN users u ON u.id=m.panel_user_id WHERE m.candidate_id=?', [$id]);

// Previous / next candidate within the same intake (matches candidates.php ORDER BY serial_no, id)
$prev = one('SELECT id FROM candidates
             WHERE intake_id=? AND is_international=0 AND (IFNULL(serial_no,-1), id) < (IFNULL(?,-1), ?)
             ORDER BY serial_no DESC, id DESC LIMIT 1',
            [$c['intake_id'], $c['serial_no'], $c['id']]);
$next = one('SELECT id FROM candidates
             WHERE intake_id=? AND is_international=0 AND (IFNULL(serial_no,-1), id) > (IFNULL(?,-1), ?)
             ORDER BY serial_no, id LIMIT 1',
            [$c['intake_id'], $c['serial_no'], $c['id']]);

$appPdf = $c['application_pdf'] ?? null;
$appPdfExists = $appPdf && is_file(UPLOAD_APP_DIR . '/' . $appPdf);

$photo = $c['photo'] ?? null;
$photoExists = $photo && is_file(UPLOAD_PHOTO_DIR . '/' . $photo);

render_header('Candidate - ' . $c['dept_reg_no'], $u);
?>
<div class="flex items-center justify-between mb-4">
  <div>
    <h1 class="text-2xl font-semibold"><?= h($c['name']) ?></h1>
    <p class="text-sm text-slate-500 font-mono"><?= h($c['dept_reg_no']) ?> &middot; <?= h($c['applicant_id']) ?></p>
  </div>
  <div class="flex flex-col items-end gap-2">
    <a href="/phdportal/admin/candidates.php" class="btn btn-secondary">&larr; Back to Candidates List</a>
    <div class="flex gap-2">
      <?php if ($prev): ?>
        <a href="/phdportal/admin/candidate.php?id=<?= (int)$prev['id'] ?>" class="btn btn-secondary">&larr; Previous Profile</a>
      <?php else: ?>
        <span class="btn btn-secondary opacity-50 cursor-not-allowed">&larr; Previous Profile</span>
      <?php endif; ?>
      <?php if ($next): ?>
        <a href="/phdportal/admin/candidate.php?id=<?= (int)$next['id'] ?>" class="btn btn-secondary">Next Profile &rarr;</a>
      <?php else: ?>
        <span class="btn btn-secondary opacity-50 cursor-not-allowed">Next Profile &rarr;</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

  <!-- Profile card -->
  <div class="card md:col-span-2">
    <h3 class="font-semibold mb-3">Profile</h3>
    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
      <dt class="text-slate-500">Gender</dt><dd><?= h($c['gender']) ?></dd>
      <dt class="text-slate-500">Birth Category</dt><dd><?= category_badge($c['birth_category'] ?? '') ?></dd>
      <dt class="text-slate-500">EWS</dt><dd><?= h($c['ews']) ?></dd>
      <dt class="text-slate-500">Disabled?</dt><dd><?= h($c['disabled']) ?></dd>
      <dt class="text-slate-500">CFTI?</dt><dd><?= h($c['cfti']) ?></dd>
      <dt class="text-slate-500">IIT BTech?</dt><dd><?= h($c['iit_btech']) ?></dd>
      <dt class="text-slate-500">Categories Applied</dt><dd><?= h(normalize_categories_applied($c['categories_applied'])) ?></dd>
      <dt class="text-slate-500">Revised Category</dt>
      <?php $revDisp = normalize_categories_applied($c['revised_categories_applied'] ?? null); ?>
      <dd><?= $revDisp ? '<span class="font-semibold text-indigo-700">'.h($revDisp).'</span>' : '<span class="text-slate-400">—</span>' ?></dd>
      <dt class="text-slate-500">Email</dt><dd><?= h($c['email']) ?></dd>
      <dt class="text-slate-500">Qualifying Exam</dt><dd><?= h($c['qualifying_exam']) ?> &middot; <?= h($c['qualifying_discipline']) ?> (<?= h($c['passing_year']) ?>)</dd>
      <dt class="text-slate-500">Percentage / CPI</dt><dd><?= h($c['percentage']) ?>% &nbsp;<?= h($c['original_percentage']) ?> / <?= h($c['original_percentage_out_of']) ?> &nbsp;CPI: <?= h($c['cpi_grade']) ?></dd>
      <dt class="text-slate-500">GATE</dt><dd><?= h($c['gate_score']) ?> (<?= h($c['gate_year']) ?>) <?= h($c['gate_regn']) ?></dd>
      <dt class="text-slate-500">Work Experience</dt><dd><?= h($c['work_experience']) ?> months</dd>
      <dt class="text-slate-500">Fellowship</dt><dd><?= h($c['fellowship']) ?></dd>
      <dt class="text-slate-500">Written Marks</dt>
      <dd>
        <?php if ($c['written_marks'] !== null): ?>
          <span class="font-semibold text-indigo-700"><?= h($c['written_marks']) ?></span>
        <?php else: ?>
          <span class="text-slate-400">not entered</span>
        <?php endif; ?>
        <a href="/phdportal/admin/marks.php?reg=<?= urlencode($c['dept_reg_no']) ?>" class="text-indigo-600 text-xs ml-2 hover:underline">Edit</a>
      </dd>
    </dl>
    <div class="mt-3">
      <div class="text-slate-500 text-sm font-medium">Research Interests (selected dept)</div>
      <div class="text-sm bg-indigo-50 p-2 rounded mt-1"><?= h($c['research_interest_selected']) ?></div>
      <div class="text-slate-500 text-sm font-medium mt-2">Research Interests (other dept)</div>
      <div class="text-sm bg-slate-50 p-2 rounded mt-1"><?= h($c['research_interest_other']) ?></div>
      <div class="text-slate-500 text-sm font-medium mt-2">Academic Record</div>
      <?php
        $acadHtml = render_academic_record($c['academic_record']);
        if ($acadHtml !== '') {
            echo $acadHtml;
        } else {
            echo '<p class="text-xs text-slate-500 mt-1">No academic record on file.</p>';
        }
      ?>
    </div>
  </div>

  <!-- Right sidebar -->
  <div class="space-y-4">

    <?php if ($photoExists): ?>
    <!-- Profile Photo -->
    <div class="card flex justify-center">
      <a href="/phdportal/uploads/photos/<?= h($photo) ?>" target="_blank" title="<?= h($photo) ?>">
        <img src="/phdportal/uploads/photos/<?= h($photo) ?>" alt="<?= h($c['name']) ?>"
             class="max-h-48 w-auto object-contain rounded border border-slate-200">
      </a>
    </div>
    <?php endif; ?>

    <!-- Application PDF -->
    <div class="card">
      <h3 class="font-semibold mb-2">Application PDF</h3>
      <?php if ($appPdfExists): ?>
        <button type="button"
                class="view-pdf flex items-center gap-2 text-indigo-600 text-sm hover:underline mb-2"
                data-pdf-url="/phdportal/uploads/applications/<?= h($appPdf) ?>"
                data-pdf-title="<?= h($appPdf) ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
          <?= h($appPdf) ?>
        </button>
      <?php else: ?>
        <p class="text-xs text-slate-500 mb-2">No application PDF uploaded yet.</p>
      <?php endif; ?>
    </div>

    <!-- Shortlisting -->
    <div class="card">
      <h3 class="font-semibold mb-2">Shortlisting</h3>
      <label class="text-xs font-medium">Decision</label>
      <select name="screening_status">
        <?php foreach (['Yes','No','Pending','Doubtful'] as $s): ?>
          <option<?= $s===$c['screening_status']?' selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Revised Application Category -->
    <div class="card">
      <h3 class="font-semibold mb-2">Revised Application Category</h3>
      <p class="text-xs text-slate-500 mb-1">Original: <span class="font-mono"><?= h(normalize_categories_applied($c['categories_applied']) ?: '—') ?></span></p>
      <label class="text-xs font-medium">Revised</label>
      <?php
        $revOpts = ['TA','SF','EX','CT','TAP','FA','SW','IS'];
        $revCurrent = normalize_categories_applied($c['revised_categories_applied'] ?? null);
      ?>
      <select name="revised_categories_applied">
        <option value="">—</option>
        <?php foreach ($revOpts as $ro): ?>
          <option value="<?= h($ro) ?>"<?= $ro===$revCurrent?' selected':'' ?>><?= h($ro) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Panel Assignment -->
    <div class="card">
      <h3 class="font-semibold mb-2">Panel Assignment</h3>
      <label class="text-xs font-medium">Panel</label>
      <select name="panel_code">
        <option value="">—</option>
        <?php foreach ($panels as $p): ?>
          <option value="<?= h($p['code']) ?>"<?= $p['code']===$c['panel_code']?' selected':'' ?>><?= h($p['code'].' — '.$p['area']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Remark + Save -->
    <div class="card">
      <h3 class="font-semibold mb-2">Admin Remarks</h3>
      <textarea name="remark" rows="4"><?= h($c['remark']) ?></textarea>
      <button class="btn btn-primary mt-2 w-full justify-center">Save Changes</button>
    </div>
  </div>
</form>

<!-- Application PDF Upload (separate form) -->
<div class="card mt-4">
  <h3 class="font-semibold mb-2">Upload Application PDF</h3>
  <p class="text-xs text-slate-500 mb-3">File will be stored as <code class="bg-slate-100 px-1 rounded"><?= h(preg_replace('/[^A-Za-z0-9_-]/', '_', $c['dept_reg_no'])) ?>.pdf</code></p>
  <form method="post" enctype="multipart/form-data" class="flex items-center gap-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="upload_app" value="1">
    <input type="file" name="app_pdf" accept=".pdf,application/pdf" required class="flex-1">
    <button class="btn btn-secondary">Upload PDF</button>
  </form>
</div>

<!-- Interview Marks (read-only; Edit links to admin/edit_marks.php) -->
<div class="card mt-5">
  <h3 class="font-semibold mb-3">Interview Marks (Panel Inputs)</h3>
  <?php if (!$marks): ?><p class="text-sm text-slate-500">No interview marks entered yet.</p><?php else: ?>
  <div class="overflow-x-auto">
  <table class="data-table w-full">
    <thead><tr>
      <th>Panel</th><th>Functional</th><th>Research Apt.</th>
      <th>Proposal</th><th>Comm.</th>
      <th>Total</th><th>Recommendation</th><th>Notes</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($marks as $m): ?>
      <tr>
        <td><?= h($m['panel_name']) ?></td>
        <td class="text-center"><?= h($m['functional_knowledge']) ?></td>
        <td class="text-center"><?= h($m['research_aptitude']) ?></td>
        <td class="text-center"><?= h($m['research_proposal_quality']) ?></td>
        <td class="text-center"><?= h($m['communication_skill']) ?></td>
        <td class="font-semibold text-indigo-700"><?= h($m['total_marks']) ?> / 100</td>
        <td>
          <?= $m['recommended'] == 1
              ? '<span class="text-green-700 text-xs font-semibold">Recommended</span>'
              : '<span class="text-rose-600 text-xs font-semibold">Not Recommended</span>' ?>
        </td>
        <td class="text-xs text-slate-600"><?= h($m['notes']) ?></td>
        <td class="whitespace-nowrap">
          <a href="/phdportal/admin/edit_marks.php?id=<?= (int)$m['id'] ?>" class="btn btn-secondary text-xs">Edit</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- PDF viewer modal -->
<div id="pdfBackdrop" class="hidden fixed inset-0 bg-slate-900/70 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-2xl w-full max-w-5xl h-[90vh] flex flex-col">
    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200">
      <h3 class="font-semibold text-slate-800">Application PDF — <span id="pdfTitle" class="font-mono text-sm text-slate-600"></span></h3>
      <div class="flex items-center gap-2">
        <a id="pdfOpenNew" href="#" target="_blank" class="btn btn-secondary text-xs">Open in new tab</a>
        <button type="button" class="text-slate-500 hover:text-slate-800 p-1" onclick="closePdfModal()" aria-label="Close">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
    </div>
    <iframe id="pdfFrame" src="about:blank" class="flex-1 w-full border-0 rounded-b-lg" title="Application PDF"></iframe>
  </div>
</div>

<script>
function openPdfModal(url, title) {
  $('#pdfTitle').text(title || '');
  $('#pdfOpenNew').attr('href', url);
  $('#pdfFrame').attr('src', url);
  $('#pdfBackdrop').removeClass('hidden');
  document.body.style.overflow = 'hidden';
}
function closePdfModal() {
  $('#pdfBackdrop').addClass('hidden');
  $('#pdfFrame').attr('src', 'about:blank');
  document.body.style.overflow = '';
}
$(document).on('click', '.view-pdf', function(e){
  e.preventDefault();
  openPdfModal($(this).data('pdf-url'), $(this).data('pdf-title'));
});
$('#pdfBackdrop').on('click', function(e){ if (e.target === this) closePdfModal(); });
$(document).on('keydown', e => { if (e.key === 'Escape' && !$('#pdfBackdrop').hasClass('hidden')) closePdfModal(); });
</script>
<?php render_footer(); ?>
