<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

$cands = all("SELECT c.id, c.dept_reg_no, c.name, c.panel_code, c.panel_area,
              (SELECT AVG(m.total_marks) FROM interview_marks m WHERE m.candidate_id=c.id) avg_interview,
              (SELECT COUNT(*) FROM interview_marks m WHERE m.candidate_id=c.id) panel_count
              FROM candidates c
              WHERE c.intake_id=? AND c.is_international=1 AND c.screening_status='Yes'
              ORDER BY c.panel_code, c.dept_reg_no", [$intake['id']]);

render_header('International — Interview Marks', $u);
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-semibold">International — Interview Marks</h1>
  <a href="/phdportal/intl/" class="btn btn-secondary text-xs">← Overview</a>
</div>

<p class="text-sm text-slate-500 mb-4">Panel members enter marks from the <a href="/phdportal/panel/dashboard.php" class="text-indigo-600 hover:underline">Panel Dashboard</a> — this view summarises international candidates only.</p>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr><th>Dept Reg No</th><th>Name</th><th>Panel</th><th>Avg Interview</th><th>Panels Marked</th><th></th></tr></thead>
<tbody>
<?php foreach ($cands as $r): ?>
<tr>
  <td class="font-mono text-xs"><?= h($r['dept_reg_no']) ?></td>
  <td><?= h($r['name']) ?></td>
  <td class="text-xs"><?= h($r['panel_code'] ?? '—') ?> <span class="text-slate-500"><?= h($r['panel_area']) ?></span></td>
  <td class="text-right font-semibold"><?= $r['avg_interview']!==null ? round($r['avg_interview'],2) : '—' ?></td>
  <td class="text-center"><?= (int)$r['panel_count'] ?></td>
  <td><a href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>" class="text-xs text-indigo-600 hover:underline">View profile</a></td>
</tr>
<?php endforeach; ?>
<?php if (!$cands): ?><tr><td colspan="6" class="text-center py-6 text-slate-500">No international candidates shortlisted.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
<?php render_footer(); ?>
