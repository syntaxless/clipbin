<?php
// Upload in one request without signing in, for the iPhone "Share to Clipbin"
// shortcut (iOS Safari can't be a share target the way Android Chrome is).
//
//   POST share.php?key=<upload key>   multipart field "video" (+ optional "title")
//
// Answers with the video's link as plain text, so the shortcut can copy it
// straight to the clipboard. Errors start with "Clipbin:" and explain the problem.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

function ini_bytes(string $v): int
{
    $n = (int) $v;
    $u = strtolower(substr(trim($v), -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
}

function reply(string $msg, int $code = 200): void
{
    http_response_code($code);
    echo $msg;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply('Clipbin: this address is for the iPhone shortcut. It only accepts uploads.', 405);
}

$given = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_CLIPBIN_KEY'] ?? $_POST['key'] ?? '');
$key = setting('upload_key');
if (!$key || !hash_equals($key, $given)) {
    usleep(400000); // slow down guessing
    reply('Clipbin: wrong upload key. Copy the shortcut address again from the Clipbin upload page.', 403);
}

$f = $_FILES['video'] ?? null;
if (!$f) {
    // When a request is bigger than post_max_size PHP drops every field.
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > ini_bytes((string) ini_get('post_max_size'))) {
        reply('Clipbin: that video is too big for one upload (the server takes up to ' . ini_get('post_max_size')
            . 'B). Use a smaller size in the shortcut, or upload it from the Clipbin page instead.', 413);
    }
    reply('Clipbin: no video arrived. In the shortcut, the form field must be a File named "video".', 400);
}
if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
    reply('Clipbin: that video is too big for one upload (the server takes up to ' . ini_get('upload_max_filesize')
        . 'B). Use a smaller size in the shortcut, or upload it from the Clipbin page instead.', 413);
}
if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    reply('Clipbin: the upload broke off. Try again.', 400);
}

$size = (int) $f['size'];
$max = (int) config('max_upload_mb') * 1024 * 1024;
if ($size <= 0) {
    reply('Clipbin: the video was empty.', 400);
}
if ($size > $max) {
    reply('Clipbin: that video is larger than the ' . config('max_upload_mb') . ' MB limit.', 413);
}

// Must be an MP4/MOV family file: bytes 4..8 are "ftyp".
$fh = fopen($f['tmp_name'], 'rb');
$head = (string) fread($fh, 12);
fclose($fh);
if (strlen($head) < 8 || substr($head, 4, 4) !== 'ftyp') {
    reply('Clipbin: that is not an MP4 or MOV video. Check that the shortcut has the "Encode Media" step.', 415);
}

$title = trim((string) ($_POST['title'] ?? ''));
if ($title === '') {
    $title = preg_replace('/\.[^.]+$/', '', (string) $f['name']);
    // Names the shortcut makes up itself say nothing about the video.
    if (preg_match('/^(encoded|movie|video|file|untitled)[\s_-]*\d*$/i', (string) $title)) {
        $title = '';
    }
}
$title = mb_substr((string) $title, 0, 200);

$id = new_id();
$media = media_dir();
if (!move_uploaded_file($f['tmp_name'], "$media/$id.mp4")) {
    reply('Clipbin: could not store the video on the server.', 500);
}
@chmod("$media/$id.mp4", 0644);
[$w, $h, $dur] = mp4_info("$media/$id.mp4");

// No thumbnail yet: the library page makes one in the browser next time it opens.
db()->prepare('INSERT INTO videos (id, title, size, received, width, height, duration, status, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, "ready", ?)')
    ->execute([$id, $title, $size, $size, $w, $h, $dur, time()]);

reply(page_url(find_video($id)));
