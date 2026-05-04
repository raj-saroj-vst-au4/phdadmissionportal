<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        $season = $_POST['season'];
        $year = (int)$_POST['year'];
        $name = $season . ' ' . $year;
        $mode = $_POST['entrance_mode'] ?? '';
        if (!in_array($mode, ['Written','CBT'], true)) $mode = null;
        $ed = trim($_POST['entrance_datetime'] ?? '');
        $id = trim($_POST['interview_datetime'] ?? '');
        // HTML datetime-local comes as "YYYY-MM-DDTHH:MM"; normalise to MySQL DATETIME.
        $entrance_dt = $ed !== '' ? str_replace('T', ' ', $ed) . (strlen($ed) === 16 ? ':00' : '') : null;
        $interview_dt = $id !== '' ? str_replace('T', ' ', $id) . (strlen($id) === 16 ? ':00' : '') : null;
        $intToNull = fn($v) => trim((string)$v) === '' ? null : max(0, (int)$v);
        $taGn  = $intToNull($_POST['ta_seats_gn']  ?? '');
        $taObc = $intToNull($_POST['ta_seats_obc'] ?? '');
        $taSc  = $intToNull($_POST['ta_seats_sc']  ?? '');
        $taSt  = $intToNull($_POST['ta_seats_st']  ?? '');
        $taEws = $intToNull($_POST['ta_seats_ews'] ?? '');
        try {
            q('INSERT INTO intakes(name,season,year,entrance_mode,entrance_datetime,interview_datetime,
                                   ta_seats_gn,ta_seats_obc,ta_seats_sc,ta_seats_st,ta_seats_ews)
               VALUES(?,?,?,?,?,?,?,?,?,?,?)',
              [$name,$season,$year,$mode,$entrance_dt,$interview_dt,$taGn,$taObc,$taSc,$taSt,$taEws]);
            flash_set("Intake $name created", 'success');
        } catch (Throwable $e) {
            flash_set('Failed: ' . $e->getMessage(), 'error');
        }
    } elseif ($act === 'update_schedule') {
        $id = (int)$_POST['id'];
        $mode = $_POST['entrance_mode'] ?? '';
        if (!in_array($mode, ['Written','CBT',''], true)) $mode = '';
        $mode = $mode === '' ? null : $mode;
        $ed = trim($_POST['entrance_datetime'] ?? '');
        $iv = trim($_POST['interview_datetime'] ?? '');
        $entrance_dt = $ed !== '' ? str_replace('T', ' ', $ed) . (strlen($ed) === 16 ? ':00' : '') : null;
        $interview_dt = $iv !== '' ? str_replace('T', ' ', $iv) . (strlen($iv) === 16 ? ':00' : '') : null;
        $intToNull = fn($v) => trim((string)$v) === '' ? null : max(0, (int)$v);
        $taGn  = $intToNull($_POST['ta_seats_gn']  ?? '');
        $taObc = $intToNull($_POST['ta_seats_obc'] ?? '');
        $taSc  = $intToNull($_POST['ta_seats_sc']  ?? '');
        $taSt  = $intToNull($_POST['ta_seats_st']  ?? '');
        $taEws = $intToNull($_POST['ta_seats_ews'] ?? '');
        q('UPDATE intakes SET entrance_mode=?, entrance_datetime=?, interview_datetime=?,
                              ta_seats_gn=?, ta_seats_obc=?, ta_seats_sc=?, ta_seats_st=?, ta_seats_ews=?
           WHERE id=?',
          [$mode, $entrance_dt, $interview_dt, $taGn, $taObc, $taSc, $taSt, $taEws, $id]);
        flash_set('Intake schedule updated.', 'success');
    } elseif ($act === 'activate') {
        $id = (int)$_POST['id'];
        $passcode = $_POST['passcode'] ?? '';
        $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
        if (!$fresh || !password_verify($passcode, $fresh['password_hash'])) {
            flash_set('Admin passcode is incorrect.', 'error');
        } else {
            q('UPDATE intakes SET is_active = 0');
            q('UPDATE intakes SET is_active = 1 WHERE id = ?', [$id]);
            flash_set('Intake activated', 'success');
        }
    } elseif ($act === 'clear_candidates') {
        $id = (int)$_POST['id'];
        $confirm = $_POST['confirm'] ?? '';
        $passcode = $_POST['passcode'] ?? '';
        $intake = one('SELECT * FROM intakes WHERE id=?', [$id]);
        $fresh  = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
        if (!$intake) {
            flash_set('Intake not found.', 'error');
        } elseif ($confirm !== $intake['name']) {
            flash_set('Confirmation text did not match intake name.', 'error');
        } elseif (!$fresh || !password_verify($passcode, $fresh['password_hash'])) {
            flash_set('Admin passcode is incorrect.', 'error');
        } else {
            // Delete uploaded application PDFs for this intake
            $pdfs = all('SELECT application_pdf FROM candidates WHERE intake_id=? AND application_pdf IS NOT NULL', [$id]);
            foreach ($pdfs as $p) {
                $path = UPLOAD_APP_DIR . '/' . $p['application_pdf'];
                if (is_file($path)) @unlink($path);
            }
            // CASCADE removes interview_marks & room_assignments via FK
            q('DELETE FROM candidates WHERE intake_id=?', [$id]);
            q('DELETE FROM rooms WHERE intake_id=?', [$id]);
            q('DELETE FROM upload_logs WHERE intake_id=?', [$id]);
            q('DELETE FROM email_log WHERE intake_id=?', [$id]);
            // Clear intake-scoped settings
            foreach (['shortlist_frozen_','final_frozen_','cutoff_frozen_','interview_frozen_',
                     'intl_shortlist_frozen_','intl_final_frozen_'] as $pre) {
                q('DELETE FROM settings WHERE `key`=?', [$pre . $id]);
            }
            flash_set("Cleared all candidate data for '{$intake['name']}'.", 'success');
        }
    } elseif ($act === 'delete_intake') {
        $id = (int)$_POST['id'];
        $confirm = $_POST['confirm'] ?? '';
        $passcode = $_POST['passcode'] ?? '';
        $intake = one('SELECT * FROM intakes WHERE id=?', [$id]);
        $fresh  = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
        if (!$intake) {
            flash_set('Intake not found.', 'error');
        } elseif ($intake['is_active']) {
            flash_set('Cannot delete the active intake. Activate another first.', 'error');
        } elseif ($confirm !== $intake['name']) {
            flash_set('Confirmation text did not match intake name.', 'error');
        } elseif (!$fresh || !password_verify($passcode, $fresh['password_hash'])) {
            flash_set('Admin passcode is incorrect.', 'error');
        } else {
            $pdfs = all('SELECT application_pdf FROM candidates WHERE intake_id=? AND application_pdf IS NOT NULL', [$id]);
            foreach ($pdfs as $p) {
                $path = UPLOAD_APP_DIR . '/' . $p['application_pdf'];
                if (is_file($path)) @unlink($path);
            }
            q('DELETE FROM upload_logs WHERE intake_id=?', [$id]);
            q('DELETE FROM email_log WHERE intake_id=?', [$id]);
            foreach (['shortlist_frozen_','final_frozen_','cutoff_frozen_','interview_frozen_',
                     'intl_shortlist_frozen_','intl_final_frozen_'] as $pre) {
                q('DELETE FROM settings WHERE `key`=?', [$pre . $id]);
            }
            // CASCADE removes candidates, rooms, and their dependents
            q('DELETE FROM intakes WHERE id=?', [$id]);
            flash_set("Intake '{$intake['name']}' deleted.", 'success');
        }
    }
    redirect('/phdportal/admin/intakes.php');
}

$intakes = all('SELECT i.*, (SELECT COUNT(*) FROM candidates c WHERE c.intake_id = i.id AND c.is_international = 0) candidate_count FROM intakes i ORDER BY year DESC, season');

function fmt_dt_input(?string $dt): string {
    if (!$dt) return '';
    $t = strtotime($dt);
    return $t ? date('Y-m-d\TH:i', $t) : '';
}
function fmt_dt_display(?string $dt): string {
    if (!$dt) return '';
    $t = strtotime($dt);
    return $t ? date('d M Y · H:i', $t) : '';
}

render_header('Intakes', $u);
?>
<h1 class="text-2xl font-semibold mb-5">Admission Intakes</h1>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
  <div class="card">
    <h3 class="font-semibold mb-3">Create New Intake</h3>
    <form method="post" class="space-y-3">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div>
        <label class="text-sm font-medium">Season</label>
        <select name="season"><option>Spring</option><option>Autumn</option></select>
      </div>
      <div>
        <label class="text-sm font-medium">Year</label>
        <input type="number" name="year" value="<?= (int)date('Y') ?>" min="2020" max="2100" required>
      </div>
      <div>
        <label class="text-sm font-medium">Entrance exam mode</label>
        <select name="entrance_mode">
          <option value="">— not set —</option>
          <option value="Written">Written</option>
          <option value="CBT">CBT</option>
        </select>
      </div>
      <div>
        <label class="text-sm font-medium">Entrance (Written / CBT) date &amp; time</label>
        <input type="datetime-local" name="entrance_datetime">
      </div>
      <div>
        <label class="text-sm font-medium">Interview date &amp; time</label>
        <input type="datetime-local" name="interview_datetime">
      </div>
      <div>
        <label class="text-sm font-medium block">Approved TA Seats (birth-category-wise) for this intake</label>
        <p class="text-xs text-slate-500 mb-2">PWD candidates draw from their own birth-category bucket.</p>
        <div class="grid grid-cols-5 gap-2">
          <div><label class="text-xs text-slate-500">GN</label><input type="number" min="0" name="ta_seats_gn" placeholder="0"></div>
          <div><label class="text-xs text-slate-500">OBC-NC</label><input type="number" min="0" name="ta_seats_obc" placeholder="0"></div>
          <div><label class="text-xs text-slate-500">SC</label><input type="number" min="0" name="ta_seats_sc" placeholder="0"></div>
          <div><label class="text-xs text-slate-500">ST</label><input type="number" min="0" name="ta_seats_st" placeholder="0"></div>
          <div><label class="text-xs text-slate-500">EWS</label><input type="number" min="0" name="ta_seats_ews" placeholder="0"></div>
        </div>
      </div>
      <button class="btn btn-primary">Create</button>
    </form>
    <p class="text-xs text-slate-500 mt-2">Admissions happen twice: Spring (Jan–Jun) and Autumn (Jul–Dec). Schedule fields and TA seat counts can be edited later.</p>
  </div>
  <div class="md:col-span-2 card">
    <h3 class="font-semibold mb-3">Existing Intakes</h3>
    <table class="data-table w-full">
      <thead><tr><th>Name</th><th>Mode</th><th>Entrance</th><th>Interview</th><th>Candidates</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($intakes as $i): ?>
        <tr>
          <td><?= h($i['name']) ?></td>
          <td class="text-xs">
            <?php if ($i['entrance_mode']): ?>
              <span class="inline-block px-2 py-0.5 rounded bg-indigo-100 text-indigo-800 font-semibold"><?= h($i['entrance_mode']) ?></span>
            <?php else: ?>
              <span class="text-slate-400">—</span>
            <?php endif; ?>
          </td>
          <td class="text-xs"><?= h(fmt_dt_display($i['entrance_datetime'])) ?: '<span class="text-slate-400">—</span>' ?></td>
          <td class="text-xs"><?= h(fmt_dt_display($i['interview_datetime'])) ?: '<span class="text-slate-400">—</span>' ?></td>
          <td><?= (int)$i['candidate_count'] ?></td>
          <td>
            <?php if ($i['is_active']): ?><span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-800">Active</span><?php else: ?><span class="inline-block px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-600">Inactive</span><?php endif; ?>
          </td>
          <td class="whitespace-nowrap">
            <button type="button" class="btn btn-secondary text-xs"
              onclick='openSchedule(<?= (int)$i['id'] ?>, <?= json_encode($i['name']) ?>, <?= json_encode($i['entrance_mode']) ?>, <?= json_encode(fmt_dt_input($i['entrance_datetime'])) ?>, <?= json_encode(fmt_dt_input($i['interview_datetime'])) ?>, <?= json_encode([
                'gn'  => $i['ta_seats_gn'],
                'obc' => $i['ta_seats_obc'],
                'sc'  => $i['ta_seats_sc'],
                'st'  => $i['ta_seats_st'],
                'ews' => $i['ta_seats_ews'],
              ]) ?>)'>Edit Schedule</button>
            <?php if (!$i['is_active']): ?>
            <button type="button" class="btn btn-secondary text-xs"
              onclick='openActivate(<?= (int)$i['id'] ?>, <?= json_encode($i['name']) ?>)'>Activate</button>
            <?php endif; ?>
            <?php if ($i['candidate_count'] > 0): ?>
            <button type="button" class="btn btn-secondary text-xs"
              onclick='openConfirm("clear_candidates", <?= (int)$i['id'] ?>, <?= json_encode($i['name']) ?>, <?= (int)$i['candidate_count'] ?>)'>Clear Data</button>
            <?php endif; ?>
            <?php if (!$i['is_active']): ?>
            <button type="button" class="btn btn-danger text-xs"
              onclick='openConfirm("delete_intake", <?= (int)$i['id'] ?>, <?= json_encode($i['name']) ?>, <?= (int)$i['candidate_count'] ?>)'>Delete</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$intakes): ?><tr><td colspan="7" class="text-center py-4 text-slate-500">No intakes yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Edit Schedule modal -->
<div id="schedBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold mb-3">Edit Schedule — <span id="sName" class="font-mono"></span></h3>
    <form method="post" class="space-y-3">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_schedule">
      <input type="hidden" name="id" id="sId">
      <div>
        <label class="text-sm font-medium">Entrance exam mode</label>
        <select name="entrance_mode" id="sMode">
          <option value="">— not set —</option>
          <option value="Written">Written</option>
          <option value="CBT">CBT</option>
        </select>
      </div>
      <div>
        <label class="text-sm font-medium">Entrance date &amp; time</label>
        <input type="datetime-local" name="entrance_datetime" id="sEntrance">
      </div>
      <div>
        <label class="text-sm font-medium">Interview date &amp; time</label>
        <input type="datetime-local" name="interview_datetime" id="sInterview">
      </div>
      <div>
        <label class="text-sm font-medium block">Approved TA Seats (birth-category-wise)</label>
        <p class="text-xs text-slate-500 mb-2">PWD candidates draw from their own birth-category bucket.</p>
        <div class="grid grid-cols-5 gap-2">
          <div><label class="text-xs text-slate-500">GN</label><input type="number" min="0" name="ta_seats_gn" id="sTaGn" placeholder="0"></div>
          <div><label class="text-xs text-slate-500">OBC-NC</label><input type="number" min="0" name="ta_seats_obc" id="sTaObc" placeholder="0"></div>
          <div><label class="text-xs text-slate-500">SC</label><input type="number" min="0" name="ta_seats_sc" id="sTaSc" placeholder="0"></div>
          <div><label class="text-xs text-slate-500">ST</label><input type="number" min="0" name="ta_seats_st" id="sTaSt" placeholder="0"></div>
          <div><label class="text-xs text-slate-500">EWS</label><input type="number" min="0" name="ta_seats_ews" id="sTaEws" placeholder="0"></div>
        </div>
      </div>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="btn btn-secondary" onclick="closeSchedule()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Activate modal -->
<div id="activateBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Activate Intake</h3>
    <p class="text-sm text-slate-700 mb-3">Switching the active intake affects every admin, panel and candidate view. Re-enter your admin password to activate <code id="aName" class="bg-slate-100 px-1 rounded font-mono"></code>.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="activate">
      <input type="hidden" name="id" id="aId">
      <label class="text-xs font-medium">Admin password:</label>
      <input type="password" name="passcode" required autocomplete="new-password" class="mt-1">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn btn-secondary" onclick="closeActivate()">Cancel</button>
        <button type="submit" class="btn btn-primary">Activate</button>
      </div>
    </form>
  </div>
</div>

<!-- Confirm modal -->
<div id="confirmBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 id="confirmTitle" class="text-lg font-semibold text-rose-700 mb-2">Confirm action</h3>
    <p id="confirmBody" class="text-sm text-slate-700 mb-3"></p>
    <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-900 mb-3">
      <strong>This cannot be undone.</strong> All candidate records, interview marks, room assignments,
      upload logs and uploaded application PDFs for this intake will be removed.
    </div>
    <form method="post" id="confirmForm" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" id="cAction">
      <input type="hidden" name="id" id="cId">
      <label class="text-xs font-medium">1. Type the intake name <code id="cName" class="bg-slate-100 px-1 rounded font-mono"></code> to confirm:</label>
      <input type="text" name="confirm" id="cInput" required autocomplete="off" class="mt-1">
      <label class="text-xs font-medium mt-3 block">2. Re-enter your admin password:</label>
      <input type="password" name="passcode" id="cPass" required autocomplete="new-password" class="mt-1" placeholder="Your login password">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn btn-secondary" onclick="closeConfirm()">Cancel</button>
        <button type="submit" id="cSubmit" class="btn btn-danger" disabled>Confirm Delete</button>
      </div>
    </form>
  </div>
</div>

<script>
function openSchedule(id, name, mode, entrance, interview, seats) {
  $('#sId').val(id);
  $('#sName').text(name);
  $('#sMode').val(mode || '');
  $('#sEntrance').val(entrance || '');
  $('#sInterview').val(interview || '');
  seats = seats || {};
  $('#sTaGn').val(seats.gn ?? '');
  $('#sTaObc').val(seats.obc ?? '');
  $('#sTaSc').val(seats.sc ?? '');
  $('#sTaSt').val(seats.st ?? '');
  $('#sTaEws').val(seats.ews ?? '');
  $('#schedBackdrop').removeClass('hidden');
}
function closeSchedule() { $('#schedBackdrop').addClass('hidden'); }
$('#schedBackdrop').on('click', function(e){ if (e.target === this) closeSchedule(); });

function openActivate(id, name) {
  $('#aId').val(id);
  $('#aName').text(name);
  $('#activateBackdrop').removeClass('hidden').find('input[name=passcode]').val('').focus();
}
function closeActivate() { $('#activateBackdrop').addClass('hidden'); }
$('#activateBackdrop').on('click', function(e){ if (e.target === this) closeActivate(); });

function openConfirm(action, id, name, count) {
  $('#cAction').val(action);
  $('#cId').val(id);
  $('#cName').text(name);
  $('#cInput').val('');
  $('#cPass').val('');
  $('#cSubmit').prop('disabled', true);
  const title = action === 'delete_intake' ? 'Delete Intake' : 'Clear Candidate Data';
  const body  = action === 'delete_intake'
    ? 'You are about to permanently delete the intake "' + name + '" (' + count + ' candidate record(s)).'
    : 'You are about to wipe all ' + count + ' candidate record(s) from "' + name + '", but keep the intake itself.';
  $('#confirmTitle').text(title);
  $('#confirmBody').text(body);
  $('#confirmBackdrop').removeClass('hidden');
  setTimeout(() => $('#cInput').focus(), 50);
}
function closeConfirm() { $('#confirmBackdrop').addClass('hidden'); }
function validateConfirm() {
  const nameOk = $('#cInput').val() === $('#cName').text();
  const passOk = $('#cPass').val().length > 0;
  $('#cSubmit').prop('disabled', !(nameOk && passOk));
}
$('#cInput, #cPass').on('input', validateConfirm);
$('#confirmBackdrop').on('click', function(e){ if (e.target === this) closeConfirm(); });
$(document).on('keydown', function(e){ if (e.key === 'Escape') { closeConfirm(); closeSchedule(); closeActivate(); } });
</script>
<?php render_footer(); ?>
