<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
require __DIR__ . '/../../src/layout.php';

$intake = active_intake();
if (!$intake) { flash_set('No active intake.', 'error'); redirect('/phdportal/dashboard.php'); }

// Freeze handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['freeze_final'])) {
    check_csrf();
    $pending = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status='Yes' AND final_status='Pending'", [$intake['id']])['c'];
    if ($pending > 0) {
        flash_set("Cannot freeze: $pending candidate(s) still have Pending final status.", 'error');
    } else {
        set_setting('final_frozen_' . $intake['id'], '1');
        flash_set('Final selection frozen.', 'success');
    }
    redirect('/phdportal/admin/final.php');
}

// Unfreeze handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unfreeze_final'])) {
    check_csrf();
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($_POST['passcode'] ?? '', $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
    } else {
        q('DELETE FROM settings WHERE `key`=?', ['final_frozen_' . $intake['id']]);
        flash_set('Final selection unfrozen.', 'success');
    }
    redirect('/phdportal/admin/final.php');
}

// AM Global cutoff: persisted per intake. Freeze stores the value; unfreeze (passcode-protected) clears it.
$amgKey = 'amg_cutoff_' . $intake['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['freeze_amg_cutoff'])) {
    check_csrf();
    $val = trim($_POST['amg_cutoff_value'] ?? '');
    if ($val === '' || !is_numeric($val)) {
        flash_set('Enter a valid AM Global Cutoff before freezing.', 'error');
    } else {
        set_setting($amgKey, $val);
        flash_set('AM Global Cutoff frozen at ' . $val . '.', 'success');
    }
    redirect('/phdportal/admin/final.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unfreeze_amg_cutoff'])) {
    check_csrf();
    $fresh = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!$fresh || !password_verify($_POST['passcode'] ?? '', $fresh['password_hash'])) {
        flash_set('Admin passcode is incorrect.', 'error');
    } else {
        q('DELETE FROM settings WHERE `key`=?', [$amgKey]);
        flash_set('AM Global Cutoff unfrozen.', 'success');
    }
    redirect('/phdportal/admin/final.php');
}
$amgCutoff = setting($amgKey);
$amgFrozen = $amgCutoff !== null && $amgCutoff !== '';

// Per-panel means (computed up-front so both export and view paths can use them)
$panelMeansRows = all(
    "SELECT p.code, p.area, AVG(m.total_marks) mean
     FROM panels p
     LEFT JOIN candidates c ON c.panel_code = p.code AND c.intake_id = ? AND c.is_international = 0
     LEFT JOIN interview_marks m ON m.candidate_id = c.id
     GROUP BY p.code, p.area
     ORDER BY p.code", [$intake['id']]);
$panelMeanByCode = [];
foreach ($panelMeansRows as $pm) $panelMeanByCode[$pm['code']] = $pm['mean'] !== null ? (float)$pm['mean'] : null;

$meanRow = one("SELECT AVG(m.total_marks) mean FROM interview_marks m
    JOIN candidates c ON c.id=m.candidate_id WHERE c.intake_id=? AND c.is_international=0", [$intake['id']]);
$depMean = $meanRow['mean'] !== null ? round($meanRow['mean'], 2) : null;

$adjusted = function(array $r) use ($panelMeanByCode): ?float {
    if ($r['avg_interview'] === null) return null;
    $pm = $panelMeanByCode[$r['panel_code']] ?? null;
    if ($pm === null) return null;
    return (float)$r['avg_interview'] - $pm;
};
$adjustedGlobal = function(array $r) use ($adjusted, $depMean): ?float {
    $a = $adjusted($r);
    if ($a === null || $depMean === null) return null;
    return $a + (float)$depMean;
};

// AM Global cutoff is a base GN value. Effective threshold per candidate = cutoff * multiplier
// for their effective category. Same scheme as the written-marks cutoff in cutoff.php.
const AMG_CUTOFF_MULTIPLIERS = [
    'GN' => 1.0, 'EWS' => 1.0,
    'OBC-NC' => 0.9,
    'SC' => 0.6667, 'ST' => 0.6667, 'PWD' => 0.6667,
];

$amgEffectiveCat = function(array $r): ?string {
    if (($r['disabled'] ?? null) === 'Y') return 'PWD';
    if (($r['ews'] ?? null) === 'Y') return 'EWS';
    return $r['birth_category'] ?? null;
};
$amgMultiplierFor = function(?string $ec): float {
    return AMG_CUTOFF_MULTIPLIERS[$ec] ?? 1.0;
};

// Auto-select for All: one pass that handles TA and Non-TA together.
//   Eligibility (shared): interview marks present, unanimously recommended (every panelist),
//                         AM Global >= saved cutoff.
//   TA candidates  -> grouped by EFFECTIVE birth category, ranked by AM Global desc, capped at
//                     intake.ta_seats_<cat>; tagged "<Cat>-TA-<n>" (e.g. GN-TA-1, OBC-NC-TA-2).
//   Non-TA         -> grouped by applied category (SF, FA, EX, ...), no seat cap;
//                     tagged "<AppliedCat>-<n>" (e.g. SF-1, FA-1).
// Skipped when final selection is frozen, and requires a saved AM Global Cutoff.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_select_all'])) {
    check_csrf();
    if ((bool)setting('final_frozen_' . $intake['id'])) {
        flash_set('Final selection is frozen. Cannot auto-select.', 'error');
        redirect('/phdportal/admin/final.php');
    }
    $cutoffSetting = setting('amg_cutoff_' . $intake['id']);
    if ($cutoffSetting === null || $cutoffSetting === '' || !is_numeric($cutoffSetting)) {
        flash_set('Freeze the AM Global Cutoff first — auto-select needs a saved cutoff value.', 'error');
        redirect('/phdportal/admin/final.php');
    }
    $cutoffVal = (float)$cutoffSetting;

    $intakeFull = one('SELECT * FROM intakes WHERE id=?', [$intake['id']]);
    $seatMap = [
        'GN'     => (int)($intakeFull['ta_seats_gn']  ?? 0),
        'OBC-NC' => (int)($intakeFull['ta_seats_obc'] ?? 0),
        'SC'     => (int)($intakeFull['ta_seats_sc']  ?? 0),
        'ST'     => (int)($intakeFull['ta_seats_st']  ?? 0),
        'EWS'    => (int)($intakeFull['ta_seats_ews'] ?? 0),
    ];
    $pwdSeats = (int)($intakeFull['ta_seats_pwd'] ?? 0);

    $cands = all("SELECT c.id, c.birth_category, c.ews, c.disabled,
                         c.categories_applied, c.revised_categories_applied, c.panel_code,
                         (SELECT AVG(total_marks) FROM interview_marks m WHERE m.candidate_id=c.id) avg_interview,
                         (SELECT COUNT(*) FROM interview_marks m WHERE m.candidate_id=c.id) interview_count,
                         (SELECT COALESCE(SUM(recommended),0) FROM interview_marks m WHERE m.candidate_id=c.id) rec_count
                  FROM candidates c
                  WHERE c.intake_id=? AND c.is_international=0 AND c.screening_status='Yes'", [$intake['id']]);

    $taPool = [];            // [{id, amg, isPwd, homeBucket, gnOK}]
    $nonTaByApplied = [];    // applied_cat -> [{id, amg}]
    foreach ($cands as $c) {
        $applied = normalize_categories_applied(
            trim((string)($c['revised_categories_applied'] ?? '')) !== ''
                ? $c['revised_categories_applied']
                : ($c['categories_applied'] ?? '')
        );
        if ($applied === '' || $applied === null) continue;
        $intCount = (int)$c['interview_count'];
        $recCount = (int)$c['rec_count'];
        if ($intCount === 0 || $recCount !== $intCount) continue;
        $amg = $adjustedGlobal($c);
        if ($amg === null) continue;
        // Per-candidate threshold: GN/EWS 100%, OBC-NC 90%, SC/ST/PWD 66.67% of base cutoff.
        $ec = $amgEffectiveCat($c);
        $threshold = $cutoffVal * $amgMultiplierFor($ec);
        if ($amg < $threshold) continue;

        if ($applied === 'TA') {
            // PWD is horizontal: PWD candidates compete in their birth-category bucket
            // (the relaxed cutoff above already gives them the PWD threshold). EWS keeps its own bucket.
            $isPwd = ($ec === 'PWD');
            $homeBucket = $isPwd ? ($c['birth_category'] ?? null) : $ec;
            if (!isset($seatMap[$homeBucket])) continue;
            // GN seats are open: any candidate clearing the base (100%) cutoff competes for them.
            $gnOK = $amg >= $cutoffVal * 1.0;
            $taPool[] = [
                'id' => (int)$c['id'], 'amg' => $amg, 'isPwd' => $isPwd,
                'homeBucket' => $homeBucket, 'gnOK' => $gnOK,
            ];
        } else {
            $nonTaByApplied[$applied][] = ['id' => (int)$c['id'], 'amg' => $amg, 'birth' => $c['birth_category'] ?? ''];
        }
    }

    // Phase 1 — GN open allocation. Top ta_seats_gn by AM Global desc among candidates clearing the
    // base cutoff (100% threshold). A non-GN-home candidate (e.g. SC, OBC) can take a GN seat on merit.
    $gnEligible = array_values(array_filter($taPool, fn($p) => !empty($p['gnOK'])));
    usort($gnEligible, fn($a, $b) => $b['amg'] <=> $a['amg']);
    $gnSelected = array_slice($gnEligible, 0, $seatMap['GN']);
    $gnSelectedIds = array_flip(array_column($gnSelected, 'id'));

    // Phase 2 — Reserved allocation per home bucket, excluding candidates already taken by GN.
    $selByCat  = ['GN' => $gnSelected];
    $waitByCat = ['GN' => []];
    foreach (['OBC-NC', 'SC', 'ST', 'EWS'] as $rc) {
        $pool = array_values(array_filter($taPool, fn($p) =>
            $p['homeBucket'] === $rc && !isset($gnSelectedIds[$p['id']])
        ));
        usort($pool, fn($a, $b) => $b['amg'] <=> $a['amg']);
        $cap = $seatMap[$rc];
        $selByCat[$rc]  = array_slice($pool, 0, $cap);
        $waitByCat[$rc] = array_slice($pool, $cap);
    }
    // GN waitlist: GN-home candidates not selected anywhere.
    $allSelIds = $gnSelectedIds;
    foreach (['OBC-NC', 'SC', 'ST', 'EWS'] as $rc) foreach ($selByCat[$rc] as $r) $allSelIds[$r['id']] = true;
    foreach ($taPool as $p) {
        if ($p['homeBucket'] === 'GN' && !isset($allSelIds[$p['id']])) {
            $waitByCat['GN'][] = $p;
        }
    }

    // Phase 3 — enforce PWD horizontal reservation. PWD seats are not separate; they are a subset
    // of birth-category (incl. GN) seats. If naturally selected PWD < ta_seats_pwd, displace the
    // lowest-AM-Global non-PWD selection in the unselected PWD candidate's birth-category bucket.
    // The displaced candidate falls back to their own home bucket's waitlist.
    $naturalPwd = 0;
    foreach ($selByCat as $list) foreach ($list as $r) if (!empty($r['isPwd'])) $naturalPwd++;
    $pwdGap = max(0, $pwdSeats - $naturalPwd);
    $pwdDisplaced = 0;
    if ($pwdGap > 0) {
        $selectedIdsNow = [];
        foreach ($selByCat as $list) foreach ($list as $r) $selectedIdsNow[$r['id']] = true;
        $unselectedPwd = array_values(array_filter($taPool, fn($p) =>
            !empty($p['isPwd']) && !isset($selectedIdsNow[$p['id']])
        ));
        usort($unselectedPwd, fn($a, $b) => $b['amg'] <=> $a['amg']);

        foreach ($unselectedPwd as $pwd) {
            if ($pwdDisplaced >= $pwdGap) break;
            $cat = $pwd['homeBucket'];
            usort($selByCat[$cat], fn($a, $b) => $b['amg'] <=> $a['amg']);
            $lowestIdx = -1;
            for ($i = count($selByCat[$cat]) - 1; $i >= 0; $i--) {
                if (empty($selByCat[$cat][$i]['isPwd'])) { $lowestIdx = $i; break; }
            }
            if ($lowestIdx === -1) continue; // bucket has only PWD selections — nothing to displace
            // Find the PWD candidate row in any waitlist (their home is $cat, but if GN promotion
            // existed they may sit in a different waitlist — search exhaustively).
            $pwdRow = null;
            foreach ($waitByCat as $wc => $list) {
                foreach ($list as $j => $w) {
                    if ($w['id'] === $pwd['id']) { $pwdRow = $w; array_splice($waitByCat[$wc], $j, 1); break 2; }
                }
            }
            if ($pwdRow === null) continue;
            $displaced = $selByCat[$cat][$lowestIdx];
            array_splice($selByCat[$cat], $lowestIdx, 1);
            $selByCat[$cat][] = $pwdRow;
            // Displaced candidate falls back to their own home bucket's waitlist.
            $waitByCat[$displaced['homeBucket']][] = $displaced;
            $pwdDisplaced++;
        }
    }

    // Phase 4 — write Selected / Waitlisted, ranked by AM Global desc within each bucket.
    $totalSelected = 0;
    $totalWaitlisted = 0;
    $taCounts = [];
    $taWaitCounts = [];
    foreach (['GN', 'OBC-NC', 'SC', 'ST', 'EWS'] as $cat) {
        if (empty($selByCat[$cat]) && empty($waitByCat[$cat])) continue;
        usort($selByCat[$cat],  fn($a, $b) => $b['amg'] <=> $a['amg']);
        usort($waitByCat[$cat], fn($a, $b) => $b['amg'] <=> $a['amg']);
        foreach ($selByCat[$cat] as $i => $r) {
            // PWD candidates are tagged with a PWD- prefix to mark the horizontal reservation seat,
            // e.g. PWD-GN-TA-2. Rank (n) is the candidate's position in the bucket by AM Global desc.
            $tag = (!empty($r['isPwd']) ? 'PWD-' : '') . $cat . '-TA-' . ($i + 1);
            q("UPDATE candidates SET final_status='Selected', birth_category_number=? WHERE id=? AND intake_id=?",
              [$tag, $r['id'], $intake['id']]);
        }
        if (count($selByCat[$cat]) > 0) $taCounts[$cat] = count($selByCat[$cat]);
        $totalSelected += count($selByCat[$cat]);
        foreach ($waitByCat[$cat] as $i => $r) {
            $tag = 'WL-' . (!empty($r['isPwd']) ? 'PWD-' : '') . $cat . '-TA-' . ($i + 1);
            q("UPDATE candidates SET final_status='Waitlisted', birth_category_number=? WHERE id=? AND intake_id=?",
              [$tag, $r['id'], $intake['id']]);
        }
        if (count($waitByCat[$cat]) > 0) $taWaitCounts[$cat] = count($waitByCat[$cat]);
        $totalWaitlisted += count($waitByCat[$cat]);
    }

    $nonTaCounts = [];
    foreach ($nonTaByApplied as $applied => $list) {
        usort($list, fn($a, $b) => $b['amg'] <=> $a['amg']);
        foreach ($list as $i => $row) {
            $birth = trim((string)($row['birth'] ?? ''));
            $tag = ($birth !== '' ? $birth . '-' : '') . $applied . '-' . ($i + 1);
            q("UPDATE candidates SET final_status='Selected', birth_category_number=? WHERE id=? AND intake_id=?",
              [$tag, $row['id'], $intake['id']]);
        }
        $nonTaCounts[$applied] = count($list);
        $totalSelected += count($list);
    }

    if ($totalSelected === 0 && $totalWaitlisted === 0) {
        flash_set('Auto-select: no eligible candidates met the criteria.', 'info');
    } else {
        $parts = [];
        foreach ($taCounts as $cat => $n) $parts[] = "$cat-TA: $n";
        foreach ($nonTaCounts as $cat => $n) $parts[] = "$cat: $n";
        $msg = "Auto-select: $totalSelected selected (" . implode(', ', $parts) . ')';
        if ($totalWaitlisted > 0) {
            $waitParts = [];
            foreach ($taWaitCounts as $cat => $n) $waitParts[] = "$cat-TA: $n";
            $msg .= "; $totalWaitlisted waitlisted (" . implode(', ', $waitParts) . ')';
        }
        if ($pwdDisplaced > 0) {
            $msg .= "; PWD reservation displaced $pwdDisplaced non-PWD selection(s)";
        }
        flash_set($msg . '.', 'success');
    }
    redirect('/phdportal/admin/final.php');
}

// CSV export
if (isset($_GET['export'])) {
    $kind = $_GET['export']; // 'simple' | 'formatb' | 'summary'
    $rows = all("SELECT c.dept_reg_no, c.name, c.gender, c.panel_code, c.panel_area,
                 c.birth_category, c.ews, c.disabled, c.categories_applied, c.revised_categories_applied,
                 c.qualifying_exam, c.gate_score, c.written_marks,
                 (SELECT AVG(m.total_marks) FROM interview_marks m WHERE m.candidate_id=c.id) avg_interview,
                 (SELECT SUM(m.recommended) FROM interview_marks m WHERE m.candidate_id=c.id) rec_count,
                 c.final_status, c.final_category, c.birth_category_number
                 FROM candidates c
                 WHERE c.intake_id=? AND c.is_international=0 AND c.screening_status='Yes'
                 ORDER BY c.final_status, avg_interview DESC", [$intake['id']]);

    $appliedCat = fn($r) => normalize_categories_applied(
        trim((string)($r['revised_categories_applied'] ?? '')) !== ''
            ? $r['revised_categories_applied']
            : ($r['categories_applied'] ?? '')
    );

    header('Content-Type: text/csv; charset=utf-8');

    if ($kind === 'formatb') {
        // Format B: full roster of (non-international) candidates for the intake.
        // Non-shortlisted candidates are included too so blank "Consider for TA" /
        // Remarks rows reflect the complete admission picture.
        $rowsB = all("SELECT c.dept_reg_no, c.name, c.birth_category, c.ews, c.disabled,
                      c.panel_code, c.final_status,
                      (SELECT AVG(m.total_marks) FROM interview_marks m WHERE m.candidate_id=c.id) avg_interview
                      FROM candidates c
                      WHERE c.intake_id=? AND c.is_international=0
                      ORDER BY c.dept_reg_no", [$intake['id']]);
        header('Content-Disposition: attachment; filename="FormatB_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['PhD Admission ' . $intake['name']]);
        fputcsv($out, ['Sr. No.', 'Dept reg no.', 'Name', 'Birth Category', 'EWS', 'PwD',
                       'Interview status', 'Total marks',
                       'Marks obtained in written test/interview',
                       'Consider for TA', 'Remarks']);
        $i = 1;
        foreach ($rowsB as $r) {
            $present = $r['avg_interview'] !== null;
            $amg = $adjustedGlobal($r);
            $marks = $present ? round($amg !== null ? $amg : (float)$r['avg_interview'], 2) : 0;
            $considerTA = ($r['final_status'] === 'Selected' || $r['final_status'] === 'Waitlisted') ? 'Yes' : '';
            $remarks = $r['final_status'] === 'Waitlisted' ? 'Waitlisted' : '';
            fputcsv($out, [
                $i++,
                $r['dept_reg_no'],
                $r['name'],
                $r['birth_category'] ?? '',
                $r['ews'] === 'Y' ? 'Y' : 'N',
                $r['disabled'] === 'Y' ? 'Y' : 'N',
                $present ? 'Present' : 'Absent',
                100,
                $marks,
                $considerTA,
                $remarks,
            ]);
        }
        fclose($out); exit;
    }

    if ($kind === 'summary') {
        header('Content-Disposition: attachment; filename="Summary_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        // Count by final_status / category / birth_category
        fputcsv($out, ['SJMSOM PhD Admissions — Summary Report']);
        fputcsv($out, ['Intake', $intake['name']]);
        fputcsv($out, ['Generated', date('Y-m-d H:i')]);
        fputcsv($out, []);
        fputcsv($out, ['Final Status Summary']);
        fputcsv($out, ['Status','Count']);
        $byStatus = [];
        foreach ($rows as $r) $byStatus[$r['final_status']] = ($byStatus[$r['final_status']] ?? 0) + 1;
        foreach ($byStatus as $s => $c) fputcsv($out, [$s, $c]);
        fputcsv($out, []);
        fputcsv($out, ['Birth Category Summary (Selected)']);
        fputcsv($out, ['Category','Selected']);
        $byCat = [];
        foreach ($rows as $r) if ($r['final_status']==='Selected') $byCat[$r['birth_category']] = ($byCat[$r['birth_category']] ?? 0) + 1;
        foreach ($byCat as $c => $n) fputcsv($out, [$c ?: 'Unknown', $n]);
        fputcsv($out, []);
        fputcsv($out, ['Research Area Summary (Selected)']);
        fputcsv($out, ['Panel','Selected']);
        $byPanel = [];
        foreach ($rows as $r) if ($r['final_status']==='Selected') $byPanel[$r['panel_code']] = ($byPanel[$r['panel_code']] ?? 0) + 1;
        foreach ($byPanel as $p => $n) fputcsv($out, [$p ?: 'Unassigned', $n]);
        fputcsv($out, []);
        fputcsv($out, ['Average Interview Marks']);
        fputcsv($out, ['Scope','Average']);
        $allAvg = array_filter(array_map(fn($r)=>$r['avg_interview'], $rows));
        $selAvg = array_filter(array_map(fn($r)=>$r['final_status']==='Selected' ? $r['avg_interview'] : null, $rows));
        fputcsv($out, ['All Shortlisted', $allAvg ? round(array_sum($allAvg)/count($allAvg), 2) : '—']);
        fputcsv($out, ['Selected Only', $selAvg ? round(array_sum($selAvg)/count($selAvg), 2) : '—']);
        fclose($out); exit;
    }

    // default: simple CSV
    header('Content-Disposition: attachment; filename="final_selection_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Dept Reg No','Name','Research Cat','Birth Category','Written','Interview Marks','Adjusted Panel Mean','Adjusted Global Mean','Final Status','Category','Birth Cat #']);
    foreach ($rows as $r) {
        $adj = $adjusted($r); $adjG = $adjustedGlobal($r);
        fputcsv($out, [
            $r['dept_reg_no'], $r['name'], $r['panel_code'], $r['birth_category'],
            $r['written_marks'],
            $r['avg_interview'] !== null ? round($r['avg_interview'], 2) : '',
            $adj !== null ? round($adj, 2) : '',
            $adjG !== null ? round($adjG, 2) : '',
            $r['final_status'], $appliedCat($r), $r['birth_category_number']
        ]);
    }
    fclose($out); exit;
}

$frozen = (bool)setting('final_frozen_' . $intake['id']);

// Auto-rule: a candidate who has been interviewed but received zero recommendations from any
// panelist is marked Not Selected. Only flips Pending rows so admin overrides (Selected /
// Waitlisted / a manual Not Selected) stay intact, and skipped while the page is frozen.
if (!$frozen) {
    q("UPDATE candidates c
       JOIN (
         SELECT candidate_id, COUNT(*) cnt, COALESCE(SUM(recommended),0) rec
         FROM interview_marks GROUP BY candidate_id
       ) m ON m.candidate_id = c.id
       SET c.final_status = 'Not Selected'
       WHERE c.intake_id = ?
         AND c.is_international = 0
         AND c.screening_status = 'Yes'
         AND c.final_status = 'Pending'
         AND m.cnt > 0
         AND m.rec = 0", [$intake['id']]);
}

$rows = all("SELECT c.*,
    (SELECT AVG(total_marks) FROM interview_marks m WHERE m.candidate_id=c.id) avg_interview,
    (SELECT COUNT(*) FROM interview_marks m WHERE m.candidate_id=c.id) interview_count,
    (SELECT SUM(recommended) FROM interview_marks m WHERE m.candidate_id=c.id) rec_count
    FROM candidates c
    WHERE c.intake_id=? AND c.is_international=0 AND c.screening_status='Yes'", [$intake['id']]);

usort($rows, function($a, $b) use ($adjustedGlobal) {
    $aa = $adjustedGlobal($a); $bb = $adjustedGlobal($b);
    if ($aa === null && $bb === null) return 0;
    if ($aa === null) return 1;
    if ($bb === null) return -1;
    return $bb <=> $aa;
});

$pending = (int)one("SELECT COUNT(*) c FROM candidates WHERE intake_id=? AND is_international=0 AND screening_status='Yes' AND final_status='Pending'", [$intake['id']])['c'];

render_header('Final Shortlisting', $u);
?>
<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
  <h1 class="text-2xl font-semibold">Final Shortlisting</h1>
  <div class="flex gap-2 flex-wrap">
    <a href="?export=simple" class="btn btn-secondary text-xs">Simple CSV</a>
    <a href="?export=formatb" class="btn btn-secondary text-xs">Format B (CSV)</a>
    <a href="?export=summary" class="btn btn-secondary text-xs">Summary Report</a>
    <button class="btn btn-secondary text-xs" onclick="downloadFinalPdf()">Export PDF</button>
  </div>
</div>

<?php
  $panelCount = count($panelMeansRows);
  // Two rows on the left: split panels evenly so they fill 2 rows.
  $leftCols = max(1, (int)ceil($panelCount / 2));
?>
<div class="grid grid-cols-5 gap-4 mb-4">
  <div class="col-span-4">
    <div class="grid gap-3" style="grid-template-columns: repeat(<?= $leftCols ?>, minmax(0, 1fr));">
      <?php foreach ($panelMeansRows as $pm): ?>
        <div class="card p-3 flex flex-col justify-between">
          <div class="text-xs font-semibold text-slate-700 truncate" title="<?= h($pm['area']) ?>">
            <span class="inline-block px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-800 text-[10px] font-bold mr-1"><?= h($pm['code']) ?></span>
            <span class="text-slate-500 font-normal"><?= h($pm['area']) ?></span>
          </div>
          <div class="mt-1">
            <?php if ($pm['mean'] !== null): ?>
              <span class="text-xl font-bold text-indigo-700"><?= h(round($pm['mean'], 2)) ?></span>
              <span class="text-xs text-slate-500"> / 100</span>
            <?php else: ?>
              <span class="text-sm text-slate-400">—</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$panelMeansRows): ?>
        <div class="card p-3 text-sm text-slate-500">No panels configured.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-span-1">
    <div class="card aspect-square h-full flex flex-col items-center justify-center text-center bg-indigo-50 border-indigo-200">
      <div class="text-xs font-semibold text-indigo-900 uppercase tracking-wide">Global Mean</div>
      <div class="mt-2">
        <?php if ($depMean !== null): ?>
          <div class="text-4xl font-bold text-indigo-700 leading-none"><?= h($depMean) ?></div>
          <div class="text-xs text-indigo-700/70 mt-1">/ 100</div>
        <?php else: ?>
          <div class="text-sm text-slate-500">— no marks yet —</div>
        <?php endif; ?>
      </div>
      <div class="text-[10px] text-slate-500 mt-2 px-2">Average across all panels</div>
      <?php if ($frozen): ?>
      <div class="mt-3 inline-flex items-center gap-1 text-rose-700 font-semibold text-xs">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Frozen
      </div>
      <button type="button" class="btn btn-secondary text-[10px] mt-1 px-2 py-0.5" onclick="$('#unfreezeBackdrop').removeClass('hidden')">Unfreeze</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($frozen): ?>
<div id="unfreezeBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Unfreeze Final Selection</h3>
    <p class="text-sm text-slate-700 mb-3">Re-enable editing of final status, category and birth-cat number.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="unfreeze_final" value="1">
      <label class="text-xs font-medium">Admin password:</label>
      <input type="password" name="passcode" required autocomplete="new-password" class="mt-1">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn btn-secondary" onclick="$('#unfreezeBackdrop').addClass('hidden')">Cancel</button>
        <button class="btn btn-danger">Unfreeze</button>
      </div>
    </form>
  </div>
</div>
<script>
$('#unfreezeBackdrop').on('click', function(e){ if (e.target === this) $(this).addClass('hidden'); });
$(document).on('keydown', e => { if (e.key === 'Escape') $('#unfreezeBackdrop').addClass('hidden'); });
</script>
<?php endif; ?>

<style>
  /* Light green flag: interview marks present and every panelist recommended the candidate. */
  #finalTable tr.row-recommended > td { background: #dcfce7; }
  #finalTable tr.row-recommended:hover > td { background: #bbf7d0; }
  /* Light red flag: interview marks present and zero panelists recommended the candidate. */
  #finalTable tr.row-not-recommended > td { background: #fee2e2; }
  #finalTable tr.row-not-recommended:hover > td { background: #fecaca; }
  /* Rows whose AM Global is below the entered cutoff (or have no AM Global value). */
  #finalTable tr.row-below-cutoff > td { color: #94a3b8; }
  #finalTable tr.row-below-cutoff.row-recommended > td { background: #f1f5f9; }
  #finalTable tr.row-below-cutoff.row-not-recommended > td { background: #f1f5f9; }
</style>

<div class="card mb-3 flex items-end gap-3 flex-wrap">
  <div>
    <label class="text-xs font-medium" for="amgCutoff">AM Global Cutoff (GN base)</label>
    <input type="number" step="0.01" id="amgCutoff" class="w-32 text-sm"
           value="<?= h($amgCutoff ?? '') ?>" placeholder="e.g. 70.00"
           <?= $amgFrozen ? 'disabled' : '' ?>>
    <p class="text-[10px] text-slate-500 mt-0.5">GN/EWS: 100%, OBC-NC: 90%, SC/ST/PWD: 66.67%</p>
  </div>
  <div class="text-xs text-slate-500 self-center">
    <span id="amgCount"><?= count($rows) ?></span> / <?= count($rows) ?> at or above cutoff
  </div>
  <?php if (!$amgFrozen): ?>
    <button type="button" id="amgApply" class="btn btn-primary text-xs">Apply</button>
    <button type="button" id="amgClear" class="btn btn-secondary text-xs">Clear</button>
    <form method="post" class="inline" id="amgFreezeForm" onsubmit="return amgFreezeConfirm();">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="freeze_amg_cutoff" value="1">
      <input type="hidden" name="amg_cutoff_value" id="amgFreezeValue" value="">
      <button type="submit" class="btn btn-danger text-xs">
        <svg class="inline" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Freeze
      </button>
    </form>
  <?php else: ?>
    <span class="inline-flex items-center gap-1 text-rose-700 text-xs font-semibold self-center">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Frozen at <?= h($amgCutoff) ?>
    </span>
    <button type="button" class="btn btn-secondary text-xs" onclick="$('#amgUnfreezeBackdrop').removeClass('hidden')">Unfreeze</button>
  <?php endif; ?>

  <?php if ($amgFrozen && !$frozen): ?>
    <form method="post" class="inline ml-auto" onsubmit="return confirm('Auto-select all eligible candidates (interview marks present, unanimously recommended, AM Global ≥ category-adjusted cutoff)?\n\nBase cutoff: <?= h($amgCutoff) ?>\n• GN / EWS: 100% (≥ <?= number_format((float)$amgCutoff, 2) ?>)\n• OBC-NC: 90% (≥ <?= number_format((float)$amgCutoff * 0.9, 2) ?>)\n• SC / ST / PWD: 66.67% (≥ <?= number_format((float)$amgCutoff * 0.6667, 2) ?>)\n\n• GN seats are open: top AM Global candidates from any birth category clearing the base (100%) cutoff fill them first.\n• Reserved seats (OBC-NC / SC / ST / EWS) then fill from remaining home-bucket candidates.\n• PWD reservation is horizontal (subset of birth-category seats). If qualifying PWD candidates < ta_seats_pwd, the lowest-AM-Global non-PWD selection in that PWD candidate''s birth category is displaced to the waitlist.\n• Tags: <Cat>-TA-N (e.g. GN-TA-1, SC-TA-2). PWD candidates carry a PWD- prefix (e.g. PWD-GN-TA-2). Waitlist: WL-... (e.g. WL-GN-TA-1, WL-PWD-SC-TA-1).\n• Non-TA: no cap, tagged like SF-1, FA-1.\n\nExisting Selected / Waitlisted statuses may be overwritten.');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="auto_select_all" value="1">
      <button type="submit" class="btn btn-primary text-xs">Auto-Select for All</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($amgFrozen): ?>
<div id="amgUnfreezeBackdrop" class="hidden fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
    <h3 class="text-lg font-semibold text-amber-700 mb-2">Unfreeze AM Global Cutoff</h3>
    <p class="text-sm text-slate-700 mb-3">Re-enable editing of the AM Global Cutoff for this intake.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="unfreeze_amg_cutoff" value="1">
      <label class="text-xs font-medium">Admin password:</label>
      <input type="password" name="passcode" required autocomplete="new-password" class="mt-1">
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn btn-secondary" onclick="$('#amgUnfreezeBackdrop').addClass('hidden')">Cancel</button>
        <button class="btn btn-danger">Unfreeze</button>
      </div>
    </form>
  </div>
</div>
<script>
$('#amgUnfreezeBackdrop').on('click', function(e){ if (e.target === this) $(this).addClass('hidden'); });
$(document).on('keydown', e => { if (e.key === 'Escape') $('#amgUnfreezeBackdrop').addClass('hidden'); });
</script>
<?php endif; ?>

<div class="card p-0 overflow-x-auto">
<table class="data-table w-full [&_th]:!text-center [&_td]:!text-center" id="finalTable">
<thead><tr>
  <th>Dept Reg No</th><th>Name</th><th>Research Cat</th><th>Written</th>
  <th>Interview</th><th>AM Panel</th><th>AM Global</th>
  <th>Status</th><th>App. Category</th><th>Birth Category</th><th>Birth Cat #</th>
</tr></thead>
<tbody>
<?php
  foreach ($rows as $r):
    $intCount = (int)($r['interview_count'] ?? 0);
    $recCount = (int)($r['rec_count'] ?? 0);
    // Unanimous recommendation: every panelist who interviewed the candidate recommended them.
    $allRecommended = $r['avg_interview'] !== null && $intCount > 0 && $recCount === $intCount;
    // Symmetric flag: interviewed but no panelist recommended.
    $noneRecommended = $r['avg_interview'] !== null && $intCount > 0 && $recCount === 0;
    $rowFlag = $allRecommended ? 'row-recommended' : ($noneRecommended ? 'row-not-recommended' : '');
?>
<?php
  $adjGRow = $adjustedGlobal($r);
  $rowMult = $amgMultiplierFor($amgEffectiveCat($r));
?>
<tr class="<?= $rowFlag ?>"
    data-amg="<?= $adjGRow !== null ? round($adjGRow, 2) : '' ?>"
    data-mult="<?= h(number_format($rowMult, 4, '.', '')) ?>">
  <td class="font-mono text-xs"><a href="/phdportal/admin/candidate.php?id=<?= (int)$r['id'] ?>" class="text-indigo-600 hover:underline"><?= h($r['dept_reg_no']) ?></a></td>
  <td><?= h($r['name']) ?></td>
  <td class="text-xs">
    <?php if ($r['panel_code']): ?>
      <span class="inline-block px-2 py-0.5 rounded bg-indigo-50 text-indigo-800 font-semibold text-xs"><?= h($r['panel_code']) ?></span>
      <span class="text-slate-500 text-xs"> <?= h($r['panel_area']) ?></span>
    <?php else: ?>
      <span class="text-slate-400">—</span>
    <?php endif; ?>
  </td>
  <td class="text-right"><?= h($r['written_marks']) ?></td>
  <td class="text-right font-semibold"><?= $r['avg_interview']!==null ? round($r['avg_interview'],2) : '—' ?></td>
  <?php $adj = $adjusted($r); $adjG = $adjustedGlobal($r); ?>
  <td class="text-right font-semibold">
    <?php if ($adj !== null): ?>
      <span class="<?= $adj >= 0 ? 'text-green-700' : 'text-rose-600' ?>"><?= ($adj >= 0 ? '+' : '') . number_format($adj, 2) ?></span>
    <?php else: ?>
      <span class="text-slate-400">—</span>
    <?php endif; ?>
  </td>
  <td class="text-right font-semibold">
    <?php if ($adjG !== null): ?>
      <span class="text-indigo-700"><?= number_format($adjG, 2) ?></span>
    <?php else: ?>
      <span class="text-slate-400">—</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($frozen): ?>
      <?= status_badge($r['final_status']) ?>
    <?php else: ?>
    <select class="status-sel text-xs" data-id="<?= (int)$r['id'] ?>">
      <?php foreach (['Pending','Selected','Not Selected','Waitlisted'] as $s): ?>
        <option<?= $s===$r['final_status']?' selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </td>
  <?php
    $applied = normalize_categories_applied(
        trim((string)($r['revised_categories_applied'] ?? '')) !== ''
            ? $r['revised_categories_applied']
            : ($r['categories_applied'] ?? '')
    );
    $isRevised = trim((string)($r['revised_categories_applied'] ?? '')) !== '';
    $origForTitle = normalize_categories_applied($r['categories_applied'] ?? null);
  ?>
  <td>
    <?php if ($applied !== '' && $applied !== null): ?>
      <span class="text-xs font-semibold"><?= h($applied) ?></span>
      <?php if ($isRevised): ?><span class="ml-1 text-[10px] text-indigo-600 font-medium" title="Revised from <?= h($origForTitle ?: '—') ?>">(revised)</span><?php endif; ?>
    <?php else: ?>
      <span class="text-slate-400">—</span>
    <?php endif; ?>
  </td>
  <td><?= category_badge($r['birth_category'] ?? '') ?></td>
  <td>
    <?php if ($frozen): ?>
      <?= h($r['birth_category_number'] ?? '—') ?>
    <?php else: ?>
    <input class="birthnum-inp w-20 text-xs" data-id="<?= (int)$r['id'] ?>" value="<?= h($r['birth_category_number']) ?>" placeholder="e.g. 3">
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="11" class="text-center py-6 text-slate-500">No shortlisted candidates yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php if (!$frozen): ?>
<div class="mt-5 flex items-center justify-end gap-3">
  <?php if ($pending > 0): ?>
    <p class="text-sm text-amber-700">
      <svg class="inline" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= $pending ?> candidate(s) still Pending final status
    </p>
    <button class="btn btn-danger opacity-40 cursor-not-allowed" disabled>Freeze Final Selection</button>
  <?php else: ?>
    <form method="post" onsubmit="return confirm('Freeze final selection? This will lock all final decisions for this intake.');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button name="freeze_final" class="btn btn-danger">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Freeze Final Selection
      </button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
function postFinal(id, field, value) {
  $.post('/phdportal/api/update_final.php', { id, field, value, csrf: window.CSRF_TOKEN })
    .done(r => { if (!r.ok) alert(r.error || 'Failed'); })
    .fail(()=>alert('Request failed'));
}
$('.status-sel').on('change', function(){ postFinal($(this).data('id'),'final_status',$(this).val()); });
$('.birthnum-inp').on('change', function(){ postFinal($(this).data('id'),'birth_category_number',$(this).val()); });

// AM Global cutoff filter — entered value is the BASE (GN) cutoff. Each row is compared against
// base * its category multiplier (data-mult): GN/EWS 1.0, OBC-NC 0.9, SC/ST/PWD 0.6667.
// Apply on click / Enter (live filtering removed so partial typed values don't jitter the table).
// Rows without an AM Global (no interview marks yet) are also marked below-cutoff once any value is set.
// `appliedCutoff` tracks the last value that actually filtered the table — used to warn at freeze
// time when the input has been edited but Apply hasn't been re-run.
let amgAppliedCutoff = null; // null = no filter currently applied
window.amgApplyFilter = (function(){
  const input = document.getElementById('amgCutoff');
  const countEl = document.getElementById('amgCount');
  const applyBtn = document.getElementById('amgApply');
  const clearBtn = document.getElementById('amgClear');
  const rows = Array.from(document.querySelectorAll('#finalTable tbody tr[data-amg]'));
  const apply = () => {
    const raw = input.value.trim();
    const cutoff = raw === '' ? null : parseFloat(raw);
    let above = 0;
    rows.forEach(tr => {
      const v = tr.dataset.amg;
      const mult = parseFloat(tr.dataset.mult || '1') || 1;
      const has = v !== '';
      const threshold = cutoff !== null ? cutoff * mult : null;
      const below = threshold !== null && (!has || parseFloat(v) < threshold);
      tr.classList.toggle('row-below-cutoff', below);
      if (threshold === null || (has && parseFloat(v) >= threshold)) above++;
    });
    countEl.textContent = above;
    amgAppliedCutoff = cutoff;
  };
  if (applyBtn) applyBtn.addEventListener('click', apply);
  if (clearBtn) clearBtn.addEventListener('click', () => { input.value = ''; apply(); });
  if (input && !input.disabled) {
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
  }
  // Reflect any saved/frozen cutoff on first paint.
  if (input && input.value.trim() !== '') apply();
  return apply;
})();

function amgFreezeConfirm() {
  const v = document.getElementById('amgCutoff').value.trim();
  if (v === '' || isNaN(parseFloat(v))) { alert('Enter a valid AM Global Cutoff first.'); return false; }
  const entered = parseFloat(v);
  // Warn when the table is currently filtered by a different value (or not filtered at all).
  // Same-number compare covers cases like "70" vs "70.0" — what matters is the numeric value.
  if (amgAppliedCutoff === null || amgAppliedCutoff !== entered) {
    const appliedTxt = amgAppliedCutoff === null ? 'none (Apply not run)' : amgAppliedCutoff;
    if (!confirm('You are about to freeze the cutoff at ' + entered + ', but the table is currently showing ' + appliedTxt + '.\n\nFreeze ' + entered + ' anyway?')) return false;
  } else if (!confirm('Freeze AM Global Cutoff at ' + entered + '? Locks the value for this intake until an admin unfreezes.')) {
    return false;
  }
  document.getElementById('amgFreezeValue').value = v;
  return true;
}

function downloadFinalPdf() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF('landscape');
  doc.setFontSize(13);
  doc.text('SJMSOM IIT Bombay — PhD Admissions Final Selection', 14, 14);
  doc.setFontSize(10);
  doc.text('Generated: ' + new Date().toLocaleString(), 14, 21);
  const rows = [];
  document.querySelectorAll('#finalTable tbody tr').forEach(tr => {
    const cells = tr.querySelectorAll('td');
    if (cells.length < 11) return;
    const researchSpans = cells[2].querySelectorAll('span');
    const researchText = researchSpans.length >= 2
      ? researchSpans[1].innerText.trim()
      : cells[2].innerText.trim();
    const statusSel = cells[7].querySelector('select');
    const statusText = statusSel
      ? statusSel.options[statusSel.selectedIndex].text
      : cells[7].innerText.trim();
    rows.push([
      cells[0].innerText.trim(),
      cells[1].innerText.trim(),
      researchText,
      cells[3].innerText.trim(),
      cells[4].innerText.trim(),
      cells[6].innerText.trim(),
      statusText,
      cells[8].innerText.trim(),
      cells[9].innerText.trim(),
      cells[10].innerText.trim(),
    ]);
  });
  doc.autoTable({
    startY: 26,
    head: [['Dept Reg No','Name','Research Cat','Written','Raw Interview','Adjusted Global Mean','Status','Applied Category','Birth Cat','Birth Cat #']],
    body: rows,
    styles: { fontSize: 8, cellPadding: 2 },
    headStyles: { fillColor: [79,70,229] }
  });
  doc.save('Final_Selection_<?= date('Ymd') ?>.pdf');
}
</script>
<?php render_footer(); ?>
