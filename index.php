<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

start_session();
$error = '';

// Share-sheet fallback: normally the service worker catches this POST.
if (isset($_GET['share-target'])) {
    header('Location: ' . base_url() . '/?shared=1', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'login') {
        // Slow down guessing.
        usleep(400000);
        if (check_password((string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: ' . base_url() . '/');
            exit;
        }
        $pw = (string) config('password');
        $error = ($pw === '' || $pw === 'change-me')
            ? 'Set a password in config.php first.'
            : 'Wrong password.';
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . base_url() . '/');
        exit;
    }
}

$site = config('site_name');
$csrf = csrf_token();

if (!is_logged_in()) {
    page_head(e($site) . ' · Sign in');
    ?>
    <section class="card narrow">
        <h1>Sign in to upload</h1>
        <p class="muted">Anyone with a link can watch. Only you can upload.</p>
        <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <label>Password <input type="password" name="password" autofocus required autocomplete="current-password"></label>
            <button class="btn primary" type="submit">Sign in</button>
        </form>
    </section>
    <?php
    page_foot();
    exit;
}

$videos = db()->query('SELECT * FROM videos WHERE status = "ready" ORDER BY created_at DESC LIMIT 500')->fetchAll();
$cfg = [
    'base' => base_url(),
    'csrf' => $csrf,
    'maxBytes' => (int) config('max_upload_mb') * 1024 * 1024,
    'maxDim' => (int) config('max_dimension'),
];
page_head(e($site) . ' · Upload');
?>
<section class="upload">
    <label id="drop" class="drop" tabindex="0">
        <input id="file" type="file" accept="video/*,.mkv,.mov,.mp4,.webm,.m4v" hidden>
        <span class="drop-icon" aria-hidden="true"></span>
        <strong class="fine">Drop a video here</strong>
        <span class="muted fine">or click to choose a file. You can also paste one.</span>
        <strong class="coarse">Tap to choose a video</strong>
        <span class="muted coarse">From your camera roll or files</span>
    </label>
    <div id="jobs"></div>
</section>

<section class="library">
    <div class="library-head">
        <h2>Your videos <span class="muted" id="count"><?= count($videos) ?></span></h2>
        <form method="post" class="head-actions">
            <a class="btn ghost small" href="<?= e(base_url()) ?>/settings.php">Settings</a>
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button class="btn ghost small" type="submit">Sign out</button>
        </form>
    </div>
    <?php if (!$videos): ?>
        <p class="muted empty">Nothing here yet. Your uploads will show up here.</p>
    <?php endif; ?>
    <ul class="grid" id="grid">
        <?php foreach ($videos as $v): ?>
            <li class="tile" data-id="<?= e($v['id']) ?>"<?= is_file(media_dir() . '/' . $v['id'] . '.jpg') ? '' : ' data-needs-thumb="' . e(video_url($v)) . '"' ?>>
                <a class="thumb" href="<?= e(page_url($v)) ?>">
                    <img src="<?= e(thumb_url($v)) ?>" alt="" loading="lazy" onerror="this.remove()">
                    <span class="dur"><?= e(fmt_duration((float) $v['duration'])) ?></span>
                </a>
                <div class="meta">
                    <a class="title" href="<?= e(page_url($v)) ?>"><?= e($v['title'] !== '' ? $v['title'] : 'Untitled') ?></a>
                    <span class="muted small"><?= (int) $v['views'] ?> view<?= (int) $v['views'] === 1 ? '' : 's' ?> · <?= e(fmt_bytes((int) $v['size'])) ?> · <?= e(date('M j, Y', (int) $v['created_at'])) ?></span>
                </div>
                <div class="actions">
                    <button class="btn small" data-copy="<?= e(page_url($v)) ?>">Copy link</button>
                    <button class="btn small" data-share="<?= e(page_url($v)) ?>" hidden>Share</button>
                    <button class="btn ghost small" data-rename>Rename</button>
                    <button class="btn ghost small danger" data-delete>Delete</button>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php
page_foot('<script>window.PASTEY = ' . json_encode($cfg, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ';</script>'
    . '<script type="module" src="' . e(asset_url('upload.js')) . '"></script>');
