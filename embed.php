<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$v = find_video((string) ($_GET['id'] ?? ''));
if (!$v || $v['status'] !== 'ready') {
    http_response_code(404);
    exit('Not found');
}
$hasThumb = is_file(media_dir() . '/' . $v['id'] . '.jpg');
$autoplay = isset($_GET['autoplay']) ? ' autoplay muted' : '';
$loop = isset($_GET['loop']) ? ' loop' : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($v['title'] !== '' ? $v['title'] : 'Video') ?></title>
<style>
html, body { margin: 0; height: 100%; background: #000; overflow: hidden; }
video { width: 100%; height: 100%; object-fit: contain; display: block; background: #000; }
</style>
</head>
<body>
<video src="<?= e(video_url($v)) ?>" <?= $hasThumb ? 'poster="' . e(thumb_url($v)) . '"' : '' ?> controls playsinline preload="metadata"<?= $autoplay . $loop ?>></video>
</body>
</html>
