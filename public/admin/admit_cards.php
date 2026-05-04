<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

$cands = all("SELECT c.id, c.dept_reg_no, c.name, c.applicant_id, c.birth_category, c.email, c.photo,
              r.name room_name, a.seat_no
              FROM candidates c
              LEFT JOIN room_assignments a ON a.candidate_id=c.id
              LEFT JOIN rooms r ON r.id=a.room_id
              WHERE c.intake_id=? AND c.is_international=0 AND c.screening_status='Yes'
              ORDER BY c.serial_no, c.id", [$intake['id']]);

render_header('Admit Cards', $u);
?>
<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-semibold">Admit Cards</h1>
    <p class="text-sm text-slate-500 mt-0.5"><?= count($cands) ?> shortlisted candidate(s)</p>
  </div>
  <div class="flex gap-2 flex-wrap">
    <button class="btn btn-primary text-xs" onclick="downloadAllAdmitCards()">Download All (PDF)</button>
  </div>
</div>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr><th>RMG No</th><th>PW No</th><th>Name</th><th>Category</th><th>Room</th><th>Seat</th><th></th></tr></thead>
<tbody>
<?php foreach ($cands as $r): ?>
<tr>
  <td class="font-mono text-xs"><?= h($r['dept_reg_no']) ?></td>
  <td class="font-mono text-xs"><?= $r['applicant_id'] ? h($r['applicant_id']) : '<span class="text-rose-500">not set</span>' ?></td>
  <td><?= h($r['name']) ?></td>
  <td><?= category_badge($r['birth_category'] ?? '') ?></td>
  <td><?= $r['room_name'] ? h($r['room_name']) : '<span class="text-slate-400">Not Allocated</span>' ?></td>
  <td class="text-center"><?= $r['seat_no'] ? h($r['seat_no']) : '<span class="text-slate-400">—</span>' ?></td>
  <td><button class="text-xs text-indigo-600 hover:underline" onclick='downloadOne(<?= json_encode($r) ?>)'>Download</button></td>
</tr>
<?php endforeach; ?>
<?php if (!$cands): ?><tr><td colspan="7" class="text-center py-6 text-slate-500">No shortlisted candidates.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php
$exam_dt_display = '';
if (!empty($intake['entrance_datetime'])) {
    $ts = strtotime($intake['entrance_datetime']);
    if ($ts) $exam_dt_display = date('d M Y, h:i A', $ts);
}
?>
<script src="/phdportal/assets/js/admit_card.js"></script>
<script>
const INTAKE_NAME = <?= json_encode($intake['name']) ?>;
const EXAM_DATETIME = <?= json_encode($exam_dt_display) ?>;
const ENTRANCE_MODE = <?= json_encode($intake['entrance_mode'] ?? '') ?>;
const CANDS = <?= json_encode($cands) ?>;
const RENDER_CTX = { intakeName: INTAKE_NAME, examDatetime: EXAM_DATETIME, entranceMode: ENTRANCE_MODE };

async function downloadOne(c) {
  await AdmitCard.ensureAssets();
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  await AdmitCard.render(doc, c, true, RENDER_CTX);
  doc.save('AdmitCard_' + c.dept_reg_no + '.pdf');
}

async function downloadAllAdmitCards() {
  await AdmitCard.ensureAssets();
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  for (let i = 0; i < CANDS.length; i++) {
    await AdmitCard.render(doc, CANDS[i], i === 0, RENDER_CTX);
  }
  doc.save('AdmitCards_' + INTAKE_NAME.replace(/\s+/g,'_') + '.pdf');
}
</script>
<?php render_footer(); ?>
