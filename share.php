<?php
// Upload in one request without signing in, for the iPhone "Share to Pastey"
// shortcuts (iOS Safari can't be a share target the way Android Chrome is).
//
//   POST share.php?key=<upload key>   multipart file field(s) (+ optional "title", "group")
//
// Any file field name works ("video" is what the video shortcut sends). Several
// photos in one request become a gallery, and so do photos sent one per
// request with the same "group" text (the photo shortcut's Repeat loop).
//
// Answers with the link as plain text, so the shortcut can copy it straight
// to the clipboard. Errors start with "Pastey:" and explain the problem.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

function reply(string $msg, int $code = 200): void
{
    http_response_code($code);
    echo $msg;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply('Pastey: this address is for the iPhone shortcut. It only accepts uploads.', 405);
}

$given = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_PASTEY_KEY'] ?? $_POST['key'] ?? '');
$key = setting('upload_key');
if (!$key || !hash_equals($key, $given)) {
    usleep(400000); // slow down guessing
    reply('Pastey: wrong upload key. Copy the shortcut address again from the Pastey upload page.', 403);
}

// Every file sent, "video" first, under any field name (also name[] lists).
$files = [];
foreach (isset($_FILES['video']) ? ['video' => $_FILES['video']] + $_FILES : $_FILES as $cand) {
    if (is_array($cand['tmp_name'])) {
        foreach (array_keys($cand['tmp_name']) as $i) {
            $files[] = array_map(fn ($v) => $v[$i] ?? null, $cand);
        }
    } else {
        $files[] = $cand;
    }
}
$files = array_values(array_filter($files, fn ($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
$raw = false;
if (!$files && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') !== 0) {
    // "Request Body: File" in Shortcuts sends the file as the whole body.
    $tmp = tempnam(APP_ROOT . '/data', 'pst');
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $n = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if ($n > 0) {
        $files[] = ['name' => '', 'tmp_name' => $tmp, 'size' => $n, 'error' => UPLOAD_ERR_OK];
        $raw = true;
    } else {
        @unlink($tmp);
    }
}
if (!$files) {
    // When a request is bigger than post_max_size PHP drops every field.
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > ini_bytes((string) ini_get('post_max_size'))) {
        reply('Pastey: that file is too big for one upload (the server takes up to ' . ini_get('post_max_size')
            . 'B). Use a smaller size in the shortcut, or upload it from the Pastey page instead.', 413);
    }
    // Say what did arrive, so a wrong setting in the shortcut is easy to spot.
    $got = [];
    foreach ($_POST as $k => $v) {
        $got[] = '"' . $k . '" (text, ' . strlen(is_string($v) ? $v : json_encode($v)) . ' characters)';
    }
    $ct = strtok((string) ($_SERVER['CONTENT_TYPE'] ?? 'none'), ';');
    reply('Pastey: no file arrived. The request had ' . ($got ? implode(', ', $got) : 'no fields')
        . ' (' . $ct . ', ' . $len . ' bytes). In Get Contents of URL, add a File field (for videos named "video", set to Encoded Media;'
        . ' for photos named "file", set to Converted Image).', 400);
}

$title = trim((string) ($_POST['title'] ?? ''));
$group = mb_substr(trim((string) ($_POST['group'] ?? $_GET['group'] ?? '')), 0, 200);
$max = (int) config('max_upload_mb') * 1024 * 1024;
$items = [];
$errors = [];
foreach ($files as $f) {
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        $errors[] = 'Pastey: that file is too big for one upload (the server takes up to ' . ini_get('upload_max_filesize')
            . 'B). Use a smaller size in the shortcut, or upload it from the Pastey page instead.';
        continue;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || (!$raw && !is_uploaded_file($f['tmp_name']))) {
        $errors[] = 'Pastey: the upload broke off. Try again.';
        continue;
    }
    $size = (int) $f['size'];
    if ($size <= 0 || $size > $max) {
        $errors[] = $size <= 0 ? 'Pastey: the file was empty.' : 'Pastey: that file is larger than the ' . config('max_upload_mb') . ' MB limit.';
        if ($raw) {
            @unlink($f['tmp_name']);
        }
        continue;
    }
    $t = $title;
    if ($t === '' || count($files) > 1) {
        $t = preg_replace('/\.[^.]+$/', '', (string) $f['name']);
        // Names the shortcut makes up itself say nothing about the file.
        if (preg_match('/^(encoded|movie|video|file|untitled|image|img|photo|converted|audio|recording)[\s_-]*\d*$/i', (string) $t)) {
            $t = '';
        }
    }
    try {
        $items[] = finish_item($f['tmp_name'], !$raw, null, ['title' => (string) $t]);
    } catch (UploadError $e) {
        if ($raw) {
            @unlink($f['tmp_name']);
        }
        $msg = $e->getMessage();
        $errors[] = 'Pastey: ' . ($e->getCode() === 415 && count($files) === 1 && isset($_FILES['video'])
            ? 'that is not a video, photo or audio file Pastey can take. Check that the shortcut has the "Encode Media" step.'
            : lcfirst($msg));
    }
}
if (!$items) {
    reply($errors[0] ?? 'Pastey: nothing arrived.', str_contains($errors[0] ?? '', 'too big') ? 413 : 415);
}

// Several photos at once: one gallery. One photo with a group word: joins the
// photos sent before it with the same word.
$photos = array_values(array_filter($items, fn ($v) => $v['kind'] === 'image'));
$gallery = null;
if (count($photos) > 1) {
    $gallery = new_gallery($title);
    foreach ($photos as $i => $p) {
        db()->prepare('UPDATE videos SET parent = ?, position = ? WHERE id = ?')->execute([$gallery['id'], $i, $p['id']]);
    }
} elseif ($photos && $group !== '') {
    $gallery = group_into_gallery($group, $photos[0]);
}

$links = [];
if ($gallery) {
    $links[] = page_url($gallery);
}
foreach ($items as $v) {
    if (!$gallery || $v['kind'] !== 'image') {
        $links[] = page_url($v);
    }
}
reply(implode("\n", $links));
