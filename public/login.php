<?php
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/helpers.php';

if (current_user()) {
    redirect('/phdportal/dashboard.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if (login($u, $p)) {
        flash_set('Welcome back, ' . $u, 'success');
        redirect('/phdportal/dashboard.php');
    }
    $err = 'Invalid username or password';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sign In - <?= h(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body { font-family: 'Inter', system-ui, sans-serif; }
  .hero-bg {
    background-image: url('/phdportal/assets/img/login-bg.jpg');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
  }
  .hero-bg::before {
    content: '';
    position: absolute; inset: 0;
    background:
      linear-gradient(to bottom, rgba(15,23,42,.65) 0%, rgba(15,23,42,.15) 35%, rgba(15,23,42,.2) 65%, rgba(15,23,42,.75) 100%),
      linear-gradient(to right, rgba(15,23,42,.35) 0%, rgba(15,23,42,0) 50%);
  }
  .hero-bg h1, .hero-bg .feature-row, .hero-bg .text-indigo-200, .hero-bg .font-semibold {
    text-shadow: 0 2px 12px rgba(0,0,0,.55), 0 1px 3px rgba(0,0,0,.6);
  }
  .hero-bg .feature-icon {
    background: rgba(0,0,0,.35);
    border-color: rgba(255,255,255,.25);
    backdrop-filter: blur(4px);
  }
  .field-wrap { position: relative; }
  .field-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
  .field-wrap input {
    width: 100%;
    padding: 14px 14px 14px 46px;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-size: 15px;
    transition: all .18s ease;
    background: #fff;
  }
  .field-wrap input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99,102,241,.12); }
  .field-wrap input:focus ~ svg { color: #6366f1; }
  .btn-signin {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white;
    font-weight: 600;
    border-radius: 12px;
    border: none;
    font-size: 15px;
    cursor: pointer;
    transition: all .2s;
    box-shadow: 0 4px 14px rgba(79,70,229,.35);
    letter-spacing: .01em;
  }
  .btn-signin:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(79,70,229,.45); }
  .btn-signin:active { transform: translateY(0); }
  .cred-pill { display:inline-block; padding:2px 8px; background:#eef2ff; color:#4338ca; border-radius:6px; font-family:ui-monospace, monospace; font-size:12px; font-weight:500; }
  details > summary { list-style:none; cursor:pointer; }
  details > summary::-webkit-details-marker { display:none; }
  details[open] .chev { transform: rotate(180deg); }
  .chev { transition: transform .2s; }
  .feature-row { display:flex; align-items:center; gap:10px; color: rgba(255,255,255,.85); font-size: 14px; }
  .feature-icon {
    flex-shrink:0; width:28px; height:28px; border-radius:8px;
    background: rgba(255,255,255,.12); display:flex; align-items:center; justify-content:center;
    border: 1px solid rgba(255,255,255,.15);
  }
</style>
</head>
<body class="bg-slate-50">
<div class="min-h-screen flex">

  <!-- Left hero panel -->
  <div class="hero-bg relative hidden lg:flex lg:w-5/12 xl:w-1/2 flex-col justify-between p-12 text-white overflow-hidden">
    <div class="relative z-10 flex items-center gap-3">
      <div class="w-12 h-12 bg-dark/15 backdrop-blur-sm rounded-xl flex items-center justify-center font-bold text-xl border border-white/20">PhD</div>
      <div class="leading-tight">
        <div class="font-semibold">SJMSOM</div>
        <div class="text-xs text-indigo-200">IIT Bombay</div>
      </div>
    </div>

    <div class="relative z-10 space-y-5">
      <h1 class="text-4xl xl:text-5xl font-extrabold leading-tight">
        PhD Admissions<br>
        <!-- <span class="bg-gradient-to-r from-indigo-200 to-pink-200 bg-clip-text text-transparent">made simple.</span> -->
      </h1>
      <!-- <p class="text-indigo-100 text-base xl:text-lg max-w-md leading-relaxed">
        From application intake to final shortlisting — a single portal for the entire Spring &amp; Autumn admissions cycle at Shailesh J. Mehta School of Management.
      </p> -->

      <div class="pt-4 space-y-3 max-w-md">
        <div class="feature-row">
          <div class="feature-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
          </div>
          <div>Excel-based candidate ingestion with auto-mapping</div>
        </div>
        <div class="feature-row">
          <div class="feature-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg>
          </div>
          <div>Category &amp; research-area analytics with live cutoffs</div>
        </div>
        <div class="feature-row">
          <div class="feature-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div>Panel-wise interview scoring &amp; final selection</div>
        </div>
      </div>
    </div>

    <div class="relative z-10 text-xs text-indigo-200">
      &copy; <?= date('Y') ?> Shailesh J. Mehta School of Management, IIT Bombay
    </div>
  </div>

  <!-- Right form panel -->
  <div class="flex-1 flex items-center justify-center p-6 lg:p-12">
    <div class="w-full max-w-md">

      <div class="lg:hidden flex items-center gap-3 mb-8">
        <div class="w-11 h-11 bg-indigo-600 rounded-xl flex items-center justify-center text-white font-bold">Ph</div>
        <div>
          <div class="font-semibold text-slate-800">SJMSOM</div>
          <div class="text-xs text-slate-500">IIT Bombay</div>
        </div>
      </div>

      <div class="mb-8">
        <h2 class="text-3xl font-bold text-slate-900 mb-2">Welcome back</h2>
        <p class="text-slate-500">Sign in to the PhD Admissions Portal.</p>
      </div>

      <?php if ($err): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3 rounded-xl mb-5 flex items-start gap-2">
          <svg class="shrink-0 mt-0.5" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span><?= h($err) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" class="space-y-4" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1.5">Username</label>
          <div class="field-wrap">
            <input name="username" required autofocus autocomplete="username" placeholder="admin or panel_xx" value="<?= h($_POST['username'] ?? '') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
          </div>
        </div>

        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="block text-sm font-medium text-slate-700">Password</label>
          </div>
          <div class="field-wrap">
            <input type="password" name="password" id="pwInp" required autocomplete="current-password" placeholder="Enter your password">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <button type="button" id="pwToggle" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" aria-label="Show password" style="padding:4px">
              <svg id="eyeOpen" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg id="eyeClosed" class="hidden" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
        </div>

        <button class="btn-signin mt-2">Sign in</button>
      </form>

      <!-- <details class="mt-8 bg-white border border-slate-200 rounded-xl">
        <summary class="flex items-center justify-between px-4 py-3 text-sm font-medium text-slate-700">
          <span class="flex items-center gap-2">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
            Demo credentials
          </span>
          <svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="px-4 pb-4 pt-1 text-xs text-slate-600 space-y-2">
          <div><strong class="text-slate-800">Admin:</strong>
            <span class="cred-pill">admin</span> /
            <span class="cred-pill">admin@2026</span>
          </div>
          <div><strong class="text-slate-800">Panels:</strong>
            <span class="cred-pill">panel_mg</span>,
            <span class="cred-pill">panel_ob</span>,
            <span class="cred-pill">panel_op</span>,
            <span class="cred-pill">panel_fn</span>,
            <span class="cred-pill">panel_mk</span>,
            <span class="cred-pill">panel_it</span>,
            <span class="cred-pill">panel_sm</span>,
            <span class="cred-pill">panel_en</span>
          </div>
          <div class="text-slate-500">Panel password: <span class="cred-pill">panel@2026</span></div>
        </div>
      </details> -->

    </div>
  </div>
</div>

<script>
(function(){
  const inp = document.getElementById('pwInp');
  const btn = document.getElementById('pwToggle');
  const open = document.getElementById('eyeOpen');
  const closed = document.getElementById('eyeClosed');
  btn.addEventListener('click', () => {
    const isPw = inp.type === 'password';
    inp.type = isPw ? 'text' : 'password';
    open.classList.toggle('hidden', isPw);
    closed.classList.toggle('hidden', !isPw);
    inp.focus();
  });
})();
</script>
</body>
</html>
