<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
$cutoffFrozen = $intake ? (bool)setting('cutoff_frozen_' . $intake['id']) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['freeze_cutoff'])) {
    check_csrf();
    if ($intake) { set_setting('cutoff_frozen_' . $intake['id'], '1'); flash_set('Cutoff frozen.', 'success'); }
    redirect('/phdportal/admin/cutoff.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unfreeze_cutoff'])) {
    check_csrf();
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($_POST['passcode'] ?? '', $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
    } elseif ($intake) {
        q('DELETE FROM settings WHERE `key`=?', ['cutoff_frozen_' . $intake['id']]);
        flash_set('Cutoff unfrozen.', 'success');
    }
    redirect('/phdportal/admin/cutoff.php');
}

// Cutoff = base GN marks. Category multipliers applied to derive effective cutoff.
const CUTOFF_MULTIPLIERS = [
    'GN' => 1.0, 'EWS' => 1.0,
    'OBC-NC' => 0.9,
    'SC' => 0.6667, 'ST' => 0.6667, 'PWD' => 0.6667,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['freeze_cutoff'])) {
    check_csrf();
    if ($cutoffFrozen) { flash_set('Cutoff is frozen. Cannot change.', 'error'); redirect('/phdportal/admin/cutoff.php'); }
    if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/admin/cutoff.php'); }
    $cutoff = (float)($_POST['cutoff_gn'] ?? 0);
    q('UPDATE intakes SET cutoff_marks = ? WHERE id = ?', [$cutoff, $intake['id']]);
    // Precedence mirrors effectiveCat(): disabled → ews → birth_category. EWS/PWD also handled
    // when stored directly as birth_category (legacy data) so those rows aren't silently dropped.
    q('UPDATE candidates SET passed_cutoff = CASE
        WHEN written_marks IS NULL OR screening_status <> "Yes" THEN 0
        WHEN disabled = "Y" AND written_marks >= ? * 0.6667 THEN 1
        WHEN ews = "Y" AND written_marks >= ? THEN 1
        WHEN birth_category = "PWD" AND written_marks >= ? * 0.6667 THEN 1
        WHEN birth_category = "EWS" AND written_marks >= ? THEN 1
        WHEN birth_category IN ("SC","ST") AND written_marks >= ? * 0.6667 THEN 1
        WHEN birth_category = "GN" AND written_marks >= ? THEN 1
        WHEN birth_category = "OBC-NC" AND written_marks >= ? * 0.9 THEN 1
        ELSE 0 END WHERE intake_id = ? AND is_international=0',
        [$cutoff, $cutoff, $cutoff, $cutoff, $cutoff, $cutoff, $cutoff, $intake['id']]);
    flash_set("Cutoff set to $cutoff (GN/EWS 100%, OBC-NC 90%, SC/ST/PWD 66.67%).", 'success');
    redirect('/phdportal/admin/cutoff.php');
}

$cutoff = (float)($intake['cutoff_marks'] ?? 0);

// Refresh passed_cutoff against the current saved cutoff and current candidate state — marks
// autosave / Excel import / new candidates don't update this flag, so it goes stale otherwise.
// Skipped when frozen so a frozen snapshot stays untouched.
if ($intake && $cutoff > 0 && !$cutoffFrozen) {
    q('UPDATE candidates SET passed_cutoff = CASE
        WHEN written_marks IS NULL OR screening_status <> "Yes" THEN 0
        WHEN disabled = "Y" AND written_marks >= ? * 0.6667 THEN 1
        WHEN ews = "Y" AND written_marks >= ? THEN 1
        WHEN birth_category = "PWD" AND written_marks >= ? * 0.6667 THEN 1
        WHEN birth_category = "EWS" AND written_marks >= ? THEN 1
        WHEN birth_category IN ("SC","ST") AND written_marks >= ? * 0.6667 THEN 1
        WHEN birth_category = "GN" AND written_marks >= ? THEN 1
        WHEN birth_category = "OBC-NC" AND written_marks >= ? * 0.9 THEN 1
        ELSE 0 END WHERE intake_id = ? AND is_international=0',
        [$cutoff, $cutoff, $cutoff, $cutoff, $cutoff, $cutoff, $cutoff, $intake['id']]);
}

// Resolve each candidate to an "effective" birth category:
// disabled='Y' -> PWD (overrides), else ews='Y' -> EWS (overrides), else birth_category.
$effectiveCat = function(array $row): ?string {
    if (($row['disabled'] ?? null) === 'Y') return 'PWD';
    if (($row['ews'] ?? null) === 'Y') return 'EWS';
    return $row['birth_category'] ?? null;
};

// Build stats (across candidates with written marks entered).
// All charts/lists below derive from the same in-memory pass so they stay consistent with catStats —
// the DB `passed_cutoff` flag is only refreshed on form submit, so it goes stale whenever marks are
// autosaved, Excel-imported, or candidates are added after the last "Visualize and Save".
$catStats = array_fill_keys(BIRTH_CATEGORIES, ['total' => 0, 'above' => 0]);
$genderStats = []; $researchStats = [];
$marksByCat = array_fill_keys(BIRTH_CATEGORIES, []);
$shortlisted = [];
if ($intake) {
    $all = all("SELECT id, serial_no, dept_reg_no, name, gender, birth_category, ews, disabled,
                       written_marks, research_interest_selected
                FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status='Yes'
                ORDER BY serial_no, id", [$intake['id']]);
    $genderLabelMap = ['M' => 'Male', 'F' => 'Female'];
    $researchTally = [];
    foreach ($all as $row) {
        $ec = $effectiveCat($row);
        if (!isset($catStats[$ec])) continue;
        $catStats[$ec]['total']++;
        if ($row['written_marks'] === null) continue;
        $marks = (float)$row['written_marks'];
        $marksByCat[$ec][] = $marks;
        $eff = $cutoff * (CUTOFF_MULTIPLIERS[$ec] ?? 1.0);
        if ($marks < $eff) continue;
        $catStats[$ec]['above']++;

        $gLabel = $genderLabelMap[$row['gender']] ?? ($row['gender'] ?: 'Unknown');
        $genderStats[$gLabel] = ($genderStats[$gLabel] ?? 0) + 1;

        $area = trim((string)($row['research_interest_selected'] ?? ''));
        if ($area !== '') $researchTally[$area] = ($researchTally[$area] ?? 0) + 1;

        $shortlisted[] = [
            'serial_no' => $row['serial_no'],
            'dept_reg_no' => $row['dept_reg_no'],
            'name' => $row['name'],
            'gender' => $row['gender'],
            'birth_category' => $row['birth_category'],
            'ews' => $row['ews'],
            'disabled' => $row['disabled'],
            'written_marks' => $row['written_marks'],
            'effective_category' => $ec,
        ];
    }
    arsort($researchTally);
    $researchStats = array_slice($researchTally, 0, 12, true);
}

render_header('Cutoff & Analytics', $u);
?>
<h1 class="text-2xl font-semibold mb-4">Cutoff & Category Analytics</h1>

<div class="card flex items-end gap-3 mb-5">
  <form method="post" class="flex items-end gap-3 flex-1">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="flex-1 max-w-xs">
      <label class="text-sm font-medium">Cutoff Marks (GN category)</label>
      <input type="number" step="0.1" name="cutoff_gn" value="<?= h($cutoff) ?>" required <?= $cutoffFrozen ? 'disabled' : '' ?>>
      <p class="text-xs text-slate-500 mt-1">GN/EWS: 100%, OBC-NC: 90%, SC/ST/PWD: 66.67% of this value.</p>
    </div>
    <?php if (!$cutoffFrozen): ?>
      <button class="btn btn-primary">Visualize and Save</button>
    <?php endif; ?>
  </form>
  <?php if (!$cutoffFrozen && $intake): ?>
    <form method="post" onsubmit="return confirm('Freeze cutoff? No further changes will be possible for this intake.');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button name="freeze_cutoff" class="btn btn-danger">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Freeze Cutoff
      </button>
    </form>
  <?php else: ?>
    <div class="inline-flex items-center gap-1 text-rose-700 font-semibold text-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Cutoff Frozen</div>
    <button type="button" class="btn btn-secondary text-xs" onclick="$('#unfreezeBackdrop').removeClass('hidden')">Unfreeze</button>
    <a href="/phdportal/admin/candidates.php?passed_cutoff=1" class="btn btn-secondary text-xs">View Shortlisted (<?= count($shortlisted) ?>)</a>
    <button type="button" class="btn btn-primary text-xs" onclick="downloadShortlistedPdf()">Download PDF</button>
  <?php endif; ?>
</div>

<?php if ($cutoffFrozen): ?>
<div id="unfreezeBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Unfreeze Cutoff</h3>
    <p class="text-sm text-slate-700 mb-3">Re-enable cutoff editing for this intake.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="unfreeze_cutoff" value="1">
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

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
  <div class="card">
    <h3 class="font-semibold mb-3">Category-wise Candidates</h3>
    <table class="data-table w-full">
      <thead><tr><th>Category</th><th>Cutoff</th><th>Total</th><th>Shortlisted</th></tr></thead>
      <tbody>
      <?php $totTotal = 0; $totAbove = 0; foreach ($catStats as $c => $s): $totTotal += $s['total']; $totAbove += $s['above']; ?>
        <tr><td><?= category_badge($c) ?></td><td><?= h(number_format($cutoff * (CUTOFF_MULTIPLIERS[$c] ?? 1.0), 2)) ?></td><td><?= $s['total'] ?></td><td class="text-green-700 font-semibold"><?= $s['above'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="font-semibold border-t"><td>Total</td><td></td><td><?= $totTotal ?></td><td class="text-green-700"><?= $totAbove ?></td></tr>
      </tfoot>
    </table>
  </div>
  <div class="card">
    <h3 class="font-semibold mb-3">Gender Breakdown</h3>
    <canvas id="genderChart" height="180"></canvas>
  </div>
  <div class="card">
    <h3 class="font-semibold mb-3">Cutoff Pass/Fail</h3>
    <canvas id="passFailChart" height="180"></canvas>
  </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
  <div class="card">
    <h3 class="font-semibold mb-3">Research Area Distribution (selected dept)</h3>
    <canvas id="researchChart" height="240"></canvas>
  </div>
  <div class="card">
    <h3 class="font-semibold mb-3">Cutoff Comparison Scenarios (all categories)</h3>
    <p class="text-xs text-slate-500 mb-2">Enter a base GN cutoff per chart. Each candidate is checked against base &times; their category multiplier. Not saved.</p>
    <div class="grid grid-cols-3 gap-3">
      <?php foreach ([max(0, $cutoff - 5), $cutoff, $cutoff + 5] as $i => $sv): ?>
        <div class="sc-box">
          <input type="number" step="0.1" class="sc-cutoff text-sm text-center" value="<?= h((float)$sv) ?>">
          <canvas class="sc-chart" height="120"></canvas>
          <div class="text-center text-xs mt-1 sc-counts"></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
const catLabels = <?= json_encode(array_keys($catStats)) ?>;
const catTotals = <?= json_encode(array_values(array_map(fn($s)=>$s['total'],$catStats))) ?>;
const catAbove = <?= json_encode(array_values(array_map(fn($s)=>$s['above'],$catStats))) ?>;
const gLabels = <?= json_encode(array_keys($genderStats)) ?>;
const gData = <?= json_encode(array_values($genderStats)) ?>;
const rLabels = <?= json_encode(array_keys($researchStats)) ?>;
const rData = <?= json_encode(array_values($researchStats)) ?>;
const marksByCat = <?= json_encode($marksByCat) ?>;

if (gLabels.length) {
  const gColorMap = {'Male':'#3b82f6','Female':'#ec4899','Unknown':'#94a3b8'};
  const gColors = gLabels.map(l => gColorMap[l] || '#94a3b8');
  new Chart(document.getElementById('genderChart'),{type:'doughnut',data:{labels:gLabels,datasets:[{data:gData,backgroundColor:gColors}]},options:{plugins:{legend:{position:'bottom'}}}});
}
if (rLabels.length) new Chart(document.getElementById('researchChart'),{type:'bar',data:{labels:rLabels,datasets:[{label:'Count',data:rData,backgroundColor:'#4f46e5'}]},options:{indexAxis:'y',plugins:{legend:{display:false}}}});
if (catLabels.length) new Chart(document.getElementById('passFailChart'),{type:'bar',data:{labels:catLabels,datasets:[{label:'Total',data:catTotals,backgroundColor:'#cbd5e1'},{label:'&ge; Cutoff',data:catAbove,backgroundColor:'#10b981'}]},options:{plugins:{legend:{position:'bottom'}}}});

const MULT = <?= json_encode(CUTOFF_MULTIPLIERS) ?>;
document.querySelectorAll('.sc-box').forEach(box => {
  const input = box.querySelector('.sc-cutoff');
  const counts = box.querySelector('.sc-counts');
  const chart = new Chart(box.querySelector('.sc-chart'),{type:'pie',data:{labels:['Pass','Fail'],datasets:[{data:[0,0],backgroundColor:['#10b981','#e11d48']}]},options:{plugins:{legend:{display:false}}}});
  const render = () => {
    const base = parseFloat(input.value) || 0;
    let pass = 0, total = 0;
    for (const cat in marksByCat) {
      const eff = base * (MULT[cat] ?? 1.0);
      for (const m of marksByCat[cat]) { total++; if (m >= eff) pass++; }
    }
    const fail = total - pass;
    chart.data.datasets[0].data = [pass, fail];
    chart.update();
    counts.innerHTML = '<span class="text-green-700">' + pass + ' pass</span> / <span class="text-rose-600">' + fail + ' fail</span>';
  };
  input.addEventListener('input', render);
  render();
});

<?php if ($cutoffFrozen): ?>
const SHORTLISTED = <?= json_encode($shortlisted) ?>;
const INTAKE_NAME = <?= json_encode($intake['name'] ?? '') ?>;
function downloadShortlistedPdf() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  doc.setFontSize(13);
  doc.text('SJMSOM IIT Bombay — Shortlisted Candidates (Cutoff)', 14, 14);
  doc.setFontSize(10);
  doc.text('Intake: ' + INTAKE_NAME + '   Generated: ' + new Date().toLocaleString(), 14, 21);
  const rows = SHORTLISTED.map(r => [
    r.serial_no ?? '', r.dept_reg_no ?? '', r.name ?? '',
    r.gender ?? '', r.effective_category ?? r.birth_category ?? '', r.written_marks ?? ''
  ]);
  doc.autoTable({
    startY: 26,
    head: [['Sr','Dept Reg No','Name','Gender','Category','Written Marks']],
    body: rows,
    styles: { fontSize: 9, cellPadding: 2 },
    headStyles: { fillColor: [79,70,229] }
  });
  doc.save('Shortlisted_' + (INTAKE_NAME || 'intake').replace(/\s+/g,'_') + '_<?= date('Ymd') ?>.pdf');
}
<?php endif; ?>
</script>
<?php render_footer(); ?>
