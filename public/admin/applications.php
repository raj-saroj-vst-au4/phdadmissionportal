<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

if (!is_dir(UPLOAD_APP_DIR)) mkdir(UPLOAD_APP_DIR, 0775, true);

// Bulk upload handler - uploads multiple PDFs, assigns by filename (RMG no)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    check_csrf();
    $ok = 0; $skip = 0; $errs = [];
    if (!empty($_FILES['app_pdfs']['name'])) {
        $files = $_FILES['app_pdfs'];
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) { $skip++; continue; }
            $raw = $files['name'][$i];
            $base = pathinfo($raw, PATHINFO_FILENAME);
            $dept = trim($base);
            // Verify candidate exists
            $cand = one('SELECT id, dept_reg_no FROM candidates WHERE intake_id=? AND dept_reg_no=?', [$intake['id'], $dept]);
            if (!$cand) {
                $skip++;
                $errs[] = "No candidate for filename '$raw'";
                continue;
            }
            $fname = preg_replace('/[^A-Za-z0-9_-]/', '_', $cand['dept_reg_no']) . '.pdf';
            $dest = UPLOAD_APP_DIR . '/' . $fname;
            if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                q('UPDATE candidates SET application_pdf=? WHERE id=?', [$fname, $cand['id']]);
                $ok++;
            } else {
                $skip++;
            }
        }
    }
    $msg = "Uploaded $ok PDF(s). Skipped: $skip.";
    if ($errs) $msg .= ' Issues: ' . implode('; ', array_slice($errs, 0, 5));
    flash_set($msg, $ok ? 'success' : 'error');
    redirect('/phdportal/admin/applications.php');
}

// Delete application PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_app'])) {
    check_csrf();
    $cid = (int)$_POST['cand_id'];
    $cand = one('SELECT * FROM candidates WHERE id=? AND intake_id=?', [$cid, $intake['id']]);
    if ($cand && $cand['application_pdf']) {
        $path = UPLOAD_APP_DIR . '/' . $cand['application_pdf'];
        if (is_file($path)) @unlink($path);
        q('UPDATE candidates SET application_pdf=NULL WHERE id=?', [$cid]);
        flash_set('Application PDF deleted.', 'success');
    }
    redirect('/phdportal/admin/applications.php');
}

$cands = all('SELECT id, serial_no, dept_reg_no, name, application_pdf, photo FROM candidates WHERE intake_id=? ORDER BY serial_no, id', [$intake['id']]);
$total = count($cands);
$withPdf = count(array_filter($cands, fn($r)=>!empty($r['application_pdf'])));
$withPhoto = count(array_filter($cands, fn($r)=>!empty($r['photo'])));
$missing = $total - $withPdf;
$missingPhotos = $total - $withPhoto;
$missingRegs = array_values(array_map(fn($r)=>$r['dept_reg_no'], array_filter($cands, fn($r)=>empty($r['application_pdf']))));
$missingPhotoRegs = array_values(array_map(fn($r)=>$r['dept_reg_no'], array_filter($cands, fn($r)=>empty($r['photo']))));

render_header('Applications', $u);
?>
<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <div>
    <h1 class="text-2xl font-semibold">Applications &amp; Photos Upload</h1>
    <p class="text-sm text-slate-500 mt-0.5">
      PDFs: <?= $withPdf ?> / <?= $total ?> &nbsp;·&nbsp;
      Photos: <?= $withPhoto ?> / <?= $total ?>
    </p>
  </div>
</div>

<?php if ($missing > 0): ?>
<div class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 flex items-start gap-2">
  <svg class="mt-0.5 flex-none text-amber-700" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
  <div class="flex-1">
    <div class="text-sm font-semibold text-amber-900"><?= $missing ?> candidate(s) still missing an application PDF</div>
    <details class="mt-1 text-xs text-amber-900/80">
      <summary class="cursor-pointer hover:underline">Show pending Dept Reg Nos</summary>
      <div class="font-mono mt-1 break-all"><?= h(implode(', ', $missingRegs)) ?></div>
    </details>
  </div>
</div>
<?php endif; ?>

<?php if ($missingPhotos > 0): ?>
<div class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 flex items-start gap-2">
  <svg class="mt-0.5 flex-none text-amber-700" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
  <div class="flex-1">
    <div class="text-sm font-semibold text-amber-900"><?= $missingPhotos ?> candidate(s) still missing a photo</div>
    <details class="mt-1 text-xs text-amber-900/80">
      <summary class="cursor-pointer hover:underline">Show pending Dept Reg Nos</summary>
      <div class="font-mono mt-1 break-all"><?= h(implode(', ', $missingPhotoRegs)) ?></div>
    </details>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4">
  <h3 class="font-semibold mb-2">Bulk Upload Applications</h3>
  <p class="text-sm text-slate-600 mb-3">
    Select any number of PDF files. <strong>Each filename must match the candidate's Dept Reg No (RMG number)</strong>
    — e.g. <code class="bg-slate-100 px-1 rounded">RMG202520001.pdf</code>. Large batches are uploaded in chunks so
    server size limits don't apply.
  </p>
  <div class="flex items-center gap-3 flex-wrap">
    <input id="appFiles" type="file" accept=".pdf,application/pdf" multiple class="flex-1 min-w-[240px]">
    <button id="appUploadBtn" type="button" class="btn btn-primary" disabled>Upload All</button>
    <button id="appCancelBtn" type="button" class="btn btn-secondary hidden">Cancel</button>
  </div>
  <div id="appOverall" class="mt-4 hidden">
    <div class="flex items-center justify-between text-xs mb-1">
      <span id="appOverallLabel" class="text-slate-600">Starting…</span>
      <span id="appOverallPct" class="font-mono text-slate-500">0%</span>
    </div>
    <div class="h-2 rounded bg-slate-200 overflow-hidden">
      <div id="appOverallBar" class="h-full bg-indigo-600 transition-all" style="width:0%"></div>
    </div>
  </div>
  <div id="appFileList" class="mt-3 space-y-1.5 max-h-80 overflow-y-auto"></div>
</div>

<div class="card mb-4">
  <h3 class="font-semibold mb-2">Bulk Upload Candidate Photos</h3>
  <p class="text-sm text-slate-600 mb-3">
    Select any number of photo files (JPG / PNG / WEBP). <strong>Each filename must match the candidate's Dept Reg No</strong>
    — e.g. <code class="bg-slate-100 px-1 rounded">RMG202520001.jpg</code>. Uploaded in chunks; up to 25 MB per image.
  </p>
  <div class="flex items-center gap-3 flex-wrap">
    <input id="photoFiles" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple class="flex-1 min-w-[240px]">
    <button id="photoUploadBtn" type="button" class="btn btn-primary" disabled>Upload All</button>
    <button id="photoCancelBtn" type="button" class="btn btn-secondary hidden">Cancel</button>
  </div>
  <div id="photoOverall" class="mt-4 hidden">
    <div class="flex items-center justify-between text-xs mb-1">
      <span id="photoOverallLabel" class="text-slate-600">Starting…</span>
      <span id="photoOverallPct" class="font-mono text-slate-500">0%</span>
    </div>
    <div class="h-2 rounded bg-slate-200 overflow-hidden">
      <div id="photoOverallBar" class="h-full bg-indigo-600 transition-all" style="width:0%"></div>
    </div>
  </div>
  <div id="photoFileList" class="mt-3 space-y-1.5 max-h-80 overflow-y-auto"></div>
</div>

<script>
(function(){
  const CHUNK = 1024 * 1024 * 1.5; // 1.5 MB, below PHP's upload_max_filesize=2M

  function fmtSize(b) {
    if (b < 1024) return b + ' B';
    if (b < 1024*1024) return (b/1024).toFixed(1) + ' KB';
    return (b/1024/1024).toFixed(2) + ' MB';
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  async function postJson(url, form) {
    const r = await fetch(url, { method: 'POST', body: form, credentials: 'same-origin' });
    let data;
    try { data = await r.json(); }
    catch { throw new Error('Server returned non-JSON (HTTP ' + r.status + ')'); }
    return data;
  }

  function makeUploader(opts) {
    const { endpoint, acceptRegex, fileInputId, uploadBtnId, cancelBtnId,
            listId, overallId, overallBarId, overallPctId, overallLabelId } = opts;
    const fileInput = document.getElementById(fileInputId);
    const uploadBtn = document.getElementById(uploadBtnId);
    const cancelBtn = document.getElementById(cancelBtnId);
    const listEl    = document.getElementById(listId);
    const overall   = document.getElementById(overallId);
    const ovBar     = document.getElementById(overallBarId);
    const ovPct     = document.getElementById(overallPctId);
    const ovLabel   = document.getElementById(overallLabelId);

    let cancelled = false;

    fileInput.addEventListener('change', () => {
      listEl.innerHTML = '';
      uploadBtn.disabled = fileInput.files.length === 0;
      [...fileInput.files].forEach((f, i) => {
        const row = document.createElement('div');
        row.className = 'border border-slate-200 rounded px-2 py-1.5 text-xs';
        row.dataset.idx = i;
        row.innerHTML = `
          <div class="flex items-center justify-between gap-2">
            <div class="font-mono truncate flex-1" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</div>
            <div class="text-slate-400 flex-none">${fmtSize(f.size)}</div>
            <div class="status flex-none text-slate-500 w-20 text-right">Queued</div>
          </div>
          <div class="mt-1 h-1 bg-slate-100 rounded overflow-hidden">
            <div class="bar h-full bg-slate-300" style="width:0%"></div>
          </div>`;
        listEl.appendChild(row);
      });
    });

    uploadBtn.addEventListener('click', async () => {
      const files = [...fileInput.files];
      if (!files.length) return;
      cancelled = false;
      uploadBtn.disabled = true;
      cancelBtn.classList.remove('hidden');
      overall.classList.remove('hidden');
      ovLabel.textContent = 'Starting…';

      const totalBytes = files.reduce((a,f)=>a+f.size, 0);
      let doneBytes = 0;
      let okCount = 0, errCount = 0;
      const failures = [];

      for (let i = 0; i < files.length; i++) {
        if (cancelled) break;
        const f = files[i];
        const row = listEl.querySelector(`[data-idx="${i}"]`);
        const bar = row.querySelector('.bar');
        const stat = row.querySelector('.status');
        stat.textContent = 'Uploading';
        stat.className = 'status flex-none text-indigo-600 w-20 text-right';

        try {
          await uploadOne(f, (fileBytesDone) => {
            bar.style.width = Math.round(fileBytesDone / f.size * 100) + '%';
            bar.className = 'bar h-full bg-indigo-500';
            const pctO = Math.round((doneBytes + fileBytesDone) / totalBytes * 100);
            ovBar.style.width = pctO + '%';
            ovPct.textContent = pctO + '%';
            ovLabel.textContent = `Uploading ${i+1} of ${files.length} — ${f.name}`;
          });
          stat.textContent = 'Done';
          stat.className = 'status flex-none text-emerald-600 font-semibold w-20 text-right';
          bar.className = 'bar h-full bg-emerald-500';
          bar.style.width = '100%';
          okCount++;
        } catch (e) {
          stat.textContent = 'Failed';
          stat.className = 'status flex-none text-rose-600 font-semibold w-20 text-right';
          bar.className = 'bar h-full bg-rose-400';
          row.querySelector('.truncate').title = e.message || 'Upload failed';
          const err = document.createElement('div');
          err.className = 'text-[11px] text-rose-600 mt-0.5';
          err.textContent = e.message || 'Upload failed';
          row.appendChild(err);
          errCount++;
          failures.push(f.name);
        }
        doneBytes += f.size;
      }

      ovBar.style.width = '100%';
      ovPct.textContent = '100%';
      cancelBtn.classList.add('hidden');
      uploadBtn.disabled = false;

      const summary = cancelled
        ? `Cancelled — ${okCount} uploaded, ${errCount} failed`
        : (errCount > 0
            ? `⚠ Completed with ${errCount} failure(s) — ${okCount} uploaded`
            : `✓ Complete — ${okCount} uploaded`);
      ovLabel.textContent = summary;
      ovLabel.className = errCount > 0 ? 'text-rose-700 font-semibold' : 'text-emerald-700 font-semibold';

      if (okCount > 0) {
        const btn = document.createElement('button');
        btn.className = 'btn btn-secondary ml-2 text-xs';
        btn.textContent = 'Reload page';
        btn.onclick = () => location.reload();
        ovLabel.appendChild(document.createTextNode(' '));
        ovLabel.appendChild(btn);
      }
    });

    cancelBtn.addEventListener('click', () => { cancelled = true; });

    async function uploadOne(file, onProgress) {
      if (!acceptRegex.test(file.name)) throw new Error('Unsupported file type');

      const initForm = new FormData();
      initForm.append('csrf', window.CSRF_TOKEN);
      initForm.append('action', 'init');
      initForm.append('name', file.name);
      initForm.append('size', file.size);
      const initRes = await postJson(endpoint, initForm);
      if (!initRes.ok) throw new Error(initRes.error || 'init failed');
      const uploadId = initRes.upload_id;

      const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK));
      for (let idx = 0; idx < totalChunks; idx++) {
        if (cancelled) throw new Error('Cancelled');
        const start = idx * CHUNK;
        const end   = Math.min(file.size, start + CHUNK);
        const slice = file.slice(start, end);
        const form = new FormData();
        form.append('csrf', window.CSRF_TOKEN);
        form.append('action', 'chunk');
        form.append('upload_id', uploadId);
        form.append('index', idx);
        form.append('data', slice, 'chunk.bin');
        const r = await postJson(endpoint, form);
        if (!r.ok) throw new Error(r.error || 'chunk failed');
        onProgress(end);
      }

      const finForm = new FormData();
      finForm.append('csrf', window.CSRF_TOKEN);
      finForm.append('action', 'finalize');
      finForm.append('upload_id', uploadId);
      finForm.append('total_chunks', totalChunks);
      const fin = await postJson(endpoint, finForm);
      if (!fin.ok) throw new Error(fin.error || 'finalize failed');
    }
  }

  makeUploader({
    endpoint: '/phdportal/api/upload_application_chunk.php',
    acceptRegex: /\.pdf$/i,
    fileInputId: 'appFiles', uploadBtnId: 'appUploadBtn', cancelBtnId: 'appCancelBtn',
    listId: 'appFileList', overallId: 'appOverall', overallBarId: 'appOverallBar',
    overallPctId: 'appOverallPct', overallLabelId: 'appOverallLabel',
  });

  makeUploader({
    endpoint: '/phdportal/api/upload_photo_chunk.php',
    acceptRegex: /\.(jpe?g|png|webp)$/i,
    fileInputId: 'photoFiles', uploadBtnId: 'photoUploadBtn', cancelBtnId: 'photoCancelBtn',
    listId: 'photoFileList', overallId: 'photoOverall', overallBarId: 'photoOverallBar',
    overallPctId: 'photoOverallPct', overallLabelId: 'photoOverallLabel',
  });
})();
</script>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full">
<thead><tr><th>Sr</th><th>Dept Reg No</th><th>Name</th><th>Application PDF</th><th>Photo</th><th></th></tr></thead>
<tbody>
<?php foreach ($cands as $r): ?>
<tr>
  <td><?= (int)$r['serial_no'] ?></td>
  <td class="font-mono text-xs"><?= h($r['dept_reg_no']) ?></td>
  <td><?= h($r['name']) ?></td>
  <td>
    <?php if ($r['application_pdf'] && is_file(UPLOAD_APP_DIR . '/' . $r['application_pdf'])): ?>
      <a href="/phdportal/uploads/applications/<?= h($r['application_pdf']) ?>" target="_blank"
         class="text-indigo-600 hover:underline inline-flex items-center gap-1 text-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
        <?= h($r['application_pdf']) ?>
      </a>
    <?php else: ?>
      <span class="text-xs text-rose-500">Not uploaded</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if (!empty($r['photo']) && is_file(UPLOAD_PHOTO_DIR . '/' . $r['photo'])): ?>
      <a href="/phdportal/uploads/photos/<?= h($r['photo']) ?>" target="_blank" title="<?= h($r['photo']) ?>">
        <img src="/phdportal/uploads/photos/<?= h($r['photo']) ?>" alt="" class="h-10 w-10 object-cover rounded border border-slate-200">
      </a>
    <?php else: ?>
      <span class="text-xs text-rose-500">Not uploaded</span>
    <?php endif; ?>
  </td>
  <td class="text-right">
    <?php if ($r['application_pdf']): ?>
    <form method="post" class="inline" onsubmit="return confirm('Delete this application PDF?');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="delete_app" value="1">
      <input type="hidden" name="cand_id" value="<?= (int)$r['id'] ?>">
      <button class="btn btn-danger text-xs">Delete</button>
    </form>
    <?php endif; ?>
    <a href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>" class="text-xs text-indigo-600 hover:underline ml-1">View</a>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$cands): ?><tr><td colspan="6" class="text-center py-6 text-slate-500">No candidates yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
<?php render_footer(); ?>
