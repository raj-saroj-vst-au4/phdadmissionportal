<?php
require_once __DIR__ . '/db.php';

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path): string {
    $p = '/' . ltrim($path, '/');
    return rtrim(APP_BASE, '/') . $p;
}

function json_out($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function flash_set(string $msg, string $type = 'info'): void {
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function flash_pop(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function category_badge(string $cat): string {
    $colors = [
        'GN' => 'bg-slate-200 text-slate-800',
        'OBC-NC' => 'bg-amber-100 text-amber-800',
        'SC' => 'bg-purple-100 text-purple-800',
        'ST' => 'bg-green-100 text-green-800',
        'EWS' => 'bg-sky-100 text-sky-800',
        'PWD' => 'bg-rose-100 text-rose-800',
    ];
    $c = $colors[$cat] ?? 'bg-slate-100 text-slate-700';
    return '<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold '.$c.'">'.h($cat).'</span>';
}

function status_badge(string $s): string {
    $map = [
        'Yes' => 'bg-green-100 text-green-800',
        'No' => 'bg-rose-100 text-rose-800',
        'Doubtful' => 'bg-amber-100 text-amber-800',
        'Pending' => 'bg-slate-100 text-slate-700',
        'Selected' => 'bg-green-100 text-green-800',
        'Not Selected' => 'bg-rose-100 text-rose-800',
        'Waitlisted' => 'bg-amber-100 text-amber-800',
    ];
    $c = $map[$s] ?? 'bg-slate-100 text-slate-700';
    return '<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold '.$c.'">'.h($s).'</span>';
}

/**
 * Normalize the "categories applied" string. RA and RA/TA (in any case / spacing) collapse to TA,
 * and any standalone "RA" token inside a list is rewritten to "TA" (so "RA, SF" becomes "TA, SF").
 * Mirrors normalize_categories_applied() in scripts/extract_excel.py — keep both in sync.
 */
function normalize_categories_applied(?string $raw): ?string {
    if ($raw === null) return null;
    $s = trim($raw);
    if ($s === '') return '';
    $compact = preg_replace('/\s+/', '', strtoupper($s));
    if (in_array($compact, ['RA', 'RA/TA', 'TA/RA'], true)) return 'TA';
    return preg_replace('/\bRA\b/i', 'TA', $s);
}

function normalize_birth_category(?string $raw): ?string {
    if (!$raw) return null;
    $r = strtoupper(trim($raw));
    $r = str_replace([' ', '_'], ['', '-'], $r);
    if (in_array($r, ['GN','GENERAL','GEN'])) return 'GN';
    if (strpos($r, 'OBC') === 0) return 'OBC-NC';
    if ($r === 'SC') return 'SC';
    if ($r === 'ST') return 'ST';
    if ($r === 'EWS') return 'EWS';
    if ($r === 'PWD' || strpos($r, 'DIS') === 0) return 'PWD';
    return $raw;
}

/**
 * Parse the pipe-delimited academic_record blob into structured rows.
 * Format per entry: "(N) Level || Degree || Specialization || (extra) || Institute || Year || Mode || Marks".
 * Entries are concatenated as "(1) ... (2) ... (3) ...". Returns [] for empty/unparseable input.
 */
function parse_academic_record(?string $raw): array {
    if (!$raw) return [];
    // Strip the leading "-->" placeholders the Excel exporter emits before real data.
    $body = $raw;
    if (($p = strpos($body, '(1)')) !== false) $body = substr($body, $p);
    if (trim($body) === '') return [];

    // Split on "(N) " markers; PCRE preserves the marker via a lookahead.
    $blocks = preg_split('/(?=\(\d+\)\s)/', trim($body), -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($blocks as $blk) {
        if (!preg_match('/^\((\d+)\)\s*(.*)$/s', trim($blk), $m)) continue;
        $idx = (int)$m[1];
        $parts = array_map('trim', explode('||', $m[2]));
        // Pad to 8 fields for safety
        $parts = array_pad($parts, 8, '');
        $out[] = [
            'idx'         => $idx,
            'level'       => $parts[0] ?? '',
            'degree'      => $parts[1] ?? '',
            'specialization' => $parts[2] ?? '',
            'extra'       => $parts[3] ?? '',
            'institute'   => $parts[4] ?? '',
            'year'        => $parts[5] ?? '',
            'mode'        => $parts[6] ?? '',
            'marks'       => $parts[7] ?? '',
        ];
    }
    return $out;
}

/**
 * Render an academic_record blob as a tidy HTML table.
 * Returns empty string if there's nothing to show.
 */
function render_academic_record(?string $raw): string {
    $rows = parse_academic_record($raw);
    if (!$rows) return '';
    $html = '<div class="overflow-x-auto rounded border border-slate-200 mt-1">';
    $html .= '<table class="data-table w-full text-xs"><thead><tr>'
           . '<th>Level</th><th>Degree</th><th>Specialization</th>'
           . '<th>Institute</th><th>Year</th><th>Mode</th><th>Marks</th>'
           . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        $html .= '<td class="font-medium text-slate-700">' . h($r['level']) . '</td>';
        $html .= '<td>' . h($r['degree']) . '</td>';
        $html .= '<td>' . h($r['specialization']) . '</td>';
        $html .= '<td>' . h($r['institute']) . '</td>';
        $html .= '<td class="text-center">' . h($r['year']) . '</td>';
        $html .= '<td class="text-xs text-slate-500">' . h($r['mode']) . '</td>';
        $html .= '<td class="font-semibold text-indigo-700 whitespace-nowrap">' . h($r['marks']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

function normalize_gender(?string $g): string {
    if (!$g) return '';
    $g = strtoupper(trim($g));
    if ($g === 'M' || strpos($g, 'MALE') === 0) return 'M';
    if ($g === 'F' || strpos($g, 'FEM') === 0) return 'F';
    return $g;
}
