<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
$tab = $_GET['tab'] ?? 'entry';
$q = trim($_GET['q'] ?? '');

// Unfreeze marks (requires admin passcode)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unfreeze_marks'])) {
    check_csrf();
    $pass = $_POST['passcode'] ?? '';
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($pass, $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
    } elseif ($intake) {
        q('DELETE FROM settings WHERE `key`=?', ['marks_frozen_' . $intake['id']]);
        flash_set('Marks unfrozen. Edits re-enabled.', 'success');
    }
    redirect('/phdportal/admin/marks.php');
}

// Per-row autosave (no freeze) — JSON response, driven by client diff cache
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autosave_marks'])) {
    check_csrf();
    if (!$intake) json_out(['ok' => false, 'error' => 'No active intake.'], 400);
    if ((bool)setting('marks_frozen_' . $intake['id'])) {
        json_out(['ok' => false, 'error' => 'Marks are frozen.', 'frozen' => true], 423);
    }
    $payload = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($payload)) json_out(['ok' => false, 'error' => 'Invalid payload.'], 400);
    $saved = [];
    foreach ($payload as $row) {
        $cid = (int)($row['id'] ?? 0);
        if (!$cid) continue;
        $vals = [];
        $totalCorrect = 0; $totalWrong = 0; $anyVal = false;
        for ($s = 1; $s <= 4; $s++) {
            $cRaw = trim((string)($row["s{$s}_correct"] ?? ''));
            $wRaw = trim((string)($row["s{$s}_wrong"] ?? ''));
            $cVal = $cRaw === '' ? null : (int)$cRaw;
            $wVal = $wRaw === '' ? null : (int)$wRaw;
            $cCount = (int)($cVal ?? 0);
            $wCount = (int)($wVal ?? 0);
            if ($cCount + $wCount > 15) {
                json_out([
                    'ok' => false,
                    'error' => "Section $s total (correct + wrong) cannot exceed 15.",
                    'invalid' => ['cid' => $cid, 'section' => $s],
                ], 422);
            }
            $vals[] = $cVal;
            $vals[] = $wVal;
            if ($cVal !== null) { $totalCorrect += $cVal; $anyVal = true; }
            if ($wVal !== null) { $totalWrong += $wVal; $anyVal = true; }
        }
        $markVal = $anyVal ? ($totalCorrect - $totalWrong) : null;
        $params = array_merge($vals, [$markVal, $cid, $intake['id']]);
        q('UPDATE candidates SET
             s1_correct=?, s1_wrong=?,
             s2_correct=?, s2_wrong=?,
             s3_correct=?, s3_wrong=?,
             s4_correct=?, s4_wrong=?,
             written_marks=?
           WHERE id=? AND intake_id=? AND is_international=0', $params);
        $saved[] = $cid;
    }
    json_out(['ok' => true, 'saved' => $saved, 'at' => date('H:i:s')]);
}

// Bulk save from list view (section-wise correct/wrong counts)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    check_csrf();
    if ($intake && (bool)setting('marks_frozen_' . $intake['id'])) {
        flash_set('Marks are frozen. Cannot save.', 'error');
        $back = '/phdportal/admin/marks.php';
        if ($q !== '') $back .= '?q=' . urlencode($q);
        redirect($back);
    }
    $saved = 0;
    $sections = [];
    for ($s = 1; $s <= 4; $s++) {
        $sections[$s] = [
            'c' => $_POST["s{$s}_correct"] ?? [],
            'w' => $_POST["s{$s}_wrong"] ?? [],
        ];
    }
    $idSet = [];
    foreach ($sections as $data) {
        foreach (array_keys($data['c']) as $id) $idSet[(int)$id] = true;
        foreach (array_keys($data['w']) as $id) $idSet[(int)$id] = true;
    }

    // Validate first — reject the whole save if any section breaches the 15-question cap.
    $violations = [];
    foreach (array_keys($idSet) as $cid) {
        if (!$cid) continue;
        for ($s = 1; $s <= 4; $s++) {
            $cVal = trim((string)($sections[$s]['c'][$cid] ?? ''));
            $wVal = trim((string)($sections[$s]['w'][$cid] ?? ''));
            $cCount = $cVal === '' ? 0 : (int)$cVal;
            $wCount = $wVal === '' ? 0 : (int)$wVal;
            if ($cCount + $wCount > 15) {
                $violations[] = ['cid' => $cid, 'section' => $s, 'sum' => $cCount + $wCount];
            }
        }
    }
    if ($violations) {
        $first = $violations[0];
        $cand = one('SELECT dept_reg_no FROM candidates WHERE id=? AND is_international=0', [$first['cid']]);
        $reg = $cand['dept_reg_no'] ?? ('id ' . $first['cid']);
        $msg = count($violations) === 1
            ? "Section {$first['section']} for $reg has correct+wrong = {$first['sum']} (cap is 15). Nothing was saved."
            : count($violations) . " section totals exceed 15 (e.g. $reg section {$first['section']} = {$first['sum']}). Nothing was saved.";
        flash_set($msg, 'error');
        $back = '/phdportal/admin/marks.php';
        if ($q !== '') $back .= '?q=' . urlencode($q);
        redirect($back);
    }

    foreach (array_keys($idSet) as $cid) {
        if (!$cid) continue;
        $vals = [];
        $totalCorrect = 0; $totalWrong = 0; $anyVal = false;
        for ($s = 1; $s <= 4; $s++) {
            $cRaw = trim((string)($sections[$s]['c'][$cid] ?? ''));
            $wRaw = trim((string)($sections[$s]['w'][$cid] ?? ''));
            $cVal = $cRaw === '' ? null : (int)$cRaw;
            $wVal = $wRaw === '' ? null : (int)$wRaw;
            $vals[] = $cVal;
            $vals[] = $wVal;
            if ($cVal !== null) { $totalCorrect += $cVal; $anyVal = true; }
            if ($wVal !== null) { $totalWrong += $wVal; $anyVal = true; }
        }
        $markVal = $anyVal ? ($totalCorrect - $totalWrong) : null;
        $params = array_merge($vals, [$markVal, $cid, $intake['id']]);
        q('UPDATE candidates SET
             s1_correct=?, s1_wrong=?,
             s2_correct=?, s2_wrong=?,
             s3_correct=?, s3_wrong=?,
             s4_correct=?, s4_wrong=?,
             written_marks=?
           WHERE id=? AND intake_id=? AND is_international=0', $params);
        $saved++;
    }
    if ($intake) {
        set_setting('marks_frozen_' . $intake['id'], '1');
    }
    flash_set("Saved section marks for $saved candidate(s) and froze marks.", 'success');
    $back = '/phdportal/admin/marks.php';
    if ($q !== '') $back .= '?q=' . urlencode($q);
    redirect($back);
}

// Per-row CBT upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_cbt'])) {
    check_csrf();
    $cid = (int)$_POST['cand_id'];
    $cand = one('SELECT * FROM candidates WHERE id=? AND intake_id=? AND is_international=0', [$cid, $intake['id']]);
    if ($cand && !empty($_FILES['cbt']) && $_FILES['cbt']['error'] === UPLOAD_ERR_OK) {
        if (!is_dir(UPLOAD_CBT_DIR)) mkdir(UPLOAD_CBT_DIR, 0775, true);
        $name = date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/','_', basename($_FILES['cbt']['name']));
        $dest = UPLOAD_CBT_DIR . '/' . $name;
        move_uploaded_file($_FILES['cbt']['tmp_name'], $dest);
        q('UPDATE candidates SET cbt_file=? WHERE id=?', [$name, $cid]);
        flash_set('CBT file uploaded for ' . $cand['dept_reg_no'], 'success');
    }
    $back = '/phdportal/admin/marks.php';
    if ($q !== '') $back .= '?q=' . urlencode($q);
    redirect($back);
}

// Excel upload for bulk marks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_marks_excel'])) {
    check_csrf();
    if (!empty($_FILES['marks_excel']['name']) && $_FILES['marks_excel']['error'] === UPLOAD_ERR_OK && $intake) {
        if (!is_dir(UPLOAD_EXCEL_DIR)) mkdir(UPLOAD_EXCEL_DIR, 0775, true);
        $fn = UPLOAD_EXCEL_DIR . '/marks_' . date('Ymd_His') . '.xlsx';
        move_uploaded_file($_FILES['marks_excel']['tmp_name'], $fn);
        $scriptDir = dirname(EXTRACT_SCRIPT);
        $cmd = escapeshellcmd(PYTHON_BIN) . ' ' . escapeshellarg($scriptDir . '/extract_marks.py') . ' ' . escapeshellarg($fn) . ' 2>&1';
        $proc = proc_open($cmd, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        if (!is_resource($proc)) { flash_set('Failed to run marks extractor.', 'error'); redirect('/phdportal/admin/marks.php?tab=excel'); }
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $rc = proc_close($proc);
        if ($rc !== 0) { flash_set('Extractor error: '.trim($err ?: $out), 'error'); redirect('/phdportal/admin/marks.php?tab=excel'); }
        $records = json_decode($out, true);
        if (!is_array($records)) { flash_set('Extractor returned invalid data.', 'error'); redirect('/phdportal/admin/marks.php?tab=excel'); }
        $saved = 0;
        foreach ($records as $r) {
            $dept = trim($r['dept_reg_no'] ?? '');
            $mark = $r['written_marks'] ?? null;
            if (!$dept || $mark === null) continue;
            $res = q('UPDATE candidates SET written_marks=? WHERE intake_id=? AND dept_reg_no=? AND is_international=0',
                [(float)$mark, $intake['id'], $dept]);
            if ($res->rowCount()) $saved++;
        }
        flash_set("Excel marks import: $saved candidates updated.", 'success');
        redirect('/phdportal/admin/marks.php');
    }
}

// Export section-wise totals as CSV (+1 per correct, -0.25 per wrong)
if ($tab === 'export' && isset($_GET['download']) && $intake) {
    $rows = all("SELECT serial_no, dept_reg_no, name,
                        s1_correct, s1_wrong, s2_correct, s2_wrong,
                        s3_correct, s3_wrong, s4_correct, s4_wrong
                 FROM candidates
                 WHERE intake_id=? AND is_international=0 AND screening_status='Yes'
                 ORDER BY serial_no, id", [$intake['id']]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="marks_sectional_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sr No','Dept Reg No','Name',
        'S1 Correct','S1 Wrong','S1 Total',
        'S2 Correct','S2 Wrong','S2 Total',
        'S3 Correct','S3 Wrong','S3 Total',
        'S4 Correct','S4 Wrong','S4 Total',
        'Overall Total']);
    foreach ($rows as $r) {
        $line = [$r['serial_no'], $r['dept_reg_no'], $r['name']];
        $overall = 0.0; $hasAny = false;
        for ($s = 1; $s <= 4; $s++) {
            $c = $r['s'.$s.'_correct'];
            $w = $r['s'.$s.'_wrong'];
            if ($c === null && $w === null) {
                $line[] = ''; $line[] = ''; $line[] = '';
                continue;
            }
            $hasAny = true;
            $cV = (int)$c; $wV = (int)$w;
            $tot = $cV - 0.25 * $wV;
            $overall += $tot;
            $line[] = $cV;
            $line[] = $wV;
            $line[] = rtrim(rtrim(number_format($tot, 2, '.', ''), '0'), '.');
        }
        $line[] = $hasAny ? rtrim(rtrim(number_format($overall, 2, '.', ''), '0'), '.') : '';
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

$frozen = $intake ? (bool)setting('marks_frozen_' . $intake['id']) : false;

// Build filtered list
$rows = [];
if ($intake) {
    $where = ['intake_id = ?', 'is_international = 0', "screening_status = 'Yes'"];
    $params = [$intake['id']];
    if ($q !== '') {
        $where[] = '(dept_reg_no LIKE ? OR name LIKE ? OR email LIKE ?)';
        $like = "%$q%"; array_push($params, $like, $like, $like);
    }
    $sql = 'SELECT id, serial_no, dept_reg_no, name, written_marks,
                   s1_correct, s1_wrong, s2_correct, s2_wrong,
                   s3_correct, s3_wrong, s4_correct, s4_wrong
            FROM candidates WHERE ' . implode(' AND ', $where) . ' ORDER BY serial_no, id LIMIT 1000';
    $rows = all($sql, $params);
}

render_header('Marks Entry', $u);
?>
<h1 class="text-2xl font-semibold mb-4">
  Written / CBT Marks Entry
  <?php if ($frozen): ?>
    <span class="inline-flex items-center gap-1 text-rose-700 text-sm font-semibold align-middle ml-2">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Marks Frozen
    </span>
  <?php endif; ?>
</h1>

<div class="flex border-b border-slate-200 mb-5 gap-1">
  <a href="?tab=entry" class="px-4 py-2 text-sm font-medium rounded-t <?= $tab==='entry' ? 'bg-white border border-b-white border-slate-200 text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">Marks Entry</a>
  <a href="?tab=excel" class="px-4 py-2 text-sm font-medium rounded-t <?= $tab==='excel' ? 'bg-white border border-b-white border-slate-200 text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">Excel Import</a>
  <a href="?tab=export" class="px-4 py-2 text-sm font-medium rounded-t <?= $tab==='export' ? 'bg-white border border-b-white border-slate-200 text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">Export</a>
</div>

<?php if ($tab === 'entry'): ?>

<form method="get" class="card mb-4 flex items-end gap-3">
  <input type="hidden" name="tab" value="entry">
  <div class="flex-1">
    <label class="text-xs font-medium">Search (reg no / name / email)</label>
    <input type="text" name="q" value="<?= h($q) ?>" autocomplete="off" placeholder="RMG… / name…" id="searchInput">
  </div>
  <button class="btn btn-primary">Filter</button>
  <?php if ($q !== ''): ?><a href="?tab=entry" class="btn btn-secondary">Reset</a><?php endif; ?>
  <div class="text-xs text-slate-500 self-center"><?= count($rows) ?> shown</div>
</form>

<form method="post" id="bulkForm">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="bulk_save" value="1">
  <?php
    $sectionTitles = [
      1 => 'Section 1 — Verbal Ability',
      2 => 'Section 2 — Quantitative Ability',
      3 => 'Section 3 — Logical Reasoning',
      4 => 'Section 4 — Data Interpretation',
    ];
  ?>
  <div class="card p-0 overflow-auto" style="max-height: calc(100vh - 180px);">
  <table class="data-table w-full">
    <thead>
      <tr>
        <th rowspan="2">Sr</th>
        <th rowspan="2">Dept Reg No</th>
        <th rowspan="2">Name</th>
        <?php foreach ($sectionTitles as $idx => $title): ?>
          <th colspan="2" class="text-center border-l border-slate-200 sec-col-<?= $idx ?>"><?= h($title) ?></th>
        <?php endforeach; ?>
      </tr>
      <tr>
        <?php for ($s = 1; $s <= 4; $s++): ?>
          <th class="text-center text-xs border-l border-slate-200 sec-col-<?= $s ?>" style="width:80px">Correct</th>
          <th class="text-center text-xs sec-col-<?= $s ?>" style="width:80px">Wrong</th>
        <?php endfor; ?>
      </tr>
    </thead>
    <tbody id="rowsBody">
    <?php foreach ($rows as $r): ?>
      <tr data-search="<?= h(strtolower($r['dept_reg_no'].' '.$r['name'])) ?>" data-cid="<?= (int)$r['id'] ?>">
        <td><?= (int)$r['serial_no'] ?></td>
        <td class="font-mono text-xs">
          <a class="text-indigo-700 hover:underline" href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>"><?= h($r['dept_reg_no']) ?></a>
        </td>
        <td><?= h($r['name']) ?></td>
        <?php for ($s = 1; $s <= 4; $s++): ?>
          <td class="border-l border-slate-200 sec-col-<?= $s ?>">
            <input type="number" min="0" max="15" step="1" name="s<?= $s ?>_correct[<?= (int)$r['id'] ?>]"
                   value="<?= h($r['s'.$s.'_correct']) ?>" placeholder="—"
                   class="w-16 text-sm text-center sec-inp"
                   data-section="<?= $s ?>" data-kind="c"
                   <?= $frozen ? 'disabled' : '' ?>>
          </td>
          <td class="sec-col-<?= $s ?>">
            <input type="number" min="0" max="15" step="1" name="s<?= $s ?>_wrong[<?= (int)$r['id'] ?>]"
                   value="<?= h($r['s'.$s.'_wrong']) ?>" placeholder="—"
                   class="w-16 text-sm text-center sec-inp"
                   data-section="<?= $s ?>" data-kind="w"
                   <?= $frozen ? 'disabled' : '' ?>>
          </td>
        <?php endfor; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="11" class="text-center py-6 text-slate-500">
        <?= $q !== '' ? 'No shortlisted candidates match "' . h($q) . '".' : 'No shortlisted candidates in active intake.' ?>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($rows): ?>
  <div class="mt-4">
    <p class="text-xs text-slate-500">Tip: enter the number of correct and wrong answers per section. Each section has 15 questions, so correct + wrong cannot exceed 15. Saving will also freeze marks for this intake.</p>
  </div>
  <?php endif; ?>
</form>

<!-- Sticky left-side action panel -->
<?php if ($rows || $frozen): ?>
<div class="fixed left-4 top-1/2 -translate-y-1/2 z-40 flex flex-col gap-2 w-44">
  <?php if (!$frozen): ?>
    <button form="bulkForm" class="btn btn-danger shadow-lg w-full text-xs"
            onclick="return confirm('Save all entered marks and freeze them? Entries will be locked for this intake until an admin unfreezes.');">
      <svg class="inline" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Save &amp; Freeze Marks
    </button>
    <div class="bg-white shadow-lg rounded-md px-3 py-2 border border-slate-200">
      <div class="text-[10px] uppercase tracking-wide text-slate-400 font-semibold">Autosave</div>
      <div id="autosaveStatus" class="text-xs mt-1 text-slate-500">Idle — edits save automatically.</div>
    </div>
  <?php else: ?>
    <div class="bg-white shadow-lg rounded-md px-3 py-2 text-xs text-rose-700 font-semibold flex items-center gap-1 border border-rose-200">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Marks Frozen
    </div>
    <button type="button" class="btn btn-secondary shadow-lg w-full text-xs" onclick="openUnfreeze()">Unfreeze</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Unfreeze confirmation modal -->
<?php if ($frozen): ?>
<div id="unfreezeBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Unfreeze Marks</h3>
    <p class="text-sm text-slate-700 mb-3">Re-open mark &amp; remark entry for this intake.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="unfreeze_marks" value="1">
      <label class="text-xs font-medium">Enter your admin password to confirm:</label>
      <input type="password" name="passcode" required autocomplete="new-password" class="mt-1" placeholder="Your login password">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn btn-secondary" onclick="closeUnfreeze()">Cancel</button>
        <button class="btn btn-danger">Unfreeze</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// Live client-side filter on top of server list (when search is unchanged)
$('#searchInput').on('input', function(){
  const v = $(this).val().toLowerCase().trim();
  $('#rowsBody tr').each(function(){
    const s = $(this).data('search') || '';
    $(this).toggle(v === '' || String(s).indexOf(v) !== -1);
  });
});

function openCbtModal(id, reg) {
  $('#cbtCandId').val(id);
  $('#cbtReg').text(reg);
  $('#cbtBackdrop').removeClass('hidden');
}
function closeCbtModal() { $('#cbtBackdrop').addClass('hidden'); }
$('#cbtBackdrop').on('click', function(e){ if (e.target === this) closeCbtModal(); });

function openUnfreeze() { $('#unfreezeBackdrop').removeClass('hidden'); }
function closeUnfreeze() { $('#unfreezeBackdrop').addClass('hidden'); }
$('#unfreezeBackdrop').on('click', function(e){ if (e.target === this) closeUnfreeze(); });
$(document).on('keydown', e => {
  if (e.key === 'Escape') { closeCbtModal(); closeUnfreeze(); }
});

<?php if (!$frozen && $rows): ?>
// ---- Per-row autosave with client-side diff cache ----
// Goal: only send rows whose fields differ from the last-known-saved snapshot,
// debounced per-row so a burst of keystrokes collapses into one request.
(function(){
  const DEBOUNCE_MS = 800;
  const FIELDS = ['s1_correct','s1_wrong','s2_correct','s2_wrong','s3_correct','s3_wrong','s4_correct','s4_wrong'];

  const cache = new Map();     // cid -> { field: stringValue }
  const timers = new Map();    // cid -> debounce timer id
  const inflight = new Set();  // cid set currently posting
  const rowInputs = new Map(); // cid -> { field: inputEl } (cached refs to skip per-keystroke querySelector)

  function inputsFor(tr) {
    const cid = parseInt(tr.dataset.cid, 10);
    let map = rowInputs.get(cid);
    if (!map) {
      map = {};
      tr.querySelectorAll('input.sec-inp').forEach(el => {
        const s = el.dataset.section, k = el.dataset.kind;
        if (s && k) map['s' + s + (k === 'c' ? '_correct' : '_wrong')] = el;
      });
      rowInputs.set(cid, map);
    }
    return map;
  }

  function readRow(tr) {
    const map = inputsFor(tr);
    const data = {};
    FIELDS.forEach(f => { data[f] = map[f] ? map[f].value.trim() : ''; });
    return data;
  }

  function rowDiffers(cid, current) {
    const snap = cache.get(cid);
    if (!snap) return true;
    return FIELDS.some(f => (snap[f] || '') !== (current[f] || ''));
  }

  function setStatus(text, tone) {
    const el = document.getElementById('autosaveStatus');
    if (!el) return;
    el.textContent = text;
    el.className = 'text-xs mt-1 ' + (
      tone === 'error' ? 'text-rose-600' :
      tone === 'saving' ? 'text-slate-500' :
      tone === 'saved' ? 'text-emerald-600' : 'text-slate-500'
    );
  }

  function markRow(tr, state) {
    tr.classList.remove('row-saving','row-saved','row-error');
    if (state) tr.classList.add('row-' + state);
  }

  async function flushRow(cid) {
    if (inflight.has(cid)) return;
    const tr = document.querySelector('tr[data-cid="' + cid + '"]');
    if (!tr) return;
    const current = readRow(tr);
    if (!rowDiffers(cid, current)) { markRow(tr, null); return; }

    inflight.add(cid);
    markRow(tr, 'saving');
    setStatus('Saving…', 'saving');

    const fd = new FormData();
    fd.append('autosave_marks', '1');
    fd.append('csrf', window.CSRF_TOKEN);
    fd.append('rows', JSON.stringify([Object.assign({ id: cid }, current)]));

    try {
      const res = await fetch(window.location.pathname, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': window.CSRF_TOKEN }
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        markRow(tr, 'error');
        setStatus(json.error || ('Save failed (' + res.status + ')'), 'error');
        if (json.frozen) disableAll();
        return;
      }
      // Refresh the snapshot to whatever we just sent so subsequent diffs are relative to saved state.
      cache.set(cid, current);
      markRow(tr, 'saved');
      setStatus('Saved ' + (json.at || ''), 'saved');
      // If the user kept typing while we were in flight, the final value may differ again.
      const now = readRow(tr);
      if (rowDiffers(cid, now)) scheduleRow(cid);
    } catch (e) {
      markRow(tr, 'error');
      setStatus('Network error — will retry on next edit', 'error');
    } finally {
      inflight.delete(cid);
    }
  }

  function scheduleRow(cid) {
    if (timers.has(cid)) clearTimeout(timers.get(cid));
    timers.set(cid, setTimeout(() => { timers.delete(cid); flushRow(cid); }, DEBOUNCE_MS));
  }

  function disableAll() {
    document.querySelectorAll('#rowsBody input[type="number"]').forEach(el => { el.disabled = true; });
  }

  // Seed cache from the initial DOM values — treat the server-rendered state as the baseline.
  document.querySelectorAll('#rowsBody tr[data-cid]').forEach(tr => {
    cache.set(parseInt(tr.dataset.cid, 10), readRow(tr));
  });

  // Validate one section's correct+wrong ≤ 15. Only the edited section is checked per keystroke
  // to keep typing cheap on large tables. Returns true if THIS section is OK.
  function validateSection(tr, s) {
    const map = inputsFor(tr);
    const cEl = map['s' + s + '_correct'];
    const wEl = map['s' + s + '_wrong'];
    if (!cEl || !wEl) return true;
    const c = parseInt(cEl.value || '0', 10) || 0;
    const w = parseInt(wEl.value || '0', 10) || 0;
    const bad = (c + w) > 15;
    const tip = bad ? 'Section ' + s + ': correct + wrong = ' + (c + w) + ' (max 15)' : '';
    cEl.classList.toggle('section-invalid', bad);
    wEl.classList.toggle('section-invalid', bad);
    cEl.title = tip;
    wEl.title = tip;
    return !bad;
  }

  function rowHasInvalidSection(tr) {
    return tr.querySelector('input.section-invalid') !== null;
  }

  // `input` covers keystrokes, paste, spinner arrows, and programmatic changes.
  document.getElementById('rowsBody').addEventListener('input', e => {
    const el = e.target;
    if (!el.classList.contains('sec-inp')) return;
    const tr = el.closest('tr[data-cid]');
    if (!tr) return;
    const cid = parseInt(tr.dataset.cid, 10);
    const sec = parseInt(el.dataset.section, 10);
    const sectionOk = validateSection(tr, sec);
    if (!sectionOk || rowHasInvalidSection(tr)) {
      // Cancel any pending autosave — server would reject anyway.
      if (timers.has(cid)) { clearTimeout(timers.get(cid)); timers.delete(cid); }
      markRow(tr, 'error');
      setStatus('Section total > 15 — fix the highlighted cells', 'error');
      return;
    }
    const current = readRow(tr);
    if (rowDiffers(cid, current)) {
      markRow(tr, 'saving');
      scheduleRow(cid);
    } else {
      // Reverted back to saved state — cancel any pending save for this row.
      if (timers.has(cid)) { clearTimeout(timers.get(cid)); timers.delete(cid); }
      markRow(tr, null);
    }
  });

  // If the user leaves the page mid-edit, flush any pending rows synchronously.
  window.addEventListener('beforeunload', () => {
    timers.forEach((t, cid) => { clearTimeout(t); flushRow(cid); });
  });
})();
<?php endif; ?>
</script>
<style>
.sec-col-1 { background: #eff6ff; }   /* light blue */
.sec-col-2 { background: #f5f3ff; }   /* light violet */
.sec-col-3 { background: #ecfeff; }   /* light cyan */
.sec-col-4 { background: #fdf2f8; }   /* light pink */
/* Sticky header — keeps section columns labelled while scrolling long lists */
.data-table thead th { position: sticky; top: 0; z-index: 5; }
.data-table thead tr:nth-child(2) th { top: 32px; }
.data-table thead th.sec-col-1 { background: #eff6ff; }
.data-table thead th.sec-col-2 { background: #f5f3ff; }
.data-table thead th.sec-col-3 { background: #ecfeff; }
.data-table thead th.sec-col-4 { background: #fdf2f8; }
tr.row-saving  input[type="number"] { background: #fef9c3; }
tr.row-saved   input[type="number"] { background: #ecfdf5; }
tr.row-error   input[type="number"] { background: #fee2e2; }
input.section-invalid { background: #fee2e2 !important; border-color: #dc2626 !important; }
</style>

<?php elseif ($tab === 'excel'): ?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
  <div class="card">
    <h3 class="font-semibold mb-2">Import Marks from Excel</h3>
    <p class="text-xs text-slate-500 mb-3">Upload an Excel (.xlsx) file with <strong>Dept Reg No</strong> and <strong>Written Marks</strong> columns.</p>
    <form method="post" enctype="multipart/form-data" class="space-y-3">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="upload_marks_excel" value="1">
      <input type="file" name="marks_excel" accept=".xlsx" required>
      <button class="btn btn-primary">Import Excel</button>
    </form>
  </div>
  <div class="card">
    <h3 class="font-semibold mb-2">Expected Format</h3>
    <ul class="text-xs text-slate-600 space-y-1 list-disc ml-4">
      <li><strong>Dept Reg No</strong> — must match existing records</li>
      <li><strong>Written Marks</strong> — numeric value</li>
    </ul>
    <p class="text-xs text-slate-500 mt-2">Columns are matched case-insensitively. Rows without a matching Dept Reg No are skipped.</p>
  </div>
</div>

<?php elseif ($tab === 'export'): ?>
<?php
  $exportRows = [];
  if ($intake) {
      $exportRows = all("SELECT id, serial_no, dept_reg_no, name,
                                s1_correct, s1_wrong, s2_correct, s2_wrong,
                                s3_correct, s3_wrong, s4_correct, s4_wrong
                         FROM candidates
                         WHERE intake_id=? AND is_international=0 AND screening_status='Yes'
                         ORDER BY serial_no, id", [$intake['id']]);
  }
  $fmt = function($v) {
      $s = number_format((float)$v, 2, '.', '');
      return rtrim(rtrim($s, '0'), '.') === '' ? '0' : rtrim(rtrim($s, '0'), '.');
  };
?>
<div class="flex items-center justify-between mb-3">
  <div class="text-xs text-slate-500">
    Scoring: <strong>+1</strong> for each correct answer, <strong>−0.25</strong> for each wrong answer.
  </div>
  <a href="?tab=export&download=1" class="btn btn-primary text-xs">Download CSV</a>
</div>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
  <thead>
    <tr>
      <th rowspan="2">Sr</th>
      <th rowspan="2">Dept Reg No</th>
      <th rowspan="2">Name</th>
      <th colspan="1" class="text-center border-l border-slate-200">Verbal Ability</th>
      <th colspan="1" class="text-center border-l border-slate-200">Quantitative Ability</th>
      <th colspan="1" class="text-center border-l border-slate-200">Logical Reasoning</th>
      <th colspan="1" class="text-center border-l border-slate-200">Data Interpretation</th>
      <th rowspan="2" class="text-center border-l-2 border-indigo-300 bg-indigo-50 text-indigo-800">Overall Total</th>
    </tr>
    <tr>
      <th class="text-center text-xs border-l border-slate-200">Section 1</th>
      <th class="text-center text-xs border-l border-slate-200">Section 2</th>
      <th class="text-center text-xs border-l border-slate-200">Section 3</th>
      <th class="text-center text-xs border-l border-slate-200">Section 4</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($exportRows as $r): ?>
    <?php
      $secTotals = [];
      $overall = 0.0; $hasAny = false;
      for ($s = 1; $s <= 4; $s++) {
          $c = $r['s'.$s.'_correct'];
          $w = $r['s'.$s.'_wrong'];
          if ($c === null && $w === null) { $secTotals[$s] = null; continue; }
          $hasAny = true;
          $t = (int)$c - 0.25 * (int)$w;
          $secTotals[$s] = $t;
          $overall += $t;
      }
    ?>
    <tr>
      <td><?= (int)$r['serial_no'] ?></td>
      <td class="font-mono text-xs">
        <a class="text-indigo-700 hover:underline" href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>"><?= h($r['dept_reg_no']) ?></a>
      </td>
      <td><?= h($r['name']) ?></td>
      <?php for ($s = 1; $s <= 4; $s++): ?>
        <td class="text-center border-l border-slate-200">
          <?= $secTotals[$s] === null ? '<span class="text-slate-300">—</span>' : h($fmt($secTotals[$s])) ?>
        </td>
      <?php endfor; ?>
      <td class="text-center border-l-2 border-indigo-300 bg-indigo-50 font-semibold text-indigo-800">
        <?= $hasAny ? h($fmt($overall)) : '<span class="text-slate-300">—</span>' ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$exportRows): ?>
    <tr><td colspan="8" class="text-center py-6 text-slate-500">No shortlisted candidates in active intake.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php render_footer(); ?>
