<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

// Add room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_room'])) {
    check_csrf();
    $name = trim($_POST['name'] ?? '');
    $cap  = max(1, (int)($_POST['capacity'] ?? 30));
    $pwd  = isset($_POST['is_pwd_scribe']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');
    if ($name) {
        q('INSERT INTO rooms(intake_id,name,capacity,is_pwd_scribe,notes) VALUES(?,?,?,?,?)',
          [$intake['id'], $name, $cap, $pwd, $notes]);
        flash_set('Room added.', 'success');
    }
    redirect('/phdportal/admin/rooms.php');
}

// Delete room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_room'])) {
    check_csrf();
    $rid = (int)$_POST['room_id'];
    q('DELETE FROM rooms WHERE id=? AND intake_id=?', [$rid, $intake['id']]);
    flash_set('Room deleted.', 'success');
    redirect('/phdportal/admin/rooms.php');
}

// Move a candidate to a different room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_assignment'])) {
    check_csrf();
    $cid = (int)($_POST['candidate_id'] ?? 0);
    $newRoomId = (int)($_POST['room_id'] ?? 0);
    $cand = $cid ? one('SELECT id FROM candidates WHERE id=? AND intake_id=?', [$cid, $intake['id']]) : null;
    $room = $newRoomId ? one('SELECT id, name, capacity FROM rooms WHERE id=? AND intake_id=?', [$newRoomId, $intake['id']]) : null;
    if (!$cand || !$room) {
        flash_set('Invalid candidate or room.', 'error');
    } else {
        $used = (int)one('SELECT COUNT(*) c FROM room_assignments WHERE room_id=? AND candidate_id<>?', [$room['id'], $cid])['c'];
        if ($used >= (int)$room['capacity']) {
            flash_set('Room "' . $room['name'] . '" is at full capacity.', 'error');
        } else {
            q('DELETE FROM room_assignments WHERE candidate_id=?', [$cid]);
            q('INSERT INTO room_assignments(candidate_id,room_id,seat_no) VALUES(?,?,?)',
              [$cid, $room['id'], (string)($used + 1)]);
            flash_set('Candidate moved to ' . $room['name'] . '.', 'success');
        }
    }
    redirect('/phdportal/admin/rooms.php');
}

// Auto-assign candidates to rooms
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_assign'])) {
    check_csrf();
    q('DELETE FROM room_assignments WHERE candidate_id IN (SELECT id FROM candidates WHERE intake_id=?)', [$intake['id']]);
    $rooms = all('SELECT * FROM rooms WHERE intake_id=? ORDER BY is_pwd_scribe DESC, id', [$intake['id']]);
    $pwdRooms = array_values(array_filter($rooms, fn($r)=>$r['is_pwd_scribe']));
    $genRooms = array_values(array_filter($rooms, fn($r)=>!$r['is_pwd_scribe']));
    // PWD candidates -> PWD scribe rooms first
    $cands = all("SELECT id, disabled FROM candidates WHERE intake_id=? AND screening_status='Yes' ORDER BY serial_no, id", [$intake['id']]);
    $pwdList = array_filter($cands, fn($c)=>in_array(strtoupper(trim($c['disabled']??'')), ['YES','Y','1']));
    $genList = array_filter($cands, fn($c)=>!in_array(strtoupper(trim($c['disabled']??'')), ['YES','Y','1']));
    $fill = function($candList, $rooms) {
        $ri = 0; $seat = 1; $assigned = 0;
        foreach ($candList as $c) {
            if (!$rooms) break;
            while ($ri < count($rooms)) {
                $r = $rooms[$ri];
                $used = (int)one('SELECT COUNT(*) c FROM room_assignments WHERE room_id=?', [$r['id']])['c'];
                if ($used >= $r['capacity']) { $ri++; $seat = 1; continue; }
                q('INSERT INTO room_assignments(candidate_id,room_id,seat_no) VALUES(?,?,?)',
                  [$c['id'], $r['id'], (string)($used+1)]);
                $assigned++;
                break;
            }
        }
        return $assigned;
    };
    $a1 = $pwdRooms ? $fill($pwdList, $pwdRooms) : 0;
    // Unassigned PWD -> general rooms
    $a2 = $fill(array_slice($pwdList, $a1), $genRooms);
    $a3 = $fill($genList, $genRooms);
    $total = $a1 + $a2 + $a3;
    flash_set("Auto-assigned $total shortlisted candidates to rooms.", 'success');
    redirect('/phdportal/admin/rooms.php');
}

$rooms = all('SELECT r.*,
              (SELECT COUNT(*) FROM room_assignments a WHERE a.room_id=r.id) assigned_count
              FROM rooms r WHERE r.intake_id=? ORDER BY r.is_pwd_scribe DESC, r.id', [$intake['id']]);
$totalCap = array_sum(array_column($rooms, 'capacity'));
$totalAssigned = array_sum(array_column($rooms, 'assigned_count'));

$assignments = all("SELECT a.seat_no, c.id cand_id, c.dept_reg_no, c.name, c.birth_category, c.disabled, r.name room_name, r.id room_id, r.is_pwd_scribe
                    FROM room_assignments a
                    JOIN candidates c ON c.id=a.candidate_id
                    JOIN rooms r ON r.id=a.room_id
                    WHERE c.intake_id=?
                    ORDER BY r.id, CAST(a.seat_no AS UNSIGNED)", [$intake['id']]);

$shortlistCount = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND screening_status='Yes'", [$intake['id']])['c'];

render_header('Room Allocation', $u);
?>
<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-semibold">Room Allocation — Written Exam</h1>
    <p class="text-sm text-slate-500 mt-0.5">
      <?= count($rooms) ?> room(s) · Capacity <?= $totalCap ?> · Assigned <?= $totalAssigned ?> / Shortlisted <?= $shortlistCount ?>
    </p>
  </div>
  <div class="flex gap-2 flex-wrap">
    <button class="btn btn-secondary text-xs" onclick="downloadAttendance()">Attendance PDF</button>
    <button class="btn btn-secondary text-xs" onclick="downloadSeating()">Seating Arrangement PDF</button>
  </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
  <div class="card md:col-span-2">
    <h3 class="font-semibold mb-3">Add Room</h3>
    <form method="post" class="grid grid-cols-2 md:grid-cols-4 gap-3 items-end">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="add_room" value="1">
      <div><label class="text-xs font-medium">Room Name</label><input name="name" required placeholder="e.g. LC-101"></div>
      <div><label class="text-xs font-medium">Capacity</label><input type="number" name="capacity" value="30" min="1"></div>
      <div class="flex items-center gap-2 pt-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_pwd_scribe" value="1"> PWD/Scribe Room</label></div>
      <div><button class="btn btn-primary w-full justify-center">Add</button></div>
      <div class="col-span-2 md:col-span-4"><label class="text-xs font-medium">Notes (optional)</label><input name="notes" placeholder="Building, floor, special instructions…"></div>
    </form>
  </div>
  <div class="card">
    <h3 class="font-semibold mb-2">Auto-Assign Seats</h3>
    <p class="text-xs text-slate-600 mb-3">Assigns shortlisted candidates to rooms in order. PWD candidates get priority for PWD/scribe rooms.</p>
    <form method="post" onsubmit="return confirm('Re-assign all candidates to rooms? Existing assignments will be cleared.');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button name="auto_assign" class="btn btn-primary w-full justify-center">Run Auto-Assign</button>
    </form>
  </div>
</div>

<div class="card p-0 overflow-x-auto mb-5">
<table class="data-table w-full">
<thead><tr><th>Room</th><th>Capacity</th><th>Assigned</th><th>Type</th><th>Notes</th><th></th></tr></thead>
<tbody>
<?php foreach ($rooms as $r): ?>
<tr>
  <td class="font-semibold"><?= h($r['name']) ?></td>
  <td class="text-center"><?= (int)$r['capacity'] ?></td>
  <td class="text-center"><?= (int)$r['assigned_count'] ?></td>
  <td><?= $r['is_pwd_scribe']
        ? '<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-rose-100 text-rose-800">PWD/Scribe</span>'
        : '<span class="inline-block px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-700">Regular</span>' ?></td>
  <td class="text-xs text-slate-600"><?= h($r['notes']) ?></td>
  <td class="text-right">
    <form method="post" class="inline" onsubmit="return confirm('Delete this room? Seat assignments will be removed.');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="delete_room" value="1">
      <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
      <button class="btn btn-danger text-xs">Delete</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$rooms): ?><tr><td colspan="6" class="text-center py-6 text-slate-500">No rooms added yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php
// Group assignments by room
$byRoom = [];
foreach ($assignments as $a) $byRoom[$a['room_id']][] = $a;
?>

<?php foreach ($rooms as $r): $list = $byRoom[$r['id']] ?? []; ?>
<div class="card mb-4">
  <div class="flex items-center justify-between mb-3">
    <h3 class="font-semibold"><?= h($r['name']) ?>
      <?php if ($r['is_pwd_scribe']): ?><span class="ml-2 text-xs font-semibold text-rose-700">[PWD/Scribe]</span><?php endif; ?>
      <span class="text-sm text-slate-500 font-normal ml-2"><?= count($list) ?> / <?= $r['capacity'] ?> seats</span>
    </h3>
  </div>
  <?php if ($list): ?>
  <table class="data-table w-full">
    <thead><tr><th>Sr. No.</th><th>Dept Reg No</th><th>Name</th><th>Category</th><th>PWD</th><th>Move To</th></tr></thead>
    <tbody>
    <?php foreach ($list as $a): ?>
      <tr>
        <td class="text-center font-semibold text-indigo-700"><?= h($a['seat_no']) ?></td>
        <td class="font-mono text-xs"><?= h($a['dept_reg_no']) ?></td>
        <td><?= h($a['name']) ?></td>
        <td><?= category_badge($a['birth_category'] ?? '') ?></td>
        <td class="text-xs"><?= h($a['disabled']) ?></td>
        <td>
          <button type="button" class="btn btn-secondary text-xs py-1 px-2 move-btn"
                  data-cand-id="<?= (int)$a['cand_id'] ?>"
                  data-cand-name="<?= h($a['name']) ?>"
                  data-cand-reg="<?= h($a['dept_reg_no']) ?>"
                  data-current-room="<?= h($r['name']) ?>"
                  data-current-room-id="<?= (int)$r['id'] ?>">Move</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p class="text-sm text-slate-500">No candidates assigned.</p><?php endif; ?>
</div>
<?php endforeach; ?>

<script>
const ASSIGNMENTS = <?= json_encode($assignments) ?>;
const INTAKE_NAME = <?= json_encode($intake['name']) ?>;
const ENTRANCE_MODE = <?= json_encode($intake['entrance_mode'] ?: 'Written') ?>;
const ENTRANCE_DATETIME = <?= json_encode($intake['entrance_datetime']) ?>;

function formatExamDate(dt) {
  if (!dt) return '';
  const d = new Date(dt.replace(' ', 'T'));
  if (isNaN(d)) return dt;
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function downloadAttendance() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  const pageWidth = doc.internal.pageSize.getWidth();
  const examDate = formatExamDate(ENTRANCE_DATETIME);
  const headerTitle = 'SJMSOM IIT Bombay — PhD ' + ENTRANCE_MODE + ' Exam Attendance';

  const drawHeader = () => {
    doc.setFontSize(14);
    doc.text(headerTitle, 14, 15);
    doc.setFontSize(10);
    doc.text('Intake: ' + INTAKE_NAME, pageWidth - 14, 12, { align: 'right' });
    doc.text('Date: ' + (examDate || '________________'), pageWidth - 14, 18, { align: 'right' });
  };

  const grouped = {};
  ASSIGNMENTS.forEach(a => { (grouped[a.room_name] = grouped[a.room_name] || []).push(a); });
  let first = true;
  Object.keys(grouped).forEach(rn => {
    if (!first) doc.addPage();
    first = false;
    drawHeader();
    doc.setFontSize(12);
    doc.text('Room: ' + rn + (grouped[rn][0].is_pwd_scribe==1 ? '  [PWD/Scribe]' : ''), 14, 28);
    const body = grouped[rn].map(a => [a.seat_no, a.dept_reg_no, a.name, '']);
    doc.autoTable({
      startY: 34,
      head: [['Sr. No.','Dept Reg No','Name','Signature']],
      body,
      styles: { fontSize: 9, cellPadding: 3 },
      columnStyles: { 0:{cellWidth:15}, 1:{cellWidth:35}, 3:{cellWidth:60} },
      headStyles: { fillColor: [79,70,229] },
      margin: { top: 34 },
      didDrawPage: (data) => {
        if (data.pageNumber > 1) drawHeader();
      }
    });
  });
  doc.save('Attendance_' + INTAKE_NAME.replace(/\s+/g,'_') + '.pdf');
}

function downloadSeating() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  doc.setFontSize(14);
  doc.text('SJMSOM IIT Bombay — Seating Arrangement', 14, 15);
  doc.setFontSize(10);
  doc.text('Intake: ' + INTAKE_NAME, 14, 22);
  const body = ASSIGNMENTS.map((a, i) => [i + 1, a.dept_reg_no, a.name, a.room_name]);
  doc.autoTable({
    startY: 28,
    head: [['S. No.','Dept Reg No','Name','Room']],
    body,
    styles: { fontSize: 9, cellPadding: 3 },
    columnStyles: { 0: { cellWidth: 15, halign: 'center' } },
    headStyles: { fillColor: [79,70,229] }
  });
  doc.save('Seating_' + INTAKE_NAME.replace(/\s+/g,'_') + '.pdf');
}

const ROOMS = <?= json_encode(array_map(fn($r) => [
  'id' => (int)$r['id'],
  'name' => $r['name'],
  'capacity' => (int)$r['capacity'],
  'assigned' => (int)$r['assigned_count'],
  'is_pwd_scribe' => (int)$r['is_pwd_scribe'],
], $rooms)) ?>;

$('.move-btn').on('click', function(){
  const $btn = $(this);
  const candId = $btn.data('cand-id');
  const candName = $btn.data('cand-name');
  const candReg = $btn.data('cand-reg');
  const currentRoom = $btn.data('current-room');
  const currentRoomId = parseInt($btn.data('current-room-id'), 10);

  $('#moveCandId').val(candId);
  $('#moveCandLabel').text(candReg + ' — ' + candName);
  $('#moveCurrentRoom').text(currentRoom);

  const $sel = $('#moveRoomSel').empty();
  $sel.append('<option value="">-- select room --</option>');
  ROOMS.forEach(r => {
    if (r.id === currentRoomId) return;
    const full = r.assigned >= r.capacity;
    const label = r.name + (r.is_pwd_scribe ? ' [PWD]' : '') +
      ' (' + r.assigned + '/' + r.capacity + ')' + (full ? ' — full' : '');
    $('<option>').val(r.id).text(label).prop('disabled', full).appendTo($sel);
  });

  $('#moveBackdrop').removeClass('hidden');
});
$('#moveBackdrop').on('click', function(e){ if (e.target === this) $(this).addClass('hidden'); });
$(document).on('keydown', e => { if (e.key === 'Escape') $('#moveBackdrop').addClass('hidden'); });
</script>

<div id="moveBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold mb-2">Move Candidate</h3>
    <p class="text-sm text-slate-700 mb-1"><strong id="moveCandLabel"></strong></p>
    <p class="text-xs text-slate-500 mb-3">Currently in: <span id="moveCurrentRoom" class="font-medium"></span></p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="move_assignment" value="1">
      <input type="hidden" name="candidate_id" id="moveCandId">
      <label class="text-xs font-medium">New Room</label>
      <select name="room_id" id="moveRoomSel" required class="mt-1"></select>
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn btn-secondary" onclick="$('#moveBackdrop').addClass('hidden')">Cancel</button>
        <button class="btn btn-primary">Move</button>
      </div>
    </form>
  </div>
</div>
<?php render_footer(); ?>
