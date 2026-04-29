<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_panel();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
$candidates = [];
if ($intake) {
    $candidates = all("SELECT c.*, (SELECT total_marks FROM interview_marks m WHERE m.candidate_id=c.id AND m.panel_user_id=?) my_total,
                             (SELECT recommended FROM interview_marks m WHERE m.candidate_id=c.id AND m.panel_user_id=?) my_rec
                      FROM candidates c
                      WHERE c.intake_id=? AND c.screening_status='Yes' AND c.panel_code=?
                      ORDER BY c.dept_reg_no", [$u['id'], $u['id'], $intake['id'], $u['panel_code']]);
}

render_header('My Panel', $u);
?>
<div class="mb-4">
  <h1 class="text-2xl font-semibold">Panel <?= h($u['panel_code']) ?> — <?= h($u['panel_area']) ?></h1>
  <p class="text-sm text-slate-500">Intake: <?= $intake ? h($intake['name']) : 'none' ?> &middot; <?= count($candidates) ?> candidates assigned</p>
</div>

<div class="card mb-4">
  <form class="flex gap-2 items-end" onsubmit="event.preventDefault(); fetchCand();">
    <div class="flex-1">
      <label class="text-xs font-medium">Search by Dept Reg. No.</label>
      <input id="regInput" placeholder="e.g. RMG202520001" autocomplete="off">
      <div class="relative"><div id="suggestList" class="absolute z-20 bg-white border rounded w-full max-h-60 overflow-y-auto shadow hidden"></div></div>
    </div>
    <button class="btn btn-primary">Fetch</button>
  </form>
</div>

<div class="card p-0 overflow-x-auto mb-4">
<table class="data-table w-full">
<thead><tr><th>Dept Reg No</th><th>Name</th><th>Gender</th><th>Applied Categories</th><th>Research Interest</th><th>My Total</th><th>Rec.</th><th></th></tr></thead>
<tbody>
<?php foreach ($candidates as $c): ?>
<tr>
  <td class="font-mono text-xs"><a class="text-indigo-700 hover:underline" href="/phdportal/panel/interview.php?id=<?= (int)$c['id'] ?>"><?= h($c['dept_reg_no']) ?></a></td>
  <td><?= h($c['name']) ?></td>
  <td><?= h($c['gender']) ?></td>
  <td class="text-xs"><?= h($c['categories_applied']) ?></td>
  <td class="text-xs max-w-md truncate" title="<?= h($c['research_interest_selected']) ?>"><?= h($c['research_interest_selected']) ?></td>
  <td class="text-right font-semibold"><?= $c['my_total']!==null ? h($c['my_total']) : '<span class="text-slate-400">—</span>' ?></td>
  <td><?= $c['my_rec']===null ? '' : ($c['my_rec'] ? '<span class="text-green-700 text-xs font-semibold">Rec</span>' : '<span class="text-rose-600 text-xs">Not</span>') ?></td>
  <td>
    <?php if ($c['my_total'] !== null): ?>
      <a href="/phdportal/panel/interview.php?id=<?= (int)$c['id'] ?>" class="text-xs text-slate-600 hover:underline inline-flex items-center gap-1">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Locked
      </a>
    <?php else: ?>
      <a href="/phdportal/panel/interview.php?id=<?= (int)$c['id'] ?>" class="text-xs text-indigo-600 hover:underline font-medium">Mark</a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$candidates): ?><tr><td colspan="8" class="text-center py-6 text-slate-500">No candidates assigned to your panel.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<script>
const $inp = $('#regInput');
const $list = $('#suggestList');
$inp.on('input', function(){
  const q = $(this).val();
  if (q.length < 2) { $list.hide(); return; }
  $.getJSON('/phdportal/api/search_candidates.php', { q }, function(rows){
    if (!rows.length) { $list.hide(); return; }
    $list.empty();
    rows.forEach(r => {
      $('<div class="p-2 hover:bg-indigo-50 cursor-pointer text-sm border-b">')
        .text(r.dept_reg_no + ' — ' + r.name)
        .on('click', function(){ $inp.val(r.dept_reg_no); $list.hide(); })
        .appendTo($list);
    });
    $list.show();
  });
});
function fetchCand() {
  const q = $inp.val().trim();
  if (!q) return;
  $.getJSON('/phdportal/api/search_candidates.php', { q }, function(rows){
    if (!rows.length) { alert('No candidate in your panel for ' + q); return; }
    window.location = '/phdportal/panel/interview.php?id=' + rows[0].id;
  });
}
$(document).on('click', e => { if (!$(e.target).closest('#suggestList,#regInput').length) $list.hide(); });
</script>
<?php render_footer(); ?>
