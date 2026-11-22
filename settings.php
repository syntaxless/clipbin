<?php
// Settings: the iPhone shortcut address and its key. Setup steps are in the README.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (!is_logged_in()) {
    header('Location: ' . base_url() . '/');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf'] ?? null)
    && ($_POST['action'] ?? '') === 'new_key') {
    upload_key(true);
    header('Location: ' . base_url() . '/settings.php?newkey=1', true, 303);
    exit;
}

$csrf = csrf_token();
page_head(e(config('site_name')) . ' · Settings');
?>
<section class="card settings">
    <p><a class="small muted" href="<?= e(base_url()) ?>/">← Back to your videos</a></p>
    <h1>Settings</h1>
    <h2>iPhone shortcut address</h2>
    <?php if (isset($_GET['newkey'])): ?>
        <p class="small ok-text">New key made. The old address stops working: paste this one into your shortcut.</p>
    <?php endif; ?>
    <label class="small">Keep it private: it lets anyone upload. Setup steps are in the README.
        <span class="copyrow">
            <input id="share-url" type="text" readonly value="<?= e(share_url()) ?>" onfocus="this.select()">
            <button class="btn small" type="button" data-copy="<?= e(share_url()) ?>">Copy</button>
        </span>
    </label>
    <form method="post">
        <input type="hidden" name="action" value="new_key">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn ghost small" type="submit">Make a new key</button>
    </form>
</section>
<?php
page_foot('<script type="module" src="' . e(asset_url('copy.js')) . '"></script>');
