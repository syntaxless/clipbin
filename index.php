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
    } elseif ($action === 'new_key' && is_logged_in()) {
        upload_key(true);
        header('Location: ' . base_url() . '/?newkey=1#iphone');
        exit;
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

<details class="card iphone" id="iphone"<?= isset($_GET['newkey']) ? ' open' : '' ?>>
    <summary>Upload from the iPhone share menu</summary>
    <p class="muted small">Make a shortcut once, and <strong>Pastey</strong> shows up when you tap Share on a video in Photos. It uploads the video and copies the link for you.</p>
    <?php if (isset($_GET['newkey'])): ?>
        <p class="small ok-text">New key made. The old shortcut address stops working: paste the new one into your shortcut.</p>
    <?php endif; ?>
    <label class="small">Shortcut address (keep it private, it lets anyone upload)
        <span class="copyrow">
            <input type="text" readonly value="<?= e(share_url()) ?>" onfocus="this.select()">
            <button class="btn small" type="button" data-copy="<?= e(share_url()) ?>">Copy</button>
        </span>
    </label>
    <ol class="small steps">
        <li>Open the <strong>Shortcuts</strong> app, tap <strong>+</strong>, and name the shortcut <strong>Pastey</strong>.</li>
        <li>Tap the <strong>ⓘ</strong> (or the name at the top → Details), turn on <strong>Show in Share Sheet</strong>, then set what it receives to <strong>Media</strong> only.</li>
        <li>Add <strong>Encode Media</strong>. Input: <em>Shortcut Input</em>. Tap <em>Show More</em>, set Size to <strong>1920×1080</strong> (not HEVC).</li>
        <li>Add <strong>Get Contents of URL</strong>. URL: paste the address above. Tap <em>Show More</em>: Method <strong>POST</strong>, Request Body <strong>Form</strong>, add a <strong>File</strong> field named <code>video</code> set to <em>Encoded Media</em>.</li>
        <li>Add <strong>Copy to Clipboard</strong> (input: <em>Contents of URL</em>), then <strong>Show Notification</strong> with <em>Contents of URL</em> as the text.</li>
        <li>Try it: in Photos, open a video, tap <strong>Share → Pastey</strong>. The link is copied when the notification shows.</li>
    </ol>
    <p class="muted small">One upload can be up to <?= e(ini_get('upload_max_filesize')) ?>B. For longer videos, pick 1280×720 in step 3, or upload here instead. Thumbnails for shortcut uploads are made the next time you open this page.</p>
    <form method="post">
        <input type="hidden" name="action" value="new_key">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn ghost small" type="submit">Make a new key</button>
    </form>
</details>

<section class="library">
    <div class="library-head">
        <h2>Your videos <span class="muted" id="count"><?= count($videos) ?></span></h2>
        <form method="post">
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
page_foot('<script>window.CLIPBIN = ' . json_encode($cfg, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ';</script>'
    . '<script type="module" src="' . e(base_url()) . '/assets/upload.js"></script>');
