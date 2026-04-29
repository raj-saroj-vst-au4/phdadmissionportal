<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

$frozen = (bool)setting('intl_shortlist_frozen_' . $intake['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['freeze'])) {
    check_csrf();
    $undecided = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=1 AND screening_status IN ('Pending','Doubtful')", [$intake['id']])['c'];
    if ($undecided > 0) {
        flash_set("Cannot freeze: $undecided pending.", 'error');
    } else {
        set_setting('intl_shortlist_frozen_' . $intake['id'], '1');
        flash_set('International shortlist frozen.', 'success');
    }
    redirect('/phdportal/intl/shortlist.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unfreeze'])) {
    check_csrf();
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($_POST['passcode'] ?? '', $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
    } else {
        q('DELETE FROM settings WHERE `key`=?', ['intl_shortlist_frozen_' . $intake['id']]);
        flash_set('International shortlist unfrozen.', 'success');
    }
    redirect('/phdportal/intl/shortlist.php');
}

$cands = all("SELECT id, dept_reg_no, name, email, gender, birth_category, research_interest_selected, screening_status
              FROM candidates WHERE intake_id=? AND is_international=1 ORDER BY serial_no, id", [$intake['id']]);
$undecided = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=1 AND screening_status IN ('Pending','Doubtful')", [$intake['id']])['c'];

render_header('International — Shortlisting', $u);
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-semibold">International Shortlisting</h1>
  <a href="/phdportal/intl/" class="btn btn-secondary text-xs">← Overview</a>
</div>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr><th>Dept Reg No</th><th>Name</th><th>Email</th><th>Gender</th><th>Cat</th><th>Research Interest</th><th>Shortlist</th></tr></thead>
<tbody>
<?php foreach ($cands as $r): ?>
<tr>
  <td class="font-mono text-xs"><a class="text-indigo-700 hover:underline" href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>"><?= h($r['dept_reg_no']) ?></a></td>
  <td><?= h($r['name']) ?></td>
  <td class="text-xs"><?= h($r['email']) ?></td>
  <td><?= h($r['gender']) ?></td>
  <td><?= category_badge($r['birth_category'] ?? '') ?></td>
  <td class="text-xs max-w-sm truncate" title="<?= h($r['research_interest_selected']) ?>"><?= h($r['research_interest_selected']) ?></td>
  <td>
    <?php if ($frozen): ?><?= status_badge($r['screening_status']) ?>
    <?php else: ?>
    <select class="shortlist-sel text-xs" data-id="<?= (int)$r['id'] ?>">
      <?php foreach (['Yes','No','Pending','Doubtful'] as $s): ?>
        <option<?= $s===$r['screening_status']?' selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$cands): ?><tr><td colspan="7" class="text-center py-6 text-slate-500">No international candidates.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php if (!$frozen && $cands): ?>
<div class="mt-5 flex items-center justify-end gap-3">
  <?php if ($undecided > 0): ?>
    <p class="text-sm text-amber-700"><?= $undecided ?> candidate(s) still Pending/Doubtful</p>
    <button class="btn btn-danger opacity-40 cursor-not-allowed" disabled>Freeze</button>
  <?php else: ?>
    <form method="post" onsubmit="return confirm('Freeze international shortlist?');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button name="freeze" class="btn btn-danger">Freeze Shortlist</button>
    </form>
  <?php endif; ?>
</div>
<?php elseif ($frozen): ?>
<div class="mt-5 flex items-center justify-end gap-3">
  <p class="text-sm text-rose-700 font-semibold">International shortlist is frozen.</p>
  <button type="button" class="btn btn-secondary" onclick="$('#unfreezeBackdrop').removeClass('hidden')">Unfreeze</button>
</div>
<div id="unfreezeBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Unfreeze International Shortlist</h3>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="unfreeze" value="1">
      <label class="text-xs font-medium">Admin password:</label>
      <input type="password" name="passcode" required autocomplete="new-password" class="mt-1">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn btn-secondary" onclick="$('#unfreezeBackdrop').addClass('hidden')">Cancel</button>
        <button class="btn btn-danger">Unfreeze</button>
      </div>
    </form>
  </div>
</div>
<script>
$('#unfreezeBackdrop').on('click', function(e){ if (e.target === this) $(this).addClass('hidden'); });
$(document).on('keydown', e => { if (e.key === 'Escape') $('#unfreezeBackdrop').addClass('hidden'); });
</script>
<?php endif; ?>

<script>
$('.shortlist-sel').on('change', function(){
  const id = $(this).data('id');
  const v = $(this).val();
  $.post('/phdportal/api/update_shortlist.php', { id, status: v, csrf: window.CSRF_TOKEN })
    .done(r => { if (!r.ok) alert(r.error || 'Failed'); })
    .fail(()=>alert('Request failed'));
});
</script>
<?php render_footer(); ?>
