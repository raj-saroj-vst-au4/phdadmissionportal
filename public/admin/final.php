<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

// Freeze handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['freeze_final'])) {
    check_csrf();
    $pending = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND screening_status='Yes' AND final_status='Pending'", [$intake['id']])['c'];
    if ($pending > 0) {
        flash_set("Cannot freeze: $pending candidate(s) still have Pending final status.", 'error');
    } else {
        set_setting('final_frozen_' . $intake['id'], '1');
        flash_set('Final selection frozen.', 'success');
    }
    redirect('/phdportal/admin/final.php');
}

// Unfreeze handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unfreeze_final'])) {
    check_csrf();
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($_POST['passcode'] ?? '', $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
    } else {
        q('DELETE FROM settings WHERE `key`=?', ['final_frozen_' . $intake['id']]);
        flash_set('Final selection unfrozen.', 'success');
    }
    redirect('/phdportal/admin/final.php');
}

// Per-panel means (computed up-front so both export and view paths can use them)
$panelMeansRows = all(
    "SELECT p.code, p.area, AVG(m.total_marks) mean
     FROM panels p
     LEFT JOIN candidates c ON c.panel_code = p.code AND c.intake_id = ?
     LEFT JOIN interview_marks m ON m.candidate_id = c.id
     GROUP BY p.code, p.area
     ORDER BY p.code", [$intake['id']]);
$panelMeanByCode = [];
foreach ($panelMeansRows as $pm) $panelMeanByCode[$pm['code']] = $pm['mean'] !== null ? (float)$pm['mean'] : null;

$meanRow = one("SELECT AVG(m.total_marks) mean FROM interview_marks m
    JOIN candidates c ON c.id=m.candidate_id WHERE c.intake_id=?", [$intake['id']]);
$depMean = $meanRow['mean'] !== null ? round($meanRow['mean'], 2) : null;

$adjusted = function(array $r) use ($panelMeanByCode): ?float {
    if ($r['avg_interview'] === null) return null;
    $pm = $panelMeanByCode[$r['panel_code']] ?? null;
    if ($pm === null) return null;
    return (float)$r['avg_interview'] - $pm;
};
$adjustedGlobal = function(array $r) use ($adjusted, $depMean): ?float {
    $a = $adjusted($r);
    if ($a === null || $depMean === null) return null;
    return $a + (float)$depMean;
};

// CSV export
if (isset($_GET['export'])) {
    $kind = $_GET['export']; // 'simple' | 'formatb' | 'summary'
    $rows = all("SELECT c.dept_reg_no, c.name, c.gender, c.panel_code, c.panel_area,
                 c.birth_category, c.ews, c.disabled, c.categories_applied, c.revised_categories_applied,
                 c.qualifying_exam, c.gate_score, c.written_marks,
                 (SELECT AVG(m.total_marks) FROM interview_marks m WHERE m.candidate_id=c.id) avg_interview,
                 (SELECT SUM(m.recommended) FROM interview_marks m WHERE m.candidate_id=c.id) rec_count,
                 c.final_status, c.final_category, c.birth_category_number
                 FROM candidates c
                 WHERE c.intake_id=? AND c.screening_status='Yes'
                 ORDER BY c.final_status, avg_interview DESC", [$intake['id']]);

    $appliedCat = fn($r) => trim((string)($r['revised_categories_applied'] ?? '')) !== ''
        ? $r['revised_categories_applied'] : ($r['categories_applied'] ?? '');

    header('Content-Type: text/csv; charset=utf-8');

    if ($kind === 'formatb') {
        // Format B: CSV with detailed columns (one row per selected candidate)
        header('Content-Disposition: attachment; filename="FormatB_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Sl No','Dept Reg No','Name','Gender','Birth Cat','EWS','PWD','Applied',
                       'Research Cat','Research Area','Qualifying Exam','GATE Score',
                       'Written','Interview Marks','Adjusted Panel Mean','Adjusted Global Mean','Final Status','Category','Birth Cat #']);
        $i = 1;
        foreach ($rows as $r) {
            if ($r['final_status'] === 'Selected' || $r['final_status'] === 'Waitlisted') {
                $adj = $adjusted($r); $adjG = $adjustedGlobal($r);
                fputcsv($out, [$i++, $r['dept_reg_no'], $r['name'], $r['gender'],
                    $r['birth_category'], $r['ews'], $r['disabled'], $r['categories_applied'],
                    $r['panel_code'], $r['panel_area'], $r['qualifying_exam'], $r['gate_score'],
                    $r['written_marks'],
                    $r['avg_interview'] !== null ? round($r['avg_interview'], 2) : '',
                    $adj !== null ? round($adj, 2) : '',
                    $adjG !== null ? round($adjG, 2) : '',
                    $r['final_status'], $appliedCat($r), $r['birth_category_number']]);
            }
        }
        fclose($out); exit;
    }

    if ($kind === 'summary') {
        header('Content-Disposition: attachment; filename="Summary_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        // Count by final_status / category / birth_category
        fputcsv($out, ['SJMSOM PhD Admissions — Summary Report']);
        fputcsv($out, ['Intake', $intake['name']]);
        fputcsv($out, ['Generated', date('Y-m-d H:i')]);
        fputcsv($out, []);
        fputcsv($out, ['Final Status Summary']);
        fputcsv($out, ['Status','Count']);
        $byStatus = [];
        foreach ($rows as $r) $byStatus[$r['final_status']] = ($byStatus[$r['final_status']] ?? 0) + 1;
        foreach ($byStatus as $s => $c) fputcsv($out, [$s, $c]);
        fputcsv($out, []);
        fputcsv($out, ['Birth Category Summary (Selected)']);
        fputcsv($out, ['Category','Selected']);
        $byCat = [];
        foreach ($rows as $r) if ($r['final_status']==='Selected') $byCat[$r['birth_category']] = ($byCat[$r['birth_category']] ?? 0) + 1;
        foreach ($byCat as $c => $n) fputcsv($out, [$c ?: 'Unknown', $n]);
        fputcsv($out, []);
        fputcsv($out, ['Research Area Summary (Selected)']);
        fputcsv($out, ['Panel','Selected']);
        $byPanel = [];
        foreach ($rows as $r) if ($r['final_status']==='Selected') $byPanel[$r['panel_code']] = ($byPanel[$r['panel_code']] ?? 0) + 1;
        foreach ($byPanel as $p => $n) fputcsv($out, [$p ?: 'Unassigned', $n]);
        fputcsv($out, []);
        fputcsv($out, ['Average Interview Marks']);
        fputcsv($out, ['Scope','Average']);
        $allAvg = array_filter(array_map(fn($r)=>$r['avg_interview'], $rows));
        $selAvg = array_filter(array_map(fn($r)=>$r['final_status']==='Selected' ? $r['avg_interview'] : null, $rows));
        fputcsv($out, ['All Shortlisted', $allAvg ? round(array_sum($allAvg)/count($allAvg), 2) : '—']);
        fputcsv($out, ['Selected Only', $selAvg ? round(array_sum($selAvg)/count($selAvg), 2) : '—']);
        fclose($out); exit;
    }

    // default: simple CSV
    header('Content-Disposition: attachment; filename="final_selection_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Dept Reg No','Name','Research Cat','Birth Category','Written','Interview Marks','Adjusted Panel Mean','Adjusted Global Mean','Final Status','Category','Birth Cat #']);
    foreach ($rows as $r) {
        $adj = $adjusted($r); $adjG = $adjustedGlobal($r);
        fputcsv($out, [
            $r['dept_reg_no'], $r['name'], $r['panel_code'], $r['birth_category'],
            $r['written_marks'],
            $r['avg_interview'] !== null ? round($r['avg_interview'], 2) : '',
            $adj !== null ? round($adj, 2) : '',
            $adjG !== null ? round($adjG, 2) : '',
            $r['final_status'], $appliedCat($r), $r['birth_category_number']
        ]);
    }
    fclose($out); exit;
}

$frozen = (bool)setting('final_frozen_' . $intake['id']);

$rows = all("SELECT c.*,
    (SELECT AVG(total_marks) FROM interview_marks m WHERE m.candidate_id=c.id) avg_interview
    FROM candidates c
    WHERE c.intake_id=? AND c.screening_status='Yes'", [$intake['id']]);

usort($rows, function($a, $b) use ($adjusted) {
    $aa = $adjusted($a); $bb = $adjusted($b);
    if ($aa === null && $bb === null) return 0;
    if ($aa === null) return 1;
    if ($bb === null) return -1;
    return $bb <=> $aa;
});

$pending = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND screening_status='Yes' AND final_status='Pending'", [$intake['id']])['c'];

render_header('Final Shortlisting', $u);
?>
<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <h1 class="text-2xl font-semibold">Final Shortlisting</h1>
  <div class="flex gap-2 flex-wrap">
    <a href="?export=simple" class="btn btn-secondary text-xs">Simple CSV</a>
    <a href="?export=formatb" class="btn btn-secondary text-xs">Format B (CSV)</a>
    <a href="?export=summary" class="btn btn-secondary text-xs">Summary Report</a>
    <button class="btn btn-secondary text-xs" onclick="downloadFinalPdf()">Export PDF</button>
  </div>
</div>

<?php
  $panelCount = count($panelMeansRows);
  // Two rows on the left: split panels evenly so they fill 2 rows.
  $leftCols = max(1, (int)ceil($panelCount / 2));
?>
<div class="grid grid-cols-5 gap-4 mb-4">
  <div class="col-span-4">
    <div class="grid gap-3" style="grid-template-columns: repeat(<?= $leftCols ?>, minmax(0, 1fr));">
      <?php foreach ($panelMeansRows as $pm): ?>
        <div class="card p-3 flex flex-col justify-between">
          <div class="text-xs font-semibold text-slate-700 truncate" title="<?= h($pm['area']) ?>">
            <span class="inline-block px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-800 text-[10px] font-bold mr-1"><?= h($pm['code']) ?></span>
            <span class="text-slate-500 font-normal"><?= h($pm['area']) ?></span>
          </div>
          <div class="mt-1">
            <?php if ($pm['mean'] !== null): ?>
              <span class="text-xl font-bold text-indigo-700"><?= h(round($pm['mean'], 2)) ?></span>
              <span class="text-xs text-slate-500"> / 100</span>
            <?php else: ?>
              <span class="text-sm text-slate-400">—</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$panelMeansRows): ?>
        <div class="card p-3 text-sm text-slate-500">No panels configured.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-span-1">
    <div class="card aspect-square h-full flex flex-col items-center justify-center text-center bg-indigo-50 border-indigo-200">
      <div class="text-xs font-semibold text-indigo-900 uppercase tracking-wide">Global Mean</div>
      <div class="mt-2">
        <?php if ($depMean !== null): ?>
          <div class="text-4xl font-bold text-indigo-700 leading-none"><?= h($depMean) ?></div>
          <div class="text-xs text-indigo-700/70 mt-1">/ 100</div>
        <?php else: ?>
          <div class="text-sm text-slate-500">— no marks yet —</div>
        <?php endif; ?>
      </div>
      <div class="text-[10px] text-slate-500 mt-2 px-2">Average across all panels</div>
      <?php if ($frozen): ?>
      <div class="mt-3 inline-flex items-center gap-1 text-rose-700 font-semibold text-xs">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Frozen
      </div>
      <button type="button" class="btn btn-secondary text-[10px] mt-1 px-2 py-0.5" onclick="$('#unfreezeBackdrop').removeClass('hidden')">Unfreeze</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($frozen): ?>
<div id="unfreezeBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Unfreeze Final Selection</h3>
    <p class="text-sm text-slate-700 mb-3">Re-enable editing of final status, category and birth-cat number.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="unfreeze_final" value="1">
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
<table class="data-table w-full [&_th]:!text-center [&_td]:!text-center" id="finalTable">
<thead><tr>
  <th>Dept Reg No</th><th>Name</th><th>Research Cat</th><th>Written</th>
  <th>Interview</th><th>AM Panel</th><th>AM Global</th>
  <th>Status</th><th>App. Category</th><th>Birth Category</th><th>Birth Cat #</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td class="font-mono text-xs"><a href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>" class="text-indigo-600 hover:underline"><?= h($r['dept_reg_no']) ?></a></td>
  <td><?= h($r['name']) ?></td>
  <td class="text-xs">
    <?php if ($r['panel_code']): ?>
      <span class="inline-block px-2 py-0.5 rounded bg-indigo-50 text-indigo-800 font-semibold text-xs"><?= h($r['panel_code']) ?></span>
      <span class="text-slate-500 text-xs"> <?= h($r['panel_area']) ?></span>
    <?php else: ?>
      <span class="text-slate-400">—</span>
    <?php endif; ?>
  </td>
  <td class="text-right"><?= h($r['written_marks']) ?></td>
  <td class="text-right font-semibold"><?= $r['avg_interview']!==null ? round($r['avg_interview'],2) : '—' ?></td>
  <?php $adj = $adjusted($r); $adjG = $adjustedGlobal($r); ?>
  <td class="text-right font-semibold">
    <?php if ($adj !== null): ?>
      <span class="<?= $adj >= 0 ? 'text-green-700' : 'text-rose-600' ?>"><?= ($adj >= 0 ? '+' : '') . number_format($adj, 2) ?></span>
    <?php else: ?>
      <span class="text-slate-400">—</span>
    <?php endif; ?>
  </td>
  <td class="text-right font-semibold">
    <?php if ($adjG !== null): ?>
      <span class="text-indigo-700"><?= number_format($adjG, 2) ?></span>
    <?php else: ?>
      <span class="text-slate-400">—</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($frozen): ?>
      <?= status_badge($r['final_status']) ?>
    <?php else: ?>
    <select class="status-sel text-xs" data-id="<?= (int)$r['id'] ?>">
      <?php foreach (['Pending','Selected','Not Selected','Waitlisted'] as $s): ?>
        <option<?= $s===$r['final_status']?' selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </td>
  <?php
    $applied = trim((string)($r['revised_categories_applied'] ?? '')) !== ''
        ? $r['revised_categories_applied'] : ($r['categories_applied'] ?? '');
    $isRevised = trim((string)($r['revised_categories_applied'] ?? '')) !== '';
  ?>
  <td>
    <?php if ($applied !== ''): ?>
      <span class="text-xs font-semibold"><?= h($applied) ?></span>
      <?php if ($isRevised): ?><span class="ml-1 text-[10px] text-indigo-600 font-medium" title="Revised from <?= h($r['categories_applied'] ?? '—') ?>">(revised)</span><?php endif; ?>
    <?php else: ?>
      <span class="text-slate-400">—</span>
    <?php endif; ?>
  </td>
  <td><?= category_badge($r['birth_category'] ?? '') ?></td>
  <td>
    <?php if ($frozen): ?>
      <?= h($r['birth_category_number'] ?? '—') ?>
    <?php else: ?>
    <input class="birthnum-inp w-20 text-xs" data-id="<?= (int)$r['id'] ?>" value="<?= h($r['birth_category_number']) ?>" placeholder="e.g. 3">
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="11" class="text-center py-6 text-slate-500">No shortlisted candidates yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php if (!$frozen): ?>
<div class="mt-5 flex items-center justify-end gap-3">
  <?php if ($pending > 0): ?>
    <p class="text-sm text-amber-700">
      <svg class="inline" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= $pending ?> candidate(s) still Pending final status
    </p>
    <button class="btn btn-danger opacity-40 cursor-not-allowed" disabled>Freeze Final Selection</button>
  <?php else: ?>
    <form method="post" onsubmit="return confirm('Freeze final selection? This will lock all final decisions for this intake.');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button name="freeze_final" class="btn btn-danger">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Freeze Final Selection
      </button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
function postFinal(id, field, value) {
  $.post('/phdportal/api/update_final.php', { id, field, value, csrf: window.CSRF_TOKEN })
    .done(r => { if (!r.ok) alert(r.error || 'Failed'); })
    .fail(()=>alert('Request failed'));
}
$('.status-sel').on('change', function(){ postFinal($(this).data('id'),'final_status',$(this).val()); });
$('.birthnum-inp').on('change', function(){ postFinal($(this).data('id'),'birth_category_number',$(this).val()); });

function downloadFinalPdf() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF('landscape');
  doc.setFontSize(13);
  doc.text('SJMSOM IIT Bombay — PhD Admissions Final Selection', 14, 14);
  doc.setFontSize(10);
  doc.text('Generated: ' + new Date().toLocaleString(), 14, 21);
  const rows = [];
  document.querySelectorAll('#finalTable tbody tr').forEach(tr => {
    const cells = tr.querySelectorAll('td');
    if (cells.length < 11) return;
    rows.push([
      cells[0].innerText.trim(),
      cells[1].innerText.trim(),
      cells[2].innerText.trim(),
      cells[3].innerText.trim(),
      cells[4].innerText.trim(),
      cells[5].innerText.trim(),
      cells[6].innerText.trim(),
      cells[7].innerText.trim(),
      cells[8].innerText.trim(),
      cells[9].innerText.trim(),
      cells[10].innerText.trim(),
    ]);
  });
  doc.autoTable({
    startY: 26,
    head: [['Dept Reg No','Name','Research Cat','Written','Interview Marks','Adjusted Panel Mean','Adjusted Global Mean','Status','Applied Category','Birth Cat','Birth Cat #']],
    body: rows,
    styles: { fontSize: 8, cellPadding: 2 },
    headStyles: { fillColor: [79,70,229] }
  });
  doc.save('Final_Selection_<?= date('Ymd') ?>.pdf');
}
</script>
<?php render_footer(); ?>
