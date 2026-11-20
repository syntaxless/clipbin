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

function cleanup_stale(): void
{
    $cutoff = time() - 86400;
    $st = db()->prepare('SELECT id FROM videos WHERE status = "uploading" AND created_at < ?');
    $st->execute([$cutoff]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        @unlink(part_path($id));
        db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
    }
}

switch ($action) {
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
        $id = new_id();
        db()->prepare('INSERT INTO videos (id, title, size, width, height, duration, status, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, "uploading", ?)')
            ->execute([
                $id, $title, $size,
                max(0, (int) ($b['width'] ?? 0)),
                max(0, (int) ($b['height'] ?? 0)),
                max(0.0, (float) ($b['duration'] ?? 0)),
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
        $data = (string) file_get_contents('php://input');
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
        if (!$v || $v['status'] !== 'uploading') {
            fail('Unknown upload', 404);
        }
        $part = part_path($id);
        clearstatcache();
        if (!is_file($part) || filesize($part) !== (int) $v['size']) {
            fail('Upload is incomplete');
        }
        // Must be an MP4/MOV family file: bytes 4..8 are "ftyp".
        $fh = fopen($part, 'rb');
        $head = fread($fh, 12);
        fclose($fh);
        if (strlen($head) < 8 || substr($head, 4, 4) !== 'ftyp') {
            @unlink($part);
            db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
            fail('That is not an MP4 video');
        }
        $media = media_dir();
        if (!rename($part, "$media/$id.mp4")) {
            fail('Could not store video', 500);
        }
        $b = json_body();
        $thumb = (string) ($b['thumb'] ?? '');
        if (preg_match('#^data:image/jpeg;base64,#', $thumb)) {
            $bin = base64_decode(substr($thumb, 23), true);
            $info = $bin ? @getimagesizefromstring($bin) : false;
            if ($info && $info[2] === IMAGETYPE_JPEG && strlen($bin) < 4 * 1024 * 1024) {
                file_put_contents("$media/$id.jpg", $bin);
            }
        }
        db()->prepare('UPDATE videos SET status = "ready", width = ?, height = ?, duration = ? WHERE id = ?')
            ->execute([
                max(0, (int) ($b['width'] ?? $v['width'])),
                max(0, (int) ($b['height'] ?? $v['height'])),
                max(0.0, (float) ($b['duration'] ?? $v['duration'])),
                $id,
            ]);
        $v = find_video($id);
        out(['id' => $id, 'url' => page_url($v)]);
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
        $media = media_dir();
        @unlink("$media/$id.mp4");
        @unlink("$media/$id.jpg");
        @unlink(part_path($id));
        db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
        out(['ok' => true]);
    }

    default:
        fail('Unknown action', 404);
}
