<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
$panels = all('SELECT * FROM panels ORDER BY code');
$interviewFrozen = $intake ? (bool)setting('interview_frozen_' . $intake['id']) : false;
$cutoffFrozen = $intake ? (bool)setting('cutoff_frozen_' . $intake['id']) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['freeze_interview'])) {
    check_csrf();
    if ($intake) { set_setting('interview_frozen_' . $intake['id'], '1'); flash_set('Interview marking frozen.', 'success'); }
    redirect('/phdportal/admin/panels.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unfreeze_interview'])) {
    check_csrf();
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($_POST['passcode'] ?? '', $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
    } elseif ($intake) {
        q('DELETE FROM settings WHERE `key`=?', ['interview_frozen_' . $intake['id']]);
        flash_set('Interview marking unfrozen.', 'success');
    }
    redirect('/phdportal/admin/panels.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_assign'])) {
    check_csrf();
    if (!$cutoffFrozen) { flash_set('Freeze the cutoff before allocating panels.', 'error'); redirect('/phdportal/admin/panels.php'); }
    if ($interviewFrozen) { flash_set('Interview marking is frozen.', 'error'); redirect('/phdportal/admin/panels.php'); }
    $cid = (int)($_POST['candidate_id'] ?? 0);
    $code = trim((string)($_POST['panel_code'] ?? ''));
    $p = $code ? one('SELECT code, area FROM panels WHERE code=?', [$code]) : null;
    if ($cid && $p && $intake) {
        q('UPDATE candidates SET panel_code=?, panel_area=? WHERE id=? AND intake_id=? AND is_international=0 AND passed_cutoff=1',
            [$p['code'], $p['area'], $cid, $intake['id']]);
        flash_set('Panel assigned.', 'success');
    } else {
        flash_set('Select a panel to assign.', 'error');
    }
    redirect('/phdportal/admin/panels.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_assignments'])) {
    check_csrf();
    if (!$cutoffFrozen) { flash_set('Freeze the cutoff before resetting panel assignments.', 'error'); redirect('/phdportal/admin/panels.php'); }
    if ($interviewFrozen) { flash_set('Interview marking is frozen.', 'error'); redirect('/phdportal/admin/panels.php'); }
    if ($intake) {
        $stmt = q('UPDATE candidates SET panel_code=NULL, panel_area=NULL WHERE intake_id=? AND is_international=0 AND passed_cutoff=1',
            [$intake['id']]);
        flash_set('Cleared panel assignments for ' . $stmt->rowCount() . ' shortlisted candidates.', 'success');
    }
    redirect('/phdportal/admin/panels.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_assign'])) {
    check_csrf();
    if (!$cutoffFrozen) { flash_set('Freeze the cutoff before allocating panels.', 'error'); redirect('/phdportal/admin/panels.php'); }
    $cands = all("SELECT id, research_interest_selected FROM candidates
                  WHERE intake_id=? AND is_international=0 AND passed_cutoff=1", [$intake['id']]);

    // Tokenize an area/interest string: lowercase, split on non-alphanumerics, drop stopwords
    // and generic words that appear across multiple panels ("management", "business").
    $stop = ['and','or','the','of','for','an','in','on','to','with','management','business'];
    $tokenize = function ($s) use ($stop) {
        $toks = preg_split('/[^a-z0-9]+/', strtolower((string)$s), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($toks, fn($t) => strlen($t) >= 3 && !in_array($t, $stop, true)));
    };

    $panelTokens = [];
    foreach ($panels as $p) $panelTokens[$p['code']] = $tokenize($p['area']);

    $updated = 0; $ambiguous = 0; $unmatched = 0;
    foreach ($cands as $c) {
        $ri = trim((string)($c['research_interest_selected'] ?? ''));
        if ($ri === '') { $unmatched++; continue; }
        $assigned = null;
        // Exact (case-insensitive) area match wins immediately
        foreach ($panels as $p) {
            if (strcasecmp($ri, $p['area']) === 0) { $assigned = $p; break; }
        }
        if (!$assigned) {
            $riTokens = $tokenize($ri);
            $bestScore = 0; $best = null; $tie = false;
            foreach ($panels as $p) {
                $score = count(array_intersect($riTokens, $panelTokens[$p['code']]));
                if ($score > $bestScore) { $best = $p; $bestScore = $score; $tie = false; }
                elseif ($score === $bestScore && $score > 0) { $tie = true; }
            }
            if ($best && !$tie) $assigned = $best;
            elseif ($tie) { $ambiguous++; continue; }
        }
        if ($assigned) {
            q('UPDATE candidates SET panel_code=?, panel_area=? WHERE id=? AND is_international=0',
                [$assigned['code'], $assigned['area'], $c['id']]);
            $updated++;
        } else {
            $unmatched++;
        }
    }
    $msg = "Auto-assigned $updated candidates.";
    if ($unmatched)  $msg .= " $unmatched left unassigned (no matching panel).";
    if ($ambiguous)  $msg .= " $ambiguous ambiguous (multiple panels tied).";
    flash_set($msg, 'success');
    redirect('/phdportal/admin/panels.php');
}

$byPanel = [];
if ($intake && $cutoffFrozen) {
    $rows = all("SELECT c.*, p.area panel_full_area FROM candidates c
                 LEFT JOIN panels p ON p.code = c.panel_code
                 WHERE c.intake_id=? AND c.is_international=0 AND c.passed_cutoff=1
                 ORDER BY c.panel_code, c.dept_reg_no", [$intake['id']]);
    foreach ($rows as $r) {
        $byPanel[$r['panel_code'] ?: 'UNASSIGNED'][] = $r;
    }
}

render_header('Panels', $u);
?>
<div class="flex justify-between items-center mb-4 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-semibold">Panels & Interview Lists</h1>
    <?php if ($interviewFrozen): ?>
      <p class="inline-flex items-center gap-1 text-rose-700 font-semibold text-sm mt-1">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Interview Marking Frozen
        <button type="button" class="btn btn-secondary text-xs ml-2" onclick="$('#unfreezeBackdrop').removeClass('hidden')">Unfreeze</button>
      </p>
    <?php endif; ?>
  </div>
  <div class="flex gap-2">
    <?php if (!$interviewFrozen && $cutoffFrozen): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button name="auto_assign" class="btn btn-secondary">Auto-Assign by Research Area</button>
    </form>
    <form method="post" onsubmit="return confirm('Clear panel assignments for all shortlisted candidates in this intake? This cannot be undone.');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button name="reset_assignments" class="btn btn-danger">Reset All Assignments</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php if (!$cutoffFrozen): ?>
<div class="card border-amber-200 bg-amber-50 mb-4">
  <p class="text-sm text-amber-800">Panel allocation is available only after the cutoff is frozen. Freeze the cutoff on the <a href="/phdportal/admin/cutoff.php" class="underline font-medium">Cutoff page</a> to shortlist candidates based on written marks.</p>
</div>
<?php else: ?>
<p class="text-sm text-slate-500 mb-4">Only candidates who cleared the frozen cutoff appear here. Assign panels manually from candidate view, or auto-assign above.</p>
<?php endif; ?>

<?php $unassigned = $byPanel['UNASSIGNED'] ?? []; if ($cutoffFrozen && !$interviewFrozen && $unassigned): ?>
<div class="card mb-4 border-amber-300 bg-amber-50">
  <div class="flex items-start gap-2 mb-2">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" class="mt-0.5 shrink-0"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
    <div>
      <h3 class="font-semibold text-amber-800">Unallocated Shortlisted Candidates (<?= count($unassigned) ?>)</h3>
      <p class="text-xs text-amber-800/80">These candidates cleared the cutoff but are not assigned to any panel. Pick a panel and save for each.</p>
    </div>
  </div>
  <div style="max-height:30vh" class="overflow-y-auto border border-amber-200 rounded bg-white">
    <table class="data-table w-full">
      <thead class="sticky top-0 bg-amber-100 z-10">
        <tr><th>Dept Reg No</th><th>Name</th><th>Category</th><th>Research Interest</th><th>Panel</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($unassigned as $r): ?>
          <tr>
            <td class="font-mono text-xs"><a href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>" class="text-indigo-700 hover:underline"><?= h($r['dept_reg_no']) ?></a></td>
            <td><a href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>" class="text-indigo-700 hover:underline"><?= h($r['name']) ?></a></td>
            <td><?= category_badge($r['birth_category'] ?? '') ?></td>
            <td class="text-xs text-slate-600"><?= h($r['research_interest_selected'] ?? '') ?></td>
            <td>
              <form method="post" class="flex gap-2 items-center">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="manual_assign" value="1">
                <input type="hidden" name="candidate_id" value="<?= (int)$r['id'] ?>">
                <select name="panel_code" required class="text-xs py-1">
                  <option value="">-- select --</option>
                  <?php foreach ($panels as $pp): ?>
                    <option value="<?= h($pp['code']) ?>"><?= h($pp['code'].' — '.$pp['area']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-primary text-xs py-1 px-2">Save</button>
              </form>
            </td>
            <td></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php foreach ($panels as $p):
  $list = $byPanel[$p['code']] ?? [];
  $members = all("SELECT id, full_name FROM users WHERE role='panel' AND panel_code=? ORDER BY full_name", [$p['code']]);
  $marksByCand = [];
  $allScores = [];
  if ($list && $members) {
    $candIds = array_column($list, 'id');
    $ph = implode(',', array_fill(0, count($candIds), '?'));
    $allMarks = all("SELECT candidate_id, panel_user_id, total_marks FROM interview_marks WHERE candidate_id IN ($ph)", $candIds);
    foreach ($allMarks as $m) {
      $marksByCand[$m['candidate_id']][$m['panel_user_id']] = $m['total_marks'];
      $allScores[] = (float)$m['total_marks'];
    }
  }
  $panelMean = $allScores ? array_sum($allScores) / count($allScores) : null;
?>
  <div class="card mb-4">
    <div class="flex items-center justify-between mb-3">
      <div>
        <h3 class="font-semibold text-lg">
          <?= h($p['code']) ?> — <?= h($p['area']) ?>
          <?php if ($panelMean !== null): ?>
            <span class="ml-2 text-sm font-medium text-indigo-700">Mean: <?= h(number_format($panelMean, 2)) ?></span>
          <?php endif; ?>
        </h3>
        <p class="text-xs text-slate-500"><?= count($list) ?> candidates</p>
      </div>
      <?php if ($list): ?>
      <button class="btn btn-primary" onclick='downloadPanelPdf(<?= json_encode($p['code']) ?>, <?= json_encode($p['area']) ?>, <?= json_encode(array_values(array_map(fn($r)=>["dept"=>$r['dept_reg_no'],"name"=>$r['name']],$list))) ?>)'>Download Interview PDF</button>
      <?php endif; ?>
    </div>
    <?php if ($list): ?>
    <table class="data-table w-full [&_th]:!text-center [&_td]:!text-center">
      <thead>
        <tr>
          <th>Dept Reg No</th><th>Name</th><th>Category</th><th>Gender</th>
          <?php foreach ($members as $pm): ?>
            <th><?= h($pm['full_name']) ?></th>
          <?php endforeach; ?>
          <?php if (!$members): ?><th>Panel Score</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $r): ?>
          <tr>
            <td class="font-mono text-xs"><a href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>" class="text-indigo-700 hover:underline"><?= h($r['dept_reg_no']) ?></a></td>
            <td><?= h($r['name']) ?></td>
            <td><?= category_badge($r['birth_category'] ?? '') ?></td>
            <td><?= h($r['gender']) ?></td>
            <?php foreach ($members as $pm):
              $score = $marksByCand[$r['id']][$pm['id']] ?? null; ?>
              <td><?= $score !== null ? h($score) : '<span class="text-slate-400">—</span>' ?></td>
            <?php endforeach; ?>
            <?php if (!$members): ?><td><span class="text-slate-400">—</span></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p class="text-sm text-slate-500">No candidates assigned.</p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($cutoffFrozen && $interviewFrozen && !empty($byPanel['UNASSIGNED'])): ?>
<div class="card mb-4 border-amber-200 bg-amber-50">
  <h3 class="font-semibold">Unassigned Shortlisted</h3>
  <p class="text-sm">These <?= count($byPanel['UNASSIGNED']) ?> candidates are shortlisted but have no panel. Unfreeze interview marking to assign.</p>
</div>
<?php endif; ?>

<?php if ($intake && !$interviewFrozen): ?>
<div class="mt-5 flex justify-end">
  <form method="post" onsubmit="return confirm('Freeze all interview marks for this intake? Panel members will no longer be able to edit their scores.');">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button name="freeze_interview" class="btn btn-danger">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Freeze Interview Marking
    </button>
  </form>
</div>
<?php endif; ?>

<?php if ($interviewFrozen): ?>
<div id="unfreezeBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Unfreeze Interview Marking</h3>
    <p class="text-sm text-slate-700 mb-3">Re-enable panels to edit their interview scores for this intake.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="unfreeze_interview" value="1">
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
function downloadPanelPdf(code, area, rows) {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  doc.setFontSize(14);
  doc.text('SJMSOM IIT Bombay — PhD Admissions Interview', 14, 15);
  doc.setFontSize(11);
  doc.text('Panel: ' + code + ' — ' + area, 14, 23);
  doc.setFontSize(10);
  doc.text('Date: ' + new Date().toLocaleDateString(), 14, 29);
  const body = rows.map((r,i) => [i+1, r.dept, r.name, '']);
  doc.autoTable({
    startY: 34,
    head: [['#', 'Dept Reg No', 'Name', 'Signature']],
    body,
    styles: { fontSize: 10, cellPadding: 3 },
    columnStyles: { 0: {cellWidth: 10}, 1: {cellWidth: 40}, 3: {cellWidth: 60}},
    headStyles: { fillColor: [79,70,229] }
  });
  doc.save('Panel_' + code + '_InterviewList.pdf');
}
</script>
<?php render_footer(); ?>
