<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_panel();
require __DIR__ . '/../../src/layout.php';

$id = (int)($_GET['id'] ?? 0);
$c = one('SELECT * FROM candidates WHERE id=? AND panel_code=? AND is_international=0', [$id, $u['panel_code']]);
if (!$c) { http_response_code(404); echo 'Candidate not found in your panel.'; exit; }

$existing = one('SELECT * FROM interview_marks WHERE candidate_id=? AND panel_user_id=?', [$c['id'], $u['id']]);
$interviewFrozen = (bool)setting('interview_frozen_' . $c['intake_id']);
$locked = !empty($existing) || $interviewFrozen;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!empty($existing)) {
        flash_set('Interview marks have already been submitted for this candidate and are locked. Contact admin to reset.', 'error');
        redirect('/phdportal/panel/interview.php?id=' . $c['id']);
    }
    if ($interviewFrozen) {
        flash_set('Interview marking is frozen. Contact admin.', 'error');
        redirect('/phdportal/panel/interview.php?id=' . $c['id']);
    }
    $fk = max(0, min(25, (float)($_POST['functional_knowledge'] ?? 0)));
    $ra = max(0, min(25, (float)($_POST['research_aptitude'] ?? 0)));
    $rp = max(0, min(25, (float)($_POST['research_proposal_quality'] ?? 0)));
    $cs = max(0, min(25, (float)($_POST['communication_skill'] ?? 0)));
    if (!isset($_POST['recommended'])) {
        flash_set('Please select Recommended or Not Recommended before saving.', 'error');
        redirect('/phdportal/panel/interview.php?id=' . $c['id']);
    }
    $rec = (int)$_POST['recommended'];
    $ug = $_POST['ug_marks'] ?? '';
    $pg = $_POST['pg_marks'] ?? '';
    $ce = $_POST['competitive_exam_marks'] ?? '';
    $notes = $_POST['notes'] ?? '';
    // Plain INSERT — a second submission for the same (candidate, panel_user) pair
    // will fail on the UNIQUE key, preventing any update by the panelist.
    q("INSERT INTO interview_marks(candidate_id, panel_user_id, functional_knowledge, research_aptitude,
         research_proposal_quality, communication_skill, recommended, ug_marks, pg_marks, competitive_exam_marks, notes)
       VALUES(?,?,?,?,?,?,?,?,?,?,?)",
       [$c['id'], $u['id'], $fk, $ra, $rp, $cs, $rec, $ug, $pg, $ce, $notes]);
    flash_set('Interview marks saved and locked for this candidate.', 'success');
    redirect('/phdportal/panel/interview.php?id=' . $c['id']);
}

render_header('Interview - ' . $c['dept_reg_no'], $u);
?>
<?php $hasAppPdf = $c['application_pdf'] && is_file(UPLOAD_APP_DIR . '/' . $c['application_pdf']); ?>
<div class="flex items-center justify-between mb-4">
  <div>
    <h1 class="text-2xl font-semibold"><?= h($c['name']) ?></h1>
    <p class="text-sm text-slate-500 font-mono"><?= h($c['dept_reg_no']) ?></p>
  </div>
  <div class="flex items-center gap-2">
    <?php if ($hasAppPdf): ?>
      <button type="button" class="btn btn-secondary view-pdf inline-flex items-center gap-1"
              data-pdf-url="/phdportal/uploads/applications/<?= h($c['application_pdf']) ?>"
              data-pdf-title="<?= h($c['dept_reg_no']) ?>">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
        View Application PDF
      </button>
    <?php endif; ?>
    <a href="/phdportal/panel/dashboard.php" class="btn btn-secondary">&larr; Back to panel</a>
  </div>
</div>

<?php if (!empty($existing)): ?>
<div class="card mb-4 border-l-4 border-rose-500 bg-rose-50 flex items-start gap-3">
  <svg class="mt-0.5 text-rose-600 shrink-0" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
  <div>
    <p class="text-sm font-semibold text-rose-800">Interview marks submitted and locked</p>
    <p class="text-xs text-rose-700 mt-0.5">
      You submitted marks on <strong><?= h($existing['created_at']) ?></strong>.
      These cannot be edited by you. If a correction is needed, contact the admin.
    </p>
  </div>
</div>
<?php elseif ($interviewFrozen): ?>
<div class="card mb-4 border-l-4 border-amber-500 bg-amber-50">
  <p class="text-sm font-semibold text-amber-800">Interview marking is frozen by admin.</p>
  <p class="text-xs text-amber-700 mt-0.5">No new submissions are accepted for this intake.</p>
</div>
<?php endif; ?>

<form method="post" id="interviewForm" class="grid grid-cols-1 md:grid-cols-3 gap-4 <?= $locked ? 'opacity-90' : '' ?>">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

  <div class="card md:col-span-2">
    <h3 class="font-semibold mb-3">Candidate Profile</h3>
    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
      <dt class="text-slate-500">Name</dt><dd><?= h($c['name']) ?></dd>
      <dt class="text-slate-500">Categories Applied</dt><dd><?= h(normalize_categories_applied($c['categories_applied'])) ?></dd>
      <dt class="text-slate-500">Qualifying Exam</dt><dd><?= h($c['qualifying_exam']) ?> (<?= h($c['passing_year']) ?>)</dd>
      <dt class="text-slate-500">Discipline</dt><dd><?= h($c['qualifying_discipline']) ?></dd>
      <dt class="text-slate-500">Percentage</dt><dd><?= h($c['percentage']) ?>%</dd>
      <dt class="text-slate-500">CPI/Grade</dt><dd><?= h($c['cpi_grade']) ?></dd>
      <dt class="text-slate-500">GATE Score</dt><dd><?= h($c['gate_score']) ?> (<?= h($c['gate_year']) ?>)</dd>
      <dt class="text-slate-500">Work Experience</dt><dd><?= h($c['work_experience']) ?> months</dd>
      <dt class="text-slate-500">Fellowship</dt><dd><?= h($c['fellowship']) ?></dd>
      <?php if ($c['written_marks'] !== null): ?>
      <dt class="text-slate-500">Written Marks</dt><dd class="font-semibold text-indigo-700"><?= h($c['written_marks']) ?></dd>
      <?php endif; ?>
      <?php if ($c['application_pdf'] && is_file(UPLOAD_APP_DIR . '/' . $c['application_pdf'])): ?>
      <dt class="text-slate-500">Application</dt>
      <dd>
        <button type="button" class="view-pdf text-indigo-600 hover:underline text-sm inline-flex items-center gap-1"
                data-pdf-url="/phdportal/uploads/applications/<?= h($c['application_pdf']) ?>"
                data-pdf-title="<?= h($c['dept_reg_no']) ?>">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
          View PDF
        </button>
      </dd>
      <?php endif; ?>
    </dl>
    <div class="mt-3">
      <div class="text-slate-500 text-sm font-medium">Research Interests</div>
      <div class="text-sm bg-indigo-50 p-2 rounded mt-1"><?= h($c['research_interest_selected']) ?></div>
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

    <div class="mt-4 grid grid-cols-3 gap-3">
      <div><label class="text-xs font-medium">UG Marks</label><input name="ug_marks" value="<?= h($existing['ug_marks'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></div>
      <div><label class="text-xs font-medium">PG Marks</label><input name="pg_marks" value="<?= h($existing['pg_marks'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></div>
      <div><label class="text-xs font-medium">Competitive Exam</label><input name="competitive_exam_marks" value="<?= h($existing['competitive_exam_marks'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></div>
    </div>
  </div>

  <div class="card">
    <h3 class="font-semibold mb-3">Interview Marks <span class="text-xs text-slate-500 font-normal">(max 25 each)</span></h3>
    <div class="space-y-3">
      <div><label class="text-sm font-medium">Functional Knowledge</label>
        <input type="number" step="0.1" min="0" max="25" class="mark-inp" name="functional_knowledge" value="<?= h($existing['functional_knowledge'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></div>
      <div><label class="text-sm font-medium">Research Aptitude</label>
        <input type="number" step="0.1" min="0" max="25" class="mark-inp" name="research_aptitude" value="<?= h($existing['research_aptitude'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></div>
      <div><label class="text-sm font-medium">Quality of Research Proposal</label>
        <input type="number" step="0.1" min="0" max="25" class="mark-inp" name="research_proposal_quality" value="<?= h($existing['research_proposal_quality'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></div>
      <div><label class="text-sm font-medium">Communication Skill</label>
        <input type="number" step="0.1" min="0" max="25" class="mark-inp" name="communication_skill" value="<?= h($existing['communication_skill'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></div>

      <div class="bg-indigo-50 border border-indigo-200 rounded p-2 text-center">
        <div class="text-xs text-indigo-700 font-medium">Total</div>
        <div class="text-3xl font-bold text-indigo-800"><span id="totalDisplay">0</span> / 100</div>
      </div>

      <!-- Mandatory recommended radio -->
      <div class="border rounded-lg p-3 space-y-2">
        <div class="text-sm font-medium text-slate-700">Recommendation <span class="text-rose-600">*</span></div>
        <label class="flex items-center gap-2 text-sm cursor-pointer">
          <input type="radio" name="recommended" value="1" class="accent-green-600"
            <?= !empty($existing) && $existing['recommended'] == 1 ? 'checked' : '' ?>
            <?= $locked ? 'disabled' : '' ?>>
          <span class="text-green-700 font-medium">Recommended</span>
        </label>
        <label class="flex items-center gap-2 text-sm cursor-pointer">
          <input type="radio" name="recommended" value="0" class="accent-rose-600"
            <?= !empty($existing) && $existing['recommended'] == 0 && isset($existing['id']) ? 'checked' : '' ?>
            <?= $locked ? 'disabled' : '' ?>>
          <span class="text-rose-700 font-medium">Not Recommended</span>
        </label>
      </div>

      <div>
        <label class="text-xs font-medium">Notes</label>
        <textarea name="notes" rows="3" <?= $locked ? 'disabled' : '' ?>><?= h($existing['notes'] ?? '') ?></textarea>
      </div>
      <?php if (!$locked): ?>
      <button type="submit" class="btn btn-primary w-full justify-center">Save Interview Marks</button>
      <?php else: ?>
      <div class="bg-slate-100 border border-slate-300 text-slate-600 text-center text-sm font-medium rounded p-2">
        <svg class="inline align-text-bottom" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Submitted &amp; Locked
      </div>
      <?php endif; ?>
    </div>
  </div>
</form>

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

function recalcTotal() {
  let total = 0;
  $('.mark-inp').each(function(){ total += parseFloat($(this).val()) || 0; });
  $('#totalDisplay').text(total.toFixed(1));
}
// Enforce 0–25 range on each input
$('.mark-inp').on('input', function(){
  let v = parseFloat($(this).val());
  if (isNaN(v)) return recalcTotal();
  if (v < 0) { $(this).val(0); }
  else if (v > 25) { $(this).val(25); }
  recalcTotal();
});
$('.mark-inp').on('blur', function(){
  let v = parseFloat($(this).val());
  if (!isNaN(v)) {
    if (v < 0) $(this).val(0);
    else if (v > 25) $(this).val(25);
  }
  recalcTotal();
});
$(document).ready(recalcTotal);

$('#interviewForm').on('submit', function(e) {
  // Hard-enforce 0–25 range on each mark input
  let outOfRange = false;
  $('.mark-inp').each(function(){
    const v = parseFloat($(this).val());
    if (!isNaN(v) && (v < 0 || v > 25)) outOfRange = true;
  });
  if (outOfRange) {
    e.preventDefault();
    alert('Each interview-mark field must be between 0 and 25.');
    return false;
  }
  const recommended = $('input[name="recommended"]:checked').val();
  if (recommended === undefined) {
    e.preventDefault();
    alert('Please select Recommended or Not Recommended before submitting.');
    return false;
  }
  const total = parseFloat($('#totalDisplay').text()) || 0;
  const recText = recommended == '1' ? 'Recommended' : 'Not Recommended';
  const msg = `Confirm submission:\n\nTotal Marks: ${total} / 100\nDecision: ${recText}\n\nSave these interview marks?`;
  if (!confirm(msg)) { e.preventDefault(); return false; }
});
</script>
<?php render_footer(); ?>
