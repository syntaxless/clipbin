<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$id = (string) ($_GET['id'] ?? '');
$v = find_video($id);
$site = config('site_name');

if (!$v || $v['status'] !== 'ready') {
    http_response_code(404);
    page_head('Not found · ' . e($site), '<meta name="robots" content="noindex">');
    echo '<section class="card narrow"><h1>Video not found</h1><p class="muted">It may have been deleted, or the link is mistyped.</p></section>';
    page_foot();
    exit;
}

// Count human views, not link-preview bots.
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua !== '' && !preg_match('/bot|crawl|spider|preview|discord|slack|telegram|whatsapp|facebookexternalhit|embedly|skype|curl|wget/i', $ua)) {
    db()->prepare('UPDATE videos SET views = views + 1 WHERE id = ?')->execute([$id]);
    $v['views']++;
}

$title = $v['title'] !== '' ? $v['title'] : 'Video';
$w = (int) $v['width'] ?: 1280;
$h = (int) $v['height'] ?: 720;
$mp4 = video_url($v);
$jpg = thumb_url($v);
$page = page_url($v);
$embed = base_url() . '/e/' . $v['id'];
$hasThumb = is_file(media_dir() . '/' . $v['id'] . '.jpg');
$secure = str_starts_with($mp4, 'https://');
$oembed = base_url() . '/oembed?format=json&url=' . rawurlencode($page);
$desc = fmt_duration((float) $v['duration']) . ' video on ' . $site;

$meta = [];
$meta[] = ['property', 'og:site_name', $site];
$meta[] = ['property', 'og:type', 'video.other'];
$meta[] = ['property', 'og:title', $title];
$meta[] = ['property', 'og:description', $desc];
$meta[] = ['property', 'og:url', $page];
if ($hasThumb) {
    $ti = @getimagesize(media_dir() . '/' . $v['id'] . '.jpg') ?: [$w, $h];
    $meta[] = ['property', 'og:image', $jpg];
    $meta[] = ['property', 'og:image:type', 'image/jpeg'];
    $meta[] = ['property', 'og:image:width', (string) $ti[0]];
    $meta[] = ['property', 'og:image:height', (string) $ti[1]];
}
$meta[] = ['property', 'og:video', $mp4];
if ($secure) {
    $meta[] = ['property', 'og:video:secure_url', $mp4];
}
$meta[] = ['property', 'og:video:type', 'video/mp4'];
$meta[] = ['property', 'og:video:width', (string) $w];
$meta[] = ['property', 'og:video:height', (string) $h];
$meta[] = ['name', 'twitter:card', 'player'];
$meta[] = ['name', 'twitter:title', $title];
$meta[] = ['name', 'twitter:description', $desc];
if ($hasThumb) {
    $meta[] = ['name', 'twitter:image', $jpg];
}
$meta[] = ['name', 'twitter:player', $embed];
$meta[] = ['name', 'twitter:player:width', (string) $w];
$meta[] = ['name', 'twitter:player:height', (string) $h];
$meta[] = ['name', 'twitter:player:stream', $mp4];
$meta[] = ['name', 'twitter:player:stream:content_type', 'video/mp4'];
$meta[] = ['name', 'theme-color', '#ff6a3d'];

$head = '';
foreach ($meta as [$attr, $k, $val]) {
    $head .= '<meta ' . $attr . '="' . e($k) . '" content="' . e($val) . "\">\n";
}
$head .= '<link rel="alternate" type="application/json+oembed" href="' . e($oembed) . '" title="' . e($title) . "\">\n";
$head .= '<link rel="canonical" href="' . e($page) . "\">\n";

$iframe = '<iframe src="' . $embed . '" width="640" height="' . (int) round(640 * $h / $w)
    . '" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';

page_head(e($title) . ' · ' . e($site), $head);
?>
<section class="watch">
    <div class="player" style="aspect-ratio: <?= $w ?> / <?= $h ?>">
        <video src="<?= e($mp4) ?>" <?= $hasThumb ? 'poster="' . e($jpg) . '"' : '' ?> controls playsinline preload="metadata" autoplay></video>
    </div>
    <div class="watch-info">
        <div>
            <h1><?= e($title) ?></h1>
            <p class="muted small"><?= (int) $v['views'] ?> view<?= (int) $v['views'] === 1 ? '' : 's' ?> · <?= e(fmt_duration((float) $v['duration'])) ?> · <?= e(date('M j, Y', (int) $v['created_at'])) ?></p>
        </div>
        <div class="actions">
            <button class="btn primary" data-share="<?= e($page) ?>" data-title="<?= e($title) ?>" hidden>Share</button>
            <button class="btn primary" data-copy="<?= e($page) ?>">Copy link</button>
            <button class="btn" data-copy="<?= e($iframe) ?>">Copy embed</button>
            <a class="btn ghost" href="<?= e($mp4) ?>" download="<?= e(preg_replace('/[^\w\- ]+/', '', $title) ?: $v['id']) ?>.mp4">Download</a>
        </div>
    </div>
</section>
<?php
page_foot('<script src="' . e(base_url()) . '/assets/copy.js"></script>');
