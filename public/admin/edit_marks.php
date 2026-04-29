<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$mid = (int)($_GET['id'] ?? 0);
$m = $mid ? one(
    'SELECT m.*, u.full_name panel_name, u.panel_code, u.panel_area,
            c.id cand_id, c.dept_reg_no, c.name cand_name
     FROM interview_marks m
     JOIN users u ON u.id = m.panel_user_id
     JOIN candidates c ON c.id = m.candidate_id
     WHERE m.id = ?', [$mid]) : null;
if (!$m) { http_response_code(404); echo 'Marks row not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    check_csrf();
    $fk = max(0, min(25, (float)($_POST['functional_knowledge'] ?? 0)));
    $ra = max(0, min(25, (float)($_POST['research_aptitude'] ?? 0)));
    $rp = max(0, min(25, (float)($_POST['research_proposal_quality'] ?? 0)));
    $cs = max(0, min(25, (float)($_POST['communication_skill'] ?? 0)));
    $rec = (int)($_POST['recommended'] ?? 0);
    $notes = $_POST['notes'] ?? '';
    q('UPDATE interview_marks SET functional_knowledge=?, research_aptitude=?,
         research_proposal_quality=?, communication_skill=?, recommended=?, notes=?
       WHERE id=?',
      [$fk, $ra, $rp, $cs, $rec, $notes, $mid]);
    flash_set('Panelist marks updated.', 'success');
    redirect('/phdportal/admin/candidate.php?id=' . (int)$m['cand_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_marks'])) {
    check_csrf();
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($_POST['passcode'] ?? '', $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
        redirect('/phdportal/admin/edit_marks.php?id=' . $mid);
    }
    q('DELETE FROM interview_marks WHERE id=?', [$mid]);
    flash_set('Panelist marks reset. They can re-submit now.', 'success');
    redirect('/phdportal/admin/candidate.php?id=' . (int)$m['cand_id']);
}

render_header('Edit Interview Marks - ' . $m['dept_reg_no'], $u);
?>
<div class="flex items-center justify-between mb-4">
  <div>
    <h1 class="text-2xl font-semibold">Edit Interview Marks</h1>
    <p class="text-sm text-slate-500 mt-0.5">
      Candidate:
      <a href="/phdportal/admin/candidate.php?id=<?= (int)$m['cand_id'] ?>" class="text-indigo-700 hover:underline font-mono"><?= h($m['dept_reg_no']) ?></a>
      &middot; <?= h($m['cand_name']) ?>
      &middot; Panel: <span class="font-semibold"><?= h($m['panel_name']) ?></span>
      <?php if ($m['panel_code']): ?>
        <span class="text-xs text-slate-400">(<?= h($m['panel_code']) ?> — <?= h($m['panel_area']) ?>)</span>
      <?php endif; ?>
    </p>
  </div>
  <a href="/phdportal/admin/candidate.php?id=<?= (int)$m['cand_id'] ?>" class="btn btn-secondary">&larr; Back to Candidate</a>
</div>

<div class="card max-w-2xl">
  <form method="post" id="editMarksForm">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="save" value="1">
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="text-sm font-medium">Functional Knowledge <span class="text-xs text-slate-500">(max 25)</span></label>
        <input type="number" step="0.1" min="0" max="25" name="functional_knowledge" class="mark-inp"
               value="<?= h($m['functional_knowledge']) ?>" required>
      </div>
      <div>
        <label class="text-sm font-medium">Research Aptitude <span class="text-xs text-slate-500">(max 25)</span></label>
        <input type="number" step="0.1" min="0" max="25" name="research_aptitude" class="mark-inp"
               value="<?= h($m['research_aptitude']) ?>" required>
      </div>
      <div>
        <label class="text-sm font-medium">Quality of Research Proposal <span class="text-xs text-slate-500">(max 25)</span></label>
        <input type="number" step="0.1" min="0" max="25" name="research_proposal_quality" class="mark-inp"
               value="<?= h($m['research_proposal_quality']) ?>" required>
      </div>
      <div>
        <label class="text-sm font-medium">Communication Skill <span class="text-xs text-slate-500">(max 25)</span></label>
        <input type="number" step="0.1" min="0" max="25" name="communication_skill" class="mark-inp"
               value="<?= h($m['communication_skill']) ?>" required>
      </div>
    </div>

    <div class="mt-4 bg-indigo-50 border border-indigo-200 rounded p-3 text-center">
      <div class="text-xs text-indigo-700 font-medium">Total</div>
      <div class="text-3xl font-bold text-indigo-800"><span id="totalDisplay">0</span> / 100</div>
    </div>

    <div class="mt-4">
      <label class="text-sm font-medium">Recommendation</label>
      <select name="recommended" class="mt-1">
        <option value="1"<?= $m['recommended']==1 ? ' selected' : '' ?>>Recommended</option>
        <option value="0"<?= $m['recommended']==0 ? ' selected' : '' ?>>Not Recommended</option>
      </select>
    </div>

    <div class="mt-4">
      <label class="text-sm font-medium">Notes</label>
      <textarea name="notes" rows="3"><?= h($m['notes'] ?? '') ?></textarea>
    </div>

    <div class="mt-5 flex justify-between items-center">
      <button type="button" class="btn btn-danger" onclick="$('#resetBackdrop').removeClass('hidden')">
        Reset &amp; Unlock
      </button>
      <div class="flex gap-2">
        <a href="/phdportal/admin/candidate.php?id=<?= (int)$m['cand_id'] ?>" class="btn btn-secondary">Cancel</a>
        <button class="btn btn-primary">Save Changes</button>
      </div>
    </div>
  </form>
</div>

<!-- Reset modal (delete this panelist's submission) -->
<div id="resetBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-rose-700 mb-2">Reset Panelist Marks</h3>
    <p class="text-sm text-slate-700 mb-3">
      Delete the interview marks submitted by <strong><?= h($m['panel_name']) ?></strong> for
      <strong><?= h($m['dept_reg_no']) ?></strong>? They will be able to submit again.
    </p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="reset_marks" value="1">
      <label class="text-xs font-medium">Admin password:</label>
      <input type="password" name="passcode" required autocomplete="new-password" class="mt-1">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn btn-secondary" onclick="$('#resetBackdrop').addClass('hidden')">Cancel</button>
        <button class="btn btn-danger">Reset &amp; Unlock</button>
      </div>
    </form>
  </div>
</div>

<script>
function recalcTotal() {
  let total = 0;
  $('.mark-inp').each(function(){ total += parseFloat($(this).val()) || 0; });
  $('#totalDisplay').text(total.toFixed(1));
}
$('.mark-inp').on('input', function(){
  let v = parseFloat($(this).val());
  if (!isNaN(v)) {
    if (v < 0) $(this).val(0);
    else if (v > 25) $(this).val(25);
  }
  recalcTotal();
});
$(document).ready(recalcTotal);

$('#resetBackdrop').on('click', function(e){ if (e.target === this) $(this).addClass('hidden'); });
$(document).on('keydown', e => { if (e.key === 'Escape') $('#resetBackdrop').addClass('hidden'); });
</script>
<?php render_footer(); ?>
