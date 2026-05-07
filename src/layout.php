<?php
// Render header/footer with Tailwind + chart.js + jQuery

function render_header(string $title, ?array $user = null): void {
    $u = $user;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - <?= h(APP_NAME) ?></title>
<script src="/phdportal/assets/vendor/tailwind.js"></script>
<script src="/phdportal/assets/vendor/jquery.min.js"></script>
<script src="/phdportal/assets/vendor/chart.umd.min.js"></script>
<script src="/phdportal/assets/vendor/jspdf.umd.min.js"></script>
<script src="/phdportal/assets/vendor/jspdf-autotable.min.js"></script>
<link rel="stylesheet" href="/phdportal/assets/css/app.css">
<script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">
<nav class="bg-gradient-to-r from-indigo-800 to-indigo-600 text-white shadow">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
    <a href="/phdportal/dashboard.php" class="flex items-center gap-3">
      <div class="bg-white/15 rounded-full w-10 h-10 flex items-center justify-center font-bold">Ph</div>
      <div class="leading-tight">
        <div class="font-semibold text-sm md:text-base">SJMSOM PhD Admissions Portal</div>
        <div class="text-xs text-indigo-100">IIT Bombay</div>
      </div>
    </a>
    <?php if ($u): ?>
    <div class="flex items-center gap-4 text-sm">
      <?php if ($u['role'] === 'admin'): ?>
        <a class="hover:underline" href="/phdportal/dashboard.php">Dashboard</a>

        <div class="dd relative">
          <button class="dd-toggle hover:underline flex items-center gap-1">Screening <span class="text-xs">&#9662;</span></button>
          <div class="dd-menu hidden absolute right-0 top-full mt-1 min-w-[240px] bg-white text-slate-800 rounded shadow-lg border border-slate-200 z-50">
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/candidates.php">All Candidates</a>
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/applications.php">Applications Upload</a>
          </div>
        </div>

        <div class="dd relative">
          <button class="dd-toggle hover:underline flex items-center gap-1">Entrance <span class="text-xs">&#9662;</span></button>
          <div class="dd-menu hidden absolute right-0 top-full mt-1 min-w-[240px] bg-white text-slate-800 rounded shadow-lg border border-slate-200 z-50">
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/rooms.php">Room Allocation</a>
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/admit_cards.php">Admit Cards</a>
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/marks.php">Written / CBT Marks</a>
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/cutoff.php">Cutoff &amp; Analytics</a>
          </div>
        </div>

        <div class="dd relative">
          <button class="dd-toggle hover:underline flex items-center gap-1">Interviews <span class="text-xs">&#9662;</span></button>
          <div class="dd-menu hidden absolute right-0 top-full mt-1 min-w-[220px] bg-white text-slate-800 rounded shadow-lg border border-slate-200 z-50">
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/panels.php">Panel Allocation</a>
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/final.php">Final Shortlisting</a>
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/email.php">Email Communication</a>
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/intl/">International Candidates</a>
          </div>
        </div>

        <div class="dd relative">
          <button class="dd-toggle hover:underline flex items-center gap-1">Admin <span class="text-xs">&#9662;</span></button>
          <div class="dd-menu hidden absolute right-0 top-full mt-1 min-w-[200px] bg-white text-slate-800 rounded shadow-lg border border-slate-200 z-50">
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/intakes.php">Manage Intakes</a>
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/upload.php">Upload Excel</a>
            <a class="block px-4 py-2 text-sm hover:bg-indigo-50" href="/phdportal/admin/users.php">Users</a>
          </div>
        </div>
      <?php else: ?>
        <a class="hover:underline" href="/phdportal/panel/dashboard.php">My Panel</a>
      <?php endif; ?>
      <span class="opacity-80">|</span>
      <span class="opacity-90"><?= h($u['full_name']) ?> <span class="text-xs opacity-70">(<?= h($u['role']) ?>)</span></span>
      <a class="bg-white/15 hover:bg-white/25 px-3 py-1 rounded" href="/phdportal/logout.php">Logout</a>
    </div>
    <?php endif; ?>
  </div>
</nav>
<script>
$(function(){
  $('.dd-toggle').on('click', function(e){
    e.stopPropagation();
    const $menu = $(this).next('.dd-menu');
    $('.dd-menu').not($menu).addClass('hidden');
    $menu.toggleClass('hidden');
  });
  $(document).on('click', function(){ $('.dd-menu').addClass('hidden'); });
});
</script>
<main class="max-w-7xl mx-auto px-4 py-6">
<?php
$flashes = flash_pop();
foreach ($flashes as $f):
    $cls = [
        'success' => 'bg-green-100 border-green-300 text-green-800',
        'error' => 'bg-rose-100 border-rose-300 text-rose-800',
        'info' => 'bg-sky-100 border-sky-300 text-sky-800',
    ][$f['type']] ?? 'bg-slate-100 border-slate-300 text-slate-800';
?>
<div class="border-l-4 <?= $cls ?> p-3 mb-4 rounded"><?= h($f['msg']) ?></div>
<?php endforeach;
}

function render_footer(): void {
    ?>
</main>
<footer class="max-w-7xl mx-auto px-4 py-6 text-xs text-slate-500 text-center">
  &copy; <?= date('Y') ?> SJMSOM, IIT Bombay | Issues/Feedback: <a href="mailto:raj.saroj@iitb.ac.in" class="hover:underline">raj.saroj@iitb.ac.in</a>
</footer>
</body>
</html>
<?php
}
