<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
check_csrf();

$intake = active_intake();
if (!$intake) json_out(['ok' => false, 'error' => 'No active intake'], 400);

$omr_root = PUBLIC_ROOT . '/uploads/omr';
$tmp_root = $omr_root . '/.tmp';
if (!is_dir($tmp_root)) @mkdir($tmp_root, 0775, true);

foreach ((array)glob($tmp_root . '/*', GLOB_ONLYDIR) as $d) {
    if (filemtime($d) < time() - 7200) {
        foreach ((array)glob($d . '/*') as $f) @unlink($f);
        @rmdir($d);
    }
}

function valid_omr_upload_id(string $id): bool {
    return (bool)preg_match('/^[a-f0-9]{32}$/', $id);
}

$action = $_POST['action'] ?? '';

if ($action === 'init') {
    $name = (string)($_POST['name'] ?? '');
    $size = (int)($_POST['size'] ?? 0);
    $kind = (string)($_POST['kind'] ?? 'bulk');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') json_out(['ok' => false, 'error' => 'Not a PDF'], 400);
    if ($size <= 0 || $size > 500 * 1024 * 1024) {
        json_out(['ok' => false, 'error' => 'File size out of range (max 500 MB)'], 400);
    }
    if (!in_array($kind, ['bulk', 'answer_key'], true)) {
        json_out(['ok' => false, 'error' => 'Invalid kind'], 400);
    }

    $uid = bin2hex(random_bytes(16));
    $dir = $tmp_root . '/' . $uid;
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/meta.json', json_encode([
        'name' => $name, 'size' => $size, 'kind' => $kind, 'intake_id' => (int)$intake['id']
    ]));
    json_out(['ok' => true, 'upload_id' => $uid]);
}

if ($action === 'chunk') {
    $uid = (string)($_POST['upload_id'] ?? '');
    $idx = (int)($_POST['index'] ?? -1);
    if (!valid_omr_upload_id($uid)) json_out(['ok' => false, 'error' => 'Bad upload id'], 400);
    $dir = $tmp_root . '/' . $uid;
    if (!is_dir($dir)) json_out(['ok' => false, 'error' => 'Unknown upload'], 404);
    if ($idx < 0 || $idx > 5000) json_out(['ok' => false, 'error' => 'Bad chunk index'], 400);
    if (!isset($_FILES['data']) || $_FILES['data']['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => 'Chunk transfer failed'], 400);
    }
    if ($_FILES['data']['size'] > 2 * 1024 * 1024) {
        json_out(['ok' => false, 'error' => 'Chunk too large'], 400);
    }
    $dest = $dir . '/chunk_' . str_pad((string)$idx, 5, '0', STR_PAD_LEFT) . '.bin';
    if (!move_uploaded_file($_FILES['data']['tmp_name'], $dest)) {
        json_out(['ok' => false, 'error' => 'Chunk save failed'], 500);
    }
    @touch($dir);
    json_out(['ok' => true]);
}

if ($action === 'finalize') {
    $uid   = (string)($_POST['upload_id'] ?? '');
    $total = (int)($_POST['total_chunks'] ?? 0);
    if (!valid_omr_upload_id($uid)) json_out(['ok' => false, 'error' => 'Bad upload id'], 400);
    $dir = $tmp_root . '/' . $uid;
    if (!is_dir($dir)) json_out(['ok' => false, 'error' => 'Unknown upload'], 404);
    $meta = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
    if (!is_array($meta)) {
        json_out(['ok' => false, 'error' => 'Upload metadata missing'], 400);
    }
    $expected_size = (int)$meta['size'];
    $kind = (string)($meta['kind'] ?? 'bulk');

    if (!is_dir($omr_root)) @mkdir($omr_root, 0775, true);
    $base = $kind === 'answer_key' ? 'answer_key' : 'bulk';
    $final_name = $base . '_' . (int)$intake['id'] . '_' . date('Ymd_His') . '_' . substr($uid, 0, 8) . '.pdf';
    $final_path = $omr_root . '/' . $final_name;
    $assembled  = $dir . '/__assembled.pdf';

    $out = fopen($assembled, 'wb');
    if (!$out) json_out(['ok' => false, 'error' => 'Cannot open output'], 500);
    for ($i = 0; $i < $total; $i++) {
        $chunk = $dir . '/chunk_' . str_pad((string)$i, 5, '0', STR_PAD_LEFT) . '.bin';
        if (!is_file($chunk)) {
            fclose($out); @unlink($assembled);
            json_out(['ok' => false, 'error' => "Missing chunk $i"], 400);
        }
        $in = fopen($chunk, 'rb');
        while (!feof($in)) { $buf = fread($in, 1 << 20); if ($buf !== false && $buf !== '') fwrite($out, $buf); }
        fclose($in);
    }
    fclose($out);

    $fh = fopen($assembled, 'rb');
    $magic = $fh ? fread($fh, 5) : '';
    if ($fh) fclose($fh);
    if ($magic !== '%PDF-') {
        @unlink($assembled);
        json_out(['ok' => false, 'error' => 'Assembled file is not a valid PDF'], 400);
    }
    if (filesize($assembled) !== $expected_size) {
        @unlink($assembled);
        json_out(['ok' => false, 'error' => 'Size mismatch after reassembly'], 400);
    }

    if (!rename($assembled, $final_path)) {
        @unlink($assembled);
        json_out(['ok' => false, 'error' => 'Final move failed'], 500);
    }

    foreach ((array)glob($dir . '/*') as $f) @unlink($f);
    @rmdir($dir);

    json_out(['ok' => true, 'filename' => $final_name, 'kind' => $kind]);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 400);
