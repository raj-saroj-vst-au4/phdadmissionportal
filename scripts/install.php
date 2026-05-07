<?php
// One-time installer: seeds admin user, panels, default intake.
require __DIR__ . '/../src/db.php';

$adminUser = 'phdcoord';
$adminPass = 'Phd@2026';
$panelPass = 'panel@2026';

$pdo = db();

// Admin
$pdo->prepare('INSERT INTO users(username,password_hash,full_name,role) VALUES(?,?,?,?)
  ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), full_name=VALUES(full_name), role=VALUES(role)')
    ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'Administrator', 'admin']);

// Panel profiles (Research Areas) matching SJMSOM PhD areas
$panels = [
    ['panel_ep', 'Economics and Policy', 'EP'],
    ['panel_mk', 'Marketing', 'MKT'],
    ['panel_tm', 'Technology Management and Strategy IB Competitiveness', 'TMSC'],
    ['panel_ds', 'DS and IT', 'DSIT'],
    ['panel_fn', 'Finance and Accounting', 'FIN'],
    ['panel_om', 'OM', 'OM'],
    ['panel_hr', 'HR and OB', 'HROB'],
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
echo "Admin login: $adminUser / $adminPass\n";
echo "Panel logins (8): panel_ep, panel_mk, panel_tm, panel_ds, panel_fn, panel_om, panel_hr, panel_en (password: $panelPass)\n";
