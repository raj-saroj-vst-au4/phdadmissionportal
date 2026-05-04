<?php
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/helpers.php';
$u = require_login();
if ($u['role'] === 'panel') { redirect('/phdportal/panel/dashboard.php'); }

require __DIR__ . '/../src/layout.php';

$intake = active_intake();
$counts = [
    'total' => 0, 'yes' => 0, 'no' => 0, 'doubtful' => 0, 'pending' => 0,
    'selected' => 0, 'notsel' => 0, 'waitlisted' => 0,
];
if ($intake) {
    $r = one("SELECT COUNT(*) c FROM candidates WHERE intake_id = ? AND is_international=0", [$intake['id']]);
    $counts['total'] = (int)$r['c'];
    foreach (['Yes','No','Doubtful','Pending'] as $s) {
        $r = one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status=?", [$intake['id'],$s]);
        $k = strtolower($s);
        $counts[$k] = (int)$r['c'];
    }
    foreach ([['Selected','selected'],['Not Selected','notsel'],['Waitlisted','waitlisted']] as $pair) {
        $r = one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 AND final_status=?", [$intake['id'],$pair[0]]);
        $counts[$pair[1]] = (int)$r['c'];
    }
}

$catRows = $intake ? all("SELECT birth_category cat, COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 GROUP BY birth_category", [$intake['id']]) : [];
$genderRows = $intake ? all("SELECT gender g, COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 GROUP BY gender", [$intake['id']]) : [];

render_header('Dashboard', $u);
?>
<div class="flex items-center justify-between mb-5">
  <div>
    <h1 class="text-2xl font-semibold">Admin Dashboard</h1>
    <p class="text-sm text-slate-500">Active intake: <strong><?= $intake ? h($intake['name']) : '— none —' ?></strong></p>
  </div>
  <div class="flex gap-2">
    <a href="/phdportal/admin/intakes.php" class="btn btn-secondary">Manage Intakes</a>
    <a href="/phdportal/admin/upload.php" class="btn btn-primary">Upload Excel</a>
  </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
  <div class="card"><div class="text-xs text-slate-500 uppercase">Total Candidates</div><div class="text-3xl font-bold text-indigo-700"><?= $counts['total'] ?></div></div>
  <div class="card"><div class="text-xs text-slate-500 uppercase">Shortlisted (Yes)</div><div class="text-3xl font-bold text-green-600"><?= $counts['yes'] ?></div></div>
  <div class="card"><div class="text-xs text-slate-500 uppercase">Doubtful</div><div class="text-3xl font-bold text-amber-500"><?= $counts['doubtful'] ?></div></div>
  <div class="card"><div class="text-xs text-slate-500 uppercase">Rejected</div><div class="text-3xl font-bold text-rose-500"><?= $counts['no'] ?></div></div>
  <div class="card"><div class="text-xs text-slate-500 uppercase">Selected</div><div class="text-3xl font-bold text-green-700"><?= $counts['selected'] ?></div></div>
  <div class="card"><div class="text-xs text-slate-500 uppercase">Waitlisted</div><div class="text-3xl font-bold text-amber-700"><?= $counts['waitlisted'] ?></div></div>
  <div class="card"><div class="text-xs text-slate-500 uppercase">Not Selected</div><div class="text-3xl font-bold text-rose-700"><?= $counts['notsel'] ?></div></div>
  <div class="card"><div class="text-xs text-slate-500 uppercase">Pending Final</div><div class="text-3xl font-bold text-slate-500"><?= max(0,$counts['total']-$counts['selected']-$counts['notsel']-$counts['waitlisted']) ?></div></div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
  <div class="card">
    <h3 class="font-semibold mb-2">Category Distribution</h3>
    <canvas id="catChart" height="180"></canvas>
  </div>
  <div class="card">
    <h3 class="font-semibold mb-2">Gender Distribution</h3>
    <canvas id="genderChart" height="180"></canvas>
  </div>
</div>

<script>
const catLabels = <?= json_encode(array_map(fn($r)=>$r['cat']?:'Unknown',$catRows)) ?>;
const catData = <?= json_encode(array_map(fn($r)=>(int)$r['c'],$catRows)) ?>;
const gLabels = <?= json_encode(array_map(fn($r)=>$r['g']?:'Unknown',$genderRows)) ?>;
const gData = <?= json_encode(array_map(fn($r)=>(int)$r['c'],$genderRows)) ?>;
if (catLabels.length) {
  new Chart(document.getElementById('catChart'),{type:'doughnut',data:{labels:catLabels,datasets:[{data:catData,backgroundColor:['#4f46e5','#f59e0b','#a855f7','#10b981','#0ea5e9','#e11d48','#64748b']}]}});
}
if (gLabels.length) {
  new Chart(document.getElementById('genderChart'),{type:'pie',data:{labels:gLabels,datasets:[{data:gData,backgroundColor:['#3b82f6','#ec4899','#94a3b8']}]}});
}
</script>

<?php render_footer(); ?>
