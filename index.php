<?php

@ini_set('display_errors', '0');
error_reporting(E_ALL);

$CONFIG = [
    'root'          => __DIR__ . '/storage',
    // Leave empty on first run to generate a password hash.
    'password_hash' => '$2y$10$ZdQdPIWTVS298NYnQ26HhOpWd6hWZs5SmQLeA91/lB/w2xu/GDxYS',
    'title'         => 'My Storage',
    'max_file_mb'   => 0,      // 0 = unlimited
    // Public links can be opened without signing in.
    'public_links'  => true,
    // When empty, this is derived from the password hash, so changing the password invalidates existing links.
    // Set a long random value to keep links valid when the password changes.
    'share_secret'  => '',
    // Direct links are detected when root is inside the web root. Set this to a path or full URL to override it.
    // If root is outside the web root and this is empty, signed links are served through this script.
    'public_base_url' => '',
    // Convert imported filenames to URL-friendly names.
    'clean_names'   => true,
    'session_hours' => 12,
    'max_attempts'  => 5,      // Failed sign-in attempts before temporary lockout.
    'lock_minutes'  => 10,
    'thumb_dir'     => sys_get_temp_dir() . '/fm_thumbs',
    // Empty means all extensions are accepted except those in blocked_ext.
    'allowed_ext'   => [
        'jpg','jpeg','png','gif','webp','avif','bmp','svg','ico',
        'mp4','m4v','webm','mov','mkv','avi','ogv','wmv',
        'mp3','wav','ogg','m4a','flac','aac',
        'pdf','txt','md','csv','json','zip','rar','7z',
    ],
    // Always rejected, even when allowed_ext is empty.
    'blocked_ext'   => [
        'php','php3','php4','php5','php7','php8','phtml','pht','phps','phar','cgi','pl','py','rb',
        'sh','bash','exe','bat','cmd','com','msi','dll','jar','htaccess','htpasswd','ini',
        'asp','aspx','jsp','shtml',
    ],
];

if (!is_dir($CONFIG['root'])) { @mkdir($CONFIG['root'], 0755, true); }
$ROOT = realpath($CONFIG['root']);
$SELF = realpath(__FILE__);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail($code, $msg, $extra = []) { out(array_merge(['error' => $msg], $extra), $code); }
function body() {
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function inRoot($real) {
    global $ROOT;
    return $ROOT !== false && ($real === $ROOT || strpos($real, $ROOT . DIRECTORY_SEPARATOR) === 0);
}
/** Resolve a relative path inside the storage root, or return null. */
function safePath($rel) {
    global $ROOT;
    if ($ROOT === false) return null;
    $rel = str_replace('\\', '/', (string)$rel);
    if (strpos($rel, "\0") !== false) return null;
    $parts = [];
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;
        $parts[] = $seg;
    }
    $full = $ROOT . ($parts ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts) : '');
    $real = realpath($full);
    if ($real === false || !inRoot($real)) return null;
    return $real;
}
function resolve($rel) {
    $p = safePath($rel);
    if ($p === null) fail(404, 'Path not found or not allowed');
    return $p;
}
function relOf($real) {
    global $ROOT;
    return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($ROOT))), '/');
}
function cleanName($n) {
    $n = trim((string)$n);
    if ($n === '' || $n === '.' || $n === '..') fail(400, 'Invalid name');
    if (preg_match('/[\x00-\x1F\x7F\/\\\\:*?"<>|]/', $n)) fail(400, 'Name contains forbidden characters ( / \\ : * ? " < > | )');
    if ($n[0] === '.') fail(400, 'Names cannot start with a dot');
    if (strlen($n) > 200) fail(400, 'Name is too long');
    return $n;
}
function checkExt($name) {
    global $CONFIG;
    $segs = explode('.', strtolower($name));
    array_shift($segs);
    foreach ($segs as $s) {
        if (in_array($s, $CONFIG['blocked_ext'], true)) fail(415, "File type is forbidden (.$s)");
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($CONFIG['allowed_ext'] && !in_array($ext, $CONFIG['allowed_ext'], true)) {
        fail(415, 'File extension is not allowed' . ($ext !== '' ? " (.$ext)" : ''));
    }
}
function uniqueName($dir, $name) {
    if (!file_exists($dir . DIRECTORY_SEPARATOR . $name)) return $name;
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : $name;
    for ($i = 1; $i < 10000; $i++) {
        $c = $base . " ($i)" . ($ext !== '' ? ".$ext" : '');
        if (!file_exists($dir . DIRECTORY_SEPARATOR . $c)) return $c;
    }
    return $base . '-' . bin2hex(random_bytes(4)) . ($ext !== '' ? ".$ext" : '');
}
function kindOf($ext) {
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (['jpg','jpeg','png','gif','webp','avif','bmp','svg','ico'] as $e) $m[$e] = 'image';
        foreach (['mp4','m4v','webm','mov','ogv','mkv'] as $e) $m[$e] = 'video';
        foreach (['mp3','wav','ogg','m4a','flac','aac'] as $e) $m[$e] = 'audio';
        $m['pdf'] = 'pdf';
        foreach (['txt','md','csv','json','log'] as $e) $m[$e] = 'text';
    }
    return isset($m[$ext]) ? $m[$ext] : 'file';
}
function mimeOf($ext) {
    static $m = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',
        'avif'=>'image/avif','bmp'=>'image/bmp','svg'=>'image/svg+xml','ico'=>'image/x-icon',
        'mp4'=>'video/mp4','m4v'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','ogv'=>'video/ogg','mkv'=>'video/x-matroska',
        'mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg','m4a'=>'audio/mp4','flac'=>'audio/flac','aac'=>'audio/aac',
        'pdf'=>'application/pdf','txt'=>'text/plain; charset=utf-8','md'=>'text/plain; charset=utf-8',
        'log'=>'text/plain; charset=utf-8','csv'=>'text/plain; charset=utf-8','json'=>'application/json','zip'=>'application/zip',
    ];
    return isset($m[$ext]) ? $m[$ext] : 'application/octet-stream';
}
function iniBytes($v) {
    $v = trim((string)$v);
    if ($v === '') return 0;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}
function maxChunk() {
    $u = iniBytes(ini_get('upload_max_filesize'));
    $p = iniBytes(ini_get('post_max_size'));
    $lim = min($u > 0 ? $u : PHP_INT_MAX, $p > 0 ? $p : PHP_INT_MAX);
    $c = $lim - 128 * 1024;
    return (int)max(64 * 1024, min($c, 8 * 1024 * 1024));
}
function rrmdir($dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isLink() || $f->isFile()) { if (!@unlink($f->getPathname())) return false; }
        else { if (!@rmdir($f->getPathname())) return false; }
    }
    return @rmdir($dir);
}

/** Load a JPEG, PNG, or WebP image with EXIF orientation applied, or return false. */
function gdLoad($path, $ext) {
    switch ($ext) {
        case 'png':  $im = @imagecreatefrompng($path); break;
        case 'webp': $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
        case 'jpg':
        case 'jpeg': $im = @imagecreatefromjpeg($path); break;
        default:     $im = false;
    }
    if (!$im) return false;
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $o = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
        $rot = $o === 3 ? 180 : ($o === 6 ? -90 : ($o === 8 ? 90 : null));
        if ($rot !== null) { $r = @imagerotate($im, $rot, 0); if ($r) { imagedestroy($im); $im = $r; } }
    }
    return $im;
}
/** Quickly sample an image to detect transparency. */
function gdHasAlpha($im) {
    $w = imagesx($im);
    $h = imagesy($im);
    $sx = max(1, (int)floor($w / 80));
    $sy = max(1, (int)floor($h / 80));
    for ($y = 0; $y < $h; $y += $sy) {
        for ($x = 0; $x < $w; $x += $sx) {
            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) return true;
        }
    }
    return false;
}

function humanSize($b) {
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float)$b;
    while ($n >= 1024 && $i < 3) { $n /= 1024; $i++; }
    return ($i ? number_format($n, $n < 10 ? 1 : 0, '.', ',') : (string)(int)$n) . ' ' . $u[$i];
}
function webpSupported() { return function_exists('imagewebp') && function_exists('imagecreatefromwebp'); }
function webpLosslessSupported() { return webpSupported() && defined('IMG_WEBP_LOSSLESS'); }

/** Validate and clamp image compression options. */
function cmpOptions($b) {
    $fmt = (string)($b['format'] ?? 'jpeg');
    if (!in_array($fmt, ['jpeg', 'webp', 'webp-lossless', 'png', 'same'], true)) $fmt = 'jpeg';
    $maxd = (int)($b['maxd'] ?? 0);
    return [
        'quality' => max(40, min(100, (int)($b['quality'] ?? 90))),
        'maxd'    => $maxd > 0 ? max(64, min(12000, $maxd)) : 0, // Longest side in pixels; 0 preserves the original dimensions.
        'format'  => $fmt,
    ];
}
/** Load and optionally downscale an image without enlarging it, then choose its output format. */
function cmpPrepare($src, $ext, $o) {
    if (!function_exists('imagecreatetruecolor')) fail(501, 'The PHP GD extension is not available on this server');
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) fail(415, 'Compressible formats: JPG, PNG, WebP');
    $hasWebp = webpSupported();
    $fmt = $o['format'];
    if (($ext === 'webp' || $fmt === 'webp' || $fmt === 'webp-lossless') && !$hasWebp) fail(501, 'WebP is not supported by this server');
    if ($fmt === 'webp-lossless' && !webpLosslessSupported()) fail(501, 'Lossless WebP is not supported by this server (PHP 8.1 or later required)');
    $info = @getimagesize($src);
    if (!$info || $info[0] * $info[1] > 60000000) fail(413, 'Image is too large to process');
    @ini_set('memory_limit', '768M');
    @set_time_limit(120);
    $im = gdLoad($src, $ext);
    if (!$im) fail(422, 'Unable to read image');
    $alpha = gdHasAlpha($im);

    $lossless = false;
    if ($fmt === 'png')                 { $target = 'png';  $lossless = true; }
    elseif ($fmt === 'webp-lossless')   { $target = 'webp'; $lossless = true; }
    elseif ($fmt === 'webp')            { $target = 'webp'; }
    elseif ($fmt === 'same')            { $target = $ext === 'jpeg' ? 'jpg' : $ext; $lossless = $target === 'png'; }
    else                                { $target = 'jpg'; }
    if ($alpha && $target === 'jpg') { // JPEG does not support transparency.
        if ($hasWebp) $target = 'webp'; else { $target = 'png'; $lossless = true; }
    }

    $sw = imagesx($im);
    $sh = imagesy($im);
    $big = max($sw, $sh);
    $scale = ($o['maxd'] > 0 && $big > $o['maxd']) ? $o['maxd'] / $big : 1;
    $nw = max(1, (int)round($sw * $scale));
    $nh = max(1, (int)round($sh * $scale));
    if ($nw !== $sw || $nh !== $sh) {
        $dst = imagecreatetruecolor($nw, $nh);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
        imagedestroy($im);
    } else {
        $dst = $im;
    }
    if ($alpha) { imagealphablending($dst, false); imagesavealpha($dst, true); }
    return ['im' => $dst, 'alpha' => $alpha, 'target' => $target, 'lossless' => $lossless, 'w' => $nw, 'h' => $nh, 'sw' => $sw, 'sh' => $sh];
}
/** Encode an image in memory and return its binary data, or false. */
function cmpEncode($im, $target, $quality, $lossless) {
    ob_start();
    if ($target === 'jpg')      { imageinterlace($im, true); $ok = @imagejpeg($im, null, $quality); }
    elseif ($target === 'webp') { $ok = @imagewebp($im, null, $lossless ? IMG_WEBP_LOSSLESS : $quality); }
    else                        { $ok = @imagepng($im, null, 9); }
    $data = ob_get_clean();
    return ($ok && is_string($data) && $data !== '') ? $data : false;
}
/** Create names such as image-min.jpg, then image-min-2.jpg, and so on. */
function cmpNewName($dir, $src, $target) {
    $base = preg_replace('/-min(-\d+)?$/', '', pathinfo($src, PATHINFO_FILENAME));
    for ($i = 1; $i < 1000; $i++) {
        $cand = $base . '-min' . ($i > 1 ? '-' . $i : '') . '.' . $target;
        if (!file_exists($dir . DIRECTORY_SEPARATOR . $cand)) return $cand;
    }
    fail(409, 'Too many compressed versions already exist');
}
/** Find the most detailed image region among five candidates, where artifacts are easiest to see. */
function pickCrop($im, $cw, $ch) {
    $w = imagesx($im);
    $h = imagesy($im);
    if ($cw < 4 || $ch < 4) return [0, 0];
    $best = [0, 0];
    $bestScore = -1;
    foreach ([[.5, .5], [.25, .3], [.75, .3], [.25, .7], [.75, .7]] as $c) {
        $x0 = (int)max(0, min($w - $cw, round($w * $c[0] - $cw / 2)));
        $y0 = (int)max(0, min($h - $ch, round($h * $c[1] - $ch / 2)));
        $s = 0;
        for ($j = 0; $j < 14; $j++) {
            $y = $y0 + (int)(($ch - 3) * $j / 13);
            for ($i = 0; $i < 20; $i++) {
                $x = $x0 + (int)(($cw - 3) * $i / 19);
                $g = (imagecolorat($im, $x, $y) >> 8) & 255;
                $s += abs($g - ((imagecolorat($im, $x + 2, $y) >> 8) & 255)) + abs($g - ((imagecolorat($im, $x, $y + 2) >> 8) & 255));
            }
        }
        if ($s > $bestScore) { $bestScore = $s; $best = [$x0, $y0]; }
    }
    return $best;
}
function cropUri($im, $x, $y, $w, $h) {
    $c = imagecreatetruecolor($w, $h);
    imagealphablending($c, false);
    imagesavealpha($c, true);
    imagefill($c, 0, 0, imagecolorallocatealpha($c, 0, 0, 0, 127));
    imagecopy($c, $im, 0, 0, $x, $y, $w, $h);
    ob_start();
    imagepng($c, null, 6);
    $d = ob_get_clean();
    imagedestroy($c);
    return 'data:image/png;base64,' . base64_encode((string)$d);
}

/** Cached storage statistics are invalidated whenever a write action occurs. */
function statsCachePath() {
    global $CONFIG, $ROOT;
    return $CONFIG['thumb_dir'] . '/fm_stats_' . md5((string)$ROOT) . '.json';
}
function computeStats() {
    global $ROOT, $SELF;
    $by = ['image' => [0, 0], 'video' => [0, 0], 'audio' => [0, 0], 'other' => [0, 0]];
    $dirs = 0; $files = 0; $bytes = 0; $n = 0; $partial = false;
    $t0 = microtime(true);
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS),
                function ($cur) { return $cur->getFilename()[0] !== '.'; }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if (++$n % 400 === 0 && microtime(true) - $t0 > 4) { $partial = true; break; }
            if ($f->isDir()) { $dirs++; continue; }
            if (!$f->isFile() || $f->getPathname() === $SELF) continue;
            $k = kindOf(strtolower($f->getExtension()));
            if (!isset($by[$k])) $k = 'other';
            $sz = (int)$f->getSize();
            $by[$k][0] += $sz;
            $by[$k][1]++;
            $bytes += $sz;
            $files++;
        }
    } catch (Throwable $e) { $partial = true; }
    $out = [];
    foreach ($by as $k => $v) $out[$k] = ['bytes' => $v[0], 'count' => $v[1]];
    $free = @disk_free_space($ROOT);
    $disk = @disk_total_space($ROOT);
    return [
        'total' => $bytes, 'files' => $files, 'dirs' => $dirs, 'by' => $out,
        'free' => $free === false ? null : (float)$free,
        'disk' => $disk === false ? null : (float)$disk,
        'partial' => $partial, 'at' => time(),
    ];
}

/** Serve a file with Range request support for media playback and seeking. */
function serveFile($path, $download = false, $public = false) {
    $size  = (int)filesize($path);
    $mtime = (int)filemtime($path);
    $ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    @session_write_close();
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');

    $inline = !$download && in_array(kindOf($ext), ['image', 'video', 'audio', 'pdf', 'text'], true);
    $fn = basename($path);
    header('Content-Type: ' . mimeOf($ext));
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Cache-Control: ' . ($public ? 'public' : 'private') . ', max-age=3600');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
        . '; filename="' . addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $fn), '"\\') . '"'
        . "; filename*=UTF-8''" . rawurlencode($fn));
    if ($ext === 'svg') header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");

    $start = 0;
    $end   = $size - 1;
    $range = isset($_SERVER['HTTP_RANGE']) ? trim($_SERVER['HTTP_RANGE']) : '';
    if ($range === '' && isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime) {
        http_response_code(304);
        exit;
    }
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) || ($m[1] === '' && $m[2] === '')) {
            http_response_code(416); header("Content-Range: bytes */$size"); exit;
        }
        if ($m[1] === '') {
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416); header("Content-Range: bytes */$size"); exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    $len = max(0, $end - $start + 1);
    header('Content-Length: ' . $len);
    while (ob_get_level() > 0) @ob_end_clean();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;

    $fp = @fopen($path, 'rb');
    if (!$fp) exit;
    if ($start > 0) fseek($fp, $start);
    $left = $len;
    while ($left > 0 && !feof($fp)) {
        $buf = fread($fp, (int)min(262144, $left));
        if ($buf === false || $buf === '') break;
        echo $buf;
        $left -= strlen($buf);
        flush();
        if (connection_aborted()) break;
    }
    fclose($fp);
    exit;
}

/** Serve a cached JPEG thumbnail, falling back to the original when unavailable. */
function serveThumb($path, $w) {
    global $CONFIG;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!function_exists('imagecreatetruecolor') || !in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) serveFile($path);
    $w   = max(64, min(1200, (int)$w));
    $dir = $CONFIG['thumb_dir'];
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $cache = $dir . '/' . md5($path . '|' . filemtime($path) . '|' . $w) . '.jpg';

    if (!is_file($cache)) {
        $info = @getimagesize($path);
        if (!$info || $info[0] * $info[1] > 40000000) serveFile($path);
        @ini_set('memory_limit', '512M');
        $src = false;
        switch ($ext) {
            case 'png':  $src = @imagecreatefrompng($path); break;
            case 'gif':  $src = @imagecreatefromgif($path); break;
            case 'webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
            default:     $src = @imagecreatefromjpeg($path);
        }
        if (!$src) serveFile($path);
        if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $o = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
            $rot = null;
            if ($o === 3) $rot = 180; elseif ($o === 6) $rot = -90; elseif ($o === 8) $rot = 90;
            if ($rot !== null) { $r = @imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
        }
        $sw = imagesx($src); $sh = imagesy($src);
        $ratio = min(1, $w / max($sw, $sh));
        $tw = max(1, (int)round($sw * $ratio));
        $th = max(1, (int)round($sh * $ratio));
        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 32, 36, 44));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
        imagejpeg($dst, $cache, 80);
        imagedestroy($src);
        imagedestroy($dst);
        if (!is_file($cache)) serveFile($path);
    }
    @session_write_close();
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=604800');
    header('Content-Length: ' . filesize($cache));
    while (ob_get_level() > 0) @ob_end_clean();
    readfile($cache);
    exit;
}

/** Signed public links use HMAC and require no server-side link storage. */
function shareSecret() {
    global $CONFIG;
    if (!empty($CONFIG['share_secret'])) return (string)$CONFIG['share_secret'];
    if ($CONFIG['password_hash'] !== '') return hash_hmac('sha256', 'fm-share-v1', (string)$CONFIG['password_hash']);
    return '';
}
function shareSig($rel) { return substr(hash_hmac('sha256', (string)$rel, shareSecret()), 0, 32); }

/** Return the direct-link base URL, or null when the storage root is not web-accessible. */
function publicBase() {
    global $CONFIG, $ROOT;
    if (!empty($CONFIG['public_base_url'])) return rtrim((string)$CONFIG['public_base_url'], '/');
    $doc = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($doc === false || $ROOT === false) return null;
    if ($ROOT === $doc) return '';
    if (strpos($ROOT, $doc . DIRECTORY_SEPARATOR) !== 0) return null;
    $parts = explode('/', trim(str_replace(DIRECTORY_SEPARATOR, '/', substr($ROOT, strlen($doc))), '/'));
    return '/' . implode('/', array_map('rawurlencode', $parts));
}
/** Convert imported filenames to URL-friendly names. */
function slugName($name) {
    static $map = [
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','œ'=>'oe','æ'=>'ae','ß'=>'ss',
        'À'=>'A','Á'=>'A','Â'=>'A','Ä'=>'A','Ã'=>'A','Å'=>'A','Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Ö'=>'O','Õ'=>'O',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','Œ'=>'OE','Æ'=>'AE',
    ];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : $name;
    $base = strtr($base, $map);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
    $base = trim(preg_replace('/-+/', '-', $base), '-._');
    if ($base === '') $base = 'file';
    return $base . ($ext !== '' ? '.' . preg_replace('/[^a-z0-9]/', '', $ext) : '');
}

/** Track failed sign-in attempts per IP address. */
function lockPath() { return sys_get_temp_dir() . '/fm_lock_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . __FILE__); }
function lockRead() {
    $d = @json_decode((string)@file_get_contents(lockPath()), true);
    return is_array($d) ? $d : ['n' => 0, 't' => 0];
}
function lockedFor() {
    global $CONFIG;
    $d = lockRead();
    if ($d['n'] >= $CONFIG['max_attempts']) {
        $left = $d['t'] + $CONFIG['lock_minutes'] * 60 - time();
        if ($left > 0) return $left;
        @unlink(lockPath());
    }
    return 0;
}
function lockFail() {
    global $CONFIG;
    $d = lockRead();
    if (time() - $d['t'] > $CONFIG['lock_minutes'] * 60) $d['n'] = 0;
    $d['n']++;
    $d['t'] = time();
    @file_put_contents(lockPath(), json_encode($d), LOCK_EX);
}

function authPage($title, $inner) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'");
    header('X-Frame-Options: SAMEORIGIN');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= h($title) ?></title>
<style>
:root{color-scheme:light dark;
--bg:light-dark(#f8f8f9,#17191e);--surface:light-dark(#ffffff,#1e2127);--line:light-dark(#e2e3e8,#2a2e37);--line2:light-dark(#cfd1d9,#3a404b);
--fg:light-dark(#17181c,#eceef2);--fg2:light-dark(#4d515c,#a6acb8);--fg3:light-dark(#6a6f7d,#7f8693);
--accent:light-dark(#b87a14,#f2b544);--danger:light-dark(#d23b48,#f0616d)}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--fg);font:14px/1.5 "Segoe UI Variable Text","Segoe UI Variable",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:16px;-webkit-font-smoothing:antialiased}
.card{width:min(380px,100%);background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:30px 28px 28px;box-shadow:0 18px 50px light-dark(rgba(20,24,40,.10),rgba(0,0,0,.42))}
.logo{width:40px;height:40px;border-radius:12px;background:#f2b544;display:grid;place-items:center;margin-bottom:22px}
h1{font-size:20px;line-height:1.25;font-weight:650;letter-spacing:-.02em;margin:0 0 6px}
p{color:var(--fg2);margin:0 0 22px;font-size:13.5px;line-height:1.55}
input{width:100%;height:42px;padding:0 14px;border-radius:10px;border:1px solid var(--line2);background:var(--bg);color:var(--fg);font:inherit;margin-bottom:12px}
input:focus{outline:2px solid var(--accent);outline-offset:-1px;border-color:transparent}
button{width:100%;height:42px;border:0;border-radius:10px;background:var(--fg);color:var(--bg);font:inherit;font-weight:600;cursor:pointer;transition:opacity .12s}
button:hover{opacity:.88}
button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.err{color:var(--danger);margin:0 0 12px;font-size:13px}
code{display:block;word-break:break-all;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:10px;font-size:12px;margin:10px 0 14px;user-select:all}
</style></head><body><div class="card"><div class="logo" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h3.6a2 2 0 0 1 1.5.7L11.5 7H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="#1b1608"/></svg></div><?= $inner ?></div></body></html><?php
    exit;
}
function renderLogin($error = '') {
    global $CONFIG;
    $csrf = $_SESSION['csrf'];
    $inner = '<h1>' . h($CONFIG['title']) . '</h1><p>Enter your password to access your files.</p>'
        . ($error !== '' ? '<div class="err" role="alert">' . h($error) . '</div>' : '')
        . '<form method="post"><input type="hidden" name="do" value="login"><input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="password" name="password" placeholder="Password" autocomplete="current-password" aria-label="Password" autofocus required><button type="submit">Sign in</button></form>';
    authPage($CONFIG['title'], $inner);
}
function renderSetup($error = '', $hash = '') {
    $csrf = $_SESSION['csrf'];
    if ($hash !== '') {
        $inner = '<h1>Password ready</h1><p>Copy this line into <b>$CONFIG</b>, replacing the <b>password_hash</b> setting, then reload the page.</p>'
            . '<code>\'password_hash\' =&gt; \'' . h($hash) . '\',</code>';
    } else {
        $inner = '<h1>Initial setup</h1><p>No password is configured. Choose one (at least 8 characters) to generate its hash.</p>'
            . ($error !== '' ? '<div class="err">' . h($error) . '</div>' : '')
            . '<form method="post" autocomplete="off"><input type="hidden" name="do" value="hash"><input type="hidden" name="csrf" value="' . h($csrf) . '">'
            . '<input type="password" name="password" placeholder="New password" minlength="8" autofocus required><button type="submit">Generate hash</button></form>';
    }
    authPage('Setup', $inner);
}

if (isset($_GET['a']) && $_GET['a'] === 'pub') {
    $real = (!empty($CONFIG['public_links']) && shareSecret() !== '') ? safePath($_GET['p'] ?? '') : null;
    if ($real === null || !is_file($real) || $real === $SELF) { http_response_code(404); exit('Invalid link'); }
    $rel = relOf($real);
    if (strpos('/' . $rel, '/.') !== false || !hash_equals(shareSig($rel), (string)($_GET['s'] ?? ''))) {
        http_response_code(404);
        exit('Invalid link');
    }
    header('Access-Control-Allow-Origin: *');
    header('Cross-Origin-Resource-Policy: cross-origin');
    serveFile($real, false, true);
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$cpath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($cpath === '' || $cpath === '.') $cpath = '/';
@ini_set('session.use_strict_mode', '1');
session_name('fm_sid');
session_set_cookie_params(['lifetime' => 0, 'path' => $cpath, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
if (!empty($_SESSION['ok']) && isset($_SESSION['t']) && time() - $_SESSION['t'] > $CONFIG['session_hours'] * 3600) {
    $_SESSION = [];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isPost = $method === 'POST';
$action = isset($_GET['a']) ? (string)$_GET['a'] : '';

if ($isPost && isset($_POST['do'])) {
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        if ($CONFIG['password_hash'] === '') renderSetup('Session expired. Please try again.');
        renderLogin('Session expired. Please try again.');
    }
    if ($_POST['do'] === 'login' && $CONFIG['password_hash'] !== '') {
        $wait = lockedFor();
        if ($wait > 0) renderLogin('Too many attempts. Try again in ' . ceil($wait / 60) . ' minute(s).');
        if (password_verify((string)($_POST['password'] ?? ''), $CONFIG['password_hash'])) {
            @unlink(lockPath());
            session_regenerate_id(true);
            $_SESSION['ok']   = true;
            $_SESSION['t']    = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        lockFail();
        usleep(600000);
        renderLogin('Incorrect password.');
    }
    if ($_POST['do'] === 'hash' && $CONFIG['password_hash'] === '') {
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 8) renderSetup('Use at least 8 characters.');
        renderSetup('', password_hash($pw, PASSWORD_DEFAULT));
    }
}

$authed = !empty($_SESSION['ok']);
if (!$authed) {
    if ($action !== '') fail(401, 'Not authenticated');
    if ($CONFIG['password_hash'] === '') renderSetup();
    renderLogin();
}
$_SESSION['t'] = time();

if ($action !== '') {
    if ($ROOT === false) fail(500, 'Storage root not found or inaccessible. Check the "root" setting.');

    $writeActions = ['mkdir', 'delete', 'rename', 'move', 'upload', 'compress', 'logout'];
    if (in_array($action, $writeActions, true)) {
        if (!$isPost) fail(405, 'Method not allowed');
        $tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals((string)$_SESSION['csrf'], $tok)) {
            // Oversized POST bodies may be empty even though the CSRF header is present.
            fail(403, 'Invalid security token. Reload the page.');
        }
        @unlink(statsCachePath());
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);
    }
    session_write_close();

    switch ($action) {

        case 'list': {
            $dir = resolve($_GET['p'] ?? '');
            if (!is_dir($dir)) fail(400, 'Not a directory');
            $names = @scandir($dir);
            if ($names === false) fail(500, 'Unable to read directory');
            $items = [];
            foreach ($names as $n) {
                if ($n === '.' || $n === '..' || $n[0] === '.') continue;
                $full = $dir . DIRECTORY_SEPARATOR . $n;
                $real = @realpath($full);
                if ($real === false || !inRoot($real) || $real === $SELF) continue;
                $isDir = is_dir($full);
                $ext   = $isDir ? '' : strtolower(pathinfo($n, PATHINFO_EXTENSION));
                $items[] = [
                    'name'  => $n,
                    'dir'   => $isDir,
                    'size'  => $isDir ? null : (int)@filesize($full),
                    'mtime' => (int)@filemtime($full),
                    'ext'   => $ext,
                    'kind'  => $isDir ? 'dir' : kindOf($ext),
                ];
            }
            out([
                'path'     => relOf($dir),
                'items'    => $items,
                'maxChunk' => maxChunk(),
                'canWrite' => is_writable($dir),
                'gd'       => function_exists('imagecreatetruecolor'),
                'webp'     => webpSupported(),
                'webpLL'   => webpLosslessSupported(),
            ]);
        }

        case 'dirs': {
            // Return child folders only; the tree is loaded on demand.
            $dir = resolve($_GET['p'] ?? '');
            if (!is_dir($dir)) fail(400, 'Not a directory');
            $names = @scandir($dir);
            if ($names === false) fail(500, 'Unable to read directory');
            $res = [];
            foreach ($names as $n) {
                if ($n[0] === '.') continue;
                $full = $dir . DIRECTORY_SEPARATOR . $n;
                if (!is_dir($full)) continue;
                $real = @realpath($full);
                if ($real === false || !inRoot($real)) continue;
                $has = false;
                if ($dh = @opendir($full)) {
                    while (($c = readdir($dh)) !== false) {
                        if ($c[0] !== '.' && is_dir($full . DIRECTORY_SEPARATOR . $c)) { $has = true; break; }
                    }
                    closedir($dh);
                }
                $res[] = ['name' => $n, 'has' => $has];
                if (count($res) >= 500) break;
            }
            out(['path' => relOf($dir), 'dirs' => $res]);
        }

        case 'stats': {
            $cache = statsCachePath();
            if (empty($_GET['fresh']) && is_file($cache) && filemtime($cache) > time() - 120) {
                $json = @file_get_contents($cache);
                if ($json) {
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-store');
                    echo $json;
                    exit;
                }
            }
            @set_time_limit(30);
            $st = computeStats();
            if (!is_dir($CONFIG['thumb_dir'])) @mkdir($CONFIG['thumb_dir'], 0700, true);
            @file_put_contents($cache, json_encode($st), LOCK_EX);
            out($st);
        }

        case 'file': {
            $f = resolve($_GET['p'] ?? '');
            if (!is_file($f)) fail(404, 'File not found');
            serveFile($f, !empty($_GET['dl']));
        }

        case 'thumb': {
            $f = resolve($_GET['p'] ?? '');
            if (!is_file($f)) fail(404, 'File not found');
            serveThumb($f, $_GET['w'] ?? 400);
        }

        case 'share': {
            if (empty($CONFIG['public_links']) || shareSecret() === '') fail(403, 'Public links are disabled');
            $f = resolve($_GET['p'] ?? '');
            if (!is_file($f) || $f === $SELF) fail(400, 'Invalid file');
            $rel = relOf($f);
            if (strpos('/' . $rel, '/.') !== false) fail(400, 'Invalid file');
            $base = publicBase();
            if ($base !== null) {
                // Direct links are shorter and served by the web server without PHP.
                $enc = implode('/', array_map('rawurlencode', explode('/', $rel)));
                out(['url' => $base . '/' . $enc, 'direct' => true]);
            }
            // Otherwise, this script serves a signed URL whose path ends with the file extension.
            out(['url' => '?a=pub&s=' . shareSig($rel) . '&p=' . str_replace('%2F', '/', rawurlencode($rel))]);
        }

        case 'mkdir': {
            $b = body();
            $dir = resolve($b['p'] ?? '');
            if (!is_dir($dir)) fail(400, 'Invalid parent directory');
            $name = cleanName($b['name'] ?? '');
            $t = $dir . DIRECTORY_SEPARATOR . $name;
            if (file_exists($t)) fail(409, 'An item with this name already exists');
            if (!@mkdir($t, 0755)) fail(500, 'Unable to create directory. Check permissions.');
            out(['ok' => true, 'name' => $name]);
        }

        case 'cmpprev': {
            // Preview the exact output size and compare the original and result at 100% zoom without writing a file.
            $src = resolve($_GET['p'] ?? '');
            if (!is_file($src) || $src === $SELF) fail(400, 'Invalid file');
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            $o   = cmpOptions($_GET);
            $r   = cmpPrepare($src, $ext, $o);
            $data = cmpEncode($r['im'], $r['target'], $o['quality'], $r['lossless']);
            if ($data === false) { imagedestroy($r['im']); fail(500, 'Unable to compress image'); }
            $dec = @imagecreatefromstring($data);
            if (!$dec) { imagedestroy($r['im']); fail(500, 'Unable to create preview'); }
            $cw = min($r['w'], 250);
            $ch = min($r['h'], 150);
            $pos = pickCrop($r['im'], $cw, $ch);
            $resp = [
                'before' => (int)filesize($src), 'after' => strlen($data),
                'w' => $r['w'], 'h' => $r['h'], 'sw' => $r['sw'], 'sh' => $r['sh'],
                'target' => $r['target'], 'lossless' => $r['lossless'], 'alpha' => $r['alpha'],
                'cw' => $cw, 'ch' => $ch,
                'orig' => cropUri($r['im'], $pos[0], $pos[1], $cw, $ch),
                'comp' => cropUri($dec, $pos[0], $pos[1], $cw, $ch),
            ];
            imagedestroy($r['im']);
            imagedestroy($dec);
            out($resp);
        }

        case 'compress': {
            $b   = body();
            $src = resolve($b['p'] ?? '');
            if (!is_file($src) || $src === $SELF) fail(400, 'Invalid file');
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            $dir = dirname($src);
            if (!is_writable($dir)) fail(403, 'Directory is not writable');
            $o = cmpOptions($b);
            $r = cmpPrepare($src, $ext, $o);
            $data = cmpEncode($r['im'], $r['target'], $o['quality'], $r['lossless']);
            imagedestroy($r['im']);
            if ($data === false) fail(500, 'Unable to compress image');

            $before = (int)filesize($src);
            $after  = strlen($data);
            if ($after >= $before) {
                fail(422, 'Already optimized: the result (' . humanSize($after) . ') would be larger than the original (' . humanSize($before) . '). Lower the quality, reduce the dimensions, or choose another format.');
            }
            $newName = cmpNewName($dir, $src, $r['target']);
            checkExt($newName);
            $tmp = $dir . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(6)) . '.part';
            if (@file_put_contents($tmp, $data, LOCK_EX) !== $after) { @unlink($tmp); fail(507, 'Unable to write file. Check available disk space.'); }
            if (!@rename($tmp, $dir . DIRECTORY_SEPARATOR . $newName)) { @unlink($tmp); fail(500, 'Unable to finalize file'); }
            @chmod($dir . DIRECTORY_SEPARATOR . $newName, 0644);
            out(['ok' => true, 'name' => $newName, 'before' => $before, 'after' => $after, 'w' => $r['w'], 'h' => $r['h'], 'target' => $r['target']]);
        }

        case 'move': {
            $b = body();
            $dest = resolve($b['to'] ?? '');
            if (!is_dir($dest)) fail(400, 'Invalid destination directory');
            if (!is_writable($dest)) fail(403, 'Destination is not writable');
            $paths = $b['paths'] ?? [];
            if (!is_array($paths) || !$paths) fail(400, 'Nothing to move');
            $moved  = 0;
            $errors = [];
            foreach ($paths as $rel) {
                $real = safePath($rel);
                if ($real === null || $real === $ROOT || $real === $SELF) { $errors[] = 'Invalid item'; continue; }
                $name = basename($real);
                if (dirname($real) === $dest) continue;
                if ($dest === $real || strpos($dest, $real . DIRECTORY_SEPARATOR) === 0) {
                    $errors[] = 'Cannot move "' . $name . '" into itself';
                    continue;
                }
                if (strpos($SELF, $real . DIRECTORY_SEPARATOR) === 0) { $errors[] = 'The script cannot be moved'; continue; }
                $target = $dest . DIRECTORY_SEPARATOR . $name;
                if (file_exists($target)) { $errors[] = '"' . $name . '" already exists in the destination directory'; continue; }
                if (@rename($real, $target)) $moved++;
                else $errors[] = 'Unable to move "' . $name . '". Check permissions.';
            }
            out(['moved' => $moved, 'errors' => $errors]);
        }

        case 'rename': {
            $b = body();
            $real = resolve($b['p'] ?? '');
            if ($real === $ROOT || $real === $SELF) fail(403, 'Renaming this item is not allowed');
            $name = cleanName($b['name'] ?? '');
            if (!is_dir($real)) checkExt($name);
            $target = dirname($real) . DIRECTORY_SEPARATOR . $name;
            if ($target === $real) out(['ok' => true, 'name' => $name]);
            if (file_exists($target)) fail(409, 'An item with this name already exists');
            if (!@rename($real, $target)) fail(500, 'Unable to rename item. Check permissions.');
            out(['ok' => true, 'name' => $name]);
        }

        case 'delete': {
            $b = body();
            $paths = $b['paths'] ?? [];
            if (!is_array($paths) || !$paths) fail(400, 'Nothing to delete');
            $deleted = 0;
            $errors  = [];
            foreach ($paths as $rel) {
                $real = safePath($rel);
                if ($real === null || $real === $ROOT) { $errors[] = 'Invalid path'; continue; }
                if ($real === $SELF || strpos($SELF, $real . DIRECTORY_SEPARATOR) === 0) {
                    $errors[] = 'The script cannot be deleted';
                    continue;
                }
                $ok = (is_dir($real) && !is_link($real)) ? rrmdir($real) : @unlink($real);
                if ($ok) $deleted++;
                else $errors[] = 'Unable to delete "' . basename($real) . '". Check permissions.';
            }
            out(['deleted' => $deleted, 'errors' => $errors]);
        }

        case 'upload': {
            if (empty($_POST) && empty($_FILES)) fail(413, 'Request is too large (PHP post_max_size exceeded)');
            $dir = resolve($_POST['p'] ?? '');
            if (!is_dir($dir)) fail(400, 'Invalid destination directory');

            $name = cleanName($_POST['name'] ?? '');
            if (!empty($CONFIG['clean_names'])) $name = slugName($name);
            checkExt($name);
            $id = (string)($_POST['id'] ?? '');
            if (!preg_match('/^[a-f0-9]{16,40}$/', $id)) fail(400, 'Invalid upload ID');
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $total  = max(0, (int)($_POST['total'] ?? 0));
            if ($CONFIG['max_file_mb'] > 0 && $total > $CONFIG['max_file_mb'] * 1048576) {
                fail(413, 'File exceeds the ' . $CONFIG['max_file_mb'] . ' MB size limit');
            }
            if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
                $err = isset($_FILES['chunk']['error']) ? $_FILES['chunk']['error'] : -1;
                fail(400, ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
                    ? 'Chunk exceeds the PHP upload limit'
                    : 'File upload failed (error code ' . $err . ')');
            }

            // Preserve the relative subdirectory structure when uploading a folder.
            $rel = trim(str_replace('\\', '/', (string)($_POST['rel'] ?? '')), '/');
            if ($rel !== '') {
                $segs = explode('/', $rel);
                if (count($segs) > 20) fail(400, 'Directory structure is too deep');
                foreach ($segs as $s) {
                    $seg = cleanName($s);
                    if (!empty($CONFIG['clean_names'])) $seg = slugName($seg);
                    $dir .= DIRECTORY_SEPARATOR . $seg;
                    if (!is_dir($dir) && !@mkdir($dir, 0755)) fail(500, 'Unable to create subdirectory');
                }
                $rd = realpath($dir);
                if ($rd === false || !inRoot($rd)) fail(403, 'Path is not allowed');
                $dir = $rd;
            }
            if (!is_writable($dir)) fail(403, 'Directory is not writable');

            $part = $dir . DIRECTORY_SEPARATOR . '.upload_' . $id . '.part';
            if ($offset === 0) {
                foreach ((array)@scandir($dir) as $old) {
                    if (preg_match('/^\.upload_.*\.part$/', (string)$old)) {
                        $op = $dir . DIRECTORY_SEPARATOR . $old;
                        if (@filemtime($op) < time() - 86400) @unlink($op);
                    }
                }
                @unlink($part);
            } else {
                if (!is_file($part) || filesize($part) !== $offset) {
                    fail(409, 'Unable to resume upload (offset mismatch)', ['received' => is_file($part) ? filesize($part) : 0]);
                }
            }

            $in   = @fopen($_FILES['chunk']['tmp_name'], 'rb');
            $outf = @fopen($part, 'ab');
            if (!$in || !$outf) fail(500, 'Unable to write file. Check permissions.');
            flock($outf, LOCK_EX);
            $n = stream_copy_to_stream($in, $outf);
            fflush($outf);
            flock($outf, LOCK_UN);
            fclose($outf);
            fclose($in);
            if ($n === false || (int)$n !== (int)$_FILES['chunk']['size']) {
                @unlink($part);
                fail(507, 'Write interrupted. Check available disk space.');
            }

            $received = $offset + (int)$n;
            if ($received >= $total) {
                $final = uniqueName($dir, $name);
                if (!@rename($part, $dir . DIRECTORY_SEPARATOR . $final)) {
                    @unlink($part);
                    fail(500, 'Unable to finalize upload');
                }
                @chmod($dir . DIRECTORY_SEPARATOR . $final, 0644);
                out(['ok' => true, 'done' => true, 'name' => $final]);
            }
            out(['ok' => true, 'done' => false, 'received' => $received]);
        }

        default:
            fail(404, 'Unknown action');
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
header('X-Frame-Options: SAMEORIGIN');
$boot = json_encode(['csrf' => $_SESSION['csrf'], 'title' => $CONFIG['title'], 'share' => (!empty($CONFIG['public_links']) && shareSecret() !== '')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($CONFIG['title']) ?></title>
<style>
:root{
  --bg:#0e1014;--panel:#161920;--panel2:#1d212a;--line:#272c37;--text:#e6e9ef;--muted:#8b93a3;
  --accent:#4f8cff;--accent-soft:rgba(79,140,255,.16);--danger:#ff5d6c;--ok:#3ecf8e;
  --radius:12px;--shadow:0 12px 34px rgba(0,0,0,.4);color-scheme:dark;
}
@media (prefers-color-scheme:light){:root{
  --bg:#f4f5f8;--panel:#ffffff;--panel2:#eef0f5;--line:#dfe3ec;--text:#1b1f2a;--muted:#6b7385;
  --accent:#2f6df0;--accent-soft:rgba(47,109,240,.12);--shadow:0 12px 34px rgba(20,30,60,.14);color-scheme:light;
}}
*{box-sizing:border-box}
[hidden]{display:none!important}
html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-tap-highlight-color:transparent}
button,input,select{font:inherit;color:inherit}
button{cursor:pointer}
.ic{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
.ic.big{width:46px;height:46px;stroke-width:1.4}
.app{display:flex;flex-direction:column;height:100%}

header.top{display:flex;align-items:center;gap:10px;padding:10px 16px;background:var(--panel);border-bottom:1px solid var(--line)}
.brand{display:flex;align-items:center;gap:8px;font-weight:700;color:var(--accent);white-space:nowrap}
.brand span{color:var(--text)}
.crumbs{display:flex;align-items:center;gap:2px;overflow-x:auto;flex:1;min-width:0;white-space:nowrap;scrollbar-width:none}
.crumbs::-webkit-scrollbar{display:none}
.crumbs button{display:inline-flex;align-items:center;gap:6px;background:none;border:0;padding:6px 8px;border-radius:8px;color:var(--muted)}
.crumbs button:hover{background:var(--panel2);color:var(--text)}
.crumbs button:last-child{color:var(--text);font-weight:600}
.crumbs .sep{color:var(--line)}

.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:10px;border:1px solid var(--line);background:var(--panel2);white-space:nowrap;transition:border-color .12s,background .12s}
.btn:hover{border-color:var(--accent)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.primary:hover{filter:brightness(1.08)}
.btn.danger{background:var(--danger);border-color:var(--danger);color:#fff}
.btn.ghost{background:none;border-color:transparent}
.btn.ghost:hover{background:var(--panel2)}
.btn:disabled{opacity:.45;cursor:default}
.btn.iconly{padding:8px}

.tools{display:flex;align-items:center;gap:8px;padding:10px 16px;flex-wrap:wrap;border-bottom:1px solid var(--line)}
.grp{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.grow{flex:1}
#selcount{font-weight:600;padding:0 4px}
.search{position:relative;display:flex;align-items:center}
.search .ic{position:absolute;left:10px;color:var(--muted);pointer-events:none}
.search input,select{padding:8px 10px;border-radius:10px;border:1px solid var(--line);background:var(--panel2)}
.search input{padding-left:34px;width:200px}
.search input:focus,select:focus{outline:2px solid var(--accent);outline-offset:0}
.seg{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden}
.seg button{background:var(--panel2);border:0;padding:8px 10px;display:grid;place-items:center;color:var(--muted)}
.seg button.on{background:var(--accent-soft);color:var(--accent)}
.rot{transform:rotate(180deg)}

.content{flex:1;overflow:auto;padding:16px;position:relative}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(172px,1fr));gap:12px}
.list{display:flex;flex-direction:column;gap:4px}
.item{position:relative;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;cursor:pointer;user-select:none;transition:border-color .12s,box-shadow .12s}
.item:hover{border-color:var(--accent)}
.item.sel{border-color:var(--accent);box-shadow:0 0 0 2px var(--accent-soft);background:var(--accent-soft)}
.thumb{aspect-ratio:4/3;background:var(--panel2);display:grid;place-items:center;position:relative;overflow:hidden;color:var(--muted)}
.thumb.dir{color:var(--accent)}
.thumb img,.thumb video{width:100%;height:100%;object-fit:cover;display:block}
.thumb video{position:absolute;inset:0;opacity:0;transition:opacity .2s;pointer-events:none}
.thumb video.ready{opacity:1}
.thumb .ext{position:absolute;bottom:8px;left:0;right:0;text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.08em}
.badge{position:absolute;right:8px;bottom:8px;background:rgba(0,0,0,.62);color:#fff;border-radius:999px;padding:3px 8px;display:flex;align-items:center}
.badge .ic{width:12px;height:12px;fill:#fff}
.meta{padding:8px 10px;min-width:0}
.name{font-weight:550;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sub{color:var(--muted);font-size:12px}
.col{display:none}
.check{position:absolute;top:8px;left:8px;width:22px;height:22px;border-radius:7px;border:1.5px solid rgba(255,255,255,.75);background:rgba(0,0,0,.4);display:grid;place-items:center;opacity:0;transition:opacity .12s;z-index:2}
.check .ic{width:14px;height:14px;stroke:#fff;stroke-width:3;opacity:0}
.item:hover .check,.sel-mode .check,.item.sel .check{opacity:1}
.item.sel .check{background:var(--accent);border-color:var(--accent)}
.item.sel .check .ic{opacity:1}

.list .item{display:grid;grid-template-columns:24px 44px minmax(0,1fr) 100px 170px;align-items:center;gap:12px;padding:6px 12px;border-radius:10px}
.list .check{position:static;opacity:1;border-color:var(--line);background:transparent}
.list .thumb{width:44px;height:44px;aspect-ratio:auto;border-radius:8px}
.list .ic.big{width:24px;height:24px;stroke-width:1.8}
.list .thumb .ext,.list .badge{display:none}
.list .meta{padding:0}
.list .sub{display:none}
.list .col{display:block;color:var(--muted);font-size:13px;white-space:nowrap}

.empty{display:grid;place-items:center;text-align:center;color:var(--muted);padding:70px 12px;gap:8px}
.empty .ic{width:54px;height:54px;stroke-width:1.2;opacity:.7}
footer.status{padding:7px 16px;border-top:1px solid var(--line);background:var(--panel);color:var(--muted);font-size:12px;display:flex;justify-content:space-between;gap:10px}

.drop{position:fixed;inset:0;background:rgba(79,140,255,.14);border:3px dashed var(--accent);display:none;place-items:center;z-index:50;pointer-events:none;text-align:center;font-size:20px;font-weight:600;padding:20px;backdrop-filter:blur(2px)}
.drop.on{display:grid}
.drop small{display:block;color:var(--muted);font-size:13px;font-weight:400;margin-top:6px}

.lb{position:fixed;inset:0;background:rgba(5,6,9,.95);z-index:60;display:none;flex-direction:column;color:#e8eaf0}
.lb.on{display:flex}
.lb-top{display:flex;align-items:center;gap:6px;padding:10px 14px}
.lb-title{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600}
.lb-count{color:#9aa2b3;font-size:12px;margin-right:6px}
.lb .btn{background:rgba(255,255,255,.08);border-color:transparent;color:#fff}
.lb .btn:hover{background:rgba(255,255,255,.16)}
.lb .btn.danger{background:rgba(255,93,108,.9)}
.lb-stage{flex:1;min-height:0;display:grid;place-items:center;position:relative;padding:0 64px 16px}
.lb-stage img,.lb-stage video{max-width:100%;max-height:100%;border-radius:6px;box-shadow:0 20px 60px rgba(0,0,0,.6);background:#000}
.lb-stage audio{width:min(520px,92%)}
.lb-stage iframe{width:100%;height:100%;border:0;background:#fff;border-radius:6px}
.lb-nav{position:absolute;top:50%;transform:translateY(-50%);width:46px;height:46px;border-radius:50%;background:rgba(255,255,255,.1);border:0;color:#fff;display:grid;place-items:center}
.lb-nav:hover{background:rgba(255,255,255,.2)}
.lb-prev{left:8px}.lb-next{right:8px}

.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;place-items:center;z-index:70;padding:16px}
.modal-bg.on{display:grid}
.modal{background:var(--panel);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);width:min(430px,100%);padding:20px}
.modal h3{margin:0 0 8px;font-size:16px}
.modal p{margin:0 0 14px;color:var(--muted);white-space:pre-line;line-height:1.5}
.modal input,.modal textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:inherit}
.modal textarea{font:12px/1.5 ui-monospace,Consolas,monospace;resize:none;word-break:break-all}
.modal input:focus,.modal textarea:focus{outline:2px solid var(--accent)}
.modal input[type=range]{padding:0;border:0;background:none;accent-color:var(--accent)}
.modal select{width:100%}
.modal p.hint{font-size:12px;margin:0}
.fld{margin:0 0 14px}
.fld label{display:flex;justify-content:space-between;color:var(--muted);font-size:13px;margin-bottom:6px}
.fld label b{color:var(--text)}
.thumb img{pointer-events:none;-webkit-user-drag:none;user-select:none}
.item.dragging{opacity:.45}
#selbox{padding-left:10px;margin-left:2px;border-left:1px solid var(--line)}
@media (hover:hover){.sel-mode .item:not(:hover):not(.sel) .check{opacity:0}}
.item.drop-target,.crumbs button.drop-target{outline:2px solid var(--accent);outline-offset:-2px;background:var(--accent-soft);color:var(--text)}
.modal .row{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}

.uploads{position:fixed;right:16px;bottom:16px;width:min(380px,calc(100vw - 32px));background:var(--panel);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);z-index:40;display:none;overflow:hidden}
.uploads.on{display:block}
.up-head{display:flex;align-items:center;justify-content:space-between;padding:8px 8px 8px 14px;border-bottom:1px solid var(--line);font-weight:600}
.up-list{max-height:280px;overflow:auto}
.up-row{padding:9px 14px;border-bottom:1px solid var(--line);font-size:13px}
.up-row:last-child{border-bottom:0}
.up-row .t{display:flex;justify-content:space-between;gap:10px}
.up-row .n{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.up-row .s{color:var(--muted);white-space:nowrap;font-size:12px}
.pbar{height:5px;border-radius:5px;background:var(--panel2);overflow:hidden;margin-top:6px}
.pbar i{display:block;height:100%;width:0;background:var(--accent);transition:width .15s}
.up-row.done .pbar i{background:var(--ok)}
.up-row.err .pbar i{background:var(--danger)}
.up-row.err .s{color:var(--danger)}

.toasts{position:fixed;left:50%;transform:translateX(-50%);bottom:20px;display:flex;flex-direction:column;gap:8px;z-index:80;align-items:center;pointer-events:none;width:max-content;max-width:92vw}
.toast{background:var(--panel);border:1px solid var(--line);padding:10px 16px;border-radius:10px;box-shadow:var(--shadow);transition:opacity .3s}
.toast.err{border-color:var(--danger)}

@media (max-width:720px){
  header.top,.tools,.content,footer.status{padding-left:12px;padding-right:12px}
  .grid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px}
  .lbl{display:none}
  .list .item{grid-template-columns:24px 40px minmax(0,1fr);}
  .list .col{display:none}
  .search{flex:1 1 100%;order:9}.search input{width:100%}
  .lb-stage{padding:0 8px 12px}
  .lb-nav{width:38px;height:38px;background:rgba(0,0,0,.45)}
  .st-r{display:none}
}
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
<symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></symbol>
<symbol id="i-folder-plus" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 10.5v5M9.5 13h5"/></symbol>
<symbol id="i-file" viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></symbol>
<symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-8 8"/></symbol>
<symbol id="i-video" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/></symbol>
<symbol id="i-audio" viewBox="0 0 24 24"><path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="16.5" cy="16" r="2.5"/></symbol>
<symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></symbol>
<symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v12"/><path d="m7 11 5 5 5-5"/><path d="M4 20h16"/></symbol>
<symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="m6 7 1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12"/><path d="M9 7V4h6v3"/></symbol>
<symbol id="i-edit" viewBox="0 0 24 24"><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="m20 20-4-4"/></symbol>
<symbol id="i-grid" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/></symbol>
<symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></symbol>
<symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4"/><path d="m16 8 4 4-4 4"/><path d="M20 12H9"/></symbol>
<symbol id="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
<symbol id="i-prev" viewBox="0 0 24 24"><path d="m15 5-7 7 7 7"/></symbol>
<symbol id="i-next" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></symbol>
<symbol id="i-sort" viewBox="0 0 24 24"><path d="M12 5v14"/><path d="m6 13 6 6 6-6"/></symbol>
<symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" fill="currentColor" stroke="none"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></symbol>
<symbol id="i-home" viewBox="0 0 24 24"><path d="M4 11 12 4l8 7"/><path d="M6 10v10h12V10"/></symbol>
<symbol id="i-link" viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></symbol>
<symbol id="i-compress" viewBox="0 0 24 24"><path d="M4 14h6v6"/><path d="M20 10h-6V4"/><path d="m14 10 7-7"/><path d="m3 21 7-7"/></symbol>
</defs></svg>

<div class="app">
  <header class="top">
    <div class="brand"><svg class="ic"><use href="#i-folder"/></svg><span><?= h($CONFIG['title']) ?></span></div>
    <nav class="crumbs" id="crumbs" aria-label="Path"></nav>
    <button class="btn ghost iconly" id="b-logout" title="Sign out"><svg class="ic"><use href="#i-logout"/></svg></button>
  </header>

  <div class="tools">
    <div class="grp" id="actions">
      <button class="btn primary" id="b-up"><svg class="ic"><use href="#i-upload"/></svg><span class="lbl">Upload</span></button>
      <button class="btn" id="b-updir" title="Upload a folder"><svg class="ic"><use href="#i-folder"/></svg><span class="lbl">Folder</span></button>
      <button class="btn" id="b-mk"><svg class="ic"><use href="#i-folder-plus"/></svg><span class="lbl">New folder</span></button>
    </div>
    <div class="grp" id="selbox" hidden>
      <span id="selcount"></span>
      <button class="btn" id="b-link" hidden title="Copy public link"><svg class="ic"><use href="#i-link"/></svg><span class="lbl">Copy link</span></button>
      <button class="btn" id="b-zip" hidden title="Compress to a new image"><svg class="ic"><use href="#i-compress"/></svg><span class="lbl">Compress</span></button>
      <button class="btn" id="b-dl" hidden><svg class="ic"><use href="#i-download"/></svg><span class="lbl">Download</span></button>
      <button class="btn" id="b-ren" hidden><svg class="ic"><use href="#i-edit"/></svg><span class="lbl">Rename</span></button>
      <button class="btn danger" id="b-del"><svg class="ic"><use href="#i-trash"/></svg><span class="lbl">Delete</span></button>
      <button class="btn ghost iconly" id="b-clear" title="Clear selection"><svg class="ic"><use href="#i-close"/></svg></button>
    </div>
    <div class="grow"></div>
    <div class="search"><svg class="ic"><use href="#i-search"/></svg><input id="q" type="search" placeholder="Filter…  ( / )" autocomplete="off"></div>
    <select id="sort" aria-label="Sort by">
      <option value="name">Name</option><option value="date">Date</option><option value="size">Size</option><option value="type">Type</option>
    </select>
    <button class="btn iconly" id="b-dir" title="Reverse sort order"><svg class="ic" id="i-dir"><use href="#i-sort"/></svg></button>
    <div class="seg">
      <button id="v-grid" title="Grid view"><svg class="ic"><use href="#i-grid"/></svg></button>
      <button id="v-list" title="List view"><svg class="ic"><use href="#i-list"/></svg></button>
    </div>
  </div>

  <main class="content" id="content"></main>
  <footer class="status"><span id="st-l"></span><span class="st-r">Drop files or folders anywhere to upload them</span></footer>
</div>

<input type="file" id="in-files" multiple hidden>
<input type="file" id="in-dir" webkitdirectory multiple hidden>

<div class="drop" id="drop"><div>Drop to upload<small id="drop-to"></small></div></div>

<div class="lb" id="lb">
  <div class="lb-top">
    <div class="lb-title" id="lb-title"></div>
    <span class="lb-count" id="lb-count"></span>
    <button class="btn" id="lb-zip" title="Compress to a new image"><svg class="ic"><use href="#i-compress"/></svg></button>
    <button class="btn" id="lb-link" title="Copy public link"><svg class="ic"><use href="#i-link"/></svg></button>
    <a class="btn" id="lb-dl" title="Download" download><svg class="ic"><use href="#i-download"/></svg></a>
    <button class="btn danger" id="lb-del" title="Delete"><svg class="ic"><use href="#i-trash"/></svg></button>
    <button class="btn" id="lb-close" title="Close (Esc)"><svg class="ic"><use href="#i-close"/></svg></button>
  </div>
  <div class="lb-stage" id="lb-stage">
    <button class="lb-nav lb-prev" id="lb-prev" aria-label="Previous"><svg class="ic"><use href="#i-prev"/></svg></button>
    <div id="lb-media" style="display:contents"></div>
    <button class="lb-nav lb-next" id="lb-next" aria-label="Next"><svg class="ic"><use href="#i-next"/></svg></button>
  </div>
</div>

<div class="modal-bg" id="modal"></div>
<div class="uploads" id="uploads">
  <div class="up-head"><span id="up-title">Uploads</span><button class="btn ghost iconly" id="up-close" title="Close"><svg class="ic"><use href="#i-close"/></svg></button></div>
  <div class="up-list" id="up-list"></div>
</div>
<div class="toasts" id="toasts"></div>

<script>
const FM = <?= $boot ?>;
(() => {
'use strict';

const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const ic = (n, cls = '') => `<svg class="ic ${cls}" aria-hidden="true"><use href="#i-${n}"/></svg>`;
const store = {
  get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
};
const join = (a, b) => a ? a + '/' + b : b;
const hashOf = p => '#/' + p.split('/').filter(Boolean).map(encodeURIComponent).join('/');
const pathFromHash = () => {
  try { return location.hash.replace(/^#\/?/, '').split('/').filter(Boolean).map(decodeURIComponent).join('/'); }
  catch (e) { return ''; }
};
const fmtSize = b => {
  if (b == null) return '';
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0, n = b;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return (i ? n.toFixed(n < 10 ? 1 : 0) : String(n)) + ' ' + u[i];
};
const fmtDate = t => t ? new Date(t * 1000).toLocaleString('en-US', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '';
const U = {
  file:  (p, dl) => `?a=file&p=${encodeURIComponent(p)}${dl ? '&dl=1' : ''}`,
  thumb: (p, v)  => `?a=thumb&p=${encodeURIComponent(p)}&w=400&v=${v}`
};

const S = {
  path: '', items: [], sel: new Set(), last: null, q: '', loading: true,
  view: store.get('fm_view', 'grid'),
  sortKey: store.get('fm_sk', 'name'),
  sortDir: parseInt(store.get('fm_sd', '1'), 10) || 1,
  info: { maxChunk: 1048576, canWrite: true }
};
const content = $('#content');
const find = n => S.items.find(i => i.name === n);

async function api(action, { method = 'GET', params = {}, body } = {}) {
  const qs = new URLSearchParams(Object.assign({ a: action }, params));
  const res = await fetch('?' + qs, {
    method, credentials: 'same-origin',
    headers: Object.assign({ 'X-CSRF-Token': FM.csrf }, body ? { 'Content-Type': 'application/json' } : {}),
    body: body ? JSON.stringify(body) : undefined
  });
  if (res.status === 401) { location.reload(); throw new Error('Session expired'); }
  let data = {};
  try { data = await res.json(); } catch (e) { data = { error: 'Invalid server response (' + res.status + ')' }; }
  if (!res.ok) throw new Error(data.error || ('Error ' + res.status));
  return data;
}

function toast(msg, type = '') {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  $('#toasts').appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, type === 'err' ? 5500 : 2800);
}
function ask({ title, message = '', input = null, ok = 'OK', danger = false }) {
  return new Promise(resolve => {
    const bg = $('#modal');
    bg.innerHTML = `<div class="modal" role="dialog" aria-modal="true"><h3>${esc(title)}</h3>`
      + (message ? `<p>${esc(message)}</p>` : '')
      + (input !== null ? `<input id="m-in" type="text" value="${esc(input)}" autocomplete="off" spellcheck="false">` : '')
      + `<div class="row"><button class="btn" data-r="0">Cancel</button><button class="btn ${danger ? 'danger' : 'primary'}" data-r="1">${esc(ok)}</button></div></div>`;
    bg.classList.add('on');
    const inp = $('#m-in', bg);
    const val = () => input !== null ? inp.value.trim() : true;
    const done = v => {
      bg.classList.remove('on'); bg.innerHTML = ''; bg.onclick = null;
      document.removeEventListener('keydown', key, true);
      resolve(v);
    };
    const key = e => {
      if (e.key === 'Escape') { e.stopPropagation(); done(null); }
      else if (e.key === 'Enter' && !(document.activeElement && document.activeElement.tagName === 'BUTTON')) {
        e.preventDefault(); e.stopPropagation();
        const v = val(); if (v !== '') done(v);
      }
    };
    bg.onclick = e => {
      const b = e.target.closest('[data-r]');
      if (b) { if (b.dataset.r === '1') { const v = val(); if (v !== '') done(v); } else done(null); }
      else if (e.target === bg) done(null);
    };
    document.addEventListener('keydown', key, true);
    if (inp) {
      inp.focus();
      const dot = input.lastIndexOf('.');
      inp.setSelectionRange(0, dot > 0 ? dot : input.length);
    } else { $('[data-r="1"]', bg).focus(); }
  });
}

async function load(path) {
  S.loading = true; render();
  try {
    const d = await api('list', { params: { p: path } });
    S.path = d.path; S.items = d.items; S.info = d;
    S.sel.clear(); S.last = null;
  } catch (e) {
    toast(e.message, 'err');
    if (path) { location.hash = '#/'; return; }
    S.items = [];
  }
  S.loading = false; render();
}
const go = p => { location.hash = hashOf(p); };

function visible() {
  const q = S.q.trim().toLowerCase();
  const d = S.sortDir;
  const cmp = {
    name: (x, y) => x.name.localeCompare(y.name, 'en', { numeric: true, sensitivity: 'base' }),
    date: (x, y) => x.mtime - y.mtime,
    size: (x, y) => (x.size || 0) - (y.size || 0),
    type: (x, y) => x.ext.localeCompare(y.ext) || x.name.localeCompare(y.name, 'en', { numeric: true })
  }[S.sortKey];
  return S.items.filter(i => !q || i.name.toLowerCase().includes(q))
    .sort((x, y) => ((y.dir ? 1 : 0) - (x.dir ? 1 : 0)) || cmp(x, y) * d);
}
function thumbHtml(i) {
  const p = join(S.path, i.name);
  if (i.dir) return `<div class="thumb dir">${ic('folder', 'big')}</div>`;
  if (i.kind === 'image') return `<div class="thumb">${ic('image', 'big')}<img loading="lazy" decoding="async" alt="" src="${U.thumb(p, i.mtime)}" style="position:absolute;inset:0"></div>`;
  if (i.kind === 'video') return `<div class="thumb">${ic('video', 'big')}<video muted playsinline preload="none" data-src="${U.file(p)}#t=0.5"></video><span class="badge">${ic('play')}</span></div>`;
  const k = i.kind === 'audio' ? 'audio' : 'file';
  return `<div class="thumb">${ic(k, 'big')}<span class="ext">${esc(i.ext || 'file')}</span></div>`;
}
function cardHtml(i) {
  return `<div class="item${S.sel.has(i.name) ? ' sel' : ''}" data-name="${esc(i.name)}" draggable="true">`
    + `<div class="check">${ic('check')}</div>${thumbHtml(i)}`
    + `<div class="meta"><div class="name" title="${esc(i.name)}">${esc(i.name)}</div><div class="sub">${i.dir ? 'Folder' : fmtSize(i.size)}</div></div>`
    + `<div class="col">${i.dir ? '—' : fmtSize(i.size)}</div><div class="col">${fmtDate(i.mtime)}</div></div>`;
}
function renderCrumbs() {
  const parts = S.path.split('/').filter(Boolean);
  let acc = '';
  let h = `<button data-p="">${ic('home')} Root</button>`;
  parts.forEach(p => { acc = join(acc, p); h += `<span class="sep">/</span><button data-p="${esc(acc)}">${esc(p)}</button>`; });
  const c = $('#crumbs');
  c.innerHTML = h;
  c.scrollLeft = c.scrollWidth;
}
function renderTools() {
  const n = S.sel.size;
  $('#selbox').hidden = n === 0;
  $('#selcount').textContent = n + (n === 1 ? ' selected item' : ' selected items');
  const one = n === 1 ? find([...S.sel][0]) : null;
  const hasFile = [...S.sel].some(nm => { const it = find(nm); return it && !it.dir; });
  $('#b-link').hidden = !(hasFile && FM.share);
  $('#b-zip').hidden = !(S.info.gd && S.info.canWrite && [...S.sel].some(nm => canCompress(find(nm))));
  $('#b-dl').hidden = !(one && !one.dir);
  $('#b-ren').hidden = !one;
  $('#v-grid').classList.toggle('on', S.view === 'grid');
  $('#v-list').classList.toggle('on', S.view === 'list');
  $('#i-dir').classList.toggle('rot', S.sortDir === -1);
  $('#b-up').disabled = $('#b-updir').disabled = $('#b-mk').disabled = !S.info.canWrite;
}
let io = null;
function lazyMedia() {
  if (io) io.disconnect();
  const vids = content.querySelectorAll('video[data-src]');
  const activate = v => { v.src = v.dataset.src; v.preload = 'metadata'; v.removeAttribute('data-src'); };
  if (!('IntersectionObserver' in window)) { vids.forEach(activate); return; }
  io = new IntersectionObserver(es => es.forEach(en => {
    if (en.isIntersecting) { activate(en.target); io.unobserve(en.target); }
  }), { root: content, rootMargin: '250px' });
  vids.forEach(v => io.observe(v));
}
function render() {
  renderCrumbs();
  renderTools();
  content.classList.toggle('sel-mode', S.sel.size > 0);
  const list = visible();
  if (S.loading && !S.items.length) { content.innerHTML = '<div class="empty">Loading…</div>'; return; }
  if (!list.length) {
    content.innerHTML = `<div class="empty">${ic('folder')}<div>${S.q
      ? 'No results for "' + esc(S.q) + '"'
      : 'This folder is empty.<br>Drop files here or click "Upload".'}</div></div>`;
  } else {
    content.innerHTML = `<div id="items" class="${S.view === 'grid' ? 'grid' : 'list'}">${list.map(cardHtml).join('')}</div>`;
    lazyMedia();
  }
  const dirs = S.items.filter(i => i.dir).length, files = S.items.length - dirs;
  const total = S.items.reduce((a, i) => a + (i.size || 0), 0);
  $('#st-l').textContent = (S.q ? list.length + ' of ' + S.items.length + ' results — ' : '')
    + dirs + ' folder' + (dirs === 1 ? '' : 's') + ' · ' + files + ' file' + (files === 1 ? '' : 's') + ' · ' + fmtSize(total);
}
function syncSel() {
  content.classList.toggle('sel-mode', S.sel.size > 0);
  content.querySelectorAll('.item').forEach(n => n.classList.toggle('sel', S.sel.has(n.dataset.name)));
  renderTools();
}
const clearSel = () => { S.sel.clear(); S.last = null; syncSel(); };
function toggle(name) {
  if (S.sel.has(name)) S.sel.delete(name); else S.sel.add(name);
  S.last = name; syncSel();
}
// Shift-click selects a range from the last single-click or Ctrl/Cmd-click anchor; the anchor stays fixed.
function selectRange(a, b, additive) {
  const names = visible().map(i => i.name);
  let i = names.indexOf(a), j = names.indexOf(b);
  if (i < 0 || j < 0) { S.sel.clear(); S.sel.add(b); S.last = b; syncSel(); return; }
  if (i > j) { const t = i; i = j; j = t; }
  if (!additive) S.sel.clear();
  for (let k = i; k <= j; k++) S.sel.add(names[k]);
  syncSel();
}

content.addEventListener('click', e => {
  const it = e.target.closest('.item');
  if (!it) { if (S.sel.size && (e.target === content || e.target.id === 'items')) clearSel(); return; }
  const name = it.dataset.name, item = find(name);
  if (!item) return;
  const additive = e.ctrlKey || e.metaKey;
  // Shift-click selects a range from the anchor; Ctrl/Cmd+Shift-click adds the range.
  if (e.shiftKey) { selectRange(S.last, name, additive); return; }
  // Ctrl/Cmd-click or the checkbox toggles an item.
  if (additive || e.target.closest('.check')) { toggle(name); return; }
  // On touch devices, a tap opens an item unless selection is active.
  if (touchLike(e)) { if (S.sel.size) toggle(name); else openItem(item); return; }
  // On desktop, a click selects one item and a double-click opens it.
  S.sel.clear(); S.sel.add(name); S.last = name; syncSel();
});
const touchLike = e => e.pointerType ? e.pointerType === 'touch' : window.matchMedia('(hover: none)').matches;
// Prevent Shift-click from extending the browser's text selection.
content.addEventListener('mousedown', e => { if (e.shiftKey && e.target.closest('.item')) e.preventDefault(); });
// Double-click opens a folder or the viewer.
content.addEventListener('dblclick', e => {
  if (e.shiftKey || e.ctrlKey || e.metaKey) return;
  const it = e.target.closest('.item');
  if (!it || e.target.closest('.check')) return;
  const item = find(it.dataset.name);
  if (item) openItem(item);
});
content.addEventListener('error', e => {
  const t = e.target;
  if (t.tagName === 'IMG') t.remove();
  else if (t.tagName === 'VIDEO') t.remove();
}, true);
content.addEventListener('loadeddata', e => { if (e.target.tagName === 'VIDEO') e.target.classList.add('ready'); }, true);
$('#crumbs').addEventListener('click', e => { const b = e.target.closest('button[data-p]'); if (b) { if (b.dataset.p === S.path) load(S.path); else go(b.dataset.p); } });

function openItem(item) {
  const p = join(S.path, item.name);
  if (item.dir) { go(p); return; }
  if (VIEWABLE.includes(item.kind)) { openLb(item.name); return; }
  downloadFile(p);
}
function downloadFile(p) {
  const a = document.createElement('a');
  a.href = U.file(p, true); a.download = '';
  document.body.appendChild(a); a.click(); a.remove();
}

async function newFolder() {
  const name = await ask({ title: 'New folder', input: 'New folder', ok: 'Create' });
  if (!name) return;
  try { await api('mkdir', { method: 'POST', body: { p: S.path, name } }); toast('Folder created'); }
  catch (e) { toast(e.message, 'err'); }
  load(S.path);
}
async function renameSel() {
  const item = find([...S.sel][0]);
  if (!item) return;
  const name = await ask({ title: 'Rename', input: item.name, ok: 'Rename' });
  if (!name || name === item.name) return;
  try { await api('rename', { method: 'POST', body: { p: join(S.path, item.name), name } }); toast('Renamed'); }
  catch (e) { toast(e.message, 'err'); }
  load(S.path);
}
async function deleteNames(names) {
  const items = names.map(find).filter(Boolean);
  if (!items.length) return false;
  const dirs = items.filter(i => i.dir).length;
  const msg = (items.length === 1 ? 'Delete "' + items[0].name + '"?' : 'Delete ' + items.length + ' items?')
    + (dirs ? '\nFolders will be deleted with all their contents.' : '')
    + '\nThis action cannot be undone.';
  if (!await ask({ title: 'Confirm deletion', message: msg, ok: 'Delete', danger: true })) return false;
  let ok = false;
  try {
    const r = await api('delete', { method: 'POST', body: { paths: names.map(n => join(S.path, n)) } });
    ok = r.deleted > 0;
    if (r.deleted) toast(r.deleted + ' item' + (r.deleted === 1 ? '' : 's') + ' deleted');
    (r.errors || []).slice(0, 3).forEach(m => toast(m, 'err'));
  } catch (e) { toast(e.message, 'err'); }
  await load(S.path);
  return ok;
}

const CMP_EXT = ['jpg', 'jpeg', 'png', 'webp'];
const canCompress = i => !!i && !i.dir && CMP_EXT.includes(i.ext);
function compressDialog(names) {
  const items = names.map(find).filter(canCompress);
  if (!items.length) { toast('Select at least one JPG, PNG, or WebP image', 'err'); return; }
  let pref = {};
  try { pref = JSON.parse(store.get('fm_cmp', '{}')) || {}; } catch (e) {}
  const q0 = pref.quality || 75, w0 = pref.maxw || 0;
  let f0 = pref.format || 'jpeg';
  if (f0 === 'webp' && !S.info.webp) f0 = 'jpeg';
  const widths = [[0, 'Original size'], [3840, '3840 px (4K)'], [2560, '2560 px'], [1920, '1920 px (Full HD)'], [1600, '1600 px'], [1280, '1280 px (HD)'], [1080, '1080 px'], [720, '720 px']];
  const fmts = [['jpeg', 'JPEG (widely compatible)']].concat(S.info.webp ? [['webp', 'WebP (smaller)']] : [], [['same', 'Keep original format']]);
  const bg = $('#modal');
  bg.innerHTML = `<div class="modal" role="dialog" aria-modal="true"><h3>Compress ${items.length > 1 ? items.length + ' images' : '"' + esc(items[0].name) + '"'}</h3>`
    + `<p>A new image is created next to the original (with a "-min" suffix). The original is unchanged.</p>`
    + `<div class="fld"><label>Quality <b><span id="c-qv">${q0}</span> %</b></label><input id="c-q" type="range" min="30" max="95" step="1" value="${q0}"></div>`
    + `<div class="fld"><label>Maximum width</label><select id="c-w">${widths.map(w => `<option value="${w[0]}"${w[0] === w0 ? ' selected' : ''}>${w[1]}</option>`).join('')}</select></div>`
    + `<div class="fld"><label>Format</label><select id="c-f">${fmts.map(f => `<option value="${f[0]}"${f[0] === f0 ? ' selected' : ''}>${f[1]}</option>`).join('')}</select></div>`
    + `<p class="hint" id="c-hint"></p>`
    + `<div class="row"><button class="btn" data-r="0">Cancel</button><button class="btn primary" data-r="1">Compress</button></div></div>`;
  bg.classList.add('on');
  const hint = () => {
    const f = $('#c-f').value;
    $('#c-hint').textContent = f === 'jpeg' ? 'JPEG: widely compatible. Images with transparency are automatically saved as WebP (or PNG).'
      : f === 'webp' ? 'WebP: smaller than JPEG and supports transparency.'
      : 'Keep original format: PNG is compressed losslessly (usually with limited savings). JPEG or WebP compress more effectively.';
  };
  hint();
  $('#c-f').onchange = hint;
  $('#c-q').oninput = e => { $('#c-qv').textContent = e.target.value; };

  let busy = false;
  const close = () => { bg.classList.remove('on'); bg.innerHTML = ''; bg.onclick = null; document.removeEventListener('keydown', key, true); };
  const run = async () => {
    if (busy) return;
    busy = true;
    const opts = { quality: +$('#c-q').value, maxw: +$('#c-w').value, format: $('#c-f').value };
    store.set('fm_cmp', JSON.stringify(opts));
    const btn = $('[data-r="1"]', bg), cancel = $('[data-r="0"]', bg);
    btn.disabled = cancel.disabled = true;
    let before = 0, after = 0, ok = 0;
    const made = [], errs = [];
    for (let k = 0; k < items.length; k++) {
      btn.textContent = items.length > 1 ? `Compressing ${k + 1}/${items.length}…` : 'Compressing…';
      try {
        const r = await api('compress', { method: 'POST', body: Object.assign({ p: join(S.path, items[k].name) }, opts) });
        before += r.before; after += r.after; ok++; made.push(r.name);
      } catch (e) { errs.push(items[k].name + ' : ' + e.message); }
    }
    close();
    if (ok) toast(ok + ' image' + (ok === 1 ? '' : 's') + ' compressed: ' + fmtSize(before) + ' → ' + fmtSize(after) + ' (' + Math.round((1 - after / before) * 100) + '% smaller)');
    errs.slice(0, 3).forEach(m => toast(m, 'err'));
    await load(S.path);
    made.forEach(n => { if (find(n)) S.sel.add(n); });
    syncSel();
  };
  const key = e => {
    if (e.key === 'Escape') { e.stopPropagation(); if (!busy) close(); }
    else if (e.key === 'Enter' && !/^(BUTTON|SELECT)$/.test((document.activeElement || {}).tagName || '')) { e.preventDefault(); e.stopPropagation(); run(); }
  };
  bg.onclick = e => {
    const b = e.target.closest('[data-r]');
    if (b) { if (b.dataset.r === '1') run(); else if (!busy) close(); }
    else if (e.target === bg && !busy) close();
  };
  document.addEventListener('keydown', key, true);
  $('[data-r="1"]', bg).focus();
}

async function copyText(text) {
  try {
    if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); return true; }
  } catch (e) {}
  try {
    const ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.appendChild(ta);
    ta.select(); ta.setSelectionRange(0, text.length);
    const ok = document.execCommand('copy');
    ta.remove();
    return ok;
  } catch (e) { return false; }
}
function showLink(text) {
  const bg = $('#modal');
  bg.innerHTML = `<div class="modal" role="dialog" aria-modal="true"><h3>Public link</h3>`
    + `<p>Anyone with this link can access the file without signing in. Copy it below:</p>`
    + `<textarea id="m-link" readonly rows="${Math.min(6, text.split('\n').length + 1)}">${esc(text)}</textarea>`
    + `<div class="row"><button class="btn" data-r="0">Close</button><button class="btn primary" data-r="1">Copy</button></div></div>`;
  bg.classList.add('on');
  const ta = $('#m-link', bg);
  ta.focus(); ta.select();
  const close = () => { bg.classList.remove('on'); bg.innerHTML = ''; bg.onclick = null; document.removeEventListener('keydown', key, true); };
  const key = e => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
  bg.onclick = async e => {
    const b = e.target.closest('[data-r]');
    if (b && b.dataset.r === '1') {
      ta.focus(); ta.select();
      if (await copyText(text)) { toast('Link copied'); close(); }
      else toast('Select the text and copy it manually', 'err');
    } else if (b || e.target === bg) close();
  };
  document.addEventListener('keydown', key, true);
}
async function copyLink(names) {
  if (!FM.share) { toast('Public links are disabled', 'err'); return; }
  const items = names.map(find).filter(i => i && !i.dir);
  if (!items.length) { toast('Select at least one file', 'err'); return; }
  const base = location.href.split('#')[0];
  try {
    const urls = [];
    for (const i of items) {
      const r = await api('share', { params: { p: join(S.path, i.name) } });
      urls.push(new URL(r.url, base).href);
    }
    const text = urls.join('\n');
    if (await copyText(text)) toast(urls.length > 1 ? urls.length + ' links copied' : 'Link copied');
    else showLink(text);
  } catch (e) { toast(e.message, 'err'); }
}
$('#b-link').onclick = () => copyLink([...S.sel]);
$('#lb-link').onclick = () => { const it = LB.list[LB.i]; if (it) copyLink([it.name]); };
if (!FM.share) $('#lb-link').hidden = true;
$('#b-zip').onclick = () => compressDialog([...S.sel]);
$('#lb-zip').onclick = () => { const it = LB.list[LB.i]; if (it) compressDialog([it.name]); };

$('#b-mk').onclick = newFolder;
$('#b-ren').onclick = renameSel;
$('#b-del').onclick = () => deleteNames([...S.sel]);
$('#b-clear').onclick = clearSel;
$('#b-dl').onclick = () => { const n = [...S.sel][0]; if (n) downloadFile(join(S.path, n)); };
$('#b-logout').onclick = () => api('logout', { method: 'POST' }).catch(() => {}).finally(() => location.reload());
$('#b-up').onclick = () => $('#in-files').click();
$('#b-updir').onclick = () => $('#in-dir').click();
$('#q').addEventListener('input', e => { S.q = e.target.value; render(); });
$('#sort').value = S.sortKey;
$('#sort').onchange = e => { S.sortKey = e.target.value; store.set('fm_sk', S.sortKey); render(); };
$('#b-dir').onclick = () => { S.sortDir *= -1; store.set('fm_sd', String(S.sortDir)); render(); };
$('#v-grid').onclick = () => { S.view = 'grid'; store.set('fm_view', 'grid'); render(); };
$('#v-list').onclick = () => { S.view = 'list'; store.set('fm_view', 'list'); render(); };

const VIEWABLE = ['image', 'video', 'audio', 'pdf', 'text'];
const LB = { list: [], i: 0 };
function clearStage() {
  const st = $('#lb-media');
  st.querySelectorAll('video,audio').forEach(m => { try { m.pause(); m.removeAttribute('src'); m.load(); } catch (e) {} });
  st.innerHTML = '';
}
function openLb(name) {
  LB.list = visible().filter(i => VIEWABLE.includes(i.kind));
  LB.i = LB.list.findIndex(x => x.name === name);
  if (LB.i < 0) return;
  $('#lb').classList.add('on');
  document.body.style.overflow = 'hidden';
  showLb();
}
function showLb() {
  const it = LB.list[LB.i];
  if (!it) { closeLb(); return; }
  const p = join(S.path, it.name);
  clearStage();
  let node;
  if (it.kind === 'image') { node = new Image(); node.alt = it.name; node.draggable = false; node.src = U.file(p); }
  else if (it.kind === 'video') { node = document.createElement('video'); node.controls = true; node.autoplay = true; node.playsInline = true; node.src = U.file(p); }
  else if (it.kind === 'audio') { node = document.createElement('audio'); node.controls = true; node.autoplay = true; node.src = U.file(p); }
  else { node = document.createElement('iframe'); node.src = U.file(p); node.title = it.name; }
  $('#lb-media').appendChild(node);
  $('#lb-title').textContent = it.name;
  $('#lb-count').textContent = (LB.i + 1) + ' / ' + LB.list.length;
  $('#lb-dl').href = U.file(p, true);
  $('#lb-zip').hidden = !(S.info.gd && S.info.canWrite && canCompress(it));
  $('#lb-prev').hidden = $('#lb-next').hidden = LB.list.length < 2;
  [-1, 1].forEach(d => {
    const n = LB.list[LB.i + d];
    if (n && n.kind === 'image') new Image().src = U.file(join(S.path, n.name));
  });
}
function stepLb(d) {
  if (LB.list.length < 2) return;
  LB.i = (LB.i + d + LB.list.length) % LB.list.length;
  showLb();
}
function closeLb() {
  clearStage();
  $('#lb').classList.remove('on');
  document.body.style.overflow = '';
}
$('#lb-close').onclick = closeLb;
$('#lb-prev').onclick = () => stepLb(-1);
$('#lb-next').onclick = () => stepLb(1);
$('#lb-stage').addEventListener('click', e => { if (e.target.id === 'lb-stage') closeLb(); });
$('#lb-del').onclick = async () => {
  const it = LB.list[LB.i];
  if (!it) return;
  const ok = await deleteNames([it.name]);
  if (!ok) return;
  LB.list = visible().filter(i => VIEWABLE.includes(i.kind));
  if (!LB.list.length) closeLb();
  else { LB.i = Math.min(LB.i, LB.list.length - 1); showLb(); }
};
let tx = null;
$('#lb-stage').addEventListener('touchstart', e => { tx = e.touches[0].clientX; }, { passive: true });
$('#lb-stage').addEventListener('touchend', e => {
  if (tx === null) return;
  const dx = e.changedTouches[0].clientX - tx; tx = null;
  if (Math.abs(dx) > 60 && e.target.tagName === 'IMG') stepLb(dx > 0 ? -1 : 1);
});

const Up = { jobs: [], running: 0, max: 2 };
const rid = () => { const a = new Uint8Array(12); crypto.getRandomValues(a); return [...a].map(b => b.toString(16).padStart(2, '0')).join(''); };
function addRow(job) {
  const row = document.createElement('div');
  row.className = 'up-row';
  row.innerHTML = `<div class="t"><span class="n">${esc((job.rel ? job.rel + '/' : '') + job.file.name)}</span><span class="s">Waiting</span></div><div class="pbar"><i></i></div>`;
  $('#up-list').appendChild(row);
  job.row = row;
  $('#up-list').scrollTop = $('#up-list').scrollHeight;
}
function setRow(job, frac, text, cls) {
  if (frac != null) $('i', job.row).style.width = Math.min(100, Math.round(frac * 100)) + '%';
  $('.s', job.row).textContent = text != null ? text : Math.min(100, Math.round(frac * 100)) + ' %';
  if (cls) { job.row.classList.add(cls); if (cls === 'done') $('i', job.row).style.width = '100%'; }
}
function addFiles(list) {
  if (!list.length) return;
  const dir = S.path;
  list.forEach(({ file, rel }) => { const job = { file, rel: rel || '', dir, id: rid(), state: 'wait', row: null }; Up.jobs.push(job); addRow(job); });
  $('#uploads').classList.add('on');
  pump();
}
function postChunk(fd, onp) {
  return new Promise((resolve, reject) => {
    let tries = 0;
    const attempt = () => {
      const x = new XMLHttpRequest();
      x.open('POST', '?a=upload');
      x.setRequestHeader('X-CSRF-Token', FM.csrf);
      x.upload.onprogress = e => { if (e.lengthComputable) onp(e.loaded); };
      x.onload = () => {
        let d = {};
        try { d = JSON.parse(x.responseText); } catch (e) {}
        if (x.status === 401) { location.reload(); return; }
        if (x.status >= 200 && x.status < 300) resolve(d);
        else if (x.status >= 500 && ++tries < 3) setTimeout(attempt, 800 * tries);
        else reject(new Error(d.error || ('Error ' + x.status)));
      };
      x.onerror = () => { if (++tries < 3) setTimeout(attempt, 800 * tries); else reject(new Error('Connection lost')); };
      x.send(fd);
    };
    attempt();
  });
}
async function sendFile(job) {
  const file = job.file, cs = S.info.maxChunk || 1048576;
  let off = 0;
  do {
    const end = Math.min(off + cs, file.size);
    const fd = new FormData();
    fd.append('p', job.dir); fd.append('rel', job.rel); fd.append('name', file.name);
    fd.append('id', job.id); fd.append('offset', String(off)); fd.append('total', String(file.size));
    fd.append('chunk', file.slice(off, end), 'chunk');
    const base = off;
    await postChunk(fd, loaded => setRow(job, (base + loaded) / Math.max(1, file.size)));
    off = end;
  } while (off < file.size);
}
function pump() {
  while (Up.running < Up.max) {
    const job = Up.jobs.find(j => j.state === 'wait');
    if (!job) break;
    job.state = 'run'; Up.running++;
    setRow(job, 0);
    sendFile(job)
      .then(() => { job.state = 'done'; setRow(job, 1, 'Done', 'done'); })
      .catch(err => { job.state = 'err'; setRow(job, null, err.message, 'err'); })
      .finally(() => {
        Up.running--;
        if (Up.jobs.some(j => j.state === 'wait' || j.state === 'run')) { pump(); return; }
        const ok = Up.jobs.filter(j => j.state === 'done').length, ko = Up.jobs.filter(j => j.state === 'err').length;
        Up.jobs = [];
        $('#up-title').textContent = 'Uploads complete';
        toast(ok + ' file' + (ok === 1 ? '' : 's') + ' uploaded' + (ko ? ' — ' + ko + ' failed' : ''), ko ? 'err' : '');
        load(S.path);
      });
  }
  if (Up.jobs.some(j => j.state === 'wait' || j.state === 'run')) $('#up-title').textContent = 'Uploading…';
}
$('#up-close').onclick = () => { $('#uploads').classList.remove('on'); if (!Up.running) $('#up-list').innerHTML = ''; };
$('#in-files').onchange = e => { addFiles([...e.target.files].map(f => ({ file: f, rel: '' }))); e.target.value = ''; };
$('#in-dir').onchange = e => {
  addFiles([...e.target.files].map(f => ({ file: f, rel: (f.webkitRelativePath || '').split('/').slice(0, -1).join('/') })));
  e.target.value = '';
};

async function collectDrop(dt) {
  const entries = [...dt.items].map(i => i.webkitGetAsEntry ? i.webkitGetAsEntry() : null).filter(Boolean);
  if (!entries.length) return [...dt.files].map(f => ({ file: f, rel: '' }));
  const res = [];
  const walk = async (entry, base) => {
    if (entry.isFile) {
      const f = await new Promise((ok, ko) => entry.file(ok, ko));
      res.push({ file: f, rel: base });
    } else if (entry.isDirectory) {
      const childBase = base ? base + '/' + entry.name : entry.name;
      const reader = entry.createReader();
      let all = [];
      for (;;) {
        const batch = await new Promise((ok, ko) => reader.readEntries(ok, ko));
        if (!batch.length) break;
        all = all.concat(batch);
      }
      for (const c of all) await walk(c, childBase);
    }
  };
  for (const en of entries) await walk(en, '');
  return res;
}
let depth = 0;
// Only files dragged in from outside the page trigger uploads; dragging a page image or thumbnail does not.
const hasFiles = e => !S.drag && !dragForeign && e.dataTransfer && [...(e.dataTransfer.types || [])].includes('Files');
window.addEventListener('dragenter', e => {
  if (!hasFiles(e)) return;
  e.preventDefault(); depth++;
  $('#drop-to').textContent = 'to /' + S.path;
  $('#drop').classList.add('on');
});
window.addEventListener('dragover', e => { if (!hasFiles(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
window.addEventListener('dragleave', e => {
  if (!hasFiles(e)) return;
  depth = Math.max(0, depth - 1);
  if (!depth) $('#drop').classList.remove('on');
});
window.addEventListener('drop', async e => {
  if (!hasFiles(e)) return;
  e.preventDefault(); depth = 0;
  $('#drop').classList.remove('on');
  if (!S.info.canWrite) { toast('This folder is read-only', 'err'); return; }
  try { addFiles(await collectDrop(e.dataTransfer)); } catch (err) { toast('Unable to read dropped items', 'err'); }
});

const DND = 'application/x-fm-items';
let dragForeign = false;
const clearDnd = () => {
  document.querySelectorAll('.drop-target').forEach(n => n.classList.remove('drop-target'));
  document.querySelectorAll('.item.dragging').forEach(n => n.classList.remove('dragging'));
};
async function moveItems(paths, to) {
  try {
    const r = await api('move', { method: 'POST', body: { paths, to } });
    if (r.moved) toast(r.moved + ' item' + (r.moved === 1 ? '' : 's') + ' moved to "' + (to || 'Root') + '"');
    (r.errors || []).slice(0, 3).forEach(m => toast(m, 'err'));
  } catch (e) { toast(e.message, 'err'); }
  await load(S.path);
}
function dropTarget(e) {
  if (!e.target.closest) return null;
  const c = e.target.closest('#crumbs button[data-p]');
  if (c) return { el: c, to: c.dataset.p };
  const it = e.target.closest('#content .item');
  if (it) { const item = find(it.dataset.name); if (item && item.dir) return { el: it, to: join(S.path, item.name) }; }
  return null;
}
function validTarget(t) {
  if (!S.drag || !t) return false;
  if (t.to === S.drag.from) return false;
  return !S.drag.names.some(n => { const p = join(S.drag.from, n); return t.to === p || t.to.startsWith(p + '/'); });
}
// A drag that did not start on a list item (for example, a viewer image) must not trigger an upload.
document.addEventListener('dragstart', e => { if (!(e.target.closest && e.target.closest('.item'))) dragForeign = true; }, true);
content.addEventListener('dragstart', e => {
  const it = e.target.closest && e.target.closest('.item');
  if (!it) return;
  const name = it.dataset.name;
  const names = S.sel.has(name) ? [...S.sel] : [name];
  S.drag = { from: S.path, names };
  e.dataTransfer.setData(DND, '1');
  e.dataTransfer.effectAllowed = 'move';
  setTimeout(() => {
    const set = new Set(names);
    content.querySelectorAll('.item').forEach(x => x.classList.toggle('dragging', set.has(x.dataset.name)));
  }, 0);
});
document.addEventListener('dragend', () => { S.drag = null; dragForeign = false; clearDnd(); }, true);
document.addEventListener('mousedown', () => { dragForeign = false; }, true);
document.addEventListener('dragover', e => {
  if (!S.drag) return;
  document.querySelectorAll('.drop-target').forEach(n => n.classList.remove('drop-target'));
  const t = dropTarget(e);
  if (validTarget(t)) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; t.el.classList.add('drop-target'); }
});
document.addEventListener('drop', e => {
  if (!S.drag) return;
  const t = dropTarget(e);
  const d = S.drag;
  const ok = validTarget(t);
  S.drag = null; dragForeign = false; clearDnd();
  if (!ok) return;
  e.preventDefault();
  moveItems(d.names.map(n => join(d.from, n)), t.to);
});

document.addEventListener('keydown', e => {
  if ($('#modal').classList.contains('on')) return;
  const tag = document.activeElement ? document.activeElement.tagName : '';
  const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(tag);
  if ($('#lb').classList.contains('on')) {
    if (e.key === 'Escape') closeLb();
    else if (e.key === 'ArrowLeft') { e.preventDefault(); stepLb(-1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); stepLb(1); }
    else if (e.key === 'Delete') $('#lb-del').click();
    return;
  }
  if (typing) { if (e.key === 'Escape') document.activeElement.blur(); return; }
  if (e.key === 'Escape') clearSel();
  else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') { e.preventDefault(); visible().forEach(i => S.sel.add(i.name)); syncSel(); }
  else if (e.key === 'Delete' && S.sel.size) deleteNames([...S.sel]);
  else if (e.key === 'F2' && S.sel.size === 1) { e.preventDefault(); renameSel(); }
  else if (e.key === 'Enter' && S.sel.size === 1) { const it = find([...S.sel][0]); if (it) openItem(it); }
  else if (e.key === '/') { e.preventDefault(); $('#q').focus(); }
});

window.addEventListener('hashchange', () => load(pathFromHash()));
load(pathFromHash());
})();
</script>
</body>
</html>