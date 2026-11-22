<?php
// What kinds of files Pastey takes, how it recognises them, and the work done
// on each one when it arrives (photo metadata, thumbnails, galleries).
//
// A file's type is decided only from its first bytes, never from its name or
// the type the browser claims, and the stored name is always <id>.<ext> from
// the list below. media/.htaccess only serves names of that shape, so nothing
// uploaded can ever run on the server.
declare(strict_types=1);

const MEDIA_TYPES = [
    // ext => [kind, mime]
    'mp4' => ['video', 'video/mp4'],
    'jpg' => ['image', 'image/jpeg'],
    'png' => ['image', 'image/png'],
    'gif' => ['image', 'image/gif'],
    'webp' => ['image', 'image/webp'],
    'avif' => ['image', 'image/avif'],
    'heic' => ['image', 'image/heic'],
    'mp3' => ['audio', 'audio/mpeg'],
    'm4a' => ['audio', 'audio/mp4'],
    'aac' => ['audio', 'audio/aac'],
    'wav' => ['audio', 'audio/wav'],
    'ogg' => ['audio', 'audio/ogg'],
    'flac' => ['audio', 'audio/flac'],
    'pdf' => ['file', 'application/pdf'],
    'zip' => ['file', 'application/zip'],
];

// Photos browsers can show as they are. HEIC only shows in Safari, so it is
// turned into JPEG (on the server when it can, else by your browser).
const WEB_IMAGES = ['jpg', 'png', 'gif', 'webp', 'avif'];

class UploadError extends RuntimeException
{
}

function unsupported_message(): string
{
    return 'Pastey takes videos, photos (JPEG, PNG, GIF, WebP, HEIC), audio (MP3, M4A, WAV, FLAC, OGG), PDF and ZIP files.';
}

function ini_bytes(string $v): int
{
    $n = (int) $v;
    $u = strtolower(substr(trim($v), -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
}

// Returns the ext from MEDIA_TYPES, or null for anything else.
function detect_ext(string $path): ?string
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return null;
    }
    $h = (string) fread($fh, 256);
    fclose($fh);
    if (strlen($h) < 12) {
        return null;
    }
    if (substr($h, 4, 4) === 'ftyp') {
        $boxLen = min(strlen($h), max(16, unpack('N', $h)[1]));
        $brands = [substr($h, 8, 4)];
        for ($p = 16; $p + 4 <= $boxLen; $p += 4) {
            $brands[] = substr($h, $p, 4);
        }
        if (array_intersect($brands, ['avif', 'avis'])) {
            return 'avif';
        }
        if (array_intersect($brands, ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1'])
            && !array_intersect($brands, ['qt  ', 'isom', 'mp41', 'mp42', 'avc1'])) {
            return 'heic';
        }
        if (in_array($brands[0], ['M4A ', 'M4B ', 'M4P '], true)) {
            return 'm4a';
        }
        // A MOV/MP4 with sound but no picture is audio (Voice Memos, Encode Media on a song).
        [$w, , $dur] = mp4_info($path);
        return ($w === 0 && $dur > 0 && !mp4_has_video($path)) ? 'm4a' : 'mp4';
    }
    if (substr($h, 0, 3) === "\xFF\xD8\xFF") {
        return 'jpg';
    }
    if (substr($h, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return 'png';
    }
    if (substr($h, 0, 6) === 'GIF87a' || substr($h, 0, 6) === 'GIF89a') {
        return 'gif';
    }
    if (substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP') {
        return 'webp';
    }
    if (substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WAVE') {
        return 'wav';
    }
    if (substr($h, 0, 4) === 'OggS') {
        return 'ogg';
    }
    if (substr($h, 0, 4) === 'fLaC') {
        return 'flac';
    }
    if (substr($h, 0, 3) === 'ID3') {
        return 'mp3';
    }
    $b0 = ord($h[0]);
    $b1 = ord($h[1]);
    if ($b0 === 0xFF && ($b1 & 0xF6) === 0xF0) {
        return 'aac'; // ADTS
    }
    if ($b0 === 0xFF && ($b1 & 0xE0) === 0xE0 && (($b1 >> 1) & 3) !== 0) {
        return 'mp3';
    }
    if (substr($h, 0, 5) === '%PDF-') {
        return 'pdf';
    }
    if (substr($h, 0, 4) === "PK\x03\x04") {
        return 'zip';
    }
    return null;
}

// mp4_info() reports no size for a video it can't measure; this tells a real
// audio-only file apart from that by looking for a video handler.
function mp4_has_video(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return true;
    }
    $size = (int) filesize($path);
    $found = false;
    // Scan the top-level boxes for moov, then search it for a "vide" handler.
    $pos = 0;
    while ($pos + 8 <= $size) {
        fseek($fh, $pos);
        $hd = (string) fread($fh, 16);
        if (strlen($hd) < 8) {
            break;
        }
        $len = unpack('N', $hd)[1];
        $hdr = 8;
        if ($len === 1 && strlen($hd) >= 16) {
            $len = unpack('J', substr($hd, 8, 8))[1];
            $hdr = 16;
        } elseif ($len === 0) {
            $len = $size - $pos;
        }
        if ($len < $hdr) {
            break;
        }
        if (substr($hd, 4, 4) === 'moov') {
            fseek($fh, $pos + $hdr);
            $moov = (string) fread($fh, (int) min($len - $hdr, 64 * 1024 * 1024));
            $found = strpos($moov, 'hdlr') !== false && preg_match('/hdlr.{8}vide/s', $moov) === 1;
            fclose($fh);
            return $found;
        }
        $pos += $len;
    }
    fclose($fh);
    return true; // no moov found: keep treating it as a video, as before
}

// ---- Photos ------------------------------------------------------------------

// Reads the EXIF orientation (1-8) from an APP1 "Exif" payload.
function exif_orientation(string $payload): int
{
    $tiff = substr($payload, 6);
    $n = strlen($tiff);
    if ($n < 8) {
        return 1;
    }
    $le = substr($tiff, 0, 2) === 'II';
    $u16 = fn (int $o) => $o + 2 <= $n ? unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
    $u32 = fn (int $o) => $o + 4 <= $n ? unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
    $ifd = $u32(4);
    $count = $u16($ifd);
    for ($i = 0; $i < $count && $i < 500; $i++) {
        $e = $ifd + 2 + $i * 12;
        if ($u16($e) === 0x0112) {
            $o = $u16($e + 8);
            return $o >= 1 && $o <= 8 ? $o : 1;
        }
    }
    return 1;
}

// Removes location and other personal details (EXIF, XMP, IPTC, comments) from
// a JPEG without re-encoding it. Kept: the colour profile, and the rotation as
// a tiny new EXIF block, so the photo still shows the right way up. Extra
// pictures Apple appends after the main one (HDR gain map, depth) are dropped
// too, since they carry their own metadata.
// Returns the orientation, or 0 when the file was left alone.
function jpeg_clean(string $path): int
{
    $size = (int) @filesize($path);
    if ($size <= 0 || $size > 80 * 1024 * 1024) {
        return 0;
    }
    $d = (string) file_get_contents($path);
    $n = strlen($d);
    if (substr($d, 0, 2) !== "\xFF\xD8") {
        return 0;
    }
    $p = 2;
    $orient = 1;
    $keep = '';
    $app0 = '';
    while (true) {
        if ($p + 4 > $n || $d[$p] !== "\xFF") {
            return 0; // not a JPEG layout we understand: leave it as it was
        }
        $m = ord($d[$p + 1]);
        if ($m === 0xFF) {
            $p++;
            continue;
        }
        if ($m === 0xDA) {
            break; // start of the picture data
        }
        $len = unpack('n', substr($d, $p + 2, 2))[1];
        if ($len < 2 || $p + 2 + $len > $n) {
            return 0;
        }
        $seg = substr($d, $p, 2 + $len);
        $payload = substr($seg, 4);
        if ($m === 0xE1) {
            if (str_starts_with($payload, "Exif\0\0")) {
                $orient = exif_orientation($payload);
            }
        } elseif ($m === 0xED || $m === 0xFE || ($m === 0xE2 && str_starts_with($payload, "MPF\0"))) {
            // IPTC/Photoshop, comment, multi-picture index: dropped
        } elseif ($m === 0xE0 && $app0 === '' && $keep === '') {
            $app0 = $seg;
        } else {
            $keep .= $seg;
        }
        $p += 2 + $len;
    }
    // The picture data runs until the first FFD9 (inside it, FF is always followed by 00 or a marker).
    $end = $p;
    while (($end = strpos($d, "\xFF", $end + 1)) !== false) {
        $next = ord($d[$end + 1] ?? "\0");
        if ($next === 0xD9) {
            break;
        }
    }
    if ($end === false) {
        return 0;
    }
    $exif = '';
    if ($orient !== 1) {
        $body = "Exif\0\0MM\0\x2A\0\0\0\x08" . "\0\x01" . "\x01\x12\0\x03\0\0\0\x01" . pack('n', $orient) . "\0\0" . "\0\0\0\0";
        $exif = "\xFF\xE1" . pack('n', strlen($body) + 2) . $body;
    }
    $out = "\xFF\xD8" . $app0 . $exif . $keep . substr($d, $p, $end + 2 - $p);
    $tmp = $path . '.clean';
    if (file_put_contents($tmp, $out) !== strlen($out) || !rename($tmp, $path)) {
        @unlink($tmp);
        return 0;
    }
    return $orient;
}

function jpeg_file_orientation(string $path): int
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return 1;
    }
    $head = (string) fread($fh, 128 * 1024);
    fclose($fh);
    $p = 2;
    while ($p + 4 <= strlen($head) && $head[$p] === "\xFF") {
        $m = ord($head[$p + 1]);
        if ($m === 0xDA) {
            break;
        }
        $len = unpack('n', substr($head, $p + 2, 2))[1];
        if ($m === 0xE1 && substr($head, $p + 4, 6) === "Exif\0\0") {
            return exif_orientation(substr($head, $p + 4, $len - 2));
        }
        $p += 2 + $len;
    }
    return 1;
}

// Picture size as shown (rotation applied). [0, 0] if unknown.
function image_size(string $path, string $ext): array
{
    $info = @getimagesize($path);
    if (!$info || !$info[0]) {
        return [0, 0];
    }
    if ($ext === 'jpg' && jpeg_file_orientation($path) >= 5) {
        return [$info[1], $info[0]];
    }
    return [$info[0], $info[1]];
}

// Small JPEG for grids and link previews, made with PHP's GD when the server
// has it and the photo fits in memory. Otherwise the library page makes one in
// the browser the next time you open it.
function make_thumb(string $src, string $ext, string $dst, int $max = 800): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $info = @getimagesize($src);
    if (!$info || !$info[0]) {
        return false;
    }
    [$w, $h] = $info;
    $limit = ini_bytes((string) ini_get('memory_limit'));
    if ($limit > 0 && $w * $h * 5 + 24 * 1024 * 1024 > $limit - memory_get_usage()) {
        return false;
    }
    $load = [
        'jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'gif' => 'imagecreatefromgif',
        'webp' => 'imagecreatefromwebp', 'avif' => 'imagecreatefromavif',
    ][$ext] ?? null;
    if (!$load || !function_exists($load)) {
        return false;
    }
    $im = @$load($src);
    if (!$im) {
        return false;
    }
    if ($ext === 'jpg') {
        $o = jpeg_file_orientation($src);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $r = imagerotate($im, $angle, 0);
            if ($r) {
                imagedestroy($im);
                $im = $r;
            }
        }
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $scale = min(1, $max / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));
    $t = imagecreatetruecolor($tw, $th);
    imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255)); // transparent PNGs on white
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($im);
    $ok = imagejpeg($t, $dst, 82);
    imagedestroy($t);
    if ($ok) {
        @chmod($dst, 0644);
    }
    return $ok;
}

// HEIC to JPEG on the server, only possible where ImageMagick was built with
// HEIC support. Returns true when $dst was written.
function heic_to_jpeg(string $src, string $dst): bool
{
    if (!class_exists('Imagick')) {
        return false;
    }
    try {
        if (!Imagick::queryFormats('HEIC')) {
            return false;
        }
        $im = new Imagick($src);
        $im->autoOrient();
        $profiles = $im->getImageProfiles('icc', true);
        $im->stripImage(); // drops location and the rest
        if (!empty($profiles['icc'])) {
            $im->profileImage('icc', $profiles['icc']);
        }
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(90);
        $ok = $im->writeImage($dst);
        $im->clear();
        return (bool) $ok && is_file($dst) && filesize($dst) > 0;
    } catch (Throwable $e) {
        @unlink($dst);
        return false;
    }
}

// A thumbnail the browser made, sent as a data: URL.
function save_data_jpeg(string $dataUrl, string $dst): bool
{
    if (!preg_match('#^data:image/jpeg;base64,#', $dataUrl)) {
        return false;
    }
    $bin = base64_decode(substr($dataUrl, 23), true);
    $info = $bin ? @getimagesizefromstring($bin) : false;
    if (!$info || $info[2] !== IMAGETYPE_JPEG || strlen($bin) >= 4 * 1024 * 1024) {
        return false;
    }
    if (file_put_contents($dst, $bin) === false) {
        return false;
    }
    @chmod($dst, 0644);
    return true;
}

// ---- Items -------------------------------------------------------------------

function held_dir(): string
{
    $d = APP_ROOT . '/data/held';
    if (!is_dir($d)) {
        mkdir($d, 0755, true);
    }
    return $d;
}

// Turns a complete uploaded file into a finished item.
//   $path      the file (a PHP upload when $uploaded, else a file we own)
//   $id        an existing "uploading" row (browser uploads) or null to make one
//   $meta      title, width, height, duration, thumb (JPEG data URL), gallery
// Returns the row. Throws UploadError with a message for the person.
function finish_item(string $path, bool $uploaded, ?string $id, array $meta): array
{
    $ext = detect_ext($path);
    if ($ext === null) {
        throw new UploadError("That kind of file isn't supported. " . unsupported_message(), 415);
    }
    [$kind] = MEDIA_TYPES[$ext];
    $size = (int) filesize($path);
    $title = mb_substr(trim((string) ($meta['title'] ?? '')), 0, 200);
    if ($id === null) {
        $id = new_id();
        db()->prepare('INSERT INTO videos (id, title, size, received, status, kind, ext, created_at)
                       VALUES (?, ?, ?, ?, "uploading", ?, ?, ?)')
            ->execute([$id, $title, $size, $size, $kind, $ext, time()]);
    }

    $media = media_dir();
    $status = 'ready';
    $dest = "$media/$id.$ext";
    if ($ext === 'heic') {
        // Browsers other than Safari can't show HEIC, and the file still holds
        // its location. Convert it here if possible; otherwise keep it out of
        // public reach until your browser converts it (see api.php "replace").
        $dest = held_dir() . "/$id.heic";
        $status = 'held';
    }
    $moved = $uploaded ? move_uploaded_file($path, $dest) : rename($path, $dest);
    if (!$moved) {
        throw new UploadError('Could not store the file on the server.', 500);
    }
    @chmod($dest, 0644);

    $w = max(0, (int) ($meta['width'] ?? 0));
    $h = max(0, (int) ($meta['height'] ?? 0));
    $dur = max(0.0, (float) ($meta['duration'] ?? 0));
    $thumb = (string) ($meta['thumb'] ?? '');

    if ($ext === 'heic' && heic_to_jpeg($dest, "$media/$id.jpg")) {
        @unlink($dest);
        $ext = 'jpg';
        $dest = "$media/$id.jpg";
        $status = 'ready';
        jpeg_clean($dest);
    }

    if ($kind === 'video') {
        if (!$w || !$h || !$dur) {
            [$w2, $h2, $d2] = mp4_info($dest);
            $w = $w ?: $w2;
            $h = $h ?: $h2;
            $dur = $dur ?: $d2;
        }
        if ($thumb !== '') {
            save_data_jpeg($thumb, "$media/$id.jpg");
        }
    } elseif ($kind === 'image') {
        if ($ext === 'jpg') {
            jpeg_clean($dest);
        }
        if ($status === 'ready') {
            [$w2, $h2] = image_size($dest, $ext);
            $w = $w2 ?: $w;
            $h = $h2 ?: $h;
            if (!($thumb !== '' && save_data_jpeg($thumb, "$media/{$id}_t.jpg"))) {
                make_thumb($dest, $ext, "$media/{$id}_t.jpg");
            }
        }
    } elseif ($kind === 'audio') {
        if (!$dur && $ext === 'm4a') {
            $dur = mp4_info($dest)[2];
        }
        $w = $h = 0;
    } else {
        $w = $h = 0;
        $dur = 0.0;
    }

    // Only photos go in galleries.
    $parent = '';
    $pos = 0;
    $g = (string) ($meta['gallery'] ?? '');
    if ($kind === 'image' && $g !== '' && ($gal = find_video($g)) && $gal['kind'] === 'gallery') {
        $parent = $gal['id'];
        $st = db()->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM videos WHERE parent = ?');
        $st->execute([$parent]);
        $pos = (int) $st->fetchColumn();
    }

    $sets = 'status = ?, kind = ?, ext = ?, size = ?, received = ?, width = ?, height = ?, duration = ?, parent = ?, position = ?';
    $args = [$status, $kind, $ext, $size, $size, $w, $h, $dur, $parent, $pos];
    if ($title !== '') {
        $sets .= ', title = ?';
        $args[] = $title;
    }
    $args[] = $id;
    db()->prepare("UPDATE videos SET $sets WHERE id = ?")->execute($args);
    return find_video($id);
}

function new_gallery(string $title = ''): array
{
    $id = new_id();
    db()->prepare('INSERT INTO videos (id, title, status, kind, ext, created_at) VALUES (?, ?, "ready", "gallery", "", ?)')
        ->execute([$id, mb_substr(trim($title), 0, 200), time()]);
    return find_video($id);
}

// Photos sent one at a time with the same "group" word (the iPhone shortcut's
// Repeat loop) become one gallery. The first photo stays on its own until a
// second one arrives, so a single photo never becomes a gallery of one.
// Returns the gallery, or null.
function group_into_gallery(string $token, array $item): ?array
{
    if ($item['kind'] !== 'image' || $item['parent'] !== '') {
        return null;
    }
    $key = 'group:' . hash('sha256', $token);
    $g = json_decode((string) setting($key), true);
    $now = time();
    if (!is_array($g) || $now - (int) ($g['t'] ?? 0) > 3 * 3600 || !find_video((string) ($g['first'] ?? ''))) {
        setting($key, json_encode(['first' => $item['id'], 'gallery' => '', 't' => $now]));
        return null;
    }
    $gal = $g['gallery'] !== '' ? find_video($g['gallery']) : null;
    if (!$gal) {
        $first = find_video($g['first']);
        $gal = new_gallery();
        db()->prepare('UPDATE videos SET created_at = ? WHERE id = ?')->execute([(int) $first['created_at'], $gal['id']]);
        db()->prepare('UPDATE videos SET parent = ?, position = 0 WHERE id = ? AND parent = ""')
            ->execute([$gal['id'], $first['id']]);
    }
    $st = db()->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM videos WHERE parent = ?');
    $st->execute([$gal['id']]);
    db()->prepare('UPDATE videos SET parent = ?, position = ? WHERE id = ?')
        ->execute([$gal['id'], (int) $st->fetchColumn(), $item['id']]);
    setting($key, json_encode(['first' => $g['first'], 'gallery' => $gal['id'], 't' => $now]));
    return $gal;
}

function forget_old_groups(): void
{
    $rows = db()->query('SELECT key, value FROM settings WHERE key LIKE "group:%"')->fetchAll();
    foreach ($rows as $r) {
        $g = json_decode($r['value'], true);
        if (time() - (int) ($g['t'] ?? 0) > 86400) {
            db()->prepare('DELETE FROM settings WHERE key = ?')->execute([$r['key']]);
        }
    }
}

function gallery_items(string $id, bool $readyOnly = true): array
{
    $st = db()->prepare('SELECT * FROM videos WHERE parent = ?' . ($readyOnly ? ' AND status = "ready"' : '')
        . ' ORDER BY position, created_at');
    $st->execute([$id]);
    return $st->fetchAll();
}

function delete_item(string $id): void
{
    $v = find_video($id);
    if (!$v) {
        return;
    }
    foreach (gallery_items($id, false) as $child) {
        delete_item($child['id']);
    }
    $media = media_dir();
    if ($v['ext'] !== '') {
        @unlink("$media/$id.{$v['ext']}");
    }
    @unlink("$media/$id.jpg");
    @unlink("$media/{$id}_t.jpg");
    @unlink(APP_ROOT . "/data/held/$id.heic");
    @unlink(APP_ROOT . "/data/tmp/$id.part");
    db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
    // A gallery left with nothing in it goes too.
    if ($v['parent'] !== '' && !gallery_items($v['parent'], false)) {
        db()->prepare('DELETE FROM videos WHERE id = ? AND kind = "gallery"')->execute([$v['parent']]);
    }
}

// ---- Links -------------------------------------------------------------------

function file_url(array $v): string
{
    return base_url() . '/media/' . $v['id'] . '.' . $v['ext'];
}

// Path of the small picture for an item, or '' if there is none yet.
function thumb_file(array $v): string
{
    $m = media_dir() . '/' . $v['id'];
    switch ($v['kind']) {
        case 'video':
            return is_file("$m.jpg") ? "$m.jpg" : '';
        case 'image':
            if (is_file("{$m}_t.jpg")) {
                return "{$m}_t.jpg";
            }
            return $v['status'] === 'ready' && in_array($v['ext'], WEB_IMAGES, true) ? "$m.{$v['ext']}" : '';
        case 'gallery':
            foreach (gallery_items($v['id']) as $c) {
                if (($t = thumb_file($c)) !== '') {
                    return $t;
                }
            }
            return '';
    }
    return '';
}

function thumb_src(array $v): string
{
    $f = thumb_file($v);
    return $f === '' ? '' : base_url() . '/media/' . basename($f);
}

function kind_label(array $v): string
{
    if ($v['kind'] === 'image') {
        return $v['ext'] === 'gif' ? 'GIF' : 'Photo';
    }
    return ['video' => 'Video', 'audio' => 'Audio', 'gallery' => 'Gallery', 'file' => strtoupper($v['ext'])][$v['kind']] ?? 'File';
}

function download_name(array $v): string
{
    $t = preg_replace('/[^\w\- ]+/u', '', (string) $v['title']);
    return ($t !== '' ? $t : $v['id']) . '.' . $v['ext'];
}
