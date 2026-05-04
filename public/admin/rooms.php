<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();

$intake = active_intake();
if (!$intake) {
    if (isset($_POST['ajax']) || isset($_GET['ajax'])) {
        json_out(['error' => 'No active intake'], 400);
    }
    flash_set('No active intake.', 'error');
    redirect('/phdportal/dashboard.php');
}

$isPwdCand = fn($c) => in_array(strtoupper(trim((string)($c['disabled'] ?? ''))), ['YES','Y','1'], true);

// =========================================================================
// AJAX endpoints — return JSON, exit before any HTML output.
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    check_csrf();
    $action = $_POST['ajax'];

    if ($action === 'toggle_scribe') {
        $cid = (int)($_POST['candidate_id'] ?? 0);
        $val = !empty($_POST['value']) ? 1 : 0;
        $cand = $cid ? one('SELECT id FROM candidates WHERE id=? AND intake_id=? AND is_international=0', [$cid, $intake['id']]) : null;
        if (!$cand) json_out(['error' => 'Invalid candidate'], 400);
        q('UPDATE candidates SET requires_scribe=? WHERE id=? AND is_international=0', [$val, $cid]);
        json_out(['ok' => true, 'requires_scribe' => $val]);
    }

    if ($action === 'prepare_auto') {
        // Wipe existing assignments for this intake.
        q('DELETE FROM room_assignments WHERE candidate_id IN (SELECT id FROM candidates WHERE intake_id=? AND is_international=0)', [$intake['id']]);

        $rooms = all('SELECT id, name, capacity, is_pwd_scribe FROM rooms WHERE intake_id=? ORDER BY is_pwd_scribe DESC, id', [$intake['id']]);
        if (!$rooms) json_out(['plan' => [], 'total' => 0, 'unplaced' => 0, 'scribe_room' => null]);

        $pwdRooms = array_values(array_filter($rooms, fn($r) => (int)$r['is_pwd_scribe'] === 1));
        $genRooms = array_values(array_filter($rooms, fn($r) => (int)$r['is_pwd_scribe'] === 0));

        // Non-scribe candidates are placed in ascending dept_reg_no order; scribes
        // are processed in the same order for deterministic room reservation.
        $cands = all("SELECT id, dept_reg_no, name, disabled, requires_scribe
                      FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status='Yes'
                      ORDER BY dept_reg_no, id", [$intake['id']]);

        // Only scribe-required candidates get special treatment (their own PWD room).
        // Non-scribe PWD candidates are placed alongside general candidates in pure
        // dept_reg_no order — they get no PWD-room priority.
        $scribeList  = array_values(array_filter($cands, fn($c) => (int)$c['requires_scribe'] === 1));
        $regularList = array_values(array_filter($cands, fn($c) => (int)$c['requires_scribe'] !== 1));

        // Reserve one whole PWD room per scribe candidate.
        // Pick rooms with smallest capacity first (least wasted seats);
        // on a tie, prefer the LAST one in original PWD-room order (per spec).
        $pwdSorted = $pwdRooms;
        $idxMap = [];
        foreach ($pwdRooms as $i => $r) $idxMap[$r['id']] = $i;
        usort($pwdSorted, function($a, $b) use ($idxMap) {
            return ((int)$a['capacity'] - (int)$b['capacity'])
                ?: ($idxMap[$b['id']] - $idxMap[$a['id']]); // tie → later index first
        });
        $scribeRooms = array_slice($pwdSorted, 0, count($scribeList));
        $reservedIds = array_flip(array_column($scribeRooms, 'id'));
        $otherPwdRooms = array_values(array_filter($pwdRooms, fn($r) => !isset($reservedIds[$r['id']])));

        $plan = [];
        $seats = []; $caps = []; $roomNames = [];
        foreach ($rooms as $r) { $seats[$r['id']] = 1; $caps[$r['id']] = (int)$r['capacity']; $roomNames[$r['id']] = $r['name']; }

        $place = function(array $list, array $rList, string $label) use (&$plan, &$seats, &$caps, &$roomNames) {
            $unplaced = [];
            $ri = 0;
            foreach ($list as $c) {
                while ($ri < count($rList)) {
                    $rid = $rList[$ri]['id'];
                    if ($seats[$rid] - 1 < $caps[$rid]) {
                        $plan[] = [
                            'candidate_id' => (int)$c['id'],
                            'room_id'      => (int)$rid,
                            'seat_no'      => (string)$seats[$rid],
                            'room_name'    => $roomNames[$rid],
                            'cand_label'   => $c['dept_reg_no'] . ' — ' . $c['name'],
                            'group'        => $label,
                        ];
                        $seats[$rid]++;
                        continue 2;
                    }
                    $ri++;
                }
                $unplaced[] = $c;
            }
            return $unplaced;
        };

        // Each scribe candidate → solo occupant of one reserved PWD room.
        $unplacedScribe = [];
        $scribeRoomNames = [];
        foreach ($scribeList as $i => $c) {
            if (!isset($scribeRooms[$i])) { $unplacedScribe[] = $c; continue; }
            $r = $scribeRooms[$i];
            $plan[] = [
                'candidate_id' => (int)$c['id'],
                'room_id'      => (int)$r['id'],
                'seat_no'      => '1',
                'room_name'    => $r['name'],
                'cand_label'   => $c['dept_reg_no'] . ' — ' . $c['name'],
                'group'        => 'scribe',
            ];
            // Lock the room so nobody else lands here.
            $caps[$r['id']] = 1;
            $seats[$r['id']] = 2;
            $scribeRoomNames[] = $r['name'] . ' (cap ' . (int)$r['capacity'] . ' → ' . $c['dept_reg_no'] . ')';
        }

        // Regular candidates (general + non-scribe PWD) → general rooms in dept_reg
        // order; overflow → any unreserved PWD room.
        $rest = $place($regularList, $genRooms, 'regular');
        $rest = $place($rest, $otherPwdRooms, 'regular-overflow');
        $unplacedRegular = $rest;

        json_out([
            'plan'         => $plan,
            'total'        => count($plan),
            'unplaced'     => count($unplacedScribe) + count($unplacedRegular),
            'scribe_count' => count($scribeList),
            'scribe_rooms' => $scribeRoomNames,
            'unplaced_scribe' => count($unplacedScribe),
        ]);
    }

    if ($action === 'assign_chunk') {
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows) || !$rows) json_out(['ok' => true, 'inserted' => 0]);

        $rids = array_unique(array_map(fn($r) => (int)($r['room_id'] ?? 0), $rows));
        $cids = array_unique(array_map(fn($r) => (int)($r['candidate_id'] ?? 0), $rows));

        $okRoomSet = [];
        if ($rids) {
            $ph = implode(',', array_fill(0, count($rids), '?'));
            $rs = all("SELECT id FROM rooms WHERE intake_id=? AND id IN ($ph)", array_merge([$intake['id']], $rids));
            $okRoomSet = array_flip(array_column($rs, 'id'));
        }
        $okCSet = [];
        if ($cids) {
            $ph = implode(',', array_fill(0, count($cids), '?'));
            $cs = all("SELECT id FROM candidates WHERE intake_id=? AND is_international=0 AND id IN ($ph)", array_merge([$intake['id']], $cids));
            $okCSet = array_flip(array_column($cs, 'id'));
        }

        $inserted = 0;
        foreach ($rows as $r) {
            $cid = (int)($r['candidate_id'] ?? 0);
            $rid = (int)($r['room_id'] ?? 0);
            $seat = (string)($r['seat_no'] ?? '');
            if (!isset($okRoomSet[$rid]) || !isset($okCSet[$cid])) continue;
            q('DELETE FROM room_assignments WHERE candidate_id=?', [$cid]);
            q('INSERT INTO room_assignments(candidate_id, room_id, seat_no) VALUES(?,?,?)', [$cid, $rid, $seat]);
            $inserted++;
        }
        json_out(['ok' => true, 'inserted' => $inserted]);
    }

    json_out(['error' => 'Unknown action'], 400);
}

require __DIR__ . '/../../src/layout.php';

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

// Reset / unassign all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_assignments'])) {
    check_csrf();
    q('DELETE FROM room_assignments WHERE candidate_id IN (SELECT id FROM candidates WHERE intake_id=? AND is_international=0)', [$intake['id']]);
    flash_set('All room assignments cleared.', 'success');
    redirect('/phdportal/admin/rooms.php');
}

// Move a candidate to a different room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_assignment'])) {
    check_csrf();
    $cid = (int)($_POST['candidate_id'] ?? 0);
    $newRoomId = (int)($_POST['room_id'] ?? 0);
    $cand = $cid ? one('SELECT id FROM candidates WHERE id=? AND intake_id=? AND is_international=0', [$cid, $intake['id']]) : null;
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

$rooms = all('SELECT r.*,
              (SELECT COUNT(*) FROM room_assignments a
                 JOIN candidates c ON c.id=a.candidate_id
                 WHERE a.room_id=r.id AND c.is_international=0) assigned_count
              FROM rooms r WHERE r.intake_id=? ORDER BY r.is_pwd_scribe DESC, r.id', [$intake['id']]);
$totalCap = array_sum(array_column($rooms, 'capacity'));
$totalAssigned = array_sum(array_column($rooms, 'assigned_count'));

$assignments = all("SELECT a.seat_no, c.id cand_id, c.dept_reg_no, c.name, c.birth_category, c.disabled, c.requires_scribe, r.name room_name, r.id room_id, r.is_pwd_scribe
                    FROM room_assignments a
                    JOIN candidates c ON c.id=a.candidate_id
                    JOIN rooms r ON r.id=a.room_id
                    WHERE c.intake_id=? AND c.is_international=0
                    ORDER BY r.id, CAST(a.seat_no AS UNSIGNED)", [$intake['id']]);

$shortlistCount = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status='Yes'", [$intake['id']])['c'];

$unassigned = all("SELECT c.id, c.dept_reg_no, c.name, c.birth_category, c.disabled, c.requires_scribe
                   FROM candidates c
                   LEFT JOIN room_assignments a ON a.candidate_id=c.id
                   WHERE c.intake_id=? AND c.is_international=0 AND c.screening_status='Yes' AND a.candidate_id IS NULL
                   ORDER BY c.dept_reg_no, c.id", [$intake['id']]);

$scribeCandCount = (int)one("SELECT COUNT(*) c FROM candidates
                              WHERE intake_id=? AND is_international=0 AND screening_status='Yes' AND requires_scribe=1",
                              [$intake['id']])['c'];
$pwdRoomCount = count(array_filter($rooms, fn($r) => (int)$r['is_pwd_scribe'] === 1));
$scribeShortfall = max(0, $scribeCandCount - $pwdRoomCount);

render_header('Room Allocation', $u);
?>
<?php if ($scribeShortfall > 0): ?>
<div class="mb-4 bg-amber-50 border-l-4 border-amber-500 text-amber-900 p-3 rounded">
  <div class="font-semibold">⚠ Not enough PWD rooms for scribe candidates</div>
  <div class="text-sm mt-1">
    <?= $scribeCandCount ?> candidate(s) require a scribe but only <?= $pwdRoomCount ?> PWD/Scribe room(s) exist.
    Each scribe candidate needs a dedicated room — add at least <?= $scribeShortfall ?> more PWD/Scribe room(s),
    or <?= $scribeShortfall ?> scribe candidate(s) will be left unassigned.
  </div>
</div>
<?php endif; ?>

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
    <p class="text-xs text-slate-600 mb-3">Each scribe-required candidate gets a whole PWD room to themselves (smallest-capacity rooms reserved first). All other candidates — including non-scribe PWD — fill general rooms in ascending dept-reg order.</p>

    <div id="autoProgressBox" class="mb-3 hidden">
      <div class="flex justify-between text-xs text-slate-600 mb-1">
        <span id="autoProgressLabel">Preparing…</span>
        <span id="autoProgressCount">0 / 0</span>
      </div>
      <div class="bg-slate-200 rounded-full h-3 overflow-hidden">
        <div id="autoProgressBar" class="auto-prog-bar h-3 transition-all duration-300" style="width:0%"></div>
      </div>
    </div>

    <div class="flex gap-2">
      <button id="autoAssignBtn" type="button" class="btn btn-primary flex-1 justify-center">Run Auto-Assign</button>
      <form method="post" class="inline" onsubmit="return confirm('Unassign ALL candidates from rooms? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="reset_assignments" value="1">
        <button class="btn btn-danger" title="Clear all room assignments">Reset</button>
      </form>
    </div>
  </div>
</div>

<style>
  .row-pwd { background-color: #fff1f2; }
  .row-pwd:hover { background-color: #ffe4e6; }
  .row-scribe { background-color: #fef3c7; }
  .row-scribe:hover { background-color: #fde68a; }
  @keyframes prog-stripes { from { background-position: 0 0; } to { background-position: 32px 0; } }
  .auto-prog-bar {
    background-image: linear-gradient(45deg,
      rgba(255,255,255,.25) 25%, transparent 25%,
      transparent 50%, rgba(255,255,255,.25) 50%,
      rgba(255,255,255,.25) 75%, transparent 75%, transparent);
    background-size: 32px 32px;
    background-color: #4f46e5;
    animation: prog-stripes 1s linear infinite;
  }
</style>

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
$unassignedPwd = array_values(array_filter($unassigned, fn($c) => $isPwdCand($c)));
$unassignedGen = array_values(array_filter($unassigned, fn($c) => !$isPwdCand($c)));

$renderUnassignedRow = function($c) use ($isPwdCand) {
    $pwd = $isPwdCand($c);
    $scribe = (int)$c['requires_scribe'] === 1;
    $cls = $scribe ? 'row-scribe' : ($pwd ? 'row-pwd' : '');
    ?>
    <tr class="<?= $cls ?>" data-cand-id="<?= (int)$c['id'] ?>" data-pwd="<?= $pwd ? 1 : 0 ?>">
      <td class="font-mono text-xs"><?= h($c['dept_reg_no']) ?></td>
      <td><?= h($c['name']) ?></td>
      <td><?= category_badge($c['birth_category'] ?? '') ?></td>
      <td class="text-xs"><?= h($c['disabled']) ?></td>
      <td>
        <?php if ($pwd): ?>
          <label class="inline-flex items-center gap-1 text-xs cursor-pointer">
            <input type="checkbox" class="scribe-toggle" data-cand-id="<?= (int)$c['id'] ?>" <?= $scribe ? 'checked' : '' ?>>
            <span class="scribe-status"><?= $scribe ? 'Yes' : 'No' ?></span>
          </label>
        <?php else: ?>
          <span class="text-xs text-slate-400">—</span>
        <?php endif; ?>
      </td>
      <td>
        <button type="button" class="btn btn-secondary text-xs py-1 px-2 move-btn"
                data-cand-id="<?= (int)$c['id'] ?>"
                data-cand-name="<?= h($c['name']) ?>"
                data-cand-reg="<?= h($c['dept_reg_no']) ?>"
                data-current-room="(unassigned)"
                data-current-room-id="0">Assign</button>
      </td>
    </tr>
    <?php
};
?>

<?php if ($unassignedPwd): ?>
<div class="card mb-5 border-l-4 border-rose-500">
  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
    <h3 class="font-semibold text-rose-800">
      Unassigned PWD Candidates
      <span class="text-sm text-slate-500 font-normal ml-2"><?= count($unassignedPwd) ?> pending</span>
    </h3>
    <div class="text-xs text-slate-500">
      <span class="inline-block w-3 h-3 rounded-sm align-middle" style="background:#fef3c7;border:1px solid #fcd34d"></span> Requires Scribe
    </div>
  </div>
  <div class="overflow-x-auto">
  <table class="data-table w-full">
    <thead><tr><th>Dept Reg No</th><th>Name</th><th>Category</th><th>PWD</th><th>Requires Scribe</th><th>Assign To</th></tr></thead>
    <tbody>
    <?php foreach ($unassignedPwd as $c) $renderUnassignedRow($c); ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($unassignedGen): ?>
<div class="card mb-5">
  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
    <h3 class="font-semibold">
      Unassigned Shortlisted Candidates
      <span class="text-sm text-slate-500 font-normal ml-2"><?= count($unassignedGen) ?> pending</span>
    </h3>
  </div>
  <div class="overflow-x-auto">
  <table class="data-table w-full">
    <thead><tr><th>Dept Reg No</th><th>Name</th><th>Category</th><th>PWD</th><th>Requires Scribe</th><th>Assign To</th></tr></thead>
    <tbody>
    <?php foreach ($unassignedGen as $c) $renderUnassignedRow($c); ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

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
    <thead><tr><th>Sr. No.</th><th>Dept Reg No</th><th>Name</th><th>Category</th><th>PWD</th><th>Scribe</th><th>Move To</th></tr></thead>
    <tbody>
    <?php foreach ($list as $a):
      $pwd = $isPwdCand($a);
      $scribe = (int)$a['requires_scribe'] === 1;
      $cls = $scribe ? 'row-scribe' : ($pwd ? 'row-pwd' : '');
    ?>
      <tr class="<?= $cls ?>" data-pwd="<?= $pwd ? 1 : 0 ?>">
        <td class="text-center font-semibold text-indigo-700"><?= h($a['seat_no']) ?></td>
        <td class="font-mono text-xs"><?= h($a['dept_reg_no']) ?></td>
        <td><?= h($a['name']) ?></td>
        <td><?= category_badge($a['birth_category'] ?? '') ?></td>
        <td class="text-xs"><?= h($a['disabled']) ?></td>
        <td>
          <?php if ($pwd): ?>
            <label class="inline-flex items-center gap-1 text-xs cursor-pointer">
              <input type="checkbox" class="scribe-toggle" data-cand-id="<?= (int)$a['cand_id'] ?>" <?= $scribe ? 'checked' : '' ?>>
              <span class="scribe-status"><?= $scribe ? 'Yes' : 'No' ?></span>
            </label>
          <?php else: ?>
            <span class="text-xs text-slate-400">—</span>
          <?php endif; ?>
        </td>
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
const SCRIBE_CAND_COUNT = <?= (int)$scribeCandCount ?>;
const PWD_ROOM_COUNT = <?= (int)$pwdRoomCount ?>;

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

// ---------- Requires Scribe toggle (AJAX) ----------
$('.scribe-toggle').on('change', function(){
  const $cb = $(this);
  const candId = $cb.data('cand-id');
  const value = $cb.is(':checked') ? 1 : 0;
  const $row = $cb.closest('tr');
  const $status = $cb.siblings('.scribe-status');
  $cb.prop('disabled', true);
  $.post('', { ajax: 'toggle_scribe', csrf: window.CSRF_TOKEN, candidate_id: candId, value: value })
    .done(function(res){
      if (res && res.ok) {
        $status.text(value ? 'Yes' : 'No');
        $row.removeClass('row-scribe row-pwd');
        if (value) $row.addClass('row-scribe');
        else if (parseInt($row.data('pwd'), 10) === 1) $row.addClass('row-pwd');
      } else {
        $cb.prop('checked', !value);
        alert((res && res.error) || 'Failed to update');
      }
    })
    .fail(function(){
      $cb.prop('checked', !value);
      alert('Network error updating scribe flag');
    })
    .always(function(){ $cb.prop('disabled', false); });
});

// ---------- Auto-Assign (chunked AJAX with progress bar) ----------
const CHUNK_SIZE = 25;
$('#autoAssignBtn').on('click', async function(){
  if (SCRIBE_CAND_COUNT > PWD_ROOM_COUNT) {
    const short = SCRIBE_CAND_COUNT - PWD_ROOM_COUNT;
    if (!confirm(
      '⚠ Not enough PWD rooms for scribe candidates.\n\n' +
      SCRIBE_CAND_COUNT + ' candidate(s) require a scribe but only ' + PWD_ROOM_COUNT + ' PWD room(s) exist.\n' +
      'Each scribe candidate needs a dedicated room — ' + short + ' will be left unassigned.\n\n' +
      'Continue auto-assignment anyway?'
    )) return;
  }
  if (!confirm('Re-assign all shortlisted candidates to rooms?\nExisting assignments will be cleared.')) return;
  const $btn = $(this);
  $btn.prop('disabled', true);
  $('#autoProgressBox').removeClass('hidden');
  $('#autoProgressLabel').text('Clearing existing assignments…');
  $('#autoProgressCount').text('0 / 0');
  $('#autoProgressBar').css('width', '0%');

  let prep;
  try {
    prep = await $.post('', { ajax: 'prepare_auto', csrf: window.CSRF_TOKEN });
  } catch (e) {
    $('#autoProgressLabel').text('Failed to prepare auto-assign.');
    $btn.prop('disabled', false);
    return;
  }

  const plan = prep.plan || [];
  const total = plan.length;
  if (!total) {
    $('#autoProgressLabel').text('Nothing to assign.');
    $('#autoProgressBar').css('width', '100%');
    setTimeout(() => location.reload(), 800);
    return;
  }

  if (prep.scribe_count) {
    const placed = (prep.scribe_rooms || []).length;
    let msg = 'Reserved ' + placed + ' room(s) for scribe candidate(s)';
    if (prep.unplaced_scribe) msg += ' (' + prep.unplaced_scribe + ' could not be placed — no PWD room left)';
    $('#autoProgressLabel').text(msg);
  }

  let done = 0;
  for (let i = 0; i < plan.length; i += CHUNK_SIZE) {
    const chunk = plan.slice(i, i + CHUNK_SIZE);
    try {
      await $.post('', {
        ajax: 'assign_chunk',
        csrf: window.CSRF_TOKEN,
        rows: JSON.stringify(chunk.map(p => ({
          candidate_id: p.candidate_id, room_id: p.room_id, seat_no: p.seat_no
        })))
      });
    } catch (e) {
      $('#autoProgressLabel').text('Chunk failed at row ' + (i+1));
      $btn.prop('disabled', false);
      return;
    }
    done += chunk.length;
    const pct = Math.round((done / total) * 100);
    $('#autoProgressBar').css('width', pct + '%');
    $('#autoProgressCount').text(done + ' / ' + total);
    const last = chunk[chunk.length - 1];
    $('#autoProgressLabel').text('Assigning… ' + last.cand_label + ' → ' + last.room_name);
  }

  const unplaced = prep.unplaced || 0;
  $('#autoProgressLabel').text('Done — ' + total + ' assigned' + (unplaced ? ', ' + unplaced + ' unplaced (no capacity)' : '') + '.');
  setTimeout(() => location.reload(), 900);
});
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
