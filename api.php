<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail(string $msg, int $code = 400): void
{
    out(['error' => $msg], $code);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('POST only', 405);
}
if (!is_logged_in()) {
    fail('Not logged in', 401);
}
if (!check_csrf($_SERVER['HTTP_X_CSRF'] ?? null)) {
    fail('Bad CSRF token, reload the page', 403);
}
// Release the session lock so parallel requests are not serialized.
session_write_close();

$action = $_GET['action'] ?? '';
$tmpDir = APP_ROOT . '/data/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
}

function json_body(): array
{
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function part_path(string $id): string
{
    return APP_ROOT . '/data/tmp/' . $id . '.part';
}

// Drop unfinished uploads nobody has touched for two days. "Touched" is the
// partial file's last write, so a slow upload that is resumed later survives.
function cleanup_stale(): void
{
    $cutoff = time() - 2 * 86400;
    $st = db()->prepare('SELECT id FROM videos WHERE status = "uploading" AND created_at < ?');
    $st->execute([$cutoff]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $part = part_path($id);
        if (is_file($part) && filemtime($part) >= $cutoff) {
            continue;
        }
        @unlink($part);
        db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
    }
    // Galleries whose photos never arrived.
    $st = db()->prepare('SELECT id FROM videos WHERE kind = "gallery" AND created_at < ?
                         AND NOT EXISTS (SELECT 1 FROM videos c WHERE c.parent = videos.id)');
    $st->execute([$cutoff]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
    }
    forget_old_groups();
}

// The answer to "finish": the item's link, and the gallery's when it is in one.
function finished(array $v): array
{
    $res = ['id' => $v['id'], 'url' => page_url($v), 'kind' => $v['kind'], 'status' => $v['status']];
    if ($v['parent'] !== '' && ($g = find_video($v['parent']))) {
        $res['gallery'] = page_url($g);
    }
    return $res;
}

switch ($action) {
    // Where an upload stands, so the browser can pick up after an interruption
    // (phone locked, app switched, page reloaded).
    case 'status': {
        $b = json_body();
        $v = find_video((string) ($b['id'] ?? ''));
        if (!$v) {
            fail('Unknown upload', 404);
        }
        $res = ['status' => $v['status'], 'received' => (int) $v['received'], 'size' => (int) $v['size']];
        if ($v['status'] === 'uploading') {
            // Trust the bytes actually on disk over the counter.
            clearstatcache();
            $part = part_path($v['id']);
            $res['received'] = is_file($part) ? min((int) $v['received'], (int) filesize($part)) : 0;
            $res['chunkSize'] = (int) config('chunk_mb') * 1024 * 1024;
        } else {
            $res += finished($v);
        }
        out($res);
    }

    // A gallery for photos picked together. The photos join it as they finish.
    case 'gallery': {
        $b = json_body();
        $g = new_gallery((string) ($b['title'] ?? ''));
        out(['id' => $g['id'], 'url' => page_url($g)]);
    }

    case 'init': {
        $b = json_body();
        $size = (int) ($b['size'] ?? 0);
        $max = (int) config('max_upload_mb') * 1024 * 1024;
        if ($size <= 0) {
            fail('Empty file');
        }
        if ($size > $max) {
            fail('File is larger than the ' . config('max_upload_mb') . ' MB limit');
        }
        cleanup_stale();
        $title = mb_substr(trim((string) ($b['title'] ?? '')), 0, 200);
        // What the browser says it is, only until the file is checked at "finish".
        $kind = in_array($b['kind'] ?? '', ['video', 'image', 'audio', 'file'], true) ? $b['kind'] : 'video';
        $id = new_id();
        db()->prepare('INSERT INTO videos (id, title, size, width, height, duration, status, kind, ext, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, "uploading", ?, "", ?)')
            ->execute([
                $id, $title, $size,
                max(0, (int) ($b['width'] ?? 0)),
                max(0, (int) ($b['height'] ?? 0)),
                max(0.0, (float) ($b['duration'] ?? 0)),
                $kind,
                time(),
            ]);
        touch(part_path($id));
        out(['id' => $id, 'chunkSize' => (int) config('chunk_mb') * 1024 * 1024]);
    }

    case 'chunk': {
        $id = (string) ($_GET['id'] ?? '');
        $offset = (int) ($_GET['offset'] ?? -1);
        $v = find_video($id);
        if (!$v || $v['status'] !== 'uploading') {
            fail('Unknown upload', 404);
        }
        // Pieces arrive as a multipart file field ("chunk"). Some hosts' firewalls
        // (DreamHost's mod_security) reject raw binary request bodies.
        if (isset($_FILES['chunk']) && $_FILES['chunk']['error'] === UPLOAD_ERR_OK) {
            $data = (string) file_get_contents($_FILES['chunk']['tmp_name']);
        } elseif (isset($_FILES['chunk']) || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') === 0) {
            // Too big for PHP's upload limits (PHP drops the file); the client retries smaller.
            fail('Bad chunk size');
        } else {
            $data = (string) file_get_contents('php://input');
        }
        $len = strlen($data);
        $limit = (int) config('chunk_mb') * 1024 * 1024;
        if ($len === 0 || $len > $limit) {
            fail('Bad chunk size');
        }
        $received = (int) $v['received'];
        if ($offset < 0 || $offset > $received || $offset + $len > (int) $v['size']) {
            out(['error' => 'Bad offset', 'received' => $received], 409);
        }
        $fh = fopen(part_path($id), 'c');
        if (!$fh || !flock($fh, LOCK_EX)) {
            fail('Could not write upload', 500);
        }
        fseek($fh, $offset);
        fwrite($fh, $data);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $newReceived = max($received, $offset + $len);
        db()->prepare('UPDATE videos SET received = ? WHERE id = ?')->execute([$newReceived, $id]);
        out(['received' => $newReceived]);
    }

    case 'finish': {
        $id = (string) ($_GET['id'] ?? '');
        $v = find_video($id);
        // Already finished (the reply got lost when the phone locked): same answer again.
        if ($v && in_array($v['status'], ['ready', 'held'], true)) {
            out(finished($v));
        }
        if (!$v || $v['status'] !== 'uploading') {
            fail('Unknown upload', 404);
        }
        $part = part_path($id);
        clearstatcache();
        if (!is_file($part) || filesize($part) !== (int) $v['size']) {
            fail('Upload is incomplete');
        }
        $b = json_body();
        try {
            $v = finish_item($part, false, $id, [
                'width' => $b['width'] ?? $v['width'],
                'height' => $b['height'] ?? $v['height'],
                'duration' => $b['duration'] ?? $v['duration'],
                'thumb' => $b['thumb'] ?? '',
                'gallery' => $b['gallery'] ?? '',
            ]);
        } catch (UploadError $e) {
            @unlink($part);
            db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
            fail($e->getMessage(), $e->getCode() >= 500 ? 500 : 415);
        }
        out(finished($v));
    }

    // Thumbnail (and size, if unknown) for something that arrived without the
    // browser, e.g. from the iPhone shortcut. Made by the library page.
    case 'thumb': {
        $b = json_body();
        $v = find_video((string) ($b['id'] ?? ''));
        if (!$v || $v['status'] !== 'ready') {
            fail('Not found', 404);
        }
        $media = media_dir();
        $dst = $v['kind'] === 'image' ? "$media/{$v['id']}_t.jpg" : "$media/{$v['id']}.jpg";
        if (in_array($v['kind'], ['video', 'image'], true) && !is_file($dst)) {
            save_data_jpeg((string) ($b['thumb'] ?? ''), $dst);
        }
        db()->prepare('UPDATE videos SET width = ?, height = ?, duration = ? WHERE id = ?')
            ->execute([
                (int) $v['width'] ?: max(0, (int) ($b['width'] ?? 0)),
                (int) $v['height'] ?: max(0, (int) ($b['height'] ?? 0)),
                (float) $v['duration'] ?: max(0.0, (float) ($b['duration'] ?? 0)),
                $v['id'],
            ]);
        out(['ok' => true, 'thumb' => is_file($dst) ? base_url() . '/media/' . basename($dst) : '']);
    }

    // A HEIC photo the server couldn't convert: the library page downloads it
    // (it is kept out of public reach), turns it into JPEG and sends it back.
    case 'held': {
        $b = json_body();
        $v = find_video((string) ($b['id'] ?? ''));
        $f = $v ? APP_ROOT . "/data/held/{$v['id']}.heic" : '';
        if (!$v || $v['status'] !== 'held' || !is_file($f)) {
            fail('Not found', 404);
        }
        header('Content-Type: image/heic');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    case 'replace': {
        $id = (string) ($_POST['id'] ?? '');
        $v = find_video($id);
        if (!$v || $v['status'] !== 'held') {
            fail('Not found', 404);
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || detect_ext($f['tmp_name']) !== 'jpg') {
            fail('Send the photo as a JPEG');
        }
        $media = media_dir();
        if (!move_uploaded_file($f['tmp_name'], "$media/$id.jpg")) {
            fail('Could not store the photo', 500);
        }
        @chmod("$media/$id.jpg", 0644);
        jpeg_clean("$media/$id.jpg");
        if (!save_data_jpeg((string) ($_POST['thumb'] ?? ''), "$media/{$id}_t.jpg")) {
            make_thumb("$media/$id.jpg", 'jpg', "$media/{$id}_t.jpg");
        }
        [$w, $h] = image_size("$media/$id.jpg", 'jpg');
        @unlink(APP_ROOT . "/data/held/$id.heic");
        db()->prepare('UPDATE videos SET status = "ready", ext = "jpg", size = ?, received = ?, width = ?, height = ? WHERE id = ?')
            ->execute([filesize("$media/$id.jpg"), filesize("$media/$id.jpg"), $w, $h, $id]);
        $v = find_video($id);
        out(['ok' => true, 'thumb' => thumb_src($v)]);
    }

    case 'rename': {
        $b = json_body();
        $id = (string) ($b['id'] ?? '');
        if (!find_video($id)) {
            fail('Not found', 404);
        }
        $title = mb_substr(trim((string) ($b['title'] ?? '')), 0, 200);
        db()->prepare('UPDATE videos SET title = ? WHERE id = ?')->execute([$title, $id]);
        out(['ok' => true]);
    }

    case 'delete': {
        $b = json_body();
        $id = (string) ($b['id'] ?? '');
        if (!find_video($id)) {
            fail('Not found', 404);
        }
        delete_item($id); // a gallery takes its photos with it
        out(['ok' => true]);
    }

    default:
        fail('Unknown action', 404);
}
