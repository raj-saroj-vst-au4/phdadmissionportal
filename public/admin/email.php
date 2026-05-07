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
    'subject' => 'Entrance Exam Instructions — SJMSOM IITB PhD Admissions {{intake}}',
    'body' => "Dear {{name}},\n\nThis is to inform you that your application (Dept Reg No: {{dept_reg_no}}) has been shortlisted for the entrance examination as part of Shailesh J. Mehta School of Management (SJMSOM), IIT Bombay, PhD Admissions for {{intake}}.\n\nThe Ph.D admission {{intake}} written test is scheduled on May 11, 2026,\nIf you clear the written test, the interview will be on May 12, 2026.\n\nAll the candidates are requested to bring the below documents during admission process:\n1.	Hard copy of your Ph.D application.\n2.	Print out of attached Admit Card.\n3.	Available scorecards of National level exams such as UGC/IIT/IISc/IIIT etc.\n4.	Marksheets of all semesters of UG and PG Programme.\n5.	Degree certificate of UG and PG Programme.\n6.	Hard copy of Research Proposal as per the format given in the link - https://www.som.iitb.ac.in/wp-content/uploads/2018/10/Sample-Research-Proposal.pdf (Statement of Proposal is not acceptable)\n7.	Work Experience Certificate, (if applicable).\n8.	Persons with Disability Certificate (for PwD category).\n9.	No Objection certificate from employer for students admitted in CT/SW/EX/IS.\n10.	Sponsorship Certificate for Sponsored / EX category students.\n11.	Caste Certificate for OBC-NC/SC/ST.\n12.	EWS certificate issued by the Competent Authority in the prescribed format.\n13.	Any other relevant certificates.\n\n\nBest regards,\nSJMSOM Admissions Office\nIIT Bombay",
  ],
  'interview' => [
    'subject' => 'Interview Shortlist — SJMSOM IITB PhD Admissions {{intake}}',
    'body' => "Dear {{name}},\n\nCongratulations! You have been shortlisted for the personal interview round of SJMSOM PhD Admissions {{intake}}.\n(Dept Reg No: {{dept_reg_no}})\n\nPlease watch this inbox for further interview details.\n\nBest regards,\nSJMSOM Admissions Office\nIIT Bombay",
  ],
  'panel_invite' => [
    'subject' => 'Panel Coordinator Invitation — SJMSOM IITB PhD Admissions {{intake}}',
    'body' => "Dear {{full_name}},\n\nYou have been invited to serve as a Panel Coordinator for SJMSOM PhD Admissions {{intake}}.\n\nPanel: {{panel_code}} — {{panel_area}}\n\nPlease use the credentials below to log in to the SJMSOM PhD Admissions Portal and access your panel:\n\nLogin URL : {{login_url}}\nUsername  : {{username}}\nPassword  : {{password}}\n\nFor security, please change your password after first login.\n\nIf you have any questions, please reply to this email.\n\nBest regards,\nSJMSOM Admissions Office\nIIT Bombay",
  ],
];
if (!isset($presets[$phase])) $phase = 'written';
$preset = $presets[$phase];

// Count recipients
if ($phase === 'written') {
    $count = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=? AND screening_status='Yes' AND email IS NOT NULL AND email<>''", [$intake['id'],$isIntl])['c'];
    $countLabel = 'Shortlisted candidates with email';
} elseif ($phase === 'interview') {
    $count = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=? AND screening_status='Yes' AND written_marks IS NOT NULL AND passed_cutoff=1 AND email IS NOT NULL AND email<>''", [$intake['id'],$isIntl])['c'];
    $countLabel = 'Candidates passing cutoff with email';
} else { // panel_invite
    // Same dataset as /admin/panels.php (Panel Members) and /admin/users.php
    // (panel-role users): every active user with role='panel'. Email is required
    // to actually send, so the count surfaces only those with an email on file.
    $count = (int)one("SELECT COUNT(*) c FROM users
                       WHERE role='panel' AND active=1
                       AND email IS NOT NULL AND email<>''")['c'];
    $countLabel = 'Active panel coordinators with email';
}

$logs = all('SELECT * FROM email_log WHERE intake_id=? ORDER BY id DESC LIMIT 15', [$intake['id']]);

render_header('Email Communication', $u);
?>
<h1 class="text-2xl font-semibold mb-4">Email Communication</h1>

<?php if ($phase !== 'panel_invite'): ?>
<div class="mb-3 flex items-center gap-4 text-sm">
  <label class="flex items-center gap-2 cursor-pointer">
    <input type="radio" name="aud" value="0" <?= !$isIntl ? 'checked' : '' ?> onchange="location.href='?phase=<?= h($phase) ?>&intl=0'">
    <span>Indian Candidates</span>
  </label>
</div>
<?php endif; ?>

<div class="flex border-b border-slate-200 mb-5 gap-1">
  <a href="?phase=written&intl=<?= $isIntl ?>" class="px-4 py-2 text-sm font-medium rounded-t <?= $phase==='written' ? 'bg-white border border-b-white border-slate-200 text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">Written Exam Instructions</a>
  <a href="?phase=interview&intl=<?= $isIntl ?>" class="px-4 py-2 text-sm font-medium rounded-t <?= $phase==='interview' ? 'bg-white border border-b-white border-slate-200 text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">Interview Shortlist</a>
  <a href="?phase=panel_invite" class="px-4 py-2 text-sm font-medium rounded-t <?= $phase==='panel_invite' ? 'bg-white border border-b-white border-slate-200 text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>">Panel Coordinator Invite</a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
  <div class="card md:col-span-2">
    <form id="emailForm">
      <label class="text-sm font-medium">Subject</label>
      <input id="em_subject" name="subject" value="<?= h($preset['subject']) ?>" required>
      <label class="text-sm font-medium mt-3 block">Body</label>
      <textarea id="em_body" name="body" rows="14" required><?= h($preset['body']) ?></textarea>
      <?php if ($phase === 'panel_invite'): ?>
        <p class="text-xs text-slate-500 mt-1">Placeholders: <code>{{full_name}}</code>, <code>{{username}}</code>, <code>{{password}}</code>, <code>{{login_url}}</code>, <code>{{panel_code}}</code>, <code>{{panel_area}}</code>, <code>{{intake}}</code></p>
        <p class="text-xs text-amber-700 mt-1">Sending will <strong>generate a fresh random password</strong> for each coordinator and overwrite their existing password. Plaintext passwords are sent only via this email and not stored on the server.</p>
      <?php else: ?>
        <p class="text-xs text-slate-500 mt-1">Placeholders: <code>{{name}}</code>, <code>{{dept_reg_no}}</code>, <code>{{intake}}</code></p>
      <?php endif; ?>

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

  <div class="space-y-4">
    <div class="card">
      <h3 class="font-semibold mb-2">Recipients</h3>
      <div class="text-4xl font-bold text-indigo-700"><?= $count ?></div>
      <p class="text-xs text-slate-500 mt-1"><?= h($countLabel) ?><?= $phase === 'panel_invite' ? '' : ' — ' . ($isIntl ? 'International' : 'Indian') ?></p>

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
          <div class="text-xs border-l-2 border-indigo-300 pl-2 py-0.5 flex items-start justify-between gap-2">
            <div>
              <div class="font-semibold capitalize"><?= h($lg['phase']) ?> · <?= (int)$lg['recipient_count'] ?> sent</div>
              <div class="text-slate-500"><?= h($lg['sent_at']) ?> · <?= h($lg['status']) ?></div>
            </div>
            <button type="button" class="batch-view-btn text-indigo-700 hover:underline shrink-0" data-batch-id="<?= (int)$lg['id'] ?>">View</button>
          </div>
        <?php endforeach; ?>
        <?php if (!$logs): ?><p class="text-xs text-slate-500">No emails sent yet.</p><?php endif; ?>
      </div>
    </div>

    <?php if ($phase !== 'panel_invite'): ?>
    <div class="card">
      <h3 class="font-semibold mb-1 text-sm">Resend to specific RMG numbers</h3>
      <p class="text-xs text-slate-500 mb-2">Comma- or newline-separated. Max 50. Uses the subject &amp; body above.</p>
      <textarea id="rs_rmg_list" rows="3" class="font-mono text-xs" placeholder="RMG202610001, RMG202610045"></textarea>
      <?php if ($phase === 'written'): ?>
      <label class="flex items-center gap-2 mt-2 text-xs cursor-pointer">
        <input type="checkbox" id="rs_attach_admit" checked>
        <span>Attach each candidate's admit card (PDF)</span>
      </label>
      <?php endif; ?>
      <div class="mt-3">
        <button type="button" id="rsStartBtn" class="btn btn-primary btn-sm">Resend</button>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script src="/phdportal/assets/js/admit_card.js?v=<?= filemtime(PUBLIC_ROOT . '/assets/js/admit_card.js') ?>"></script>
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

async function runBatch(queueRes, attachAdmit) {
  const subject = $('#em_subject').val().trim();
  const body    = $('#em_body').val().trim();
  const recipients = queueRes.recipients;
  const batchId = queueRes.batch_id;
  const total = recipients.length;
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

  const isPanelInvite = (PHASE === 'panel_invite');
  const sendUrl = isPanelInvite
    ? '/phdportal/api/email_panel_send_one.php'
    : '/phdportal/api/email_send_one.php';

  for (let i = 0; i < total; i++) {
    if (stopRequested) break;
    const r = recipients[i];
    const tag = isPanelInvite ? (r.username || '') : (r.dept_reg_no || '');
    $('#progressLabel').text('Sending ' + (i+1) + ' of ' + total + ' → ' + r.email);

    const payload = isPanelInvite
      ? { csrf: window.CSRF_TOKEN, batch_id: batchId, user_id: r.id, password: r.password, subject, body }
      : { csrf: window.CSRF_TOKEN, batch_id: batchId, cand_id: r.id, subject, body };
    if (attachAdmit && !isPanelInvite) {
      try {
        payload.attachment = await buildAdmitCardB64(r, admitCtx);
        payload.attachment_name = 'AdmitCard_' + r.dept_reg_no + '.pdf';
      } catch (e) {
        appendDetail('! ' + tag + ' — admit card build failed, sending without attachment', 'text-amber-700');
      }
    }

    try {
      const res = await $.post(sendUrl, payload);
      if (res.ok) { sent++; appendDetail('✓ ' + tag + ' — ' + r.email, 'text-green-700'); }
      else { failed++; appendDetail('✗ ' + tag + ' — ' + r.email + ' — ' + (res.error || 'failed'), 'text-rose-700'); }
    } catch (e) {
      failed++;
      appendDetail('✗ ' + tag + ' — request error', 'text-rose-700');
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
}

function startUi() {
  stopRequested = false;
  $('#startBtn,#rsStartBtn').prop('disabled', true).addClass('opacity-50');
  $('#stopBtn').removeClass('hidden');
  $('#progressBox').removeClass('hidden');
  $('#progressDetails').empty();
}

function resetUi() {
  $('#startBtn,#rsStartBtn').prop('disabled', false).removeClass('opacity-50');
  $('#stopBtn').addClass('hidden');
}

$('#startBtn').on('click', async function() {
  const subject = $('#em_subject').val().trim();
  const body    = $('#em_body').val().trim();
  if (!subject || !body) { alert('Subject and body are required.'); return; }
  if (!confirm('Send emails to ' + <?= $count ?> + ' recipient(s)?\n\nRandom delay between sends: ' + (DELAY_MIN/1000).toFixed(1) + '–' + (DELAY_MAX/1000).toFixed(1) + 's')) return;
  const password = prompt('Re-enter your admin password to confirm sending:');
  if (password === null) return;
  if (!password) { alert('Password is required.'); return; }

  startUi();
  $('#progressLabel').text('Verifying password & loading recipient list…');

  let queueRes;
  const queueUrl = (PHASE === 'panel_invite')
    ? '/phdportal/api/email_panel_queue.php'
    : '/phdportal/api/email_queue.php';
  try {
    queueRes = await $.post(queueUrl,
      { csrf: window.CSRF_TOKEN, phase: PHASE, is_international: IS_INTL, subject, body, password });
  } catch (e) { alert('Failed to build queue.'); resetUi(); return; }
  if (!queueRes.ok) { alert(queueRes.error || 'Queue failed'); resetUi(); return; }
  if (queueRes.recipients.length === 0) { alert('No recipients.'); resetUi(); return; }

  const attachAdmit = (PHASE === 'written') && $('#em_attach_admit').is(':checked');
  await runBatch(queueRes, attachAdmit);
  resetUi();
});

$('#rsStartBtn').on('click', async function() {
  const subject = $('#em_subject').val().trim();
  const body    = $('#em_body').val().trim();
  const rmgList = $('#rs_rmg_list').val().trim();
  if (!subject || !body) { alert('Subject and body are required.'); return; }
  if (!rmgList) { alert('Enter at least one RMG number.'); return; }
  const preview = rmgList.split(/[\s,;]+/).filter(Boolean);
  if (!confirm('Resend to ' + preview.length + ' RMG number(s)?')) return;
  const password = prompt('Re-enter your admin password to confirm sending:');
  if (password === null) return;
  if (!password) { alert('Password is required.'); return; }

  startUi();
  $('#progressLabel').text('Verifying password & looking up candidates…');

  let queueRes;
  try {
    queueRes = await $.post('/phdportal/api/email_resend_queue.php',
      { csrf: window.CSRF_TOKEN, phase: PHASE, rmg_list: rmgList, subject, body, password });
  } catch (e) { alert('Failed to build resend queue.'); resetUi(); return; }
  if (!queueRes.ok) {
    let msg = queueRes.error || 'Queue failed';
    if (queueRes.missing && queueRes.missing.length) msg += '\nNot found: ' + queueRes.missing.join(', ');
    if (queueRes.no_email && queueRes.no_email.length) msg += '\nNo email: ' + queueRes.no_email.join(', ');
    alert(msg);
    resetUi();
    return;
  }
  if (queueRes.missing && queueRes.missing.length) {
    appendDetail('! Not found in DB: ' + queueRes.missing.join(', '), 'text-amber-700');
  }
  if (queueRes.no_email && queueRes.no_email.length) {
    appendDetail('! No email on file: ' + queueRes.no_email.join(', '), 'text-amber-700');
  }

  const attachAdmit = (PHASE === 'written') && $('#rs_attach_admit').is(':checked');
  await runBatch(queueRes, attachAdmit);
  resetUi();
});

$('#stopBtn').on('click', function(){ stopRequested = true; $('#progressLabel').text('Stopping after current send…'); });

// ---- Batch view modal ----
let currentFailedRmgs = [];

$(document).on('click', '.batch-view-btn', async function() {
  const batchId = $(this).data('batch-id');
  $('#batchModalTitle').text('Batch #' + batchId);
  $('#batchModalMeta').text('Loading…');
  $('#batchModalBody').empty();
  $('#batchCopyFailedBtn').addClass('hidden');
  currentFailedRmgs = [];
  $('#batchModalBackdrop').removeClass('hidden');

  let res;
  try {
    res = await $.get('/phdportal/api/email_log_view.php', { batch_id: batchId });
  } catch (e) { $('#batchModalMeta').text('Failed to load.'); return; }
  if (!res.ok) { $('#batchModalMeta').text(res.error || 'Failed to load.'); return; }

  const b = res.batch, c = res.counts;
  $('#batchModalTitle').text('Batch #' + b.id + ' — ' + b.phase);
  $('#batchModalMeta').html(
    '<span class="text-slate-600">' + b.sent_at + ' · ' + b.status + '</span>' +
    ' · <span class="text-emerald-700">' + c.sent + ' sent</span>' +
    ' · <span class="text-rose-700">' + c.failed + ' failed</span>'
  );

  const failedRmgs = [];
  const $body = $('#batchModalBody');
  if (res.recipients.length === 0) {
    $body.html('<p class="text-sm text-slate-500 p-4">No per-recipient log for this batch.</p>');
  } else {
    const rows = res.recipients.map(r => {
      const isFail = r.status === 'failed';
      if (isFail && r.dept_reg_no) failedRmgs.push(r.dept_reg_no);
      const cls = isFail ? 'bg-rose-50 text-rose-900' : '';
      const statusBadge = isFail
        ? '<span class="px-1.5 py-0.5 rounded bg-rose-200 text-rose-800 text-xs">failed</span>'
        : '<span class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-xs">sent</span>';
      const err = isFail && r.error_msg ? '<div class="text-xs text-rose-700 mt-0.5">' + $('<div>').text(r.error_msg).html() + '</div>' : '';
      return '<tr class="' + cls + '">' +
        '<td class="px-2 py-1 font-mono text-xs">' + (r.dept_reg_no || '') + '</td>' +
        '<td class="px-2 py-1 text-xs">' + (r.email || '') + err + '</td>' +
        '<td class="px-2 py-1">' + statusBadge + '</td>' +
        '<td class="px-2 py-1 text-xs text-slate-500">' + (r.sent_at || '') + '</td>' +
      '</tr>';
    }).join('');
    $body.html(
      '<table class="w-full text-sm">' +
        '<thead class="bg-slate-50 text-slate-600 text-xs uppercase"><tr>' +
          '<th class="px-2 py-1 text-left">RMG</th>' +
          '<th class="px-2 py-1 text-left">Email</th>' +
          '<th class="px-2 py-1 text-left">Status</th>' +
          '<th class="px-2 py-1 text-left">Sent At</th>' +
        '</tr></thead>' +
        '<tbody>' + rows + '</tbody>' +
      '</table>'
    );
  }

  currentFailedRmgs = failedRmgs;
  if (failedRmgs.length > 0) {
    $('#batchCopyFailedBtn').removeClass('hidden').text('Copy ' + failedRmgs.length + ' failed RMG(s) to Resend');
  }
});

$('#batchCopyFailedBtn').on('click', function() {
  if (currentFailedRmgs.length === 0) return;
  $('#rs_rmg_list').val(currentFailedRmgs.join(', '));
  $('#batchModalBackdrop').addClass('hidden');
  document.getElementById('rs_rmg_list').scrollIntoView({ behavior: 'smooth', block: 'center' });
  $('#rs_rmg_list').focus();
});

$('#batchModalCloseBtn, #batchModalBackdrop').on('click', function(e) {
  if (e.target === this) $('#batchModalBackdrop').addClass('hidden');
});
</script>

<div id="batchModalBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[85vh] flex flex-col">
    <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
      <div>
        <h3 id="batchModalTitle" class="text-lg font-semibold">Batch</h3>
        <div id="batchModalMeta" class="text-xs"></div>
      </div>
      <button type="button" id="batchModalCloseBtn" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">&times;</button>
    </div>
    <div id="batchModalBody" class="flex-1 overflow-y-auto"></div>
    <div class="px-5 py-3 border-t border-slate-200 flex items-center justify-end gap-2">
      <button type="button" id="batchCopyFailedBtn" class="btn btn-primary btn-sm hidden">Copy failed RMGs to Resend</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="$('#batchModalBackdrop').addClass('hidden')">Close</button>
    </div>
  </div>
</div>
<?php render_footer(); ?>
