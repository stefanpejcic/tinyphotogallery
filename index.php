<?php

// ---------------------------------------------------------------
// tinyphotogallery — by Stefan Pejcic
// https://github.com/stefanpejcic/tinyphotogallery/
$config = [
    'indexing' => false,
    'password' => null,
    'site_url' => '',
    'photos_per_page' => 100,
    'debug' => false,
];

error_reporting(E_ALL);
ini_set('display_errors', $config['debug'] ? '1' : '0');
ini_set('log_errors', '1');
ini_set('memory_limit', '256M');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$photosDir = __DIR__ . '/photos';
$photosUrl = 'photos';

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function requireAuth(array $config): void
{
    if ($config['password'] === null) { return; }
    if (!empty($_SESSION['gallery_authed'])) { return; }

    $error = null;
    $minInterval = 2; // seconds between attempts
    $lastAttempt = $_SESSION['gallery_last_attempt'] ?? 0;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_password'])) {
        if ((microtime(true) - $lastAttempt) < $minInterval) {
            $error = 'Too many attempts. Please wait a moment and try again.';
        } elseif (password_verify($_POST['gallery_password'], $config['password'])) {
            session_regenerate_id(true);
            $_SESSION['gallery_authed'] = true;
            unset($_SESSION['gallery_last_attempt']);
            $qs = $_SERVER['QUERY_STRING'] ?? '';
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . $qs : ''));
            exit;
        } else {
            $_SESSION['gallery_last_attempt'] = microtime(true);
            $error = 'Incorrect password.';
        }
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Password required</title>
        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23e5e7eb'%3E%3Cpath d='M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0'/%3E%3Cpath d='M2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4H2zm.5 2a.5.5 0 1 1 0-1 .5.5 0 0 1 0 1z'/%3E%3C/svg%3E">
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-gray-950 text-white flex items-center justify-center p-4">
        <form method="post" class="w-full max-w-sm rounded-xl border border-gray-800 bg-gray-900 p-6">
            <h1 class="text-xl font-semibold mb-1">🔒 Protected Gallery</h1>
            <p class="text-sm text-gray-400 mb-4">Enter the password to continue.</p>
            <?php if ($error !== null): ?><p class="text-sm text-red-400 mb-3"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <input type="password" name="gallery_password" autofocus class="w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-white mb-4 focus:outline-none focus:ring-2 focus:ring-white" placeholder="Password">
            <button type="submit" class="w-full rounded-lg bg-white text-gray-950 font-medium py-2 hover:bg-gray-200 transition">Enter</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

requireAuth($config);

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

// Per-request memoization: the same directory gets scanned repeatedly
// (once for its own listing, again by countImagesRecursive, again by
// cover-image lookups) — cache scandir results for this request only.
function imageFiles(string $directory, array $allowedExtensions): array
{
    static $cache = [];
    $cacheKey = $directory . '|' . implode(',', $allowedExtensions);
    if (isset($cache[$cacheKey])) { return $cache[$cacheKey]; }

    if (!is_dir($directory)) { return $cache[$cacheKey] = []; }

    $files = [];
    foreach (scandir($directory) ?: [] as $file) {
        if ($file === '.' || $file === '..') { continue; }
        $path = $directory . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) { continue; }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($extension, $allowedExtensions, true)) { $files[] = $file; }
    }

    natcasesort($files);
    return $cache[$cacheKey] = array_values($files);
}

function albums(string $photosDir, string $relativePath = ''): array
{
    static $cache = [];
    $basePath = $relativePath !== '' ? $photosDir . DIRECTORY_SEPARATOR . $relativePath : $photosDir;
    if (isset($cache[$basePath])) { return $cache[$basePath]; }

    if (!is_dir($basePath)) { return $cache[$basePath] = []; }

    $albums = [];
    foreach (scandir($basePath) ?: [] as $directory) {
        if ($directory === '.' || $directory === '..') { continue; }
        $path = $basePath . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($path) || str_starts_with($directory, '.')) { continue; }
        $albums[] = $directory;
    }

    natcasesort($albums);
    return $cache[$basePath] = array_values($albums);
}

// Memoized recursive count — with 500+ photos across several subfolders
// this was being recomputed (rescanning every subdirectory) on every
// single album card in the listing; cache per absolute path per request.
function countImagesRecursive(string $albumPath, array $allowedExtensions): int
{
    static $cache = [];
    if (isset($cache[$albumPath])) { return $cache[$albumPath]; }

    $count = count(imageFiles($albumPath, $allowedExtensions));

    foreach (scandir($albumPath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) { continue; }
        $path = $albumPath . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) { $count += countImagesRecursive($path, $allowedExtensions); }
    }

    return $cache[$albumPath] = $count;
}

function normalizeAlbumPath(string $rawPath): ?string
{
    $rawPath = trim($rawPath, "/\\");
    if ($rawPath === '') { return null; }

    $segments = preg_split('#[/\\\\]+#', $rawPath);
    $clean = [];

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') { return null; }
        $safeSegment = basename($segment);
        if ($safeSegment !== $segment || str_starts_with($safeSegment, '.')) { return null; }
        $clean[] = $safeSegment;
    }

    return implode('/', $clean);
}

function exifNumber(mixed $value): ?float
{
    if ($value === null || $value === '') { return null; }
    if (is_numeric($value)) { return (float) $value; }

    if (is_string($value) && str_contains($value, '/')) {
        [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, null);
        if (is_numeric($numerator) && is_numeric($denominator) && (float) $denominator != 0) { return (float) $numerator / (float) $denominator; }
    }

    return null;
}

function formatGpsCoordinate(mixed $coordinate, string $hemisphere): ?float
{
    if (!is_array($coordinate) || count($coordinate) < 3) { return null; }

    $degrees = exifNumber($coordinate[0]);
    $minutes = exifNumber($coordinate[1]);
    $seconds = exifNumber($coordinate[2]);
    if ($degrees === null || $minutes === null || $seconds === null) { return null; }

    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
    if (in_array(strtoupper($hemisphere), ['S', 'W'], true)) { $decimal *= -1; }

    return round($decimal, 7);
}

function exiftoolAvailable(): bool
{
    static $available = null;
    if ($available !== null) { return $available; }
    if (!function_exists('shell_exec') || stripos((string) ini_get('disable_functions'), 'shell_exec') !== false) { return $available = false; }

    $result = @shell_exec('command -v exiftool 2>/dev/null');
    return $available = !empty(trim((string) $result));
}

function getImageMetadataViaExiftool(string $path): ?array
{
    $escaped = escapeshellarg($path);
    $json = @shell_exec("timeout 5 exiftool -json -n -GPSLatitude -GPSLongitude -Make -Model -DateTimeOriginal -ISO -FocalLength -FNumber -ExposureTime {$escaped} 2>/dev/null");
    if (!$json) { return null; }

    $data = json_decode($json, true);
    $row = $data[0] ?? null;
    if (!is_array($row)) { return null; }

    $metadata = [
        'camera' => null, 'date' => null, 'date_iso' => null, 'iso' => $row['ISO'] ?? null,
        'focal_length' => null, 'aperture' => null, 'shutter' => null,
        'latitude' => $row['GPSLatitude'] ?? null, 'longitude' => $row['GPSLongitude'] ?? null, 'gps_url' => null,
    ];

    $make = trim((string) ($row['Make'] ?? ''));
    $model = trim((string) ($row['Model'] ?? ''));
    if ($make && $model) { $metadata['camera'] = $make . ' ' . $model; }
    elseif ($model || $make) { $metadata['camera'] = $model ?: $make; }

    if (!empty($row['DateTimeOriginal'])) {
        $timestamp = strtotime(str_replace(':', '-', substr((string) $row['DateTimeOriginal'], 0, 10)) . substr((string) $row['DateTimeOriginal'], 10));
        if ($timestamp !== false) {
            $metadata['date'] = date('F j, Y H:i', $timestamp);
            $metadata['date_iso'] = date('c', $timestamp);
        }
    }

    if (!empty($row['FocalLength'])) { $metadata['focal_length'] = rtrim(rtrim(number_format((float) $row['FocalLength'], 1), '0'), '.') . ' mm'; }
    if (!empty($row['FNumber'])) { $metadata['aperture'] = 'f/' . rtrim(rtrim(number_format((float) $row['FNumber'], 1), '0'), '.'); }

    if (!empty($row['ExposureTime'])) {
        $shutter = (float) $row['ExposureTime'];
        $metadata['shutter'] = ($shutter > 0 && $shutter < 1) ? '1/' . round(1 / $shutter) . 's' : rtrim(rtrim(number_format($shutter, 2), '0'), '.') . 's';
    }

    if (is_numeric($metadata['latitude']) && is_numeric($metadata['longitude'])) {
        $metadata['latitude'] = round((float) $metadata['latitude'], 7);
        $metadata['longitude'] = round((float) $metadata['longitude'], 7);
        $metadata['gps_url'] = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($metadata['latitude'] . ',' . $metadata['longitude']);
    } else {
        $metadata['latitude'] = null;
        $metadata['longitude'] = null;
    }

    return $metadata;
}

function getImageMetadata(string $path): array
{
    $metadata = [
        'camera' => null, 'date' => null, 'date_iso' => null, 'iso' => null, 'focal_length' => null,
        'aperture' => null, 'shutter' => null, 'width' => null, 'height' => null,
        'latitude' => null, 'longitude' => null, 'gps_url' => null, 'filesize' => null,
    ];

    if (is_file($path)) { $metadata['filesize'] = filesize($path) ?: null; }

    $imageInfo = @getimagesize($path);
    if ($imageInfo) {
        $metadata['width'] = $imageInfo[0] ?? null;
        $metadata['height'] = $imageInfo[1] ?? null;
    }

    if (exiftoolAvailable()) {
        $viaExiftool = getImageMetadataViaExiftool($path);
        if ($viaExiftool !== null) { return array_merge($metadata, $viaExiftool); }
    }

    if (!function_exists('exif_read_data')) { return $metadata; }

    $exif = @exif_read_data($path, null, true);
    if (!$exif) { return $metadata; }

    $make = trim((string) ($exif['IFD0']['Make'] ?? ''));
    $model = trim((string) ($exif['IFD0']['Model'] ?? ''));
    if ($make && $model) { $metadata['camera'] = $make . ' ' . $model; }
    elseif ($model) { $metadata['camera'] = $model; }
    elseif ($make) { $metadata['camera'] = $make; }

    $date = $exif['EXIF']['DateTimeOriginal'] ?? $exif['EXIF']['CreateDate'] ?? $exif['IFD0']['DateTime'] ?? null;
    if ($date) {
        $timestamp = strtotime((string) $date);
        if ($timestamp !== false) {
            $metadata['date'] = date('F j, Y H:i', $timestamp);
            $metadata['date_iso'] = date('c', $timestamp);
        }
    }

    $metadata['iso'] = $exif['EXIF']['ISOSpeedRatings'] ?? $exif['EXIF']['PhotographicSensitivity'] ?? null;

    $focalLength = exifNumber($exif['EXIF']['FocalLength'] ?? null);
    if ($focalLength !== null) { $metadata['focal_length'] = rtrim(rtrim(number_format($focalLength, 1), '0'), '.') . ' mm'; }

    $aperture = exifNumber($exif['EXIF']['FNumber'] ?? $exif['EXIF']['ApertureValue'] ?? null);
    if ($aperture !== null) { $metadata['aperture'] = 'f/' . rtrim(rtrim(number_format($aperture, 1), '0'), '.'); }

    $shutter = exifNumber($exif['EXIF']['ExposureTime'] ?? null);
    if ($shutter !== null) {
        $metadata['shutter'] = ($shutter > 0 && $shutter < 1) ? '1/' . round(1 / $shutter) . 's' : rtrim(rtrim(number_format($shutter, 2), '0'), '.') . 's';
    }

    $gps = $exif['GPS'] ?? [];
    $latitude = formatGpsCoordinate($gps['GPSLatitude'] ?? null, (string) ($gps['GPSLatitudeRef'] ?? ''));
    $longitude = formatGpsCoordinate($gps['GPSLongitude'] ?? null, (string) ($gps['GPSLongitudeRef'] ?? ''));

    if ($latitude !== null && $longitude !== null) {
        $metadata['latitude'] = $latitude;
        $metadata['longitude'] = $longitude;
        $metadata['gps_url'] = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($latitude . ',' . $longitude);
    }

    return $metadata;
}

function humanizeFilename(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(['-', '_'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    return $name === '' ? 'Photo' : ucfirst($name);
}

function formatBytes(?int $bytes): ?string
{
    if ($bytes === null) { return null; }

    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = $bytes;
    while ($value >= 1024 && $i < count($units) - 1) { $value /= 1024; $i++; }

    return rtrim(rtrim(number_format($value, 1), '0'), '.') . ' ' . $units[$i];
}

// Cleans up cached thumbnails whose source photo no longer exists.
// Cache layout is now .thumbs/{maxDim}_{quality}/{album}/{filename} —
// same filename as the original, so this just checks each file exists
// in its corresponding album folder.
function cleanupOrphanedThumbnails(string $photosDir, array $allowedExtensions): void
{
    $thumbsRoot = $photosDir . DIRECTORY_SEPARATOR . '.thumbs';
    if (!is_dir($thumbsRoot)) { return; }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($thumbsRoot, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) { continue; }

        // Path under .thumbs/ is: {maxDim}_{quality}/{album...}/{filename}
        $relative = substr($fileInfo->getPathname(), strlen($thumbsRoot) + 1);
        $parts = explode(DIRECTORY_SEPARATOR, $relative);
        if (count($parts) < 2) { continue; } // malformed/legacy entry

        array_shift($parts); // drop the {maxDim}_{quality} segment
        $filename = array_pop($parts);
        $albumRelDir = implode(DIRECTORY_SEPARATOR, $parts);
        $albumDir = $albumRelDir === '' ? $photosDir : $photosDir . DIRECTORY_SEPARATOR . $albumRelDir;

        if (!is_file($albumDir . DIRECTORY_SEPARATOR . $filename)) {
            @unlink($fileInfo->getPathname());
        }
    }
}

// Generates (and disk-caches) a resized/compressed JPEG copy of a photo.
// Cache path mirrors the original: .thumbs/{maxDim}_{quality}/{album}/{filename}
// Fixed size presets — keeps the on-demand generation endpoint from
// accepting arbitrary dimensions (which would let anyone force GD work
// at any size/quality just by crafting a URL).
function thumbnailSizeParams(string $size): ?array
{
    return match ($size) {
        'grid' => [300, 65],
        'cover' => [400, 70],
        'lightbox' => [1600, 80],
        default => null,
    };
}

// Returns a URL for this photo's thumbnail WITHOUT generating it if it
// isn't cached yet. If cached, returns the static file URL directly
// (fast — just a filesystem check). If not cached, returns a URL to
// the on-demand generation endpoint instead. Combined with the <img
// loading="lazy"> attribute, this means the browser only requests (and
// therefore only triggers generation for) images that actually scroll
// into view — instead of every photo in the album being generated
// synchronously on every page load, which is what made large albums
// slow to open.
function thumbnailSrc(string $photosUrl, string $photosDir, string $album, string $image, string $size): string
{
    [$maxDim, $quality] = thumbnailSizeParams($size);
    $albumFsPath = str_replace('/', DIRECTORY_SEPARATOR, $album);
    $srcPath = $photosDir . DIRECTORY_SEPARATOR . $albumFsPath . DIRECTORY_SEPARATOR . $image;
    $cacheDir = $photosDir . DIRECTORY_SEPARATOR . '.thumbs' . DIRECTORY_SEPARATOR . $maxDim . '_' . $quality . DIRECTORY_SEPARATOR . $albumFsPath;
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $image;

    if (is_file($cacheFile) && filesize($cacheFile) > 0 && filemtime($cacheFile) >= @filemtime($srcPath)) {
        return albumFileUrl($photosUrl, '.thumbs/' . $maxDim . '_' . $quality . '/' . $album, $image);
    }

    return '?album=' . albumQueryValue($album) . '&thumb=' . rawurlencode($image) . '&size=' . $size;
}

// Generates (and disk-caches) a resized/compressed JPEG copy of a photo.
// This always generates immediately — used by the on-demand thumb
// endpoint (when the browser actually requests an uncached image) and
// by the ajax_metadata endpoint (for the lightbox-size image, generated
// the moment a photo is opened).
// Cache path mirrors the original: .thumbs/{maxDim}_{quality}/{album}/{filename}
// — same filename as the source (not a hash), which keeps URL structure
// consistent with the working photos/ path and avoids server/proxy
// path-encoding quirks some hosts hit on hashed filenames.
function thumbnailUrl(string $photosUrl, string $photosDir, string $album, string $image, int $maxDim, int $quality): string
{
    $albumFsPath = str_replace('/', DIRECTORY_SEPARATOR, $album);
    $srcPath = $photosDir . DIRECTORY_SEPARATOR . $albumFsPath . DIRECTORY_SEPARATOR . $image;
    $cacheDir = $photosDir . DIRECTORY_SEPARATOR . '.thumbs' . DIRECTORY_SEPARATOR . $maxDim . '_' . $quality . DIRECTORY_SEPARATOR . $albumFsPath;
    $originalUrl = albumFileUrl($photosUrl, $album, $image);

    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $image;
    $cacheUrl = albumFileUrl($photosUrl, '.thumbs/' . $maxDim . '_' . $quality . '/' . $album, $image);

    if (is_file($cacheFile) && filemtime($cacheFile) >= filemtime($srcPath)) {
        if (filesize($cacheFile) > 0) { return $cacheUrl; }
        @unlink($cacheFile); // corrupt/truncated write from a previous request — regenerate below
    }

    if (!function_exists('imagecreatetruecolor')) { return $originalUrl; }
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }

    $info = @getimagesize($srcPath);
    if (!$info) { return $originalUrl; }

    [$srcWidth, $srcHeight, $type] = $info;

    $source = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($srcPath),
        IMAGETYPE_PNG => @imagecreatefrompng($srcPath),
        IMAGETYPE_GIF => @imagecreatefromgif($srcPath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : null,
        default => null,
    };

    if (!$source) { return $originalUrl; }

    if (function_exists('exif_read_data') && $type === IMAGETYPE_JPEG) {
        $exif = @exif_read_data($srcPath);
        $orientation = $exif['Orientation'] ?? 1;
        $source = match ($orientation) {
            3 => imagerotate($source, 180, 0),
            6 => imagerotate($source, -90, 0),
            8 => imagerotate($source, 90, 0),
            default => $source,
        };
        if (in_array($orientation, [6, 8], true)) { [$srcWidth, $srcHeight] = [$srcHeight, $srcWidth]; }
    }

    $ratio = min($maxDim / $srcWidth, $maxDim / $srcHeight, 1);
    $dstWidth = max(1, (int) round($srcWidth * $ratio));
    $dstHeight = max(1, (int) round($srcHeight * $ratio));

    $dest = imagecreatetruecolor($dstWidth, $dstHeight);
    imagefill($dest, 0, 0, imagecolorallocate($dest, 17, 24, 39)); // gray-900 bg for transparent PNGs
    imagecopyresampled($dest, $source, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);
    $wrote = imagejpeg($dest, $cacheFile, $quality);

    imagedestroy($source);
    imagedestroy($dest);

    // Validate the write actually succeeded and produced a real file —
    // a failed/interrupted write (memory limit, disk full, concurrent
    // request) should fall back to serving the original, not a broken
    // or empty cached file.
    if (!$wrote || !is_file($cacheFile) || filesize($cacheFile) === 0) {
        @unlink($cacheFile);
        return $originalUrl;
    }

    return $cacheUrl;
}

function albumQueryValue(string $albumPath): string { return implode('%2F', array_map('rawurlencode', explode('/', $albumPath))); }

function albumFileUrl(string $photosUrl, string $albumPath, string $file = ''): string
{
    $url = $photosUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $albumPath)));
    return $file !== '' ? $url . '/' . rawurlencode($file) : $url;
}

function absoluteUrl(string $siteUrl, string $relative): ?string
{
    $base = rtrim($siteUrl, '/');
    if ($base === '') { return null; }
    return $base . '/' . ltrim($relative, '/');
}

$album = $_GET['album'] ?? null;

if ($album !== null) {
    $album = normalizeAlbumPath((string) $album);
    if ($album === null) { http_response_code(404); die('Album not found.'); }

    $albumPath = $photosDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $album);
    if (!is_dir($albumPath)) { http_response_code(404); die('Album not found.'); }

    $images = imageFiles($albumPath, $allowedExtensions);
    $subAlbumList = albums($photosDir, str_replace('/', DIRECTORY_SEPARATOR, $album));

    $breadcrumbs = [];
    $accumulated = [];
    foreach (explode('/', $album) as $segment) {
        $accumulated[] = $segment;
        $breadcrumbs[] = ['label' => $segment, 'path' => implode('/', $accumulated)];
    }

    if (isset($_GET['download'])) {
        $requestedFile = basename((string) $_GET['download']);
        if (!in_array($requestedFile, $images, true)) { http_response_code(404); die('File not found.'); }

        $filePath = $albumPath . DIRECTORY_SEPARATOR . $requestedFile;
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $requestedFile . '"');
        header('Content-Length: ' . filesize($filePath));
        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($filePath);
        exit;
    }

    // On-demand thumbnail generation: ?album=X&thumb=filename&size=grid|cover|lightbox
    // Only hit when a browser actually requests an image whose thumbnail
    // isn't cached yet (see thumbnailSrc()) — combined with <img
    // loading="lazy">, this means only visible/scrolled-to photos ever
    // trigger GD work, instead of the whole album generating up front.
    // Generates the thumbnail then redirects to the resulting static
    // file, so the browser caches the real file normally afterward.
    if (isset($_GET['thumb'], $_GET['size'])) {
        $requestedFile = basename((string) $_GET['thumb']);
        $sizeParams = thumbnailSizeParams((string) $_GET['size']);

        header('Cache-Control: no-store, no-cache, must-revalidate');

        if (!$sizeParams || !in_array($requestedFile, $images, true)) {
            http_response_code(404);
            exit;
        }

        [$maxDim, $quality] = $sizeParams;
        $generatedUrl = thumbnailUrl($photosUrl, $photosDir, $album, $requestedFile, $maxDim, $quality);
        header('Location: /' . ltrim($generatedUrl, '/'));
        http_response_code(302);
        exit;
    }

    // AJAX metadata endpoint: called only for the photo actually opened
    // in the lightbox. Also generates (and returns) the larger lightbox
    // thumbnail lazily here, instead of pre-generating it for every photo
    // in the album up front — this is what makes big albums load fast.
    if (isset($_GET['ajax_metadata'])) {
        $requestedFile = basename((string) $_GET['ajax_metadata']);
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        if (!in_array($requestedFile, $images, true)) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }

        $metadata = getImageMetadata($albumPath . DIRECTORY_SEPARATOR . $requestedFile);
        $metadata['filesize'] = formatBytes($metadata['filesize'] ?? null);
        $metadata['lightbox_url'] = thumbnailUrl($photosUrl, $photosDir, $album, $requestedFile, 1600, 80);
        echo json_encode($metadata);
        exit;
    }

    $perPage = max(1, (int) $config['photos_per_page']);
    $totalImages = count($images);
    $totalPages = max(1, (int) ceil($totalImages / $perPage));
    $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
    $pagedImages = array_values(array_slice($images, ($page - 1) * $perPage, $perPage));
} else {
    $albumList = albums($photosDir);
    if (mt_rand(1, 50) === 1) { cleanupOrphanedThumbnails($photosDir, $allowedExtensions); }
}

$imageCount = $album ? count($images) : 0;
$pageTitle = $album ? e($album) . ' - Gallery' : 'Gallery';
$pageDescription = $album ? 'Browse ' . $imageCount . ' photo' . ($imageCount === 1 ? '' : 's') . ' in the ' . $album . ' album.' : 'A photo gallery.';
$canonicalUrl = absoluteUrl($config['site_url'], $album ? '?album=' . albumQueryValue($album) : '');

$ogImageUrl = null;
if ($album && !empty($images[0])) { $ogImageUrl = absoluteUrl($config['site_url'], albumFileUrl($photosUrl, $album, $images[0])); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= $pageTitle ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23e5e7eb'%3E%3Cpath d='M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0'/%3E%3Cpath d='M2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4H2zm.5 2a.5.5 0 1 1 0-1 .5.5 0 0 1 0 1z'/%3E%3C/svg%3E">
    <meta name="description" content="<?= e($pageDescription) ?>">

    <?php if ($config['indexing']): ?><meta name="robots" content="index, follow"><?php else: ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <?php if ($canonicalUrl): ?><link rel="canonical" href="<?= e($canonicalUrl) ?>"><?php endif; ?>

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($album ?: 'Gallery') ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <?php if ($canonicalUrl): ?><meta property="og:url" content="<?= e($canonicalUrl) ?>"><?php endif; ?>
    <?php if ($ogImageUrl): ?><meta property="og:image" content="<?= e($ogImageUrl) ?>"><?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>

<body class="min-h-screen bg-gray-950 text-white">

<div x-data="gallery()" x-init="init()" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <?php if ($album === null): ?>

        <header class="mb-8">
            <h1 class="text-3xl font-bold">Gallery</h1>
            <p class="mt-2 text-gray-400"><?= count($albumList) ?> album<?= count($albumList) === 1 ? '' : 's' ?></p>
        </header>

        <?php if (!$albumList): ?>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-10 text-center">
                <div class="text-5xl mb-4">📷</div>
                <h2 class="text-xl font-semibold">No albums yet</h2>
                <p class="mt-2 text-gray-400">Create a folder inside <code class="text-gray-300">photos/</code> and put some images in it.</p>
            </div>

        <?php else: ?>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5">

                <?php foreach ($albumList as $albumName): ?>

                    <?php
                    $albumFsPath = $photosDir . DIRECTORY_SEPARATOR . $albumName;
                    $albumImages = imageFiles($albumFsPath, $allowedExtensions);
                    $cover = $albumImages[0] ?? null;
                    $coverAlbumRelPath = $albumName;

                    if (!$cover) {
                        foreach (albums($photosDir, $albumName) as $subName) {
                            $subImages = imageFiles($albumFsPath . DIRECTORY_SEPARATOR . $subName, $allowedExtensions);
                            if ($subImages) {
                                $cover = $subImages[0];
                                $coverAlbumRelPath = $albumName . '/' . $subName;
                                break;
                            }
                        }
                    }

                    $totalCount = countImagesRecursive($albumFsPath, $allowedExtensions);
                    ?>

                    <a href="?album=<?= albumQueryValue($albumName) ?>" class="group block rounded-xl overflow-hidden bg-gray-900 border border-gray-800 hover:border-gray-600 transition">
                        <div class="aspect-square bg-gray-800 overflow-hidden">
                            <?php if ($cover): ?>
                                <img src="<?= e(thumbnailSrc($photosUrl, $photosDir, $coverAlbumRelPath, $cover, 'cover')) ?>" alt="<?= e(humanizeFilename($cover) . ' - ' . $albumName . ' album cover') ?>" loading="lazy" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-gray-600"><span class="text-5xl">📁</span></div>
                            <?php endif; ?>
                        </div>

                        <div class="p-4">
                            <h2 class="font-semibold truncate"><?= e($albumName) ?></h2>
                            <p class="text-sm text-gray-500 mt-1"><?= $totalCount ?> photo<?= $totalCount === 1 ? '' : 's' ?></p>
                        </div>
                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    <?php else: ?>

        <header class="mb-8">

            <?php
            $parentSegments = $breadcrumbs;
            array_pop($parentSegments);
            $parentHref = $parentSegments ? '?album=' . albumQueryValue(end($parentSegments)['path']) : './';
            ?>

            <a href="<?= e($parentHref) ?>" class="inline-flex items-center text-gray-400 hover:text-white mb-6">← Back to <?= $parentSegments ? e(end($parentSegments)['label']) : 'albums' ?></a>

            <nav class="flex flex-wrap items-center gap-1 text-sm text-gray-500 mb-3">
                <a href="./" class="hover:text-white transition">Gallery</a>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <span class="text-gray-700">/</span>
                    <a href="?album=<?= albumQueryValue($crumb['path']) ?>" class="hover:text-white transition truncate max-w-[160px]"><?= e($crumb['label']) ?></a>
                <?php endforeach; ?>
            </nav>

            <h1 class="text-3xl font-bold"><?= e($breadcrumbs[array_key_last($breadcrumbs)]['label']) ?></h1>
            <p class="mt-2 text-gray-400"><?= count($images) ?> photo<?= count($images) === 1 ? '' : 's' ?> <?php if ($subAlbumList): ?> · <?= count($subAlbumList) ?> sub-album<?= count($subAlbumList) === 1 ? '' : 's' ?><?php endif; ?>
            </p>

        </header>

        <?php if ($subAlbumList): ?>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5 mb-10">

                <?php foreach ($subAlbumList as $subName): ?>

                    <?php
                    $subRelPath = $album . '/' . $subName;
                    $subFsPath = $albumPath . DIRECTORY_SEPARATOR . $subName;
                    $subImages = imageFiles($subFsPath, $allowedExtensions);
                    $subCover = $subImages[0] ?? null;
                    $subCoverRelPath = $subRelPath;

                    if (!$subCover) {
                        foreach (albums($photosDir, str_replace('/', DIRECTORY_SEPARATOR, $subRelPath)) as $deeperName) {
                            $deeperImages = imageFiles($subFsPath . DIRECTORY_SEPARATOR . $deeperName, $allowedExtensions);
                            if ($deeperImages) {
                                $subCover = $deeperImages[0];
                                $subCoverRelPath = $subRelPath . '/' . $deeperName;
                                break;
                            }
                        }
                    }

                    $subTotalCount = countImagesRecursive($subFsPath, $allowedExtensions);
                    ?>

                    <a href="?album=<?= albumQueryValue($subRelPath) ?>" class="group block rounded-xl overflow-hidden bg-gray-900 border border-gray-800 hover:border-gray-600 transition">
                        <div class="aspect-square bg-gray-800 overflow-hidden">

                            <?php if ($subCover): ?>
                                <img src="<?= e(thumbnailSrc($photosUrl, $photosDir, $subCoverRelPath, $subCover, 'cover')) ?>" alt="<?= e(humanizeFilename($subCover) . ' - ' . $subName . ' album cover') ?>" loading="lazy" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-gray-600"><span class="text-5xl">📁</span></div>
                            <?php endif; ?>

                        </div>

                        <div class="p-4">
                            <h2 class="font-semibold truncate"><?= e($subName) ?></h2>
                            <p class="text-sm text-gray-500 mt-1"><?= $subTotalCount ?> photo<?= $subTotalCount === 1 ? '' : 's' ?></p>
                        </div>
                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <?php if (!$images && !$subAlbumList): ?>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-10 text-center">
                <div class="text-5xl mb-4">📷</div>
                <h2 class="text-xl font-semibold">No images</h2>
            </div>

        <?php elseif ($images): ?>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">

                <?php foreach ($pagedImages as $index => $image): ?>

                    <?php
                    // Only the grid thumbnail is generated eagerly here.
                    // The larger lightbox version is generated on demand
                    // via ajax_metadata when a photo is actually opened —
                    // this is what keeps large albums fast to load.
                    $gridThumbUrl = thumbnailSrc($photosUrl, $photosDir, $album, $image, 'grid');
                    $altText = humanizeFilename($image) . ' - ' . $album;
                    ?>

                    <button type="button" @click="open(<?= $index ?>)" class="group aspect-square overflow-hidden rounded-lg bg-gray-900 focus:outline-none focus:ring-2 focus:ring-white"><img src="<?= e($gridThumbUrl) ?>" alt="<?= e($altText) ?>" title="<?= e($altText) ?>" loading="lazy" class="w-full h-full object-cover group-hover:scale-105 transition duration-300"></button>

                    <?php
                    $imagesAlt[$index] = $altText;
                    $imagesGrid[$index] = $gridThumbUrl;
                    ?>

                <?php endforeach; ?>

            </div>

            <?php if ($totalPages > 1): ?>

                <nav class="flex items-center justify-center gap-2 mt-8">

                    <?php if ($page > 1): ?>
                        <a href="?album=<?= albumQueryValue($album) ?>&page=<?= $page - 1 ?>" class="px-3 py-2 rounded-lg bg-gray-900 border border-gray-800 hover:border-gray-600 text-sm transition">‹ Prev</a>
                    <?php endif; ?>

                    <span class="px-3 py-2 text-sm text-gray-400">Page <?= $page ?> of <?= $totalPages ?></span>

                    <?php if ($page < $totalPages): ?>
                        <a href="?album=<?= albumQueryValue($album) ?>&page=<?= $page + 1 ?>" class="px-3 py-2 rounded-lg bg-gray-900 border border-gray-800 hover:border-gray-600 text-sm transition">Next ›</a>
                    <?php endif; ?>

                </nav>

            <?php endif; ?>

            <!-- Lightbox -->

            <div x-show="active !== null" x-cloak @keydown.escape.window="close()" @keydown.arrow-left.window="previous()" @keydown.arrow-right.window="next()" class="fixed inset-0 z-50 bg-black flex flex-col md:flex-row">

                <!-- Image side -->
                <div class="relative flex-1 min-w-0 flex items-center justify-center bg-black">

                    <!-- Close (mobile: top of image area) -->
                    <button @click="close()" class="absolute top-4 left-4 z-50 text-white/70 hover:text-white text-2xl leading-none w-10 h-10 flex items-center justify-center rounded-full bg-black/40 hover:bg-black/60 transition">×</button>

                    <!-- Previous -->
                    <button @click="previous()" class="absolute left-2 sm:left-4 z-50 p-3 text-white/70 hover:text-white text-3xl w-10 h-10 flex items-center justify-center rounded-full bg-black/30 hover:bg-black/50 transition">‹</button>

                    <template x-if="active !== null">
                        <img :src="images[active]" class="max-w-full max-h-[50vh] md:max-h-[92vh] object-contain" :alt="alts[active]">
                    </template>

                    <!-- Next -->
                    <button @click="next()" class="absolute right-2 sm:right-4 z-50 p-3 text-white/70 hover:text-white text-3xl w-10 h-10 flex items-center justify-center rounded-full bg-black/30 hover:bg-black/50 transition">›</button>

                    <!-- Counter -->
                    <div class="absolute bottom-3 left-1/2 -translate-x-1/2 text-xs text-white/60 md:hidden" x-text="active !== null ? `${active + 1} / ${images.length}` : ''"></div>

                </div>

                <!-- Info sidebar -->
                <template x-if="active !== null">
                    <div class="w-full md:w-[340px] shrink-0 bg-gray-950 border-t md:border-t-0 md:border-l border-gray-800 flex flex-col max-h-[50vh] md:max-h-screen overflow-y-auto">

                        <div class="px-5 py-4 border-b border-gray-800 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="font-medium text-sm break-words" x-text="names[active]"></h2>
                                <p x-show="metadata[names[active]]?.date" x-text="metadata[names[active]]?.date" class="text-xs text-gray-500 mt-1"></p>
                            </div>

                            <span class="shrink-0 text-xs text-gray-500 hidden md:block" x-text="`${active + 1} / ${images.length}`"></span>
                        </div>

                        <dl class="px-5 py-4 space-y-3 text-sm">

                            <div x-show="metadata[names[active]]?.camera" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Camera</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[names[active]]?.camera"></dd>
                            </div>

                            <div x-show="metadata[names[active]]?.focal_length" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Focal length</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[names[active]]?.focal_length"></dd>
                            </div>

                            <div x-show="metadata[names[active]]?.aperture" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Aperture</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[names[active]]?.aperture"></dd>
                            </div>

                            <div x-show="metadata[names[active]]?.shutter" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Shutter</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[names[active]]?.shutter"></dd>
                            </div>

                            <div x-show="metadata[names[active]]?.iso" class="flex justify-between gap-4">
                                <dt class="text-gray-500">ISO</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[names[active]]?.iso"></dd>
                            </div>

                            <div x-show="metadata[names[active]]?.width && metadata[names[active]]?.height" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Dimensions</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[names[active]]?.width + ' × ' + metadata[names[active]]?.height"></dd>
                            </div>

                            <div x-show="metadata[names[active]]?.filesize" class="flex justify-between gap-4">
                                <dt class="text-gray-500">File size</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[names[active]]?.filesize"></dd>
                            </div>

                            <div x-show="metadata[names[active]]?.latitude != null" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Location</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[names[active]]?.latitude + ', ' + metadata[names[active]]?.longitude"></dd>
                            </div>

                        </dl>

                        <div class="mt-auto px-5 py-4 border-t border-gray-800 flex flex-col gap-2">
                            <a x-show="metadata[names[active]]?.gps_url" :href="metadata[names[active]]?.gps_url" target="_blank" rel="noopener noreferrer" class="flex items-center justify-center gap-2 text-sm font-medium text-gray-200 bg-gray-800 hover:bg-gray-700 rounded-lg px-4 py-2 transition">📍 View on map</a>
                            <a :href="downloadUrl(active)" class="flex items-center justify-center gap-2 text-sm font-medium text-gray-200 bg-gray-800 hover:bg-gray-700 rounded-lg px-4 py-2 transition"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708z"/></svg>Download original</a>
                        </div>

                    </div>
                </template>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
function gallery() {
    return {
        active: null,
        album: <?= json_encode($album ?? '') ?>,
        // Starts as the small grid thumbnails so the lightbox opens
        // instantly; fetchMetadata() swaps in the full-size lightbox
        // image once it's ready (generated on demand server-side).
        images: <?= json_encode(array_values($imagesGrid ?? []), JSON_UNESCAPED_SLASHES) ?>,
        names: <?= json_encode($pagedImages ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        alts: <?= json_encode($imagesAlt ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,

        metadata: {},
        _pending: {},

        init() {
            this.openFromHash();
            window.addEventListener('hashchange', () => this.openFromHash());
        },

        openFromHash() {
            const hash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
            if (!hash) { this.active = null; return; }
            const index = this.names.indexOf(hash);
            if (index !== -1) this.open(index, false);
        },

        open(index, updateHash = true) {
            this.active = index;
            document.body.classList.add('overflow-hidden');
            if (updateHash) history.pushState(null, '', '#' + encodeURIComponent(this.names[index]));
            this.fetchMetadata(index).then(() => this.preloadNeighbors(index));
        },

        // Fetches metadata + the full-size lightbox image for a photo.
        // Returns a promise so callers can chain work (like preloading)
        // once it's done. Safe to call repeatedly — already-fetched or
        // in-flight requests are reused instead of duplicated.
        fetchMetadata(index) {
            const name = this.names[index];
            if (this.metadata[name]) return Promise.resolve();
            if (this._pending[name]) return this._pending[name];

            const promise = fetch('?album=' + encodeURIComponent(this.album) + '&ajax_metadata=' + encodeURIComponent(name))
                .then(res => res.ok ? res.json() : null)
                .then(data => {
                    if (data && !data.error) {
                        this.metadata[name] = data;
                        if (data.lightbox_url) this.images[index] = data.lightbox_url;
                    }
                })
                .catch(() => {})
                .finally(() => { delete this._pending[name]; });

            this._pending[name] = promise;
            return promise;
        },

        // Quietly fetches metadata (and generates the lightbox thumbnail)
        // for the photo before and after the given index, so pressing
        // next/previous usually finds the image already ready instead
        // of waiting on a fresh fetch. Runs in the background — doesn't
        // block or change what's currently shown.
        preloadNeighbors(index) {
            if (this.images.length < 2) return;
            const nextIndex = (index + 1) % this.images.length;
            const prevIndex = (index - 1 + this.images.length) % this.images.length;
            this.fetchMetadata(nextIndex);
            this.fetchMetadata(prevIndex);
        },

        close() {
            this.active = null;
            document.body.classList.remove('overflow-hidden');
            history.pushState(null, '', window.location.pathname + window.location.search);
        },

        previous() {
            if (this.active === null) return;
            this.active = (this.active - 1 + this.images.length) % this.images.length;
            history.replaceState(null, '', '#' + encodeURIComponent(this.names[this.active]));
            this.fetchMetadata(this.active).then(() => this.preloadNeighbors(this.active));
        },

        next() {
            if (this.active === null) return;
            this.active = (this.active + 1) % this.images.length;
            history.replaceState(null, '', '#' + encodeURIComponent(this.names[this.active]));
            this.fetchMetadata(this.active).then(() => this.preloadNeighbors(this.active));
        },

        downloadUrl(index) {
            return '?album=' + encodeURIComponent(this.album) + '&download=' + encodeURIComponent(this.names[index]);
        }
    }
}
</script>

<style>[x-cloak] { display: none !important; }</style>

</body>
</html>
