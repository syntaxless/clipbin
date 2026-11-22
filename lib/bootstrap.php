<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
require __DIR__ . '/media.php';

function config(string $key)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_ROOT . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            echo 'Pastey is not configured yet: copy config.sample.php to config.php and edit it.';
            exit;
        }
        $defaults = [
            'site_name' => 'Pastey',
            'base_url' => '',
            'password' => '',
            'max_upload_mb' => 2048,
            'chunk_mb' => 8,
            'max_dimension' => 1920,
        ];
        $cfg = array_merge($defaults, require $file);
    }
    return $cfg[$key] ?? null;
}

function base_url(): string
{
    $b = rtrim((string) config('base_url'), '/');
    if ($b !== '') {
        return $b;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

// Older installs kept the database as data/clipbin.sqlite (the app's first
// name). Move it, and any SQLite side files, to the new name once. If the
// move fails (another request got there first, or the folder isn't
// writable) we just carry on: either the new file now exists, or we keep
// using the old one where it is.
function migrate_old_db(string $dir, string &$file): void
{
    $old = $dir . '/clipbin.sqlite';
    if (is_file($file) || !is_file($old)) {
        return;
    }
    foreach (['-wal', '-shm', '-journal'] as $side) {
        if (is_file($old . $side) && !is_file($file . $side)) {
            @rename($old . $side, $file . $side);
        }
    }
    if (!@rename($old, $file) && !is_file($file)) {
        $file = $old;
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dir = APP_ROOT . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = $dir . '/pastey.sqlite';
    migrate_old_db($dir, $file);
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS videos (
        id TEXT PRIMARY KEY,
        title TEXT NOT NULL DEFAULT "",
        size INTEGER NOT NULL DEFAULT 0,
        received INTEGER NOT NULL DEFAULT 0,
        width INTEGER NOT NULL DEFAULT 0,
        height INTEGER NOT NULL DEFAULT 0,
        duration REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT "uploading",
        views INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    migrate_schema($pdo);
    return $pdo;
}

// Version 1 (photos, audio, galleries): every row gets a kind and a file
// extension. Rows from before are all MP4 videos, which is what the defaults
// say, so existing links keep working untouched. The table keeps its old
// name "videos" for the same reason.
function migrate_schema(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 1) {
        return;
    }
    $have = array_column($pdo->query('PRAGMA table_info(videos)')->fetchAll(), 'name');
    $add = [
        'kind' => "TEXT NOT NULL DEFAULT 'video'",   // video, image, audio, file, gallery
        'ext' => "TEXT NOT NULL DEFAULT 'mp4'",      // stored as media/<id>.<ext>
        'parent' => "TEXT NOT NULL DEFAULT ''",      // gallery a photo belongs to
        'position' => 'INTEGER NOT NULL DEFAULT 0',  // order inside the gallery
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $have, true)) {
            try {
                $pdo->exec("ALTER TABLE videos ADD COLUMN $col $def");
            } catch (PDOException $e) {
                // another request added it at the same moment
            }
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS videos_parent ON videos (parent)');
    $pdo->exec('PRAGMA user_version = 1');
}

function setting(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')->execute([$key, $value]);
        return $value;
    }
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

// Secret key for uploading without signing in (the iPhone shortcut).
// Made on first use; "Make a new key" on the upload page replaces it.
function upload_key(bool $renew = false): string
{
    $k = $renew ? null : setting('upload_key');
    return $k ?: setting('upload_key', bin2hex(random_bytes(20)));
}

function share_url(): string
{
    return base_url() . '/share.php?key=' . upload_key();
}

// Size and length of an MP4/MOV file, read from its moov box, so videos that
// arrive without the browser (the iPhone shortcut) still get proper embeds.
// Returns [width, height, duration] with zeros for anything not found.
function mp4_info(string $path): array
{
    $info = [0, 0, 0.0];
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return $info;
    }
    $fileSize = (int) filesize($path);
    $pos = 0;
    $moov = null;
    while ($pos + 8 <= $fileSize) {
        fseek($fh, $pos);
        $h = fread($fh, 16);
        if (strlen($h) < 8) {
            break;
        }
        $size = unpack('N', $h)[1];
        $type = substr($h, 4, 4);
        $hdr = 8;
        if ($size === 1 && strlen($h) >= 16) {
            $size = unpack('J', substr($h, 8, 8))[1];
            $hdr = 16;
        } elseif ($size === 0) {
            $size = $fileSize - $pos;
        }
        if ($size < $hdr) {
            break;
        }
        if ($type === 'moov') {
            if ($size > 64 * 1024 * 1024) {
                break;
            }
            fseek($fh, $pos + $hdr);
            $moov = fread($fh, $size - $hdr);
            break;
        }
        $pos += $size;
    }
    fclose($fh);
    if (!$moov) {
        return $info;
    }
    $boxes = function (string $buf) {
        $out = [];
        $p = 0;
        $n = strlen($buf);
        while ($p + 8 <= $n) {
            $size = unpack('N', substr($buf, $p, 4))[1];
            $type = substr($buf, $p + 4, 4);
            $hdr = 8;
            if ($size === 1 && $p + 16 <= $n) {
                $size = unpack('J', substr($buf, $p + 8, 8))[1];
                $hdr = 16;
            } elseif ($size === 0) {
                $size = $n - $p;
            }
            if ($size < $hdr || $p + $size > $n) {
                break;
            }
            $out[] = [$type, substr($buf, $p + $hdr, $size - $hdr)];
            $p += $size;
        }
        return $out;
    };
    foreach ($boxes($moov) as [$type, $body]) {
        if ($type === 'mvhd' && strlen($body) >= 32) {
            $v1 = ord($body[0]) === 1;
            $scale = unpack('N', substr($body, $v1 ? 20 : 12, 4))[1];
            $dur = $v1 ? unpack('J', substr($body, 24, 8))[1] : unpack('N', substr($body, 16, 4))[1];
            if ($scale > 0) {
                $info[2] = round($dur / $scale, 3);
            }
        } elseif ($type === 'trak' && !$info[0]) {
            $tkhd = null;
            $isVideo = false;
            foreach ($boxes($body) as [$t2, $b2]) {
                if ($t2 === 'tkhd') {
                    $tkhd = $b2;
                } elseif ($t2 === 'mdia') {
                    foreach ($boxes($b2) as [$t3, $b3]) {
                        if ($t3 === 'hdlr' && substr($b3, 8, 4) === 'vide') {
                            $isVideo = true;
                        }
                    }
                }
            }
            if ($isVideo && $tkhd && strlen($tkhd) >= 84) {
                $n = strlen($tkhd);
                $w = unpack('N', substr($tkhd, $n - 8, 4))[1] >> 16;
                $hgt = unpack('N', substr($tkhd, $n - 4, 4))[1] >> 16;
                // Rotation matrix: a non-zero "b" entry means turned 90 or 270 degrees.
                $b = unpack('N', substr($tkhd, $n - 40, 4))[1];
                $info[0] = $b !== 0 ? $hgt : $w;
                $info[1] = $b !== 0 ? $w : $hgt;
            }
        }
    }
    return $info;
}

function media_dir(): string
{
    $d = APP_ROOT . '/media';
    if (!is_dir($d)) {
        mkdir($d, 0755, true);
    }
    return $d;
}

function valid_id(?string $id): bool
{
    return is_string($id) && preg_match('/^[A-Za-z0-9]{6,12}$/', $id) === 1;
}

function new_id(int $len = 7): string
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    do {
        $id = '';
        for ($i = 0; $i < $len; $i++) {
            $id .= $alphabet[random_int(0, $max)];
        }
        $st = db()->prepare('SELECT 1 FROM videos WHERE id = ?');
        $st->execute([$id]);
    } while ($st->fetchColumn());
    return $id;
}

function find_video(string $id): ?array
{
    if (!valid_id($id)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM videos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function video_url(array $v): string
{
    return base_url() . '/media/' . $v['id'] . '.mp4';
}

function thumb_url(array $v): string
{
    return base_url() . '/media/' . $v['id'] . '.jpg';
}

function page_url(array $v): string
{
    return base_url() . '/' . $v['id'];
}

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_duration(float $s): string
{
    $s = (int) round($s);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    return $h ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
}

function fmt_bytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float) $b;
    while ($n >= 1024 && $i < 3) {
        $n /= 1024;
        $i++;
    }
    return ($i ? number_format($n, 1) : (string) $b) . ' ' . $u[$i];
}

// ---- Auth ----------------------------------------------------------------

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Keep logins for 30 days. Store sessions in our own folder so the
    // host's shared session cleanup doesn't log you out every half hour.
    $dir = APP_ROOT . '/data/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.use_strict_mode', '1');
    session_name('pastey');
    // Logins made before the rename used a cookie called "clipbin". The
    // session file itself doesn't depend on the name, so pick it up under
    // the new cookie and drop the old one, instead of signing people out.
    $old = $_COOKIE['clipbin'] ?? '';
    $carry = !isset($_COOKIE['pastey']) && preg_match('/^[A-Za-z0-9,-]{22,256}$/', $old)
        && is_file($dir . '/sess_' . $old);
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => str_starts_with(base_url(), 'https://'),
    ]);
    if ($carry) {
        session_id($old);
    }
    session_start();
    if ($old !== '' && !headers_sent()) {
        setcookie('clipbin', '', ['expires' => 1, 'path' => '/']);
    }
}

function is_logged_in(): bool
{
    start_session();
    return !empty($_SESSION['auth']);
}

function check_password(string $given): bool
{
    $pw = (string) config('password');
    if ($pw === '' || $pw === 'change-me') {
        return false; // refuse until the owner sets a real password
    }
    if (str_starts_with($pw, '$2y$') || str_starts_with($pw, '$argon2')) {
        return password_verify($given, $pw);
    }
    return hash_equals($pw, $given);
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $t): bool
{
    start_session();
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

// ---- Layout --------------------------------------------------------------

// Link to a file in assets/ with its change time attached, so phones fetch
// the new copy after a deploy instead of running a stale cached one.
function asset_url(string $name): string
{
    return base_url() . '/assets/' . $name . '?v=' . (int) @filemtime(__DIR__ . '/../assets/' . $name);
}

function page_head(string $title, string $extraHead = ''): void
{
    $site = e(config('site_name'));
    $base = e(base_url());
    $cssv = (int) @filemtime(__DIR__ . '/../assets/app.css');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<link rel="stylesheet" href="{$base}/assets/app.css?v={$cssv}">
<link rel="icon" href="{$base}/assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="{$base}/assets/icon-180.png">
<link rel="manifest" href="{$base}/manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{$site}">
{$extraHead}
</head>
<body>
<header class="top"><a class="brand" href="{$base}/"><span class="logo" aria-hidden="true"></span>{$site}</a></header>
<main>
HTML;
}

function page_foot(string $scripts = ''): void
{
    $base = json_encode(base_url() . '/');
    $sw = "<script>if('serviceWorker' in navigator){navigator.serviceWorker.register({$base}+'sw.js',{scope:{$base}}).catch(()=>{});}</script>";
    $ptr = '<script src="' . e(base_url()) . '/assets/ptr.js?v=' . (int) @filemtime(__DIR__ . '/../assets/ptr.js') . '"></script>';
    echo "</main>\n{$scripts}\n{$ptr}\n{$sw}\n</body>\n</html>\n";
}
