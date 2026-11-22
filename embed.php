<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$v = find_video((string) ($_GET['id'] ?? ''));
$items = $v && $v['kind'] === 'gallery' ? gallery_items($v['id']) : [];
if (!$v || $v['status'] !== 'ready' || ($v['kind'] === 'gallery' && !$items)) {
    http_response_code(404);
    exit('Not found');
}
$kind = $v['kind'];
$poster = thumb_src($v);
$autoplay = isset($_GET['autoplay']) ? ' autoplay' . ($kind === 'video' ? ' muted' : '') : '';
$loop = isset($_GET['loop']) ? ' loop' : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($v['title'] !== '' ? $v['title'] : kind_label($v)) ?></title>
<style>
html, body { margin: 0; height: 100%; background: #000; overflow: hidden; color: #fff; font: 14px system-ui, sans-serif; }
video, .photo { width: 100%; height: 100%; object-fit: contain; display: block; background: #000; }
.audio { display: flex; align-items: center; height: 100%; padding: 0 12px; box-sizing: border-box; background: #1c1c1c; }
.audio audio { width: 100%; }
.strip { display: flex; gap: 4px; height: 100%; overflow-x: auto; scroll-snap-type: x mandatory; }
.strip a { flex: 0 0 auto; height: 100%; scroll-snap-align: start; }
.strip img { height: 100%; display: block; }
.file { display: flex; align-items: center; justify-content: center; height: 100%; }
.file a { color: #fff; }
</style>
</head>
<body>
<?php if ($kind === 'video'): ?>
<video src="<?= e(file_url($v)) ?>" <?= $poster !== '' ? 'poster="' . e($poster) . '"' : '' ?> controls playsinline preload="metadata"<?= $autoplay . $loop ?>></video>
<?php elseif ($kind === 'image'): ?>
<a href="<?= e(page_url($v)) ?>" target="_blank" rel="noopener"><img class="photo" src="<?= e(in_array($v['ext'], WEB_IMAGES, true) ? file_url($v) : $poster) ?>" alt="<?= e($v['title']) ?>"></a>
<?php elseif ($kind === 'gallery'): ?>
<div class="strip">
<?php foreach ($items as $c): ?>
    <a href="<?= e(page_url($v)) ?>" target="_blank" rel="noopener"><img src="<?= e(thumb_src($c) ?: file_url($c)) ?>" alt="" loading="lazy"></a>
<?php endforeach; ?>
</div>
<?php elseif ($kind === 'audio'): ?>
<div class="audio"><audio src="<?= e(file_url($v)) ?>" controls preload="metadata"<?= $autoplay . $loop ?>></audio></div>
<?php else: ?>
<div class="file"><a href="<?= e(page_url($v)) ?>" target="_blank" rel="noopener"><?= e(($v['title'] !== '' ? $v['title'] : kind_label($v)) . ' · ' . fmt_bytes((int) $v['size'])) ?></a></div>
<?php endif; ?>
</body>
</html>
