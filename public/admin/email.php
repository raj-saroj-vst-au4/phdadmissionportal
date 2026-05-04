<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

$phase = $_GET['phase'] ?? 'written';
$isIntl = 0;

$presets = [
  'written' => [
    'subject' => 'Entrance Exam Instructions — SJMSOM IITB PhD Admissions {{intake}}aourva',
    'body' => "Dear {{name}},\n\nThis is to inform you that your application (Dept Reg No: {{dept_reg_no}}) has been shortlisted for the entrance examination as part of SJMSOM PhD Admissions for {{intake}}.\n\nPlease carry a printout of your admit card and a valid photo ID.\nReporting time: 1 hour before the exam start.\nYour RMG No. is {{dept_reg_no}} — quote it in all communications.\n\nIf a downloadable admit card is not attached, please note your RMG No, PW No and Name as printed in this communication.\n\nBest regards,\nSJMSOM Admissions Office\nIIT Bombay",
  ],
  'interview' => [
    'subject' => 'Interview Shortlist — SJMSOM IITB PhD Admissions {{intake}}',
    'body' => "Dear {{name}},\n\nCongratulations! You have been shortlisted for the personal interview round of SJMSOM PhD Admissions {{intake}}.\n(Dept Reg No: {{dept_reg_no}})\n\nPlease watch this inbox for further interview details.\n\nBest regards,\nSJMSOM Admissions Office\nIIT Bombay",
  ],
  'final' => [
    'subject' => 'PhD Admission Offer — SJMSOM IITB {{intake}}',
    'body' => "Dear {{name}},\n\nCongratulations! We are pleased to offer you admission to the PhD programme at Shailesh J. Mehta School of Management, IIT Bombay for {{intake}}.\n(Dept Reg No: {{dept_reg_no}})\n\nA formal admission letter with further details will follow.\n\nBest regards,\nSJMSOM Admissions Office\nIIT Bombay",
  ],
];
$preset = $presets[$phase] ?? $presets['written'];

// Count recipients
if ($phase === 'written') {
    $count = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=? AND screening_status='Yes' AND email IS NOT NULL AND email<>''", [$intake['id'],$isIntl])['c'];
    $countLabel = 'Shortlisted candidates with email';
} elseif ($phase === 'interview') {
    $count = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=? AND screening_status='Yes' AND written_marks IS NOT NULL AND passed_cutoff=1 AND email IS NOT NULL AND email<>''", [$intake['id'],$isIntl])['c'];
    $countLabel = 'Candidates passing cutoff with email';
} else {
    $count = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=? AND final_status='Selected' AND email IS NOT NULL AND email<>''", [$intake['id'],$isIntl])['c'];
    $countLabel = 'Finally selected with email';
}

$logs = all('SELECT * FROM email_log WHERE intake_id=? ORDER BY id DESC LIMIT 15', [$intake['id']]);

render_header('Email Communication', $u);
?>
<h1 class="text-2xl font-semibold mb-4">Email Communication</h1>

<div class="mb-3 flex items-center gap-4 text-sm">
  <label class="flex items-center gap-2 cursor-pointer">
    <input type="radio" name="aud" value="0" <?= !$isIntl ? 'checked' : '' ?> onchange="location.href='?phase=<?= h($phase) ?>&intl=0'">
    <span>Indian Candidates</span>
  </label>
</div>

<div class="flex border-b border-slate-200 mb-5 gap-1">
  <a href="?phase=written&intl=<?= $isIntl ?>" class="px-4 py-2 text-sm font-medium rounded-t <?= $phase==='written' ? 'bg-white border border-b-white border-slate-200 text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">Written Exam Instructions</a>
  <a href="?phase=interview&intl=<?= $isIntl ?>" class="px-4 py-2 text-sm font-medium rounded-t <?= $phase==='interview' ? 'bg-white border border-b-white border-slate-200 text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">Interview Shortlist</a>
  <a href="?phase=final&intl=<?= $isIntl ?>" class="px-4 py-2 text-sm font-medium rounded-t <?= $phase==='final' ? 'bg-white border border-b-white border-slate-200 text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">Final Selection</a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
  <div class="card md:col-span-2">
    <form id="emailForm">
      <label class="text-sm font-medium">Subject</label>
      <input id="em_subject" name="subject" value="<?= h($preset['subject']) ?>" required>
      <label class="text-sm font-medium mt-3 block">Body</label>
      <textarea id="em_body" name="body" rows="14" required><?= h($preset['body']) ?></textarea>
      <p class="text-xs text-slate-500 mt-1">Placeholders: <code>{{name}}</code>, <code>{{dept_reg_no}}</code>, <code>{{intake}}</code></p>

      <?php if ($phase === 'written'): ?>
      <label class="flex items-center gap-2 mt-3 text-sm cursor-pointer">
        <input type="checkbox" id="em_attach_admit" checked>
        <span>Attach each candidate's admit card (PDF)</span>
      </label>
      <?php endif; ?>

      <div id="progressBox" class="mt-4 hidden">
        <div class="flex justify-between text-xs text-slate-600 mb-1">
          <span id="progressLabel">Preparing…</span>
          <span id="progressCount">0 / 0</span>
        </div>
        <div class="bg-slate-200 rounded-full h-3 overflow-hidden">
          <div id="progressBar" class="bg-indigo-600 h-3 transition-all duration-300" style="width:0%"></div>
        </div>
        <div id="progressDetails" class="mt-2 max-h-40 overflow-y-auto text-xs font-mono bg-slate-50 border border-slate-200 rounded p-2 space-y-0.5"></div>
      </div>

      <div class="mt-4 flex gap-2 items-center">
        <button type="button" id="startBtn" class="btn btn-primary">Send Emails</button>
        <button type="button" id="stopBtn" class="btn btn-danger hidden">Stop</button>
        <?php if ($phase === 'written'): ?>
          <a href="/phdportal/admin/admit_cards.php" class="btn btn-secondary">Admit Cards</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 class="font-semibold mb-2">Recipients</h3>
    <div class="text-4xl font-bold text-indigo-700"><?= $count ?></div>
    <p class="text-xs text-slate-500 mt-1"><?= h($countLabel) ?> — <?= $isIntl ? 'International' : 'Indian' ?></p>

    <hr class="my-3 border-slate-200">
    <h3 class="font-semibold mb-2 text-sm">SMTP</h3>
    <div class="text-xs text-slate-600 space-y-0.5">
      <div>Host: <code class="bg-slate-100 px-1"><?= h(SMTP_HOST) ?>:<?= (int)SMTP_PORT ?></code></div>
      <div>From: <code class="bg-slate-100 px-1"><?= h(SMTP_FROM) ?></code></div>
      <div class="text-slate-500 italic">Edit <code>src/config.php</code> to set real credentials.</div>
    </div>

    <hr class="my-3 border-slate-200">
    <h3 class="font-semibold mb-2 text-sm">Recent Batches</h3>
    <div class="space-y-2 max-h-80 overflow-y-auto">
      <?php foreach ($logs as $lg): ?>
        <div class="text-xs border-l-2 border-indigo-300 pl-2 py-0.5">
          <div class="font-semibold capitalize"><?= h($lg['phase']) ?> · <?= (int)$lg['recipient_count'] ?> sent</div>
          <div class="text-slate-500"><?= h($lg['sent_at']) ?> · <?= h($lg['status']) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$logs): ?><p class="text-xs text-slate-500">No emails sent yet.</p><?php endif; ?>
    </div>
  </div>
</div>

<script src="/phdportal/assets/js/admit_card.js"></script>
<script>
const DELAY_MIN = <?= (int)MAIL_DELAY_MIN_MS ?>;
const DELAY_MAX = <?= (int)MAIL_DELAY_MAX_MS ?>;
const PHASE = <?= json_encode($phase) ?>;
const IS_INTL = <?= (int)$isIntl ?>;
let stopRequested = false;

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function randDelay() { return DELAY_MIN + Math.floor(Math.random() * (DELAY_MAX - DELAY_MIN + 1)); }

function appendDetail(text, cls) {
  const div = document.createElement('div');
  div.className = cls || 'text-slate-700';
  div.textContent = text;
  const box = document.getElementById('progressDetails');
  box.appendChild(div);
  box.scrollTop = box.scrollHeight;
}

async function buildAdmitCardB64(cand, ctx) {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  await AdmitCard.render(doc, cand, true, ctx);
  const dataUri = doc.output('datauristring');
  return dataUri.split(',', 2)[1] || '';
}

$('#startBtn').on('click', async function() {
  const subject = $('#em_subject').val().trim();
  const body    = $('#em_body').val().trim();
  if (!subject || !body) { alert('Subject and body are required.'); return; }
  if (!confirm('Send emails to ' + <?= $count ?> + ' recipient(s)?\n\nRandom delay between sends: ' + (DELAY_MIN/1000).toFixed(1) + '–' + (DELAY_MAX/1000).toFixed(1) + 's')) return;
  const password = prompt('Re-enter your admin password to confirm sending:');
  if (password === null) return;
  if (!password) { alert('Password is required.'); return; }

  stopRequested = false;
  $('#startBtn').prop('disabled', true).addClass('opacity-50');
  $('#stopBtn').removeClass('hidden');
  $('#progressBox').removeClass('hidden');
  $('#progressDetails').empty();
  $('#progressLabel').text('Verifying password & loading recipient list…');

  // 1) Build queue (also verifies admin password server-side)
  let queueRes;
  try {
    queueRes = await $.post('/phdportal/api/email_queue.php',
      { csrf: window.CSRF_TOKEN, phase: PHASE, is_international: IS_INTL, subject, body, password });
  } catch (e) { alert('Failed to build queue.'); resetUi(); return; }
  if (!queueRes.ok) { alert(queueRes.error || 'Queue failed'); resetUi(); return; }

  const recipients = queueRes.recipients;
  const batchId = queueRes.batch_id;
  const total = recipients.length;
  if (total === 0) { alert('No recipients.'); resetUi(); return; }

  // Pre-load admit card assets once (written phase, if checkbox is on).
  const attachAdmit = (PHASE === 'written') && $('#em_attach_admit').is(':checked');
  const admitCtx = {
    intakeName: queueRes.intake_name,
    examDatetime: queueRes.exam_datetime,
    entranceMode: queueRes.entrance_mode,
  };
  if (attachAdmit) {
    $('#progressLabel').text('Loading admit card assets…');
    await AdmitCard.ensureAssets();
  }

  let sent = 0, failed = 0;
  $('#progressCount').text('0 / ' + total);

  for (let i = 0; i < total; i++) {
    if (stopRequested) break;
    const r = recipients[i];
    $('#progressLabel').text('Sending ' + (i+1) + ' of ' + total + ' → ' + r.email);

    const payload = {
      csrf: window.CSRF_TOKEN, batch_id: batchId, cand_id: r.id, subject, body
    };
    if (attachAdmit) {
      try {
        payload.attachment = await buildAdmitCardB64(r, admitCtx);
        payload.attachment_name = 'AdmitCard_' + r.dept_reg_no + '.pdf';
      } catch (e) {
        appendDetail('! ' + r.dept_reg_no + ' — admit card build failed, sending without attachment', 'text-amber-700');
      }
    }

    try {
      const res = await $.post('/phdportal/api/email_send_one.php', payload);
      if (res.ok) { sent++; appendDetail('✓ ' + r.dept_reg_no + ' — ' + r.email, 'text-green-700'); }
      else { failed++; appendDetail('✗ ' + r.dept_reg_no + ' — ' + r.email + ' — ' + (res.error || 'failed'), 'text-rose-700'); }
    } catch (e) {
      failed++;
      appendDetail('✗ ' + r.dept_reg_no + ' — request error', 'text-rose-700');
    }
    const pct = Math.round(((i+1) / total) * 100);
    $('#progressBar').css('width', pct + '%');
    $('#progressCount').text((i+1) + ' / ' + total);

    if (i < total - 1 && !stopRequested) {
      const d = randDelay();
      $('#progressLabel').text('Waiting ' + (d/1000).toFixed(1) + 's before next send…');
      await sleep(d);
    }
  }

  const status = stopRequested ? 'stopped' : (failed ? 'partial' : 'sent');
  await $.post('/phdportal/api/email_finalize.php', { csrf: window.CSRF_TOKEN, batch_id: batchId, status });
  $('#progressLabel').text((stopRequested ? 'Stopped — ' : 'Done — ') + sent + ' sent, ' + failed + ' failed.');
  resetUi();
});

$('#stopBtn').on('click', function(){ stopRequested = true; $('#progressLabel').text('Stopping after current send…'); });

function resetUi() {
  $('#startBtn').prop('disabled', false).removeClass('opacity-50');
  $('#stopBtn').addClass('hidden');
}
</script>
<?php render_footer(); ?>
