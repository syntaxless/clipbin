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

// Your library: everything except the photos inside galleries.
$rows = db()->query('SELECT * FROM videos WHERE status IN ("ready", "held") ORDER BY created_at DESC LIMIT 2000')->fetchAll();
$children = [];
foreach ($rows as $r) {
    if ($r['parent'] !== '' && $r['status'] === 'ready') {
        $children[$r['parent']][] = $r;
    }
}
$videos = array_values(array_filter($rows, fn ($r) => $r['parent'] === ''
    && ($r['kind'] !== 'gallery' || !empty($children[$r['id']]))));
$videos = array_slice($videos, 0, 500);

// Work your browser does for things that arrived without it (the iPhone
// shortcuts): video thumbnails, photo thumbnails, audio length, HEIC to JPEG.
$todo = [];
foreach ($rows as $r) {
    $m = media_dir() . '/' . $r['id'];
    if ($r['status'] === 'held') {
        $todo[] = ['id' => $r['id'], 'kind' => 'held'];
    } elseif ($r['kind'] === 'video' && !is_file("$m.jpg")) {
        $todo[] = ['id' => $r['id'], 'kind' => 'video', 'src' => file_url($r)];
    } elseif ($r['kind'] === 'image' && !is_file("{$m}_t.jpg")) {
        $todo[] = ['id' => $r['id'], 'kind' => 'image', 'src' => file_url($r)];
    } elseif ($r['kind'] === 'audio' && (float) $r['duration'] <= 0) {
        $todo[] = ['id' => $r['id'], 'kind' => 'audio', 'src' => file_url($r)];
    }
}

$cfg = [
    'base' => base_url(),
    'csrf' => $csrf,
    'maxBytes' => (int) config('max_upload_mb') * 1024 * 1024,
    'maxDim' => (int) config('max_dimension'),
    'todo' => array_slice($todo, 0, 100),
];
page_head(e($site) . ' · Upload');
?>
<section class="upload">
    <label id="drop" class="drop" tabindex="0">
        <input id="file" type="file" multiple accept="video/*,image/*,audio/*,.mkv,.mov,.mp4,.webm,.m4v,.mp3,.m4a,.wav,.flac,.ogg,.opus,.aac,.pdf,.zip,application/pdf,application/zip" hidden>
        <span class="drop-icon" aria-hidden="true"></span>
        <strong class="fine">Drop files here</strong>
        <span class="muted fine">Videos, photos, audio, PDF or ZIP. Or click to choose, or paste.</span>
        <strong class="coarse">Tap to choose</strong>
        <span class="muted coarse">Videos, photos or audio from your phone</span>
        <span class="muted small">Pick several photos at once to make a gallery</span>
    </label>
    <div id="jobs"></div>
</section>

<section class="library">
    <div class="library-head">
        <h2>Your uploads <span class="muted" id="count"><?= count($videos) ?></span></h2>
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
        <?php foreach ($videos as $v):
            $kids = $children[$v['id']] ?? [];
            $thumb = $v['kind'] === 'gallery' ? thumb_src($kids[0]) : thumb_src($v);
            $badge = match ($v['kind']) {
                'video', 'audio' => fmt_duration((float) $v['duration']),
                'gallery' => count($kids) . ' photos',
                'image' => $v['ext'] === 'gif' ? 'GIF' : '',
                default => strtoupper($v['ext']),
            };
            $size = $v['kind'] === 'gallery' ? array_sum(array_map(fn ($k) => (int) $k['size'], $kids)) : (int) $v['size'];
            ?>
            <li class="tile kind-<?= e($v['kind']) ?>" data-id="<?= e($v['id']) ?>">
                <a class="thumb" href="<?= e(page_url($v)) ?>" data-label="<?= e($v['kind'] === 'file' ? strtoupper($v['ext']) : '') ?>">
                    <?php if ($thumb !== ''): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
                    <?php if ($badge !== ''): ?><span class="dur"><?= e($badge) ?></span><?php endif; ?>
                </a>
                <div class="meta">
                    <a class="title" href="<?= e(page_url($v)) ?>"><?= e($v['title'] !== '' ? $v['title'] : 'Untitled') ?></a>
                    <span class="muted small"><?= e(kind_label($v)) ?> · <?= (int) $v['views'] ?> view<?= (int) $v['views'] === 1 ? '' : 's' ?> · <?= e(fmt_bytes($size)) ?> · <?= e(date('M j, Y', (int) $v['created_at'])) ?><?= $v['status'] === 'held' ? ' · converting…' : '' ?></span>
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
