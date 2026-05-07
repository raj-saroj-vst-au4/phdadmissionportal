<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

$omr_root = PUBLIC_ROOT . '/uploads/omr';
$pages_dir = $omr_root . '/pages';
if (!is_dir($omr_root)) @mkdir($omr_root, 0775, true);
if (!is_dir($pages_dir)) @mkdir($pages_dir, 0775, true);

$existing_key = one('SELECT * FROM omr_keys WHERE intake_id=?', [$intake['id']]);

// --- Set/replace answer key (manual entry) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_key_manual'])) {
    check_csrf();
    $numQ = max(1, min(200, (int)($_POST['num_questions'] ?? 0)));
    $raw = trim((string)($_POST['answers'] ?? ''));
    // Accept formats:
    //   "1:A 2:B 3:C ..." or "A,B,C,D,..." or one-per-line "A" / "1=A"
    $answers = [];
    $tokens = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($tokens) === $numQ && preg_match('/^[A-Da-d]$/', $tokens[0])) {
        // Plain ordered list
        foreach ($tokens as $i => $t) {
            $answers[$i + 1] = strtoupper(trim($t));
        }
    } else {
        // Q:A or Q=A pairs
        foreach ($tokens as $t) {
            if (preg_match('/^(\d+)\s*[:=]\s*([A-Da-d])$/', $t, $m)) {
                $answers[(int)$m[1]] = strtoupper($m[2]);
            }
        }
    }
    $missing = [];
    for ($i = 1; $i <= $numQ; $i++) {
        if (!isset($answers[$i])) $missing[] = $i;
    }
    if ($missing) {
        flash_set('Missing answers for question(s): ' . implode(', ', array_slice($missing, 0, 12))
            . (count($missing) > 12 ? '…' : '') . '. None saved.', 'error');
    } else {
        $layout = ['num_questions' => $numQ];
        $ansJson = json_encode($answers);
        if ($existing_key) {
            q('UPDATE omr_keys SET num_questions=?, layout_json=?, answer_json=?, uploaded_by=?, uploaded_at=NOW() WHERE intake_id=?',
                [$numQ, json_encode($layout), $ansJson, $u['id'], $intake['id']]);
        } else {
            q('INSERT INTO omr_keys(intake_id, num_questions, layout_json, answer_json, uploaded_by) VALUES(?,?,?,?,?)',
                [$intake['id'], $numQ, json_encode($layout), $ansJson, $u['id']]);
        }
        flash_set("Answer key saved ($numQ questions).", 'success');
    }
    redirect('/phdportal/admin/omr.php');
}

// --- Process uploaded answer-key PDF (extract answers via scanner) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_answer_key_pdf'])) {
    check_csrf();
    $fname = basename((string)($_POST['filename'] ?? ''));
    $numQ  = max(1, min(200, (int)($_POST['num_questions'] ?? 0)));
    $path  = $omr_root . '/' . $fname;
    if (!is_file($path)) {
        flash_set('Uploaded file not found: ' . h($fname), 'error');
        redirect('/phdportal/admin/omr.php');
    }
    $cmd = escapeshellcmd(OMR_PYTHON_BIN) . ' '
        . escapeshellarg(APP_ROOT . '/scripts/omr_scan.py')
        . ' --pdf ' . escapeshellarg($path)
        . ' --num-questions ' . (int)$numQ
        . ' --mode answer-key';
    $out = shell_exec($cmd . ' 2>&1');
    $data = json_decode((string)$out, true);
    if (!is_array($data) || empty($data['pages'])) {
        flash_set('Scanner produced no result. Output: ' . h(substr((string)$out, 0, 400)), 'error');
        redirect('/phdportal/admin/omr.php');
    }
    $page0 = $data['pages'][0];
    if (empty($page0['ok'])) {
        flash_set('Could not read answer key page: ' . h($page0['error'] ?? 'unknown'), 'error');
        redirect('/phdportal/admin/omr.php');
    }
    $answers = [];
    foreach (($page0['answers'] ?? []) as $q => $ch) {
        $answers[(int)$q] = ($ch === '-' || $ch === 'X') ? '' : strtoupper((string)$ch);
    }
    $missing = [];
    for ($i = 1; $i <= $numQ; $i++) {
        if (empty($answers[$i])) $missing[] = $i;
    }
    if ($missing) {
        flash_set('Scanner could not detect answers for question(s): ' . implode(', ', array_slice($missing, 0, 12))
            . (count($missing) > 12 ? '…' : '') . '. Edit manually below and re-save.', 'error');
        $_SESSION['omr_partial_key'] = ['num' => $numQ, 'answers' => $answers];
    } else {
        $layout = ['num_questions' => $numQ];
        $ansJson = json_encode($answers);
        if ($existing_key) {
            q('UPDATE omr_keys SET num_questions=?, layout_json=?, answer_json=?, uploaded_by=?, uploaded_at=NOW() WHERE intake_id=?',
                [$numQ, json_encode($layout), $ansJson, $u['id'], $intake['id']]);
        } else {
            q('INSERT INTO omr_keys(intake_id, num_questions, layout_json, answer_json, uploaded_by) VALUES(?,?,?,?,?)',
                [$intake['id'], $numQ, json_encode($layout), $ansJson, $u['id']]);
        }
        flash_set("Answer key extracted from PDF and saved ($numQ questions).", 'success');
    }
    redirect('/phdportal/admin/omr.php');
}

// --- Process bulk uploaded candidate PDF ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_bulk'])) {
    check_csrf();
    $fname = basename((string)($_POST['filename'] ?? ''));
    $path  = $omr_root . '/' . $fname;
    if (!$existing_key) {
        flash_set('Set the answer key first.', 'error');
        redirect('/phdportal/admin/omr.php');
    }
    if (!is_file($path)) {
        flash_set('Uploaded file not found: ' . h($fname), 'error');
        redirect('/phdportal/admin/omr.php');
    }
    $numQ = (int)$existing_key['num_questions'];
    $key  = json_decode((string)$existing_key['answer_json'], true) ?: [];
    $keyTmp = tempnam(sys_get_temp_dir(), 'omrkey_');
    file_put_contents($keyTmp, json_encode($key));

    $cmd = escapeshellcmd(OMR_PYTHON_BIN) . ' '
        . escapeshellarg(APP_ROOT . '/scripts/omr_scan.py')
        . ' --pdf ' . escapeshellarg($path)
        . ' --num-questions ' . (int)$numQ
        . ' --key-json ' . escapeshellarg($keyTmp)
        . ' --save-pages-dir ' . escapeshellarg($pages_dir)
        . ' --mode bulk';
    $out = shell_exec($cmd . ' 2>&1');
    @unlink($keyTmp);

    $data = json_decode((string)$out, true);
    if (!is_array($data) || empty($data['pages'])) {
        flash_set('Scanner produced no result. Output: ' . h(substr((string)$out, 0, 400)), 'error');
        redirect('/phdportal/admin/omr.php');
    }
    $matched = 0; $unmatched = 0; $bad = 0;
    $issues = [];
    foreach ($data['pages'] as $pg) {
        if (empty($pg['ok'])) { $bad++; $issues[] = "p{$pg['page']}: " . ($pg['error'] ?? 'unknown'); continue; }
        $qr = trim((string)($pg['qr'] ?? ''));
        if ($qr === '') { $unmatched++; $issues[] = "p{$pg['page']}: QR not detected"; continue; }
        $cand = one('SELECT id, dept_reg_no FROM candidates WHERE intake_id=? AND dept_reg_no=? AND is_international=0',
            [$intake['id'], $qr]);
        if (!$cand) {
            $unmatched++;
            $issues[] = "p{$pg['page']}: no candidate for QR='$qr'";
            continue;
        }
        $params = [
            $cand['id'], $intake['id'], $qr,
            (int)$pg['correct'], (int)$pg['wrong'], (int)$pg['blank'], (int)$pg['multi'],
            (float)($pg['confidence'] ?? 0), !empty($pg['review_needed']) ? 1 : 0,
            (float)$pg['marks'], json_encode($pg['answers'] ?? []), (string)($pg['page_image'] ?? ''),
        ];
        q('INSERT INTO omr_results(candidate_id, intake_id, qr_code, correct_count, wrong_count, blank_count, multi_count, confidence, review_needed, marks, answers_json, page_image)
           VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE
             qr_code=VALUES(qr_code), correct_count=VALUES(correct_count), wrong_count=VALUES(wrong_count),
             blank_count=VALUES(blank_count), multi_count=VALUES(multi_count),
             confidence=VALUES(confidence), review_needed=VALUES(review_needed),
             marks=VALUES(marks), answers_json=VALUES(answers_json), page_image=VALUES(page_image),
             processed_at=NOW()',
            $params);
        $matched++;
        if (!empty($pg['review_needed'])) $reviewCount = ($reviewCount ?? 0) + 1;
    }
    $msg = "Processed " . count($data['pages']) . " page(s): $matched matched to candidates, $unmatched unmatched, $bad failed.";
    if (!empty($reviewCount)) $msg .= " $reviewCount flagged for manual review (low confidence or QR missing).";
    if ($issues) $msg .= ' First issues: ' . implode(' | ', array_slice($issues, 0, 5));
    flash_set($msg, $matched > 0 ? 'success' : 'error');
    redirect('/phdportal/admin/omr.php');
}

// --- Apply OMR marks to candidates.written_marks ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_to_written'])) {
    check_csrf();
    $rows = all('SELECT candidate_id, marks FROM omr_results WHERE intake_id=?', [$intake['id']]);
    $n = 0;
    foreach ($rows as $r) {
        q('UPDATE candidates SET written_marks=? WHERE id=? AND intake_id=? AND is_international=0',
            [(float)$r['marks'], (int)$r['candidate_id'], $intake['id']]);
        $n++;
    }
    flash_set("Applied OMR marks to $n candidate(s).", 'success');
    redirect('/phdportal/admin/omr.php');
}

// --- Push OMR results to marks page (section-wise correct/wrong + written_marks) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['push_to_marks'])) {
    check_csrf();
    if (!$existing_key) {
        flash_set('Set the answer key first.', 'error');
        redirect('/phdportal/admin/omr.php');
    }
    $numQ = (int)$existing_key['num_questions'];
    $key  = json_decode((string)$existing_key['answer_json'], true) ?: [];

    $sec = [];
    for ($s = 1; $s <= 4; $s++) {
        $start = (int)($_POST["s{$s}_start"] ?? 0);
        $end   = (int)($_POST["s{$s}_end"] ?? 0);
        if ($start < 1 || $end < $start || $end > $numQ) {
            flash_set("Invalid range for section $s ($start–$end). Must be between 1 and $numQ with start ≤ end.", 'error');
            redirect('/phdportal/admin/omr.php');
        }
        $sec[$s] = [$start, $end];
    }

    $rows = all('SELECT candidate_id, answers_json, marks FROM omr_results WHERE intake_id=?', [$intake['id']]);
    $n = 0;
    foreach ($rows as $r) {
        $ans = json_decode((string)$r['answers_json'], true) ?: [];
        $params = [];
        foreach ($sec as [$start, $end]) {
            $c = 0; $w = 0;
            for ($q = $start; $q <= $end; $q++) {
                $a = $ans[(string)$q] ?? $ans[$q] ?? '-';
                if ($a === '-' || $a === '') continue;
                if ($a === 'X') { $w++; continue; }
                $kAns = $key[(string)$q] ?? $key[$q] ?? null;
                if ($kAns && strtoupper((string)$a) === strtoupper((string)$kAns)) $c++;
                else $w++;
            }
            $params[] = $c;
            $params[] = $w;
        }
        $params[] = (float)$r['marks'];
        $params[] = (int)$r['candidate_id'];
        $params[] = (int)$intake['id'];
        q('UPDATE candidates SET
             s1_correct=?, s1_wrong=?,
             s2_correct=?, s2_wrong=?,
             s3_correct=?, s3_wrong=?,
             s4_correct=?, s4_wrong=?,
             written_marks=?
           WHERE id=? AND intake_id=? AND is_international=0', $params);
        $n++;
    }
    flash_set("Pushed OMR results to marks page for $n candidate(s).", 'success');
    redirect('/phdportal/admin/marks.php');
}

// --- Clear all OMR results for the intake ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_results'])) {
    check_csrf();
    q('DELETE FROM omr_results WHERE intake_id=?', [$intake['id']]);
    flash_set('OMR results cleared.', 'success');
    redirect('/phdportal/admin/omr.php');
}

$results = all('SELECT r.*, c.name, c.dept_reg_no
                FROM omr_results r
                JOIN candidates c ON c.id=r.candidate_id
                WHERE r.intake_id=?
                ORDER BY r.marks DESC, c.dept_reg_no', [$intake['id']]);
$totalCands = (int)(one('SELECT COUNT(*) n FROM candidates WHERE intake_id=? AND is_international=0', [$intake['id']])['n'] ?? 0);
$candsForBatch = all('SELECT id, dept_reg_no, name FROM candidates WHERE intake_id=? AND is_international=0 ORDER BY serial_no, id', [$intake['id']]);
$keyAnswers = $existing_key ? (json_decode((string)$existing_key['answer_json'], true) ?: []) : [];
$keyNum = $existing_key ? (int)$existing_key['num_questions'] : 60;
$partialKey = $_SESSION['omr_partial_key'] ?? null;
unset($_SESSION['omr_partial_key']);

render_header('OMR Upload', $u);
?>
<style>
  .num-pill { display:inline-flex; align-items:center; justify-content:center; min-width:1.6rem; height:1.6rem; padding:0 .35rem; border-radius:.35rem; background:#eef2ff; color:#3730a3; font-weight:600; font-size:.7rem; }
  .ans-cell { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

  /* Highlighting for low-confidence OMR rows that need a manual check. */
  .omr-review-row > td {
    background: #fef3c7;
    border-top: 1px solid #fcd34d;
    border-bottom: 1px solid #fcd34d;
  }
  .omr-review-row > td:first-child {
    border-left: 4px solid #d97706;
  }
  .omr-review-row:hover > td { background: #fde68a; }
  .omr-review-pill {
    display: inline-flex; align-items: center; gap: 3px;
    margin-right: .35rem;
    padding: 1px 6px;
    border-radius: 999px;
    background: #d97706; color: #fff;
    font-weight: 700; font-size: 9.5px; letter-spacing: .04em;
    vertical-align: middle;
    box-shadow: 0 0 0 2px #fef3c7;
  }
  .omr-review-pill svg { stroke: #fff; }

  /* Busy overlay for long-running operations. */
  #omr-busy {
    position: fixed; inset: 0;
    background: rgba(15, 23, 42, .55);
    display: none; align-items: center; justify-content: center;
    z-index: 9999;
  }
  #omr-busy.show { display: flex; }
  .omr-busy-card {
    background: #fff;
    padding: 1.6rem 2rem;
    border-radius: .5rem;
    min-width: 360px; max-width: 480px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, .3);
    text-align: center;
  }
  .omr-busy-msg { font-weight: 600; color: #1e293b; margin-bottom: .85rem; }
  .omr-busy-sub { font-size: .75rem; color: #64748b; margin-top: .55rem; }
  .omr-busy-bar {
    height: 8px; border-radius: 999px; overflow: hidden;
    background: #e2e8f0; position: relative;
  }
  .omr-busy-bar-fill {
    position: absolute; top: 0; left: -40%; height: 100%; width: 40%;
    background: linear-gradient(90deg,
      rgba(99, 102, 241, 0) 0%,
      rgba(79, 70, 229, 1) 50%,
      rgba(99, 102, 241, 0) 100%);
    animation: omr-busy-slide 1.4s ease-in-out infinite;
  }
  .omr-busy-bar-fill-det {
    position: absolute; top: 0; left: 0; height: 100%; width: 0%;
    background: #4f46e5;
    transition: width .2s ease;
  }
  #omr-busy[data-mode="indeterminate"] .omr-busy-bar-fill-det { display: none; }
  #omr-busy[data-mode="determinate"]   .omr-busy-bar-fill { display: none; }
  @keyframes omr-busy-slide {
    0%   { left: -40%; }
    100% { left: 100%; }
  }
</style>
<div id="omr-busy" data-mode="indeterminate" aria-live="polite">
  <div class="omr-busy-card">
    <div class="omr-busy-msg">Working…</div>
    <div class="omr-busy-bar">
      <div class="omr-busy-bar-fill"></div>
      <div class="omr-busy-bar-fill-det"></div>
    </div>
    <div class="omr-busy-sub"></div>
  </div>
</div>

<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-semibold">OMR Upload</h1>
    <p class="text-sm text-slate-500 mt-0.5">
      Active intake: <strong><?= h($intake['name']) ?></strong>
      &nbsp;·&nbsp; Answer key:
      <?php if ($existing_key): ?>
        <span class="text-emerald-700 font-semibold"><?= (int)$existing_key['num_questions'] ?> questions set</span>
        (uploaded <?= h(date('d M Y, H:i', strtotime($existing_key['uploaded_at']))) ?>)
      <?php else: ?>
        <span class="text-rose-600 font-semibold">not set</span>
      <?php endif; ?>
      &nbsp;·&nbsp; Processed: <strong><?= count($results) ?></strong> / <?= $totalCands ?>
    </p>
  </div>
</div>

<!-- Section A: Generate blank OMR -->
<div class="card mb-4">
  <h3 class="font-semibold mb-2">1. Download Blank OMR Sheet</h3>
  <p class="text-sm text-slate-600 mb-3">Generates a printable A4 OMR with SJMSOM logo, QR placeholder, signature boxes, instructions and the bubble grid.</p>
  <form class="flex items-center gap-3 flex-wrap" onsubmit="return downloadBlank(event)">
    <label class="text-sm">Total Questions:
      <input id="blankN" type="number" min="1" max="200" value="<?= (int)$keyNum ?>" class="border rounded px-2 py-1 w-24 ml-2">
    </label>
    <button type="submit" class="btn btn-primary text-sm">Download Blank OMR (PDF)</button>
    <button type="button" class="btn btn-secondary text-sm" onclick="downloadAllPerCand()">Download Per-Candidate OMRs (with QR)</button>
    <span class="text-xs text-slate-500"><?= count($candsForBatch) ?> candidate(s) in active intake</span>
  </form>
</div>

<!-- Section B: Set answer key -->
<div class="card mb-4">
  <h3 class="font-semibold mb-2">2. Set Answer Key</h3>
  <p class="text-sm text-slate-600 mb-3">
    Either type the correct answers below, or upload a filled OMR PDF and the scanner will extract them.
  </p>

  <div class="grid md:grid-cols-2 gap-4">
    <!-- Manual key entry -->
    <form method="post" class="border border-slate-200 rounded p-3">
      <div class="font-semibold text-sm mb-2">Manual entry</div>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="set_key_manual" value="1">
      <label class="text-sm block mb-2">Total Questions:
        <input name="num_questions" type="number" min="1" max="200" value="<?= (int)($partialKey['num'] ?? $keyNum) ?>" class="border rounded px-2 py-1 w-24 ml-2" required>
      </label>
      <label class="text-sm block mb-1">Answers (comma/space-separated <code>A,B,C,D…</code> in question order, or pairs <code>1:A 2:B …</code>):</label>
      <textarea name="answers" rows="6" class="w-full border rounded p-2 font-mono text-xs" required placeholder="A,B,C,D,A,B,C,D,…
or
1:A 2:B 3:C 4:D"><?php
        if ($partialKey) {
            $arr = [];
            foreach ($partialKey['answers'] as $q => $ch) {
                $arr[] = $q . ':' . ($ch ?: '?');
            }
            echo h(implode(' ', $arr));
        } elseif ($keyAnswers) {
            echo h(implode(',', array_values($keyAnswers)));
        }
      ?></textarea>
      <button class="btn btn-primary text-sm mt-2">Save Answer Key</button>
    </form>

    <!-- Upload key PDF -->
    <div class="border border-slate-200 rounded p-3">
      <div class="font-semibold text-sm mb-2">Upload filled answer-key OMR PDF</div>
      <p class="text-xs text-slate-500 mb-2">A single A4 page generated from this portal with the correct bubbles darkened.</p>
      <label class="text-sm block mb-2">Total Questions:
        <input id="keyN" type="number" min="1" max="200" value="<?= (int)$keyNum ?>" class="border rounded px-2 py-1 w-24 ml-2" required>
      </label>
      <input id="keyFile" type="file" accept=".pdf,application/pdf" class="block mb-2 text-sm">
      <button id="keyUploadBtn" type="button" class="btn btn-primary text-sm" disabled>Upload &amp; Extract</button>
      <div id="keyOverall" class="mt-3 hidden">
        <div class="flex items-center justify-between text-xs mb-1">
          <span id="keyOverallLabel" class="text-slate-600">Starting…</span>
          <span id="keyOverallPct" class="font-mono text-slate-500">0%</span>
        </div>
        <div class="h-2 rounded bg-slate-200 overflow-hidden">
          <div id="keyOverallBar" class="h-full bg-indigo-600 transition-all" style="width:0%"></div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($keyAnswers): ?>
    <details class="mt-3 text-xs text-slate-600">
      <summary class="cursor-pointer hover:underline">Show current answer key (<?= count($keyAnswers) ?>)</summary>
      <div class="mt-1 grid grid-cols-10 gap-1 font-mono">
        <?php foreach ($keyAnswers as $q => $ch): ?>
          <span class="px-1 py-0.5 bg-slate-100 rounded text-center"><?= (int)$q ?>:<?= h((string)$ch) ?></span>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>
</div>

<!-- Section C: Bulk Upload -->
<div class="card mb-4">
  <h3 class="font-semibold mb-2">3. Bulk Upload Scanned Candidate Sheets</h3>
  <p class="text-sm text-slate-600 mb-3">
    Upload a single PDF containing all scanned candidate OMR sheets (one per page).
    The system reads each page's QR code to identify the candidate and grades against the answer key.
  </p>
  <?php if (!$existing_key): ?>
    <div class="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 mb-3">Set the answer key first (section 2 above) before processing candidate sheets.</div>
  <?php endif; ?>
  <input id="bulkFile" type="file" accept=".pdf,application/pdf" class="block mb-2 text-sm" <?= $existing_key ? '' : 'disabled' ?>>
  <button id="bulkUploadBtn" type="button" class="btn btn-primary text-sm" disabled>Upload &amp; Process</button>
  <div id="bulkOverall" class="mt-3 hidden">
    <div class="flex items-center justify-between text-xs mb-1">
      <span id="bulkOverallLabel" class="text-slate-600">Starting…</span>
      <span id="bulkOverallPct" class="font-mono text-slate-500">0%</span>
    </div>
    <div class="h-2 rounded bg-slate-200 overflow-hidden">
      <div id="bulkOverallBar" class="h-full bg-indigo-600 transition-all" style="width:0%"></div>
    </div>
  </div>
</div>

<!-- Results -->
<div class="card mb-4">
  <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
    <h3 class="font-semibold">4. Results</h3>
    <div class="flex gap-2 flex-wrap">
      <?php if ($results): ?>
        <form method="post" class="inline"
              data-busy="Applying OMR marks to written_marks…"
              data-busy-sub="<?= count($results) ?> candidate(s)"
              onsubmit="return confirm('Apply OMR marks to candidates.written_marks for all '+<?= count($results) ?>+' processed candidates?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="apply_to_written" value="1">
          <button class="btn btn-primary text-xs">Apply Marks → Written Marks</button>
        </form>
        <form method="post" class="inline"
              data-busy="Clearing OMR results…"
              onsubmit="return confirm('Delete all OMR results for this intake?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="clear_results" value="1">
          <button class="btn btn-danger text-xs">Clear Results</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($results): ?>
  <form method="post" class="border border-slate-200 rounded p-3 mb-3 bg-slate-50"
        data-busy="Pushing OMR results to the Marks page…"
        data-busy-sub="<?= count($results) ?> candidate(s) — redirecting to marks.php"
        onsubmit="return confirm('Push OMR results to the Marks page for all <?= count($results) ?> processed candidate(s)? This overwrites their s1–s4 correct/wrong counts and written_marks.');">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="push_to_marks" value="1">
    <div class="flex items-center gap-2 flex-wrap text-sm">
      <span class="font-semibold mr-1">Push to Marks Page:</span>
      <?php $defaults = [[1,15],[16,30],[31,45],[46,60]]; ?>
      <?php for ($s = 1; $s <= 4; $s++): ?>
        <span class="inline-flex items-center gap-1 bg-white border border-slate-200 rounded px-2 py-1">
          <span class="text-xs font-semibold text-slate-700">Sec <?= $s ?></span>
          <input type="number" name="s<?= $s ?>_start" min="1" max="200"
                 value="<?= (int)$defaults[$s-1][0] ?>"
                 class="border rounded px-1 py-0.5 w-12 text-xs text-center" required>
          <span class="text-xs text-slate-400">–</span>
          <input type="number" name="s<?= $s ?>_end" min="1" max="200"
                 value="<?= (int)$defaults[$s-1][1] ?>"
                 class="border rounded px-1 py-0.5 w-12 text-xs text-center" required>
        </span>
      <?php endfor; ?>
      <button type="submit" class="btn btn-primary text-xs ml-1">Push to Marks</button>
    </div>
    <p class="text-xs text-slate-500 mt-2">
      Splits each candidate's OMR answers into the four sections by question number,
      writes section-wise correct/wrong counts, and copies OMR marks (+1 / -0.25) into <code>written_marks</code>.
    </p>
  </form>
  <?php endif; ?>
  <div class="overflow-x-auto">
  <table class="data-table w-full">
    <thead><tr>
      <th>Dept Reg No</th><th>Name</th>
      <th class="text-center">Correct</th>
      <th class="text-center">Wrong</th>
      <th class="text-center">Blank</th>
      <th class="text-center">Multi</th>
      <th class="text-center">Marks</th>
      <th class="text-center" title="Median margin between top and second bubble lift across questions; higher = more reliable">Confidence</th>
      <th>Scanned Sheet</th>
      <th>Processed</th>
    </tr></thead>
    <tbody>
    <?php foreach ($results as $r):
      $conf = (float)($r['confidence'] ?? 0);
      $needsReview = !empty($r['review_needed']);
      $rowCls = $needsReview ? 'omr-review-row' : '';
    ?>
      <tr class="<?= $rowCls ?>">
        <td class="font-mono text-xs">
          <?php if ($needsReview): ?>
            <span class="omr-review-pill" title="Low confidence — please open the scanned sheet and verify the answers manually.">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              REVIEW
            </span>
          <?php endif; ?>
          <?= h($r['dept_reg_no']) ?>
        </td>
        <td><?= h($r['name']) ?></td>
        <td class="text-center text-emerald-700 font-semibold"><?= (int)$r['correct_count'] ?></td>
        <td class="text-center text-rose-700"><?= (int)$r['wrong_count'] ?></td>
        <td class="text-center text-slate-500"><?= (int)$r['blank_count'] ?></td>
        <td class="text-center text-amber-700"><?= (int)$r['multi_count'] ?></td>
        <td class="text-center font-bold"><?= number_format((float)$r['marks'], 2) ?></td>
        <td class="text-center text-xs">
          <?php
            $confCls = $conf >= 50 ? 'text-emerald-700' : ($conf >= 30 ? 'text-amber-700' : 'text-rose-700');
          ?>
          <span class="<?= $confCls ?> font-mono"><?= number_format($conf, 1) ?></span>
        </td>
        <td>
          <?php if ($r['page_image'] && is_file($pages_dir . '/' . $r['page_image'])): ?>
            <a class="text-xs text-indigo-600 hover:underline" target="_blank"
               href="/phdportal/uploads/omr/pages/<?= h($r['page_image']) ?>">view</a>
          <?php else: ?>
            <span class="text-xs text-slate-400">—</span>
          <?php endif; ?>
        </td>
        <td class="text-xs text-slate-500"><?= h(date('d M H:i', strtotime($r['processed_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$results): ?>
      <tr><td colspan="10" class="text-center py-6 text-slate-500">No OMR results yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script src="/phdportal/assets/js/omr.js?v=<?= filemtime(PUBLIC_ROOT . '/assets/js/omr.js') ?>"></script>
<script>
const INTAKE_NAME = <?= json_encode($intake['name']) ?>;
const ALL_CANDS = <?= json_encode($candsForBatch) ?>;

// Animated busy overlay used by every long-running operation on this page.
window.OMRBusy = (function () {
  const el = document.getElementById('omr-busy');
  const msgEl = el.querySelector('.omr-busy-msg');
  const subEl = el.querySelector('.omr-busy-sub');
  const detEl = el.querySelector('.omr-busy-bar-fill-det');
  function show(msg, sub, mode) {
    msgEl.textContent = msg || 'Working…';
    subEl.textContent = sub || '';
    el.dataset.mode = (mode === 'determinate') ? 'determinate' : 'indeterminate';
    if (mode === 'determinate') detEl.style.width = '0%';
    el.classList.add('show');
  }
  function update(pct, msg, sub) {
    if (msg !== undefined && msg !== null) msgEl.textContent = msg;
    if (sub !== undefined && sub !== null) subEl.textContent = sub;
    if (pct !== undefined && pct !== null) {
      detEl.style.width = Math.max(0, Math.min(100, pct)) + '%';
    }
  }
  function hide() { el.classList.remove('show'); }
  return { show, update, hide };
})();

async function downloadBlank(e) {
  e.preventDefault();
  const n = parseInt(document.getElementById('blankN').value, 10) || 60;
  OMRBusy.show('Generating blank OMR…', '', 'indeterminate');
  try { await OMR.downloadBlank(n, INTAKE_NAME); }
  finally { OMRBusy.hide(); }
  return false;
}
async function downloadAllPerCand() {
  const n = parseInt(document.getElementById('blankN').value, 10) || 60;
  if (!ALL_CANDS.length) { alert('No candidates in active intake.'); return; }
  if (ALL_CANDS.length > 200 && !confirm('Generate ' + ALL_CANDS.length + ' OMR pages in one PDF? This may take a while.')) return;
  OMRBusy.show('Generating per-candidate OMR sheets…',
               '0 of ' + ALL_CANDS.length, 'determinate');
  try {
    await OMR.downloadAll(n, INTAKE_NAME, ALL_CANDS, (i, total) => {
      OMRBusy.update(Math.round(i / total * 100),
                     'Rendering page ' + i + ' of ' + total + '…',
                     ALL_CANDS[i - 1] ? ALL_CANDS[i - 1].dept_reg_no : '');
    });
  } finally { OMRBusy.hide(); }
}

// Hook any form with a `data-busy` attribute so the user sees an animated bar
// while the redirect round-trip is in flight. Inline `confirm()` handlers run
// before delegated listeners, so we can short-circuit on defaultPrevented.
document.addEventListener('submit', function (ev) {
  if (ev.defaultPrevented) return;
  const f = ev.target;
  if (!(f instanceof HTMLFormElement) || !f.dataset.busy) return;
  OMRBusy.show(f.dataset.busy, f.dataset.busySub || '', 'indeterminate');
});

(function(){
  const CHUNK = 1024 * 1024 * 1.5;
  async function postJson(url, form) {
    const r = await fetch(url, { method:'POST', body: form, credentials:'same-origin' });
    let data;
    try { data = await r.json(); } catch { throw new Error('Server returned non-JSON (HTTP ' + r.status + ')'); }
    return data;
  }

  function bindUploader(opts) {
    const { fileId, btnId, ovId, ovBarId, ovPctId, ovLabelId, kind, postProcessUrl, postProcessExtras } = opts;
    const fileEl = document.getElementById(fileId);
    const btn    = document.getElementById(btnId);
    const ov     = document.getElementById(ovId);
    const bar    = document.getElementById(ovBarId);
    const pct    = document.getElementById(ovPctId);
    const lab    = document.getElementById(ovLabelId);

    fileEl.addEventListener('change', () => { btn.disabled = !fileEl.files.length; });

    btn.addEventListener('click', async () => {
      const f = fileEl.files[0];
      if (!f) return;
      btn.disabled = true;
      ov.classList.remove('hidden');
      lab.textContent = 'Initializing…';
      try {
        const initForm = new FormData();
        initForm.append('csrf', window.CSRF_TOKEN);
        initForm.append('action', 'init');
        initForm.append('name', f.name);
        initForm.append('size', f.size);
        initForm.append('kind', kind);
        const initRes = await postJson('/phdportal/api/upload_omr_chunk.php', initForm);
        if (!initRes.ok) throw new Error(initRes.error || 'init failed');
        const uid = initRes.upload_id;

        const total = Math.max(1, Math.ceil(f.size / CHUNK));
        for (let i = 0; i < total; i++) {
          const slice = f.slice(i * CHUNK, Math.min(f.size, (i + 1) * CHUNK));
          const cf = new FormData();
          cf.append('csrf', window.CSRF_TOKEN);
          cf.append('action', 'chunk');
          cf.append('upload_id', uid);
          cf.append('index', i);
          cf.append('data', slice, 'chunk.bin');
          const cr = await postJson('/phdportal/api/upload_omr_chunk.php', cf);
          if (!cr.ok) throw new Error(cr.error || 'chunk failed');
          const p = Math.round(((i + 1) / total) * 90);
          bar.style.width = p + '%'; pct.textContent = p + '%';
          lab.textContent = 'Uploading… ' + (i + 1) + ' / ' + total;
        }

        lab.textContent = 'Finalizing upload…';
        const fin = new FormData();
        fin.append('csrf', window.CSRF_TOKEN);
        fin.append('action', 'finalize');
        fin.append('upload_id', uid);
        fin.append('total_chunks', total);
        const fr = await postJson('/phdportal/api/upload_omr_chunk.php', fin);
        if (!fr.ok) throw new Error(fr.error || 'finalize failed');

        bar.style.width = '95%'; pct.textContent = '95%';
        lab.textContent = 'Upload complete — handing off to scanner…';

        const scanMsg = (kind === 'answer_key')
          ? 'Extracting answer key from PDF…'
          : 'Scanning candidate OMR sheets — this may take 30–120 seconds…';
        OMRBusy.show(scanMsg, "Don't close this tab.", 'indeterminate');

        // Submit the form to process
        const pf = document.createElement('form');
        pf.method = 'POST';
        pf.style.display = 'none';
        pf.action = '/phdportal/admin/omr.php';
        const addInput = (name, value) => {
          const inp = document.createElement('input');
          inp.name = name; inp.value = value;
          pf.appendChild(inp);
        };
        addInput('csrf', window.CSRF_TOKEN);
        addInput('filename', fr.filename);
        for (const [k, v] of Object.entries(postProcessExtras || {})) {
          addInput(k, typeof v === 'function' ? v() : v);
        }
        addInput(postProcessUrl.flag, '1');
        document.body.appendChild(pf);
        pf.submit();
      } catch (e) {
        lab.textContent = 'Failed: ' + (e.message || 'unknown');
        lab.className = 'text-rose-700 font-semibold';
        btn.disabled = false;
      }
    });
  }

  bindUploader({
    fileId: 'keyFile', btnId: 'keyUploadBtn',
    ovId: 'keyOverall', ovBarId: 'keyOverallBar', ovPctId: 'keyOverallPct', ovLabelId: 'keyOverallLabel',
    kind: 'answer_key',
    postProcessUrl: { flag: 'process_answer_key_pdf' },
    postProcessExtras: { num_questions: () => document.getElementById('keyN').value },
  });

  bindUploader({
    fileId: 'bulkFile', btnId: 'bulkUploadBtn',
    ovId: 'bulkOverall', ovBarId: 'bulkOverallBar', ovPctId: 'bulkOverallPct', ovLabelId: 'bulkOverallLabel',
    kind: 'bulk',
    postProcessUrl: { flag: 'process_bulk' },
    postProcessExtras: {},
  });
})();
</script>
<?php render_footer(); ?>
