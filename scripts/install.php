<?php
// One-time installer: seeds admin user, panels, default intake.
require __DIR__ . '/../src/db.php';

$adminPass = 'admin@2026';
$panelPass = 'panel@2026';

$pdo = db();

// Admin
$pdo->prepare('INSERT INTO users(username,password_hash,full_name,role) VALUES(?,?,?,?)
  ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), full_name=VALUES(full_name), role=VALUES(role)')
    ->execute(['admin', password_hash($adminPass, PASSWORD_DEFAULT), 'Administrator', 'admin']);

// Panel profiles (Research Areas) matching SJMSOM PhD areas
$panels = [
    ['panel_mg', 'Economics & Policy', 'EP'],
    ['panel_ob', 'Organizational Behaviour & HRM', 'OBHR'],
    ['panel_op', 'Operations & Supply Chain Management', 'OSCM'],
    ['panel_fn', 'Finance & Accounting', 'FIN'],
    ['panel_mk', 'Marketing', 'MKT'],
    ['panel_it', 'Information Systems & Analytics', 'ISA'],
    ['panel_sm', 'Strategic Management & Competitiveness', 'SMC'],
    ['panel_en', 'Entrepreneurship', 'ENT'],
];
$stmt = $pdo->prepare('INSERT INTO users(username,password_hash,full_name,role,panel_code,panel_area) VALUES(?,?,?,?,?,?)
  ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), panel_code=VALUES(panel_code), panel_area=VALUES(panel_area), role=VALUES(role)');
foreach ($panels as $p) {
    $stmt->execute([$p[0], password_hash($panelPass, PASSWORD_DEFAULT), 'Panel - ' . $p[1], 'panel', $p[2], $p[1]]);
}

// Panels table
$pstmt = $pdo->prepare('INSERT INTO panels(code,area) VALUES(?,?) ON DUPLICATE KEY UPDATE area=VALUES(area)');
foreach ($panels as $p) {
    $pstmt->execute([$p[2], $p[1]]);
}

// Seed a default intake if none exist
$exists = one('SELECT COUNT(*) c FROM intakes');
if (!$exists || (int)$exists['c'] === 0) {
    $year = (int)date('Y');
    $month = (int)date('n');
    $season = $month <= 6 ? 'Spring' : 'Autumn';
    q('INSERT INTO intakes(name,season,year,is_active) VALUES(?,?,?,1)',
        [$season . ' ' . $year, $season, $year]);
}

echo "Install complete.\n";
echo "Admin login: admin / $adminPass\n";
echo "Panel logins (8): panel_mg, panel_ob, panel_op, panel_fn, panel_mk, panel_it, panel_sm, panel_en (password: $panelPass)\n";
