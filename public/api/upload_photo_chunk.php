<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
$u = require_admin();
check_csrf();

$intake = active_intake();
if (!$intake) json_out(['ok' => false, 'error' => 'No active intake'], 400);

if (!is_dir(UPLOAD_PHOTO_DIR)) @mkdir(UPLOAD_PHOTO_DIR, 0775, true);
$tmp_root = UPLOAD_PHOTO_DIR . '/.tmp';
if (!is_dir($tmp_root)) @mkdir($tmp_root, 0775, true);

foreach ((array)glob($tmp_root . '/*', GLOB_ONLYDIR) as $d) {
    if (filemtime($d) < time() - 7200) {
        foreach ((array)glob($d . '/*') as $f) @unlink($f);
        @rmdir($d);
    }
}

function valid_photo_upload_id(string $id): bool {
    return (bool)preg_match('/^[a-f0-9]{32}$/', $id);
}

const ALLOWED_PHOTO_EXT = ['jpg', 'jpeg', 'png', 'webp'];

function detect_image_kind(string $path): ?string {
    $fh = fopen($path, 'rb');
    if (!$fh) return null;
    $h = fread($fh, 12);
    fclose($fh);
    if (strlen($h) < 4) return null;
    if (substr($h, 0, 3) === "\xFF\xD8\xFF") return 'jpeg';
    if (substr($h, 0, 8) === "\x89PNG\r\n\x1A\n") return 'png';
    if (strlen($h) >= 12 && substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP') return 'webp';
    return null;
}

$action = $_POST['action'] ?? '';

if ($action === 'init') {
    $name = (string)($_POST['name'] ?? '');
    $size = (int)($_POST['size'] ?? 0);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $dept = trim(pathinfo($name, PATHINFO_FILENAME));
    if (!in_array($ext, ALLOWED_PHOTO_EXT, true)) {
        json_out(['ok' => false, 'error' => 'Only JPG / PNG / WEBP allowed'], 400);
    }
    if ($size <= 0 || $size > 25 * 1024 * 1024) {
        json_out(['ok' => false, 'error' => 'Size out of range (max 25 MB)'], 400);
    }
    $cand = one('SELECT id FROM candidates WHERE intake_id=? AND dept_reg_no=? AND is_international=0', [$intake['id'], $dept]);
    if (!$cand) json_out(['ok' => false, 'error' => "No candidate for '$dept'"], 404);

    $uid = bin2hex(random_bytes(16));
    $dir = $tmp_root . '/' . $uid;
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/meta.json', json_encode(['dept' => $dept, 'size' => $size, 'ext' => $ext]));
    json_out(['ok' => true, 'upload_id' => $uid]);
}

if ($action === 'chunk') {
    $uid = (string)($_POST['upload_id'] ?? '');
    $idx = (int)($_POST['index'] ?? -1);
    if (!valid_photo_upload_id($uid)) json_out(['ok' => false, 'error' => 'Bad upload id'], 400);
    $dir = $tmp_root . '/' . $uid;
    if (!is_dir($dir)) json_out(['ok' => false, 'error' => 'Unknown upload'], 404);
    if ($idx < 0 || $idx > 2000) json_out(['ok' => false, 'error' => 'Bad chunk index'], 400);
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
    if (!valid_photo_upload_id($uid)) json_out(['ok' => false, 'error' => 'Bad upload id'], 400);
    $dir = $tmp_root . '/' . $uid;
    if (!is_dir($dir)) json_out(['ok' => false, 'error' => 'Unknown upload'], 404);
    $meta = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
    if (!is_array($meta) || empty($meta['dept'])) {
        json_out(['ok' => false, 'error' => 'Upload metadata missing'], 400);
    }
    $dept = (string)$meta['dept'];
    $ext  = (string)$meta['ext'];
    $expected_size = (int)$meta['size'];

    $cand = one('SELECT id, dept_reg_no, photo FROM candidates WHERE intake_id=? AND dept_reg_no=? AND is_international=0', [$intake['id'], $dept]);
    if (!$cand) json_out(['ok' => false, 'error' => 'Candidate no longer exists'], 404);

    $safe_reg = preg_replace('/[^A-Za-z0-9_-]/', '_', $cand['dept_reg_no']);
    $final_ext = $ext === 'jpeg' ? 'jpg' : $ext;
    $final_name = $safe_reg . '.' . $final_ext;
    $final_path = UPLOAD_PHOTO_DIR . '/' . $final_name;
    $assembled  = $dir . '/__assembled.' . $final_ext;

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

    $kind = detect_image_kind($assembled);
    $extExpected = $ext === 'jpg' ? 'jpeg' : $ext;
    if ($kind === null || $kind !== $extExpected) {
        @unlink($assembled);
        json_out(['ok' => false, 'error' => 'File does not look like a valid ' . strtoupper($ext) . ' image'], 400);
    }
    if (filesize($assembled) !== $expected_size) {
        @unlink($assembled);
        json_out(['ok' => false, 'error' => 'Size mismatch after reassembly'], 400);
    }

    // If the candidate already has a photo with a different extension, remove the old one
    if (!empty($cand['photo']) && $cand['photo'] !== $final_name) {
        $old = UPLOAD_PHOTO_DIR . '/' . $cand['photo'];
        if (is_file($old)) @unlink($old);
    }

    if (!rename($assembled, $final_path)) {
        @unlink($assembled);
        json_out(['ok' => false, 'error' => 'Final move failed'], 500);
    }
    q('UPDATE candidates SET photo=? WHERE id=?', [$final_name, $cand['id']]);

    foreach ((array)glob($dir . '/*') as $f) @unlink($f);
    @rmdir($dir);

    json_out(['ok' => true, 'dept_reg_no' => $cand['dept_reg_no'], 'filename' => $final_name]);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 400);
