<?php
// The public page for a link: a video player, a photo, a gallery, an audio
// player or a download, with the tags that make link previews work.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$id = (string) ($_GET['id'] ?? '');
$v = find_video($id);
$site = config('site_name');
$items = $v && $v['kind'] === 'gallery' ? gallery_items($v['id']) : [];

function not_found(string $site, string $h1, string $p, int $code = 404): void
{
    http_response_code($code);
    page_head(e($h1) . ' · ' . e($site), '<meta name="robots" content="noindex">');
    echo '<section class="card narrow"><h1>' . e($h1) . '</h1><p class="muted">' . e($p) . '</p></section>';
    page_foot();
    exit;
}

if (!$v || !in_array($v['status'], ['ready', 'held'], true) || ($v['kind'] === 'gallery' && !$items)) {
    not_found($site, 'Not found', 'It may have been deleted, or the link is mistyped.');
}
if ($v['status'] === 'held') {
    not_found($site, 'Almost ready', 'This photo is still being converted for the web. It shows up here the next time its owner opens Pastey.', 200);
}

// Count human views, not link-preview bots.
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua !== '' && !preg_match('/bot|crawl|spider|preview|discord|slack|telegram|whatsapp|facebookexternalhit|embedly|skype|curl|wget/i', $ua)) {
    db()->prepare('UPDATE videos SET views = views + 1 WHERE id = ?')->execute([$id]);
    $v['views']++;
}

$kind = $v['kind'];
$page = page_url($v);
$embed = base_url() . '/e/' . $v['id'];
$oembed = base_url() . '/oembed?format=json&url=' . rawurlencode($page);
$label = kind_label($v);
$count = count($items);
$title = $v['title'] !== '' ? $v['title'] : ($kind === 'gallery' ? $count . ' photos' : $label);
$views = (int) $v['views'] . ' view' . ((int) $v['views'] === 1 ? '' : 's');
$date = date('M j, Y', (int) $v['created_at']);
$w = (int) $v['width'];
$h = (int) $v['height'];
$file = $kind === 'gallery' ? '' : file_url($v);

// Preview picture: the photo itself where every app can show it, else the thumbnail.
$preview = '';
$pw = $ph = 0;
if ($kind === 'image' && in_array($v['ext'], ['jpg', 'png', 'gif', 'webp'], true)) {
    [$preview, $pw, $ph] = [$file, $w, $h];
} elseif ($kind === 'gallery') {
    $first = $items[0];
    if (in_array($first['ext'], ['jpg', 'png', 'gif', 'webp'], true)) {
        [$preview, $pw, $ph] = [file_url($first), (int) $first['width'], (int) $first['height']];
    }
}
if ($preview === '' && ($tf = thumb_file($v)) !== '') {
    $preview = base_url() . '/media/' . basename($tf);
    [$pw, $ph] = @getimagesize($tf) ?: [0, 0];
}

$desc = [
    'video' => fmt_duration((float) $v['duration']) . ' video on ' . $site,
    'image' => $label . ' on ' . $site,
    'gallery' => $count . ' photos on ' . $site,
    'audio' => fmt_duration((float) $v['duration']) . ' audio on ' . $site,
    'file' => $label . ' · ' . fmt_bytes((int) $v['size']) . ' on ' . $site,
][$kind] ?? $site;

$meta = [];
$meta[] = ['property', 'og:site_name', $site];
$meta[] = ['property', 'og:type', $kind === 'video' ? 'video.other' : ($kind === 'audio' ? 'music.song' : 'website')];
$meta[] = ['property', 'og:title', $title];
$meta[] = ['property', 'og:description', $desc];
$meta[] = ['property', 'og:url', $page];
if ($preview !== '') {
    $meta[] = ['property', 'og:image', $preview];
    if ($pw && $ph) {
        $meta[] = ['property', 'og:image:width', (string) $pw];
        $meta[] = ['property', 'og:image:height', (string) $ph];
    }
    $meta[] = ['name', 'twitter:image', $preview];
}
$meta[] = ['name', 'twitter:title', $title];
$meta[] = ['name', 'twitter:description', $desc];
if ($kind === 'video') {
    $vw = $w ?: 1280;
    $vh = $h ?: 720;
    $meta[] = ['property', 'og:video', $file];
    if (str_starts_with($file, 'https://')) {
        $meta[] = ['property', 'og:video:secure_url', $file];
    }
    $meta[] = ['property', 'og:video:type', 'video/mp4'];
    $meta[] = ['property', 'og:video:width', (string) $vw];
    $meta[] = ['property', 'og:video:height', (string) $vh];
    $meta[] = ['name', 'twitter:card', 'player'];
    $meta[] = ['name', 'twitter:player', $embed];
    $meta[] = ['name', 'twitter:player:width', (string) $vw];
    $meta[] = ['name', 'twitter:player:height', (string) $vh];
    $meta[] = ['name', 'twitter:player:stream', $file];
    $meta[] = ['name', 'twitter:player:stream:content_type', 'video/mp4'];
} elseif ($kind === 'audio') {
    $meta[] = ['property', 'og:audio', $file];
    if (str_starts_with($file, 'https://')) {
        $meta[] = ['property', 'og:audio:secure_url', $file];
    }
    $meta[] = ['property', 'og:audio:type', MEDIA_TYPES[$v['ext']][1]];
    $meta[] = ['name', 'twitter:card', 'player'];
    $meta[] = ['name', 'twitter:player', $embed];
    $meta[] = ['name', 'twitter:player:width', '480'];
    $meta[] = ['name', 'twitter:player:height', '80'];
} else {
    $meta[] = ['name', 'twitter:card', $preview !== '' ? 'summary_large_image' : 'summary'];
}
$meta[] = ['name', 'theme-color', '#ff6a3d'];

$head = '';
foreach ($meta as [$attr, $k, $val]) {
    $head .= '<meta ' . $attr . '="' . e($k) . '" content="' . e($val) . "\">\n";
}
$head .= '<link rel="alternate" type="application/json+oembed" href="' . e($oembed) . '" title="' . e($title) . "\">\n";
$head .= '<link rel="canonical" href="' . e($page) . "\">\n";

$iframe = '';
if ($kind === 'video') {
    $iframe = '<iframe src="' . $embed . '" width="640" height="' . (int) round(640 * ($h ?: 720) / ($w ?: 1280))
        . '" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
} elseif ($kind === 'audio') {
    $iframe = '<iframe src="' . $embed . '" width="480" height="80" frameborder="0" allow="autoplay"></iframe>';
}

$parent = $v['parent'] !== '' ? find_video($v['parent']) : null;

page_head(e($title) . ' · ' . e($site), $head);
?>
<section class="watch watch-<?= e($kind) ?>">
<?php if ($parent): ?>
    <p class="small"><a class="muted" href="<?= e(page_url($parent)) ?>">← Back to the gallery</a></p>
<?php endif; ?>
<?php if ($kind === 'video'): ?>
    <div class="player" style="aspect-ratio: <?= $w ?: 1280 ?> / <?= $h ?: 720 ?>">
        <video src="<?= e($file) ?>" <?= $preview !== '' ? 'poster="' . e($preview) . '"' : '' ?> controls playsinline preload="metadata" autoplay></video>
    </div>
<?php elseif ($kind === 'image'): ?>
    <a class="photo" href="<?= e($file) ?>"<?= $w && $h ? ' style="aspect-ratio: ' . $w . ' / ' . $h . '"' : '' ?>>
        <img src="<?= e($file) ?>" alt="<?= e($v['title']) ?>"<?= $w && $h ? ' width="' . $w . '" height="' . $h . '"' : '' ?>>
    </a>
<?php elseif ($kind === 'gallery'): ?>
    <ul class="gallery" id="gallery">
        <?php foreach ($items as $i => $c): ?>
            <li><a href="<?= e(page_url($c)) ?>" data-full="<?= e(file_url($c)) ?>" data-w="<?= (int) $c['width'] ?>" data-h="<?= (int) $c['height'] ?>" data-name="<?= e(download_name($c)) ?>">
                <img src="<?= e(thumb_src($c) ?: file_url($c)) ?>" alt="Photo <?= $i + 1 ?>" loading="<?= $i < 12 ? 'eager' : 'lazy' ?>">
            </a></li>
        <?php endforeach; ?>
    </ul>
<?php elseif ($kind === 'audio'): ?>
    <div class="audio-card">
        <span class="audio-icon" aria-hidden="true"></span>
        <audio src="<?= e($file) ?>" controls preload="metadata"></audio>
    </div>
<?php else: ?>
    <div class="file-card">
        <span class="file-icon"><?= e(strtoupper($v['ext'])) ?></span>
        <span class="muted"><?= e(fmt_bytes((int) $v['size'])) ?></span>
    </div>
<?php endif; ?>
    <div class="watch-info">
        <div>
            <h1><?= e($title) ?></h1>
            <p class="muted small"><?= e(implode(' · ', array_filter([
                $views,
                in_array($kind, ['video', 'audio'], true) ? fmt_duration((float) $v['duration']) : '',
                $kind === 'gallery' && $v['title'] !== '' ? $count . ' photos' : '',
                $kind === 'image' && $w ? $w . '×' . $h : '',
                $date,
            ]))) ?></p>
        </div>
        <div class="actions">
            <button class="btn primary" data-share="<?= e($page) ?>" data-title="<?= e($title) ?>" hidden>Share</button>
            <button class="btn primary" data-copy="<?= e($page) ?>">Copy link</button>
            <?php if ($iframe !== ''): ?>
                <button class="btn" data-copy="<?= e($iframe) ?>">Copy embed</button>
            <?php elseif ($kind === 'image'): ?>
                <button class="btn" data-copy="<?= e($file) ?>">Copy image link</button>
            <?php endif; ?>
            <?php if ($kind === 'file' && $v['ext'] === 'pdf'): ?>
                <a class="btn" href="<?= e($file) ?>">Open</a>
            <?php endif; ?>
            <?php if ($kind !== 'gallery'): ?>
                <a class="btn ghost" href="<?= e($file) ?>" download="<?= e(download_name($v)) ?>">Download</a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php
page_foot('<script src="' . e(asset_url('copy.js')) . '"></script>'
    . ($kind === 'gallery' ? '<script src="' . e(asset_url('gallery.js')) . '"></script>' : ''));
