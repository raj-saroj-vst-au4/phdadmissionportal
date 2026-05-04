<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$id = (int)($_GET['id'] ?? 0);
$c = one('SELECT * FROM candidates WHERE id=? AND is_international=1', [$id]);
if (!$c) { http_response_code(404); echo 'Not found'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $panelCode = $_POST['panel_code'] ?: null;
    $panelArea = null;
    if ($panelCode) {
        $p = one('SELECT area FROM panels WHERE code=?', [$panelCode]);
        $panelArea = $p['area'] ?? null;
    }
    q('UPDATE candidates SET remark=?, screening_status=?, panel_code=?, panel_area=? WHERE id=? AND is_international=1',
        [$_POST['remark'] ?? null, $_POST['screening_status'] ?? 'Pending',
         $panelCode, $panelArea, $id]);
    flash_set('Candidate updated', 'success');
    redirect('/phdportal/intl/candidate.php?id=' . $id);
}

$c = one('SELECT * FROM candidates WHERE id=? AND is_international=1', [$id]);
$panels = all('SELECT * FROM panels ORDER BY area');
$marks = all('SELECT m.*, u.full_name panel_name FROM interview_marks m JOIN users u ON u.id=m.panel_user_id WHERE m.candidate_id=?', [$id]);

$prev = one('SELECT id FROM candidates
             WHERE intake_id=? AND is_international=1 AND (IFNULL(serial_no,-1), id) < (IFNULL(?,-1), ?)
             ORDER BY serial_no DESC, id DESC LIMIT 1',
            [$c['intake_id'], $c['serial_no'], $c['id']]);
$next = one('SELECT id FROM candidates
             WHERE intake_id=? AND is_international=1 AND (IFNULL(serial_no,-1), id) > (IFNULL(?,-1), ?)
             ORDER BY serial_no, id LIMIT 1',
            [$c['intake_id'], $c['serial_no'], $c['id']]);

render_header('International Candidate - ' . ($c['applicant_id'] ?: $c['dept_reg_no']), $u);
?>
<div class="flex items-center justify-between mb-4">
  <div>
    <h1 class="text-2xl font-semibold"><?= h($c['name']) ?></h1>
    <p class="text-sm text-slate-500 font-mono"><?= h($c['applicant_id'] ?: $c['dept_reg_no']) ?></p>
  </div>
  <div class="flex flex-col items-end gap-2">
    <a href="/phdportal/intl/" class="btn btn-secondary">&larr; Back to International Candidates</a>
    <div class="flex gap-2">
      <?php if ($prev): ?>
        <a href="/phdportal/intl/candidate.php?id=<?= (int)$prev['id'] ?>" class="btn btn-secondary">&larr; Previous Profile</a>
      <?php else: ?>
        <span class="btn btn-secondary opacity-50 cursor-not-allowed">&larr; Previous Profile</span>
      <?php endif; ?>
      <?php if ($next): ?>
        <a href="/phdportal/intl/candidate.php?id=<?= (int)$next['id'] ?>" class="btn btn-secondary">Next Profile &rarr;</a>
      <?php else: ?>
        <span class="btn btn-secondary opacity-50 cursor-not-allowed">Next Profile &rarr;</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

  <div class="card md:col-span-2">
    <h3 class="font-semibold mb-3">Profile</h3>
    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
      <dt class="text-slate-500">Name</dt><dd><?= h($c['name']) ?></dd>
      <dt class="text-slate-500">Student ID</dt><dd class="font-mono"><?= h($c['applicant_id'] ?: $c['dept_reg_no']) ?></dd>
      <dt class="text-slate-500">Email</dt><dd><?= h($c['email']) ?></dd>
      <dt class="text-slate-500">Nationality</dt><dd><?= h($c['nationality']) ?></dd>
    </dl>
    <div class="mt-3">
      <div class="text-slate-500 text-sm font-medium">Research Area</div>
      <div class="text-sm bg-indigo-50 p-2 rounded mt-1"><?= h($c['research_interest_selected']) ?: '<span class="text-slate-400">—</span>' ?></div>
    </div>
  </div>

  <div class="space-y-4">
    <div class="card">
      <h3 class="font-semibold mb-2">Shortlisting</h3>
      <label class="text-xs font-medium">Decision</label>
      <select name="screening_status">
        <?php foreach (['Yes','No','Pending','Doubtful'] as $s): ?>
          <option<?= $s===$c['screening_status']?' selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>

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

    <div class="card">
      <h3 class="font-semibold mb-2">Admin Remarks</h3>
      <textarea name="remark" rows="4"><?= h($c['remark']) ?></textarea>
      <button class="btn btn-primary mt-2 w-full justify-center">Save Changes</button>
    </div>
  </div>
</form>

<div class="card mt-5">
  <h3 class="font-semibold mb-3">Interview Marks (Panel Inputs)</h3>
  <?php if (!$marks): ?><p class="text-sm text-slate-500">No interview marks entered yet.</p><?php else: ?>
  <div class="overflow-x-auto">
  <table class="data-table w-full">
    <thead><tr>
      <th>Panel</th><th>Functional</th><th>Research Apt.</th>
      <th>Proposal</th><th>Comm.</th>
      <th>Total</th><th>Recommendation</th><th>Notes</th>
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
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
