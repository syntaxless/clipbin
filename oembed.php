<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$url = (string) ($_GET['url'] ?? '');
$path = (string) parse_url($url, PHP_URL_PATH);
$id = basename(rtrim($path, '/'));
$v = find_video($id);
if (!$v || $v['status'] !== 'ready') {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

$w = (int) $v['width'] ?: 1280;
$h = (int) $v['height'] ?: 720;
$maxW = (int) ($_GET['maxwidth'] ?? 0);
$maxH = (int) ($_GET['maxheight'] ?? 0);
$scale = min(1, 640 / max($w, $h));
if ($maxW > 0) {
    $scale = min($scale, $maxW / $w);
}
if ($maxH > 0) {
    $scale = min($scale, $maxH / $h);
}
$ew = (int) round($w * $scale);
$eh = (int) round($h * $scale);

$embed = base_url() . '/e/' . $v['id'];
$data = [
    'version' => '1.0',
    'type' => 'video',
    'provider_name' => config('site_name'),
    'provider_url' => base_url() . '/',
    'title' => $v['title'] !== '' ? $v['title'] : 'Video',
    'width' => $ew,
    'height' => $eh,
    'html' => '<iframe src="' . e($embed) . '" width="' . $ew . '" height="' . $eh
        . '" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>',
];
if (is_file(media_dir() . '/' . $v['id'] . '.jpg')) {
    $data['thumbnail_url'] = thumb_url($v);
    $data['thumbnail_width'] = $w;
    $data['thumbnail_height'] = $h;
}
echo json_encode($data, JSON_UNESCAPED_SLASHES);
