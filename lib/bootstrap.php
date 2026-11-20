<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function config(string $key)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_ROOT . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            echo 'Clipbin is not configured yet: copy config.sample.php to config.php and edit it.';
            exit;
        }
        $defaults = [
            'site_name' => 'Clipbin',
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
    $pdo = new PDO('sqlite:' . $dir . '/clipbin.sqlite');
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
    return $pdo;
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
    session_name('clipbin');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => str_starts_with(base_url(), 'https://'),
    ]);
    session_start();
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

function page_head(string $title, string $extraHead = ''): void
{
    $site = e(config('site_name'));
    $base = e(base_url());
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<link rel="stylesheet" href="{$base}/assets/app.css">
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
    echo "</main>\n{$scripts}\n{$sw}\n</body>\n</html>\n";
}
