<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

$frozen = (bool)setting('intl_final_frozen_' . $intake['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['freeze'])) {
    check_csrf();
    set_setting('intl_final_frozen_' . $intake['id'], '1');
    flash_set('International final selection frozen.', 'success');
    redirect('/phdportal/intl/final.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unfreeze'])) {
    check_csrf();
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($_POST['passcode'] ?? '', $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
    } else {
        q('DELETE FROM settings WHERE `key`=?', ['intl_final_frozen_' . $intake['id']]);
        flash_set('International final selection unfrozen.', 'success');
    }
    redirect('/phdportal/intl/final.php');
}

if (isset($_GET['export'])) {
    $rows = all("SELECT c.dept_reg_no, c.name, c.panel_code, c.birth_category,
                 (SELECT AVG(m.total_marks) FROM interview_marks m WHERE m.candidate_id=c.id) avg_interview,
                 c.final_status, c.final_category
                 FROM candidates c
                 WHERE c.intake_id=? AND c.is_international=1 AND c.screening_status='Yes'
                 ORDER BY c.final_status, avg_interview DESC", [$intake['id']]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="intl_final_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Dept Reg No','Name','Research Cat','Birth Cat','Avg Interview','Final Status','Category']);
    foreach ($rows as $r) fputcsv($out, [$r['dept_reg_no'],$r['name'],$r['panel_code'],$r['birth_category'],
        $r['avg_interview']!==null?round($r['avg_interview'],2):'',$r['final_status'],$r['final_category']]);
    fclose($out); exit;
}

$rows = all("SELECT c.*,
    (SELECT AVG(total_marks) FROM interview_marks m WHERE m.candidate_id=c.id) avg_interview,
    (SELECT COUNT(*) FROM interview_marks m WHERE m.candidate_id=c.id) panel_count
    FROM candidates c
    WHERE c.intake_id=? AND c.is_international=1 AND c.screening_status='Yes'
    ORDER BY avg_interview DESC", [$intake['id']]);

render_header('International — Final Selection', $u);
?>
<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <h1 class="text-2xl font-semibold">International — Final Selection</h1>
  <div class="flex gap-2 flex-wrap">
    <a href="?export=1" class="btn btn-secondary text-xs">Export CSV</a>
    <a href="/phdportal/intl/" class="btn btn-secondary text-xs">← Overview</a>
  </div>
</div>

<?php if ($frozen): ?>
<p class="mb-3 inline-flex items-center gap-1 text-rose-700 font-semibold text-sm">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
  Frozen
  <button type="button" class="btn btn-secondary text-xs ml-2" onclick="$('#unfreezeBackdrop').removeClass('hidden')">Unfreeze</button>
</p>

<div id="unfreezeBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Unfreeze International Final Selection</h3>
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

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr>
  <th>Dept Reg No</th><th>Name</th><th>Research Cat</th><th>Avg Interview</th><th>Panels</th><th>Status</th><th>Category</th><th>Birth Cat</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td class="font-mono text-xs"><a href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>" class="text-indigo-600 hover:underline"><?= h($r['dept_reg_no']) ?></a></td>
  <td><?= h($r['name']) ?></td>
  <td class="text-xs"><?= h($r['panel_code'] ?? '—') ?></td>
  <td class="text-right font-semibold"><?= $r['avg_interview']!==null ? round($r['avg_interview'],2) : '—' ?></td>
  <td class="text-center"><?= (int)$r['panel_count'] ?></td>
  <td>
    <?php if ($frozen): ?><?= status_badge($r['final_status']) ?>
    <?php else: ?>
    <select class="status-sel text-xs" data-id="<?= (int)$r['id'] ?>">
      <?php foreach (['Pending','Selected','Not Selected','Waitlisted'] as $s): ?>
        <option<?= $s===$r['final_status']?' selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($frozen): ?><?= h($r['final_category'] ?? '—') ?>
    <?php else: ?>
    <select class="finalcat-sel text-xs" data-id="<?= (int)$r['id'] ?>">
      <option value="">—</option>
      <?php foreach (FINAL_CATEGORIES as $fc): ?>
        <option<?= $fc===$r['final_category']?' selected':'' ?>><?= $fc ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </td>
  <td><?= category_badge($r['birth_category'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="text-center py-6 text-slate-500">No shortlisted international candidates.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php if (!$frozen && $rows): ?>
<div class="mt-5 flex justify-end">
  <form method="post" onsubmit="return confirm('Freeze international final selection?');">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button name="freeze" class="btn btn-danger">Freeze Final Selection</button>
  </form>
</div>
<?php endif; ?>

<script>
function postFinal(id, field, value) {
  $.post('/phdportal/api/update_final.php', { id, field, value, csrf: window.CSRF_TOKEN })
    .done(r => { if (!r.ok) alert(r.error || 'Failed'); }).fail(()=>alert('Request failed'));
}
$('.status-sel').on('change', function(){ postFinal($(this).data('id'),'final_status',$(this).val()); });
$('.finalcat-sel').on('change', function(){ postFinal($(this).data('id'),'final_category',$(this).val()); });
</script>
<?php render_footer(); ?>
