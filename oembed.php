<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$url = (string) ($_GET['url'] ?? '');
$path = (string) parse_url($url, PHP_URL_PATH);
$id = basename(rtrim($path, '/'));
$v = find_video($id);
$items = $v && $v['kind'] === 'gallery' ? gallery_items($v['id']) : [];
if (!$v || $v['status'] !== 'ready' || ($v['kind'] === 'gallery' && !$items)) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

$kind = $v['kind'];
// A gallery is described by its first photo.
$shown = $kind === 'gallery' ? $items[0] : $v;
$w = (int) $shown['width'] ?: ($kind === 'audio' ? 480 : 1280);
$h = (int) $shown['height'] ?: ($kind === 'audio' ? 80 : 720);
$maxW = (int) ($_GET['maxwidth'] ?? 0);
$maxH = (int) ($_GET['maxheight'] ?? 0);
$scale = $kind === 'audio' ? 1 : min(1, 640 / max($w, $h));
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
    'provider_name' => config('site_name'),
    'provider_url' => base_url() . '/',
    'title' => $v['title'] !== '' ? $v['title'] : ($kind === 'gallery' ? count($items) . ' photos' : kind_label($v)),
];
if (($kind === 'image' || $kind === 'gallery') && in_array($shown['ext'], WEB_IMAGES, true)) {
    $data += ['type' => 'photo', 'url' => file_url($shown), 'width' => $w, 'height' => $h];
} elseif ($kind === 'video' || $kind === 'audio') {
    $data += [
        'type' => $kind === 'video' ? 'video' : 'rich',
        'width' => $ew,
        'height' => $eh,
        'html' => '<iframe src="' . e($embed) . '" width="' . $ew . '" height="' . $eh
            . '" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>',
    ];
} else {
    $data['type'] = 'link';
}
if (($tf = thumb_file($v)) !== '') {
    [$tw, $th] = @getimagesize($tf) ?: [$w, $h];
    $data['thumbnail_url'] = base_url() . '/media/' . basename($tf);
    $data['thumbnail_width'] = $tw;
    $data['thumbnail_height'] = $th;
}
echo json_encode($data, JSON_UNESCAPED_SLASHES);
