<?php
// Upload in one request without signing in, for the iPhone "Share to Pastey"
// shortcut (iOS Safari can't be a share target the way Android Chrome is).
//
//   POST share.php?key=<upload key>   multipart field "video" (+ optional "title")
//
// Answers with the video's link as plain text, so the shortcut can copy it
// straight to the clipboard. Errors start with "Pastey:" and explain the problem.
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
    reply('Pastey: this address is for the iPhone shortcut. It only accepts uploads.', 405);
}

$given = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_CLIPBIN_KEY'] ?? $_POST['key'] ?? '');
$key = setting('upload_key');
if (!$key || !hash_equals($key, $given)) {
    usleep(400000); // slow down guessing
    reply('Pastey: wrong upload key. Copy the shortcut address again from the Pastey upload page.', 403);
}

// Take the "video" field, or else the first file sent under any name, so a
// shortcut with a differently named field still works.
$f = null;
foreach (isset($_FILES['video']) ? ['video' => $_FILES['video']] + $_FILES : $_FILES as $cand) {
    if (is_array($cand['tmp_name'])) {
        $cand = array_map(fn ($v) => $v[0] ?? null, $cand); // field sent as name[]
    }
    if (($cand['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $cand;
        break;
    }
}
$raw = false;
if (!$f && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') !== 0) {
    // "Request Body: File" in Shortcuts sends the video as the whole body.
    $tmp = tempnam(sys_get_temp_dir(), 'pst');
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $n = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if ($n > 0) {
        $f = ['name' => '', 'tmp_name' => $tmp, 'size' => $n, 'error' => UPLOAD_ERR_OK];
        $raw = true;
    } else {
        @unlink($tmp);
    }
}
if (!$f) {
    // When a request is bigger than post_max_size PHP drops every field.
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > ini_bytes((string) ini_get('post_max_size'))) {
        reply('Pastey: that video is too big for one upload (the server takes up to ' . ini_get('post_max_size')
            . 'B). Use a smaller size in the shortcut, or upload it from the Pastey page instead.', 413);
    }
    // Say what did arrive, so a wrong setting in the shortcut is easy to spot.
    $got = [];
    foreach ($_POST as $k => $v) {
        $got[] = '"' . $k . '" (text, ' . strlen(is_string($v) ? $v : json_encode($v)) . ' characters)';
    }
    $ct = strtok((string) ($_SERVER['CONTENT_TYPE'] ?? 'none'), ';');
    reply('Pastey: no video arrived. The request had ' . ($got ? implode(', ', $got) : 'no fields')
        . ' (' . $ct . ', ' . $len . ' bytes). In Get Contents of URL, add a File field named "video" set to Encoded Media,'
        . ' and in Encode Media pick Shortcut Input.', 400);
}
if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
    reply('Pastey: that video is too big for one upload (the server takes up to ' . ini_get('upload_max_filesize')
        . 'B). Use a smaller size in the shortcut, or upload it from the Pastey page instead.', 413);
}
if ($f['error'] !== UPLOAD_ERR_OK || (!$raw && !is_uploaded_file($f['tmp_name']))) {
    reply('Pastey: the upload broke off. Try again.', 400);
}

$size = (int) $f['size'];
$max = (int) config('max_upload_mb') * 1024 * 1024;
if ($size <= 0) {
    reply('Pastey: the video was empty.', 400);
}
if ($size > $max) {
    reply('Pastey: that video is larger than the ' . config('max_upload_mb') . ' MB limit.', 413);
}

// Must be an MP4/MOV family file: bytes 4..8 are "ftyp".
$fh = fopen($f['tmp_name'], 'rb');
$head = (string) fread($fh, 12);
fclose($fh);
if (strlen($head) < 8 || substr($head, 4, 4) !== 'ftyp') {
    if ($raw) {
        @unlink($f['tmp_name']);
    }
    reply('Pastey: that is not an MP4 or MOV video. Check that the shortcut has the "Encode Media" step.', 415);
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
$stored = $raw ? rename($f['tmp_name'], "$media/$id.mp4") : move_uploaded_file($f['tmp_name'], "$media/$id.mp4");
if (!$stored) {
    reply('Pastey: could not store the video on the server.', 500);
}
@chmod("$media/$id.mp4", 0644);
[$w, $h, $dur] = mp4_info("$media/$id.mp4");

// No thumbnail yet: the library page makes one in the browser next time it opens.
db()->prepare('INSERT INTO videos (id, title, size, received, width, height, duration, status, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, "ready", ?)')
    ->execute([$id, $title, $size, $size, $w, $h, $dur, time()]);

reply(page_url(find_video($id)));
