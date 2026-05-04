<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();

// Bulk shortlist all (honors current filters)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shortlist_all_yes'])) {
    check_csrf();
    if ($intake && !(bool)setting('shortlist_frozen_' . $intake['id'])) {
        $bw = ['intake_id = ?', 'is_international = 0'];
        $bp = [$intake['id']];
        $bq = trim($_POST['q'] ?? '');
        $bs = $_POST['shortlist'] ?? '';
        $bc = $_POST['cat'] ?? '';
        if ($bq !== '') {
            $bw[] = '(dept_reg_no LIKE ? OR name LIKE ? OR email LIKE ? OR applicant_id LIKE ?)';
            $like = "%$bq%"; array_push($bp, $like, $like, $like, $like);
        }
        if ($bs !== '') { $bw[] = 'screening_status = ?'; $bp[] = $bs; }
        if ($bc !== '') { $bw[] = 'birth_category = ?'; $bp[] = $bc; }
        $sql = 'UPDATE candidates SET screening_status=? WHERE ' . implode(' AND ', $bw);
        $n = q($sql, array_merge(['Yes'], $bp))->rowCount();
        flash_set("Marked $n candidate(s) as Shortlisted (Yes).", 'success');
    } elseif ($intake) {
        flash_set('Shortlist is frozen.', 'error');
    }
    $qs = http_build_query(array_filter([
        'q' => $_POST['q'] ?? '',
        'shortlist' => $_POST['shortlist'] ?? '',
        'cat' => $_POST['cat'] ?? '',
    ], fn($v) => $v !== ''));
    redirect('/phdportal/admin/candidates.php' . ($qs ? "?$qs" : ''));
}

// Freeze shortlist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['freeze_shortlist'])) {
    check_csrf();
    if ($intake) {
        $undecided = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status IN ('Pending','Doubtful')", [$intake['id']])['c'];
        if ($undecided > 0) {
            flash_set("Cannot freeze: $undecided candidate(s) still have Pending/Doubtful status.", 'error');
        } else {
            set_setting('shortlist_frozen_' . $intake['id'], '1');
            flash_set('Shortlist frozen. No further changes allowed.', 'success');
        }
    }
    redirect('/phdportal/admin/candidates.php');
}

// Unfreeze shortlist (requires admin passcode)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unfreeze_shortlist'])) {
    check_csrf();
    $pass = $_POST['passcode'] ?? '';
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($pass, $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
    } elseif ($intake) {
        q('DELETE FROM settings WHERE `key`=?', ['shortlist_frozen_' . $intake['id']]);
        flash_set('Shortlist unfrozen. Edits re-enabled.', 'success');
    }
    redirect('/phdportal/admin/candidates.php');
}

// Preflight check used before releasing Rejected exports.
// Returns null if OK, else an error message string.
function rejected_export_block_reason(int $intake_id): ?string {
    $und = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status IN ('Pending','Doubtful')", [$intake_id])['c'];
    if ($und > 0) {
        return "Cannot export Rejected list: $und candidate(s) still have Pending/Doubtful shortlist status. Resolve all shortlist decisions first.";
    }
    $missing = all("SELECT dept_reg_no FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status='No' AND (remark IS NULL OR TRIM(remark)='') ORDER BY serial_no, id", [$intake_id]);
    if ($missing) {
        $names = array_slice(array_column($missing, 'dept_reg_no'), 0, 10);
        $extra = count($missing) - count($names);
        return 'Cannot export Rejected list: ' . count($missing) . ' rejected candidate(s) have no remark — '
             . implode(', ', $names) . ($extra ? " … and $extra more" : '')
             . '. Add a remark on each rejected candidate before exporting.';
    }
    return null;
}

// CSV export
if (isset($_GET['export']) && $intake) {
    $status = $_GET['export'];
    if (in_array($status, ['Yes','No'])) {
        if ($status === 'No') {
            if ($reason = rejected_export_block_reason((int)$intake['id'])) {
                flash_set($reason, 'error');
                redirect('/phdportal/admin/candidates.php');
            }
        }
        $rows = all("SELECT serial_no, dept_reg_no, applicant_id, name, gender, birth_category, ews, disabled,
                     categories_applied, research_interest_selected, written_marks, screening_status
                     FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status=? ORDER BY serial_no, id",
                    [$intake['id'], $status]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="shortlist_' . strtolower($status) . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Sr No','Dept Reg No','Applicant ID','Name','Gender','Category','EWS','PWD',
                       'Applied','Research Interest','Written Marks','Shortlist']);
        foreach ($rows as $r) fputcsv($out, array_values($r));
        fclose($out);
        exit;
    }
}

$frozen = $intake ? (bool)setting('shortlist_frozen_' . $intake['id']) : false;
$undecided = $intake ? (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status IN ('Pending','Doubtful')", [$intake['id']])['c'] : 0;

$q = trim($_GET['q'] ?? '');
$shortlist = $_GET['shortlist'] ?? '';
$cat = $_GET['cat'] ?? '';
$passedCutoff = $_GET['passed_cutoff'] ?? '';

$where = ['intake_id = ?', 'is_international = 0'];
$params = [$intake['id'] ?? 0];
if ($q !== '') {
    $where[] = '(dept_reg_no LIKE ? OR name LIKE ? OR email LIKE ? OR applicant_id LIKE ?)';
    $like = "%$q%"; array_push($params, $like, $like, $like, $like);
}
if ($shortlist !== '') { $where[] = 'screening_status = ?'; $params[] = $shortlist; }
if ($cat !== '') { $where[] = 'birth_category = ?'; $params[] = $cat; }
if ($passedCutoff === '1') { $where[] = 'passed_cutoff = 1'; }
$sql = 'SELECT * FROM candidates WHERE ' . implode(' AND ', $where) . ' ORDER BY serial_no, id LIMIT 500';
$rows = $intake ? all($sql, $params) : [];

render_header('Candidates', $u);
?>
<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-semibold">Candidates — <?= $intake ? h($intake['name']) : 'No active intake' ?></h1>
    <p class="text-sm text-slate-500 mt-0.5">
      <?= count($rows) ?> shown
      <?php if ($frozen): ?>
        &nbsp;<span class="inline-flex items-center gap-1 text-rose-700 font-semibold">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Shortlist Frozen
        </span>
      <?php endif; ?>
    </p>
  </div>
  <?php if ($intake): ?>
  <div class="flex gap-2 flex-wrap">
    <a href="?export=Yes" class="btn btn-secondary text-xs">Shortlisted CSV</a>
    <a href="?export=No" class="btn btn-secondary text-xs">Rejected CSV</a>
    <button class="btn btn-secondary text-xs" onclick="downloadShortlistPdf('Yes','Shortlisted')">Shortlisted PDF</button>
    <button class="btn btn-secondary text-xs" onclick="downloadShortlistPdf('No','Rejected')">Rejected PDF</button>
    <?php if (!$frozen): ?>
      <form method="post" class="inline" onsubmit="return confirm('Set Shortlist = Yes for all <?= count($rows) ?> candidate(s) matching the current filter?');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="q" value="<?= h($q) ?>">
        <input type="hidden" name="shortlist" value="<?= h($shortlist) ?>">
        <input type="hidden" name="cat" value="<?= h($cat) ?>">
        <button name="shortlist_all_yes" class="btn btn-primary text-xs">Shortlist All as Yes</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<form class="card mb-4 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
  <div><label class="text-xs font-medium">Search (reg no / name / email)</label><input name="q" value="<?= h($q) ?>"></div>
  <div><label class="text-xs font-medium">Shortlist</label>
    <select name="shortlist">
      <option value="">All</option>
      <?php foreach (['Yes','No','Pending','Doubtful'] as $s): ?>
        <option<?= $s===$shortlist?' selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label class="text-xs font-medium">Category</label>
    <select name="cat">
      <option value="">All</option>
      <?php foreach (BIRTH_CATEGORIES as $c): ?>
        <option<?= $c===$cat?' selected':'' ?>><?= $c ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><button class="btn btn-primary">Filter</button></div>
  <div class="text-right"><a href="/phdportal/admin/candidates.php" class="btn btn-secondary">Reset</a></div>
</form>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr>
  <th>Sr</th><th>Dept Reg No</th><th>Name</th><th>Gender</th><th>Cat</th><th>EWS</th><th>PWD</th>
  <th>Applied</th><th>Research Interest</th><th>Shortlist</th><th></th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr data-id="<?= (int)$r['id'] ?>">
  <td><?= (int)$r['serial_no'] ?></td>
  <td class="font-mono text-xs"><a class="text-indigo-700 hover:underline" href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>"><?= h($r['dept_reg_no']) ?></a></td>
  <td><?= h($r['name']) ?></td>
  <td><?= h($r['gender']) ?></td>
  <td><?= category_badge($r['birth_category'] ?? '') ?></td>
  <td><?= h($r['ews']) ?></td>
  <td><?= h($r['disabled']) ?></td>
  <td class="text-xs"><?= h(normalize_categories_applied($r['categories_applied'])) ?></td>
  <td class="text-xs max-w-sm truncate" title="<?= h($r['research_interest_selected']) ?>"><?= h($r['research_interest_selected']) ?></td>
  <td><?= status_badge($r['screening_status']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="11" class="text-center py-6 text-slate-500">No candidates found.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php if ($intake && !$frozen): ?>
<div class="mt-5 flex items-center justify-end gap-3">
  <?php if ($undecided > 0): ?>
    <p class="text-sm text-amber-700">
      <svg class="inline" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= $undecided ?> candidate(s) still Pending/Doubtful — resolve before freezing
    </p>
    <button class="btn btn-danger opacity-40 cursor-not-allowed" disabled>Freeze Shortlist</button>
  <?php else: ?>
    <form method="post" onsubmit="return confirm('Freeze shortlist? All shortlist decisions will be locked for this intake.');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button name="freeze_shortlist" class="btn btn-danger">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Freeze Shortlist
      </button>
    </form>
  <?php endif; ?>
</div>
<?php elseif ($frozen): ?>
<div class="mt-5 flex items-center justify-end gap-3">
  <p class="text-sm text-slate-500">Shortlist is frozen.</p>
  <button type="button" class="btn btn-secondary" onclick="openUnfreeze()">Unfreeze</button>
</div>

<div id="unfreezeBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Unfreeze Shortlist</h3>
    <p class="text-sm text-slate-700 mb-3">Re-open the shortlist for this intake so dropdowns become editable again.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="unfreeze_shortlist" value="1">
      <label class="text-xs font-medium">Enter your admin password to confirm:</label>
      <input type="password" name="passcode" required autocomplete="new-password" class="mt-1" placeholder="Your login password">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn btn-secondary" onclick="closeUnfreeze()">Cancel</button>
        <button class="btn btn-danger">Unfreeze</button>
      </div>
    </form>
  </div>
</div>

<script>
function openUnfreeze() { $('#unfreezeBackdrop').removeClass('hidden'); }
function closeUnfreeze() { $('#unfreezeBackdrop').addClass('hidden'); }
$('#unfreezeBackdrop').on('click', function(e){ if (e.target === this) closeUnfreeze(); });
$(document).on('keydown', e => { if (e.key === 'Escape') closeUnfreeze(); });
</script>
<?php endif; ?>

<script>
function downloadShortlistPdf(status, label) {
  $.ajax({ url: '/phdportal/api/shortlist_export.php', data: { status }, dataType: 'json' })
    .done(function(resp) {
      if (resp && !Array.isArray(resp) && resp.error) {
        alert(resp.error);
        return;
      }
      const rows = Array.isArray(resp) ? resp : [];
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF('landscape');
      doc.setFontSize(14);
      doc.text('SJMSOM IIT Bombay — PhD Admissions', 14, 15);
      doc.setFontSize(11);
      doc.text(label + ' Candidates — ' + <?= json_encode($intake['name'] ?? '') ?>, 14, 22);
      doc.setFontSize(9);
      doc.text('Generated: ' + new Date().toLocaleString() + ' · Count: ' + rows.length, 14, 28);
      const isRejected = status === 'No';
      const lastCol = isRejected ? 'Remarks' : 'Written';
      const body = rows.map((r,i) => [i+1, r.dept_reg_no, r.name, r.birth_category||'', r.gender||'',
        r.categories_applied||'', isRejected ? (r.remark||'') : (r.written_marks||'—')]);
      doc.autoTable({
        startY: 33,
        head: [['#','Dept Reg No','Name','Category','Gender','Applied', lastCol]],
        body,
        styles: { fontSize: 8, cellPadding: 2 },
        headStyles: { fillColor: [79,70,229] },
        columnStyles: isRejected ? { 6: { cellWidth: 90 } } : {}
      });
      doc.save(label + '_Candidates_' + new Date().toISOString().slice(0,10) + '.pdf');
    })
    .fail(function(xhr) {
      let msg = 'Failed to fetch data';
      try {
        const j = JSON.parse(xhr.responseText);
        if (j && j.error) msg = j.error;
      } catch (e) {}
      alert(msg);
    });
}
</script>
<?php render_footer(); ?>
