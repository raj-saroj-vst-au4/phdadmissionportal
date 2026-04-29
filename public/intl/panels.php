<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

$panels = all('SELECT * FROM panels ORDER BY code');

// Auto-assign
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_assign'])) {
    check_csrf();
    $cands = all("SELECT id, research_interest_selected FROM candidates
                  WHERE intake_id=? AND is_international=1 AND screening_status='Yes'", [$intake['id']]);
    $map = [];
    foreach ($panels as $p) $map[strtoupper($p['code'])] = $p;
    $n = 0;
    foreach ($cands as $c) {
        preg_match_all('/\(\d+\)\s*([A-Z]+)\s*:/u', $c['research_interest_selected'] ?? '', $m);
        $assigned = null;
        foreach ($m[1] as $code) {
            $code = strtoupper($code);
            foreach ($map as $pc => $p) {
                if ($pc === $code || strpos($pc, $code) !== false) { $assigned = $p; break 2; }
            }
        }
        if (!$assigned && $panels) $assigned = $panels[0];
        if ($assigned) {
            q('UPDATE candidates SET panel_code=?, panel_area=? WHERE id=?',
              [$assigned['code'], $assigned['area'], $c['id']]);
            $n++;
        }
    }
    flash_set("Auto-assigned $n international candidates.", 'success');
    redirect('/phdportal/intl/panels.php');
}

// Manual assignment via dropdown
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_assign'])) {
    check_csrf();
    $cid = (int)$_POST['cand_id'];
    $code = $_POST['panel_code'] ?: null;
    $p = $code ? one('SELECT area FROM panels WHERE code=?', [$code]) : null;
    q('UPDATE candidates SET panel_code=?, panel_area=? WHERE id=? AND is_international=1 AND intake_id=?',
      [$code, $p['area'] ?? null, $cid, $intake['id']]);
    flash_set('Panel assignment updated.', 'success');
    redirect('/phdportal/intl/panels.php');
}

$cands = all("SELECT id, dept_reg_no, name, research_interest_selected, panel_code, panel_area
              FROM candidates WHERE intake_id=? AND is_international=1 AND screening_status='Yes'
              ORDER BY panel_code, dept_reg_no", [$intake['id']]);

render_header('International — Panel Allocation', $u);
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-semibold">International — Panel Allocation</h1>
  <div class="flex gap-2">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button name="auto_assign" class="btn btn-secondary">Auto-Assign</button>
    </form>
    <a href="/phdportal/intl/" class="btn btn-secondary text-xs">← Overview</a>
  </div>
</div>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr><th>Dept Reg No</th><th>Name</th><th>Research Interest</th><th>Panel</th><th></th></tr></thead>
<tbody>
<?php foreach ($cands as $r): ?>
<tr>
  <td class="font-mono text-xs"><?= h($r['dept_reg_no']) ?></td>
  <td><?= h($r['name']) ?></td>
  <td class="text-xs max-w-sm truncate" title="<?= h($r['research_interest_selected']) ?>"><?= h($r['research_interest_selected']) ?></td>
  <td><?= $r['panel_code'] ? '<span class="inline-block px-2 py-0.5 rounded bg-indigo-50 text-indigo-800 text-xs font-semibold">'.h($r['panel_code']).'</span> <span class="text-xs text-slate-500">'.h($r['panel_area']).'</span>' : '<span class="text-xs text-rose-500">Unassigned</span>' ?></td>
  <td>
    <form method="post" class="flex items-center gap-1">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="manual_assign" value="1">
      <input type="hidden" name="cand_id" value="<?= (int)$r['id'] ?>">
      <select name="panel_code" class="text-xs">
        <option value="">—</option>
        <?php foreach ($panels as $p): ?>
          <option value="<?= h($p['code']) ?>"<?= $p['code']===$r['panel_code']?' selected':'' ?>><?= h($p['code']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary text-xs">Set</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$cands): ?><tr><td colspan="5" class="text-center py-6 text-slate-500">No shortlisted international candidates.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
<?php render_footer(); ?>
