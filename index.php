<?php

// ---------------------------------------------------------------
// Config — edit these directly, no separate file needed.
// ---------------------------------------------------------------
$config = [
    // Search engine indexing on/off
    'indexing' => false,

    // Set to null to disable password protection.
    // Generate a hash with: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
    'password' => null,

    // Used for canonical URLs / Open Graph — leave blank to skip
    'site_url' => '',
];

$photosDir = __DIR__ . '/photos';
$photosUrl = 'photos';

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

session_start();

function requireAuth(array $config): void
{
    if ($config['password'] === null) {
        return;
    }

    if (!empty($_SESSION['gallery_authed'])) {
        return;
    }

    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_password'])) {
        if (password_verify($_POST['gallery_password'], $config['password'])) {
            $_SESSION['gallery_authed'] = true;
            // Redirect to drop the POST and preserve any ?album= query.
            $qs = $_SERVER['QUERY_STRING'] ?? '';
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? '?' . $qs : ''));
            exit;
        }

        $error = 'Incorrect password.';
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Password required</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-gray-950 text-white flex items-center justify-center p-4">
        <form method="post" class="w-full max-w-sm rounded-xl border border-gray-800 bg-gray-900 p-6">
            <h1 class="text-xl font-semibold mb-1">🔒 Protected Gallery</h1>
            <p class="text-sm text-gray-400 mb-4">Enter the password to continue.</p>
            <?php if ($error !== null): ?>
                <p class="text-sm text-red-400 mb-3"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <input
                type="password"
                name="gallery_password"
                autofocus
                class="w-full rounded-lg bg-gray-800 border border-gray-700 px-3 py-2 text-white mb-4 focus:outline-none focus:ring-2 focus:ring-white"
                placeholder="Password"
            >
            <button type="submit" class="w-full rounded-lg bg-white text-gray-950 font-medium py-2 hover:bg-gray-200 transition">
                Enter
            </button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

requireAuth($config);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function imageFiles(string $directory, array $allowedExtensions): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];

    foreach (scandir($directory) ?: [] as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            continue;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (in_array($extension, $allowedExtensions, true)) {
            $files[] = $file;
        }
    }

    natcasesort($files);

    return array_values($files);
}

function albums(string $photosDir, string $relativePath = ''): array
{
    $basePath = $relativePath !== ''
        ? $photosDir . DIRECTORY_SEPARATOR . $relativePath
        : $photosDir;

    if (!is_dir($basePath)) {
        return [];
    }

    $albums = [];

    foreach (scandir($basePath) ?: [] as $directory) {
        if ($directory === '.' || $directory === '..') {
            continue;
        }

        $path = $basePath . DIRECTORY_SEPARATOR . $directory;

        if (!is_dir($path) || str_starts_with($directory, '.')) {
            continue;
        }

        $albums[] = $directory;
    }

    natcasesort($albums);

    return array_values($albums);
}

// Recursively count images within an album folder and all its subfolders.
function countImagesRecursive(string $albumPath, array $allowedExtensions): int
{
    $count = count(imageFiles($albumPath, $allowedExtensions));

    foreach (scandir($albumPath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            continue;
        }

        $path = $albumPath . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($path)) {
            $count += countImagesRecursive($path, $allowedExtensions);
        }
    }

    return $count;
}

// Validate and normalize a user-supplied album path (from ?album=).
// Prevents directory traversal while allowing nested segments like
// "Wedding2026/Ceremony". Returns null if the path is invalid.
function normalizeAlbumPath(string $rawPath): ?string
{
    $rawPath = trim($rawPath, "/\\");

    if ($rawPath === '') {
        return null;
    }

    $segments = preg_split('#[/\\\\]+#', $rawPath);
    $clean = [];

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return null;
        }
        // basename() strips any residual separators/traversal per segment.
        $safeSegment = basename($segment);
        if ($safeSegment !== $segment || str_starts_with($safeSegment, '.')) {
            return null;
        }
        $clean[] = $safeSegment;
    }

    return implode('/', $clean);
}

function exifNumber(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (float) $value;
    }

    if (is_string($value) && str_contains($value, '/')) {
        [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, null);

        if (is_numeric($numerator) && is_numeric($denominator) && (float) $denominator != 0) {
            return (float) $numerator / (float) $denominator;
        }
    }

    return null;
}

function formatGpsCoordinate(mixed $coordinate, string $hemisphere): ?float
{
    if (!is_array($coordinate) || count($coordinate) < 3) {
        return null;
    }

    $degrees = exifNumber($coordinate[0]);
    $minutes = exifNumber($coordinate[1]);
    $seconds = exifNumber($coordinate[2]);

    if ($degrees === null || $minutes === null || $seconds === null) {
        return null;
    }

    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

    if (in_array(strtoupper($hemisphere), ['S', 'W'], true)) {
        $decimal *= -1;
    }

    return round($decimal, 7);
}

// Checks once per request whether the `exiftool` binary is callable.
// exiftool reads GPS/camera metadata without needing the PHP `exif`
// extension, and also handles PNG/WebP/HEIC (exif_read_data does not).
function exiftoolAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    if (!function_exists('shell_exec') || stripos((string) ini_get('disable_functions'), 'shell_exec') !== false) {
        return $available = false;
    }

    $result = @shell_exec('command -v exiftool 2>/dev/null');
    return $available = !empty(trim((string) $result));
}

function getImageMetadataViaExiftool(string $path): ?array
{
    $escaped = escapeshellarg($path);
    $json = @shell_exec("exiftool -json -n -GPSLatitude -GPSLongitude -Make -Model -DateTimeOriginal -ISO -FocalLength -FNumber -ExposureTime {$escaped} 2>/dev/null");

    if (!$json) {
        return null;
    }

    $data = json_decode($json, true);
    $row = $data[0] ?? null;

    if (!is_array($row)) {
        return null;
    }

    $metadata = [
        'camera' => null,
        'date' => null,
        'date_iso' => null,
        'iso' => $row['ISO'] ?? null,
        'focal_length' => null,
        'aperture' => null,
        'shutter' => null,
        'latitude' => $row['GPSLatitude'] ?? null,
        'longitude' => $row['GPSLongitude'] ?? null,
        'gps_url' => null,
    ];

    $make = trim((string) ($row['Make'] ?? ''));
    $model = trim((string) ($row['Model'] ?? ''));
    if ($make && $model) {
        $metadata['camera'] = $make . ' ' . $model;
    } elseif ($model || $make) {
        $metadata['camera'] = $model ?: $make;
    }

    if (!empty($row['DateTimeOriginal'])) {
        $timestamp = strtotime(str_replace(':', '-', substr((string) $row['DateTimeOriginal'], 0, 10)) . substr((string) $row['DateTimeOriginal'], 10));
        if ($timestamp !== false) {
            $metadata['date'] = date('F j, Y H:i', $timestamp);
            $metadata['date_iso'] = date('c', $timestamp);
        }
    }

    if (!empty($row['FocalLength'])) {
        $metadata['focal_length'] = rtrim(rtrim(number_format((float) $row['FocalLength'], 1), '0'), '.') . ' mm';
    }

    if (!empty($row['FNumber'])) {
        $metadata['aperture'] = 'f/' . rtrim(rtrim(number_format((float) $row['FNumber'], 1), '0'), '.');
    }

    if (!empty($row['ExposureTime'])) {
        $shutter = (float) $row['ExposureTime'];
        $metadata['shutter'] = ($shutter > 0 && $shutter < 1)
            ? '1/' . round(1 / $shutter) . 's'
            : rtrim(rtrim(number_format($shutter, 2), '0'), '.') . 's';
    }

    if (is_numeric($metadata['latitude']) && is_numeric($metadata['longitude'])) {
        $metadata['latitude'] = round((float) $metadata['latitude'], 7);
        $metadata['longitude'] = round((float) $metadata['longitude'], 7);
        $metadata['gps_url'] =
            'https://www.google.com/maps/search/?api=1&query=' .
            rawurlencode($metadata['latitude'] . ',' . $metadata['longitude']);
    } else {
        $metadata['latitude'] = null;
        $metadata['longitude'] = null;
    }

    return $metadata;
}

function getImageMetadata(string $path): array
{
    $metadata = [
        'camera' => null,
        'date' => null,
        'date_iso' => null,
        'iso' => null,
        'focal_length' => null,
        'aperture' => null,
        'shutter' => null,
        'width' => null,
        'height' => null,
        'latitude' => null,
        'longitude' => null,
        'gps_url' => null,
        'filesize' => null,
    ];

    if (is_file($path)) {
        $metadata['filesize'] = filesize($path) ?: null;
    }

    $imageInfo = @getimagesize($path);

    if ($imageInfo) {
        $metadata['width'] = $imageInfo[0] ?? null;
        $metadata['height'] = $imageInfo[1] ?? null;
    }

    // Prefer exiftool when available — no PHP extension needed, and it
    // covers formats (PNG, WebP, HEIC) exif_read_data() cannot read.
    if (exiftoolAvailable()) {
        $viaExiftool = getImageMetadataViaExiftool($path);
        if ($viaExiftool !== null) {
            return array_merge($metadata, $viaExiftool);
        }
    }

    if (!function_exists('exif_read_data')) {
        return $metadata;
    }

    // EXIF is generally available for JPEG/TIFF images. Suppress warnings
    // for formats/files where EXIF is not supported.
    $exif = @exif_read_data($path, null, true);

    if (!$exif) {
        return $metadata;
    }

    $make = trim((string) ($exif['IFD0']['Make'] ?? ''));
    $model = trim((string) ($exif['IFD0']['Model'] ?? ''));

    if ($make && $model) {
        $metadata['camera'] = $make . ' ' . $model;
    } elseif ($model) {
        $metadata['camera'] = $model;
    } elseif ($make) {
        $metadata['camera'] = $make;
    }

    $date = $exif['EXIF']['DateTimeOriginal']
        ?? $exif['EXIF']['CreateDate']
        ?? $exif['IFD0']['DateTime']
        ?? null;

    if ($date) {
        $timestamp = strtotime((string) $date);

        if ($timestamp !== false) {
            $metadata['date'] = date('F j, Y H:i', $timestamp);
            $metadata['date_iso'] = date('c', $timestamp);
        }
    }

    $metadata['iso'] = $exif['EXIF']['ISOSpeedRatings']
        ?? $exif['EXIF']['PhotographicSensitivity']
        ?? null;

    $focalLength = exifNumber($exif['EXIF']['FocalLength'] ?? null);

    if ($focalLength !== null) {
        $metadata['focal_length'] = rtrim(rtrim(number_format($focalLength, 1), '0'), '.') . ' mm';
    }

    $aperture = exifNumber(
        $exif['EXIF']['FNumber']
        ?? $exif['EXIF']['ApertureValue']
        ?? null
    );

    if ($aperture !== null) {
        $metadata['aperture'] = 'f/' . rtrim(rtrim(number_format($aperture, 1), '0'), '.');
    }

    $shutter = exifNumber($exif['EXIF']['ExposureTime'] ?? null);

    if ($shutter !== null) {
        if ($shutter > 0 && $shutter < 1) {
            $metadata['shutter'] = '1/' . round(1 / $shutter) . 's';
        } else {
            $metadata['shutter'] = rtrim(rtrim(number_format($shutter, 2), '0'), '.') . 's';
        }
    }

    // GPS coordinates are stored as DMS arrays in EXIF.
    $gps = $exif['GPS'] ?? [];

    $latitude = formatGpsCoordinate(
        $gps['GPSLatitude'] ?? null,
        (string) ($gps['GPSLatitudeRef'] ?? '')
    );

    $longitude = formatGpsCoordinate(
        $gps['GPSLongitude'] ?? null,
        (string) ($gps['GPSLongitudeRef'] ?? '')
    );

    if ($latitude !== null && $longitude !== null) {
        $metadata['latitude'] = $latitude;
        $metadata['longitude'] = $longitude;

        // Google Maps link. Coordinates are kept numeric and generated
        // server-side rather than accepting arbitrary URLs from EXIF.
        $metadata['gps_url'] =
            'https://www.google.com/maps/search/?api=1&query=' .
            rawurlencode($latitude . ',' . $longitude);
    }

    return $metadata;
}

// Build a human-friendly alt/title string from a filename, e.g.
// "sunset-over-lake_2.jpg" -> "Sunset over lake 2"
function humanizeFilename(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(['-', '_'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    return $name === '' ? 'Photo' : ucfirst($name);
}

function formatBytes(?int $bytes): ?string
{
    if ($bytes === null) {
        return null;
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = $bytes;

    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return rtrim(rtrim(number_format($value, 1), '0'), '.') . ' ' . $units[$i];
}

// ---------------------------------------------------------------
// Thumbnails: resized + compressed copies, cached to disk on first
// request. Needs the GD extension (bundled with PHP by default).
// ---------------------------------------------------------------
function thumbnailUrl(string $photosUrl, string $photosDir, string $album, string $image, int $maxDim, int $quality): string
{
    $albumFsPath = str_replace('/', DIRECTORY_SEPARATOR, $album);
    $srcPath = $photosDir . DIRECTORY_SEPARATOR . $albumFsPath . DIRECTORY_SEPARATOR . $image;
    $cacheDir = $photosDir . DIRECTORY_SEPARATOR . '.thumbs' . DIRECTORY_SEPARATOR . $albumFsPath;
    $cacheKey = $maxDim . '_' . $quality . '_' . md5($image . filemtime($srcPath));
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.jpg';
    $cacheUrl = $photosUrl . '/.thumbs/' . implode('/', array_map('rawurlencode', explode('/', $album))) . '/' . rawurlencode($cacheKey) . '.jpg';

    if (is_file($cacheFile)) {
        return $cacheUrl;
    }

    if (!function_exists('imagecreatetruecolor')) {
        // GD not installed — fall back to the original file.
        return $photosUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $album))) . '/' . rawurlencode($image);
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $info = @getimagesize($srcPath);
    if (!$info) {
        return $photosUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $album))) . '/' . rawurlencode($image);
    }

    [$srcWidth, $srcHeight, $type] = $info;

    $source = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($srcPath),
        IMAGETYPE_PNG => @imagecreatefrompng($srcPath),
        IMAGETYPE_GIF => @imagecreatefromgif($srcPath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : null,
        default => null,
    };

    if (!$source) {
        return $photosUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $album))) . '/' . rawurlencode($image);
    }

    // Correct orientation using EXIF if available, so thumbnails aren't sideways.
    if (function_exists('exif_read_data') && $type === IMAGETYPE_JPEG) {
        $exif = @exif_read_data($srcPath);
        $orientation = $exif['Orientation'] ?? 1;

        $source = match ($orientation) {
            3 => imagerotate($source, 180, 0),
            6 => imagerotate($source, -90, 0),
            8 => imagerotate($source, 90, 0),
            default => $source,
        };

        if (in_array($orientation, [6, 8], true)) {
            [$srcWidth, $srcHeight] = [$srcHeight, $srcWidth];
        }
    }

    $ratio = min($maxDim / $srcWidth, $maxDim / $srcHeight, 1);
    $dstWidth = max(1, (int) round($srcWidth * $ratio));
    $dstHeight = max(1, (int) round($srcHeight * $ratio));

    $dest = imagecreatetruecolor($dstWidth, $dstHeight);
    imagefill($dest, 0, 0, imagecolorallocate($dest, 17, 24, 39)); // gray-900 bg for transparent PNGs
    imagecopyresampled($dest, $source, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);

    imagejpeg($dest, $cacheFile, $quality);

    imagedestroy($source);
    imagedestroy($dest);

    return $cacheUrl;
}

// Build a safe ?album= query value for a nested album path.
function albumQueryValue(string $albumPath): string
{
    return implode('%2F', array_map('rawurlencode', explode('/', $albumPath)));
}

// Build a safe photos/ URL for a nested album path.
function albumFileUrl(string $photosUrl, string $albumPath, string $file = ''): string
{
    $url = $photosUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $albumPath)));
    return $file !== '' ? $url . '/' . rawurlencode($file) : $url;
}

// Build an absolute URL from site_url + a relative path.
function absoluteUrl(string $siteUrl, string $relative): ?string
{
    $base = rtrim($siteUrl, '/');

    if ($base === '') {
        return null;
    }

    return $base . '/' . ltrim($relative, '/');
}

$album = $_GET['album'] ?? null;

if ($album !== null) {
    $album = normalizeAlbumPath((string) $album);

    if ($album === null) {
        http_response_code(404);
        die('Album not found.');
    }

    $albumPath = $photosDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $album);

    if (!is_dir($albumPath)) {
        http_response_code(404);
        die('Album not found.');
    }

    $images = imageFiles($albumPath, $allowedExtensions);
    $subAlbumList = albums($photosDir, str_replace('/', DIRECTORY_SEPARATOR, $album));

    // Breadcrumb trail: each entry is [label, path-to-that-level]
    $breadcrumbs = [];
    $accumulated = [];
    foreach (explode('/', $album) as $segment) {
        $accumulated[] = $segment;
        $breadcrumbs[] = [
            'label' => $segment,
            'path' => implode('/', $accumulated),
        ];
    }

    // ---------------------------------------------------------------
    // Direct download endpoint: ?album=X&download=filename.jpg
    // ---------------------------------------------------------------
    if (isset($_GET['download'])) {
        $requestedFile = basename((string) $_GET['download']);

        if (!in_array($requestedFile, $images, true)) {
            http_response_code(404);
            die('File not found.');
        }

        $filePath = $albumPath . DIRECTORY_SEPARATOR . $requestedFile;

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $requestedFile . '"');
        header('Content-Length: ' . filesize($filePath));
        header('X-Robots-Tag: noindex');
        readfile($filePath);
        exit;
    }
} else {
    $albumList = albums($photosDir);
}

// ---------------------------------------------------------------
// SEO: per-image title/description/alt + noindex toggle
// ---------------------------------------------------------------
$imageCount = $album ? count($images) : 0;

$pageTitle = $album ? e($album) . ' - Gallery' : 'Gallery';
$pageDescription = $album
    ? 'Browse ' . $imageCount . ' photo' . ($imageCount === 1 ? '' : 's') . ' in the ' . $album . ' album.'
    : 'A photo gallery.';

$canonicalUrl = absoluteUrl(
    $config['site_url'],
    $album ? '?album=' . implode('%2F', array_map('rawurlencode', explode('/', $album))) : ''
);

$ogImageUrl = null;
if ($album && !empty($images[0])) {
    $ogImageUrl = absoluteUrl(
        $config['site_url'],
        $photosUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $album))) . '/' . rawurlencode($images[0])
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= $pageTitle ?></title>
    <meta name="description" content="<?= e($pageDescription) ?>">

    <?php if ($config['indexing']): ?>
        <meta name="robots" content="index, follow">
    <?php else: ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>

    <?php if ($canonicalUrl): ?>
        <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <?php endif; ?>

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($album ?: 'Gallery') ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <?php if ($canonicalUrl): ?>
        <meta property="og:url" content="<?= e($canonicalUrl) ?>">
    <?php endif; ?>
    <?php if ($ogImageUrl): ?>
        <meta property="og:image" content="<?= e($ogImageUrl) ?>">
    <?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>

<body class="min-h-screen bg-gray-950 text-white">

<div
    x-data="gallery()"
    x-init="init()"
    class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
>

    <?php if ($album === null): ?>

        <header class="mb-8">
            <h1 class="text-3xl font-bold">Gallery</h1>
            <p class="mt-2 text-gray-400">
                <?= count($albumList) ?> album<?= count($albumList) === 1 ? '' : 's' ?>
            </p>
        </header>

        <?php if (!$albumList): ?>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-10 text-center">
                <div class="text-5xl mb-4">📷</div>
                <h2 class="text-xl font-semibold">No albums yet</h2>
                <p class="mt-2 text-gray-400">
                    Create a folder inside <code class="text-gray-300">photos/</code>
                    and put some images in it.
                </p>
            </div>

        <?php else: ?>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5">

                <?php foreach ($albumList as $albumName): ?>

                    <?php
                    $albumFsPath = $photosDir . DIRECTORY_SEPARATOR . $albumName;
                    $albumImages = imageFiles($albumFsPath, $allowedExtensions);
                    $cover = $albumImages[0] ?? null;
                    $coverAlbumRelPath = $albumName;

                    // If this album folder has no images directly inside it,
                    // look one level into its subfolders for a cover photo.
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

                    <a
                        href="?album=<?= albumQueryValue($albumName) ?>"
                        class="group block rounded-xl overflow-hidden bg-gray-900 border border-gray-800 hover:border-gray-600 transition"
                    >
                        <div class="aspect-square bg-gray-800 overflow-hidden">

                            <?php if ($cover): ?>

                                <img
                                    src="<?= e(thumbnailUrl($photosUrl, $photosDir, $coverAlbumRelPath, $cover, 400, 70)) ?>"
                                    alt="<?= e(humanizeFilename($cover) . ' - ' . $albumName . ' album cover') ?>"
                                    loading="lazy"
                                    class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                >

                            <?php else: ?>

                                <div class="w-full h-full flex items-center justify-center text-gray-600">
                                    <span class="text-5xl">📁</span>
                                </div>

                            <?php endif; ?>

                        </div>

                        <div class="p-4">
                            <h2 class="font-semibold truncate">
                                <?= e($albumName) ?>
                            </h2>

                            <p class="text-sm text-gray-500 mt-1">
                                <?= $totalCount ?>
                                photo<?= $totalCount === 1 ? '' : 's' ?>
                            </p>
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
            $parentHref = $parentSegments
                ? '?album=' . albumQueryValue(end($parentSegments)['path'])
                : './';
            ?>

            <a
                href="<?= e($parentHref) ?>"
                class="inline-flex items-center text-gray-400 hover:text-white mb-6"
            >
                ← Back to <?= $parentSegments ? e(end($parentSegments)['label']) : 'albums' ?>
            </a>

            <nav class="flex flex-wrap items-center gap-1 text-sm text-gray-500 mb-3">
                <a href="./" class="hover:text-white transition">Gallery</a>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <span class="text-gray-700">/</span>
                    <a
                        href="?album=<?= albumQueryValue($crumb['path']) ?>"
                        class="hover:text-white transition truncate max-w-[160px]"
                    >
                        <?= e($crumb['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <h1 class="text-3xl font-bold">
                <?= e($breadcrumbs[array_key_last($breadcrumbs)]['label']) ?>
            </h1>

            <p class="mt-2 text-gray-400">
                <?= count($images) ?>
                photo<?= count($images) === 1 ? '' : 's' ?>
                <?php if ($subAlbumList): ?>
                    · <?= count($subAlbumList) ?> sub-album<?= count($subAlbumList) === 1 ? '' : 's' ?>
                <?php endif; ?>
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

                    <a
                        href="?album=<?= albumQueryValue($subRelPath) ?>"
                        class="group block rounded-xl overflow-hidden bg-gray-900 border border-gray-800 hover:border-gray-600 transition"
                    >
                        <div class="aspect-square bg-gray-800 overflow-hidden">

                            <?php if ($subCover): ?>

                                <img
                                    src="<?= e(thumbnailUrl($photosUrl, $photosDir, $subCoverRelPath, $subCover, 400, 70)) ?>"
                                    alt="<?= e(humanizeFilename($subCover) . ' - ' . $subName . ' album cover') ?>"
                                    loading="lazy"
                                    class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                >

                            <?php else: ?>

                                <div class="w-full h-full flex items-center justify-center text-gray-600">
                                    <span class="text-5xl">📁</span>
                                </div>

                            <?php endif; ?>

                        </div>

                        <div class="p-4">
                            <h2 class="font-semibold truncate">
                                <?= e($subName) ?>
                            </h2>

                            <p class="text-sm text-gray-500 mt-1">
                                <?= $subTotalCount ?>
                                photo<?= $subTotalCount === 1 ? '' : 's' ?>
                            </p>
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

                <?php foreach ($images as $index => $image): ?>

                    <?php
                    $gridThumbUrl = thumbnailUrl($photosUrl, $photosDir, $album, $image, 300, 65);
                    $lightboxUrl = thumbnailUrl($photosUrl, $photosDir, $album, $image, 1600, 80);

                    $metadata = getImageMetadata(
                        $albumPath . DIRECTORY_SEPARATOR . $image
                    );

                    $altText = humanizeFilename($image) . ' - ' . $album;
                    ?>

                    <button
                        type="button"
                        @click="open(<?= $index ?>)"
                        class="group aspect-square overflow-hidden rounded-lg bg-gray-900 focus:outline-none focus:ring-2 focus:ring-white"
                    >
                        <img
                            src="<?= e($gridThumbUrl) ?>"
                            alt="<?= e($altText) ?>"
                            title="<?= e($altText) ?>"
                            loading="lazy"
                            class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                        >
                    </button>

                    <?php
                    $imagesMetadata[$index] = $metadata;
                    $imagesAlt[$index] = $altText;
                    $imagesLightbox[$index] = $lightboxUrl;
                    ?>

                <?php endforeach; ?>

            </div>

            <!-- Lightbox -->

            <div
                x-show="active !== null"
                x-cloak
                @keydown.escape.window="close()"
                @keydown.arrow-left.window="previous()"
                @keydown.arrow-right.window="next()"
                class="fixed inset-0 z-50 bg-black flex flex-col md:flex-row"
            >

                <!-- Image side -->

                <div class="relative flex-1 min-w-0 flex items-center justify-center bg-black">

                    <!-- Close (mobile: top of image area) -->

                    <button
                        @click="close()"
                        class="absolute top-4 left-4 z-50 text-white/70 hover:text-white text-2xl leading-none w-10 h-10 flex items-center justify-center rounded-full bg-black/40 hover:bg-black/60 transition"
                    >
                        ×
                    </button>

                    <!-- Previous -->

                    <button
                        @click="previous()"
                        class="absolute left-2 sm:left-4 z-50 p-3 text-white/70 hover:text-white text-3xl w-10 h-10 flex items-center justify-center rounded-full bg-black/30 hover:bg-black/50 transition"
                    >
                        ‹
                    </button>

                    <template x-if="active !== null">
                        <img
                            :src="images[active]"
                            class="max-w-full max-h-[50vh] md:max-h-[92vh] object-contain"
                            :alt="alts[active]"
                        >
                    </template>

                    <!-- Next -->

                    <button
                        @click="next()"
                        class="absolute right-2 sm:right-4 z-50 p-3 text-white/70 hover:text-white text-3xl w-10 h-10 flex items-center justify-center rounded-full bg-black/30 hover:bg-black/50 transition"
                    >
                        ›
                    </button>

                    <!-- Counter -->

                    <div
                        class="absolute bottom-3 left-1/2 -translate-x-1/2 text-xs text-white/60 md:hidden"
                        x-text="active !== null ? `${active + 1} / ${images.length}` : ''"
                    ></div>

                </div>

                <!-- Info sidebar -->

                <template x-if="active !== null">
                    <div class="w-full md:w-[340px] shrink-0 bg-gray-950 border-t md:border-t-0 md:border-l border-gray-800 flex flex-col max-h-[50vh] md:max-h-screen overflow-y-auto">

                        <div class="px-5 py-4 border-b border-gray-800 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="font-medium text-sm break-words" x-text="names[active]"></h2>
                                <p
                                    x-show="metadata[active]?.date"
                                    x-text="metadata[active]?.date"
                                    class="text-xs text-gray-500 mt-1"
                                ></p>
                            </div>

                            <span
                                class="shrink-0 text-xs text-gray-500 hidden md:block"
                                x-text="`${active + 1} / ${images.length}`"
                            ></span>
                        </div>

                        <dl class="px-5 py-4 space-y-3 text-sm">

                            <div x-show="metadata[active]?.camera" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Camera</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[active]?.camera"></dd>
                            </div>

                            <div x-show="metadata[active]?.focal_length" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Focal length</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[active]?.focal_length"></dd>
                            </div>

                            <div x-show="metadata[active]?.aperture" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Aperture</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[active]?.aperture"></dd>
                            </div>

                            <div x-show="metadata[active]?.shutter" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Shutter</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[active]?.shutter"></dd>
                            </div>

                            <div x-show="metadata[active]?.iso" class="flex justify-between gap-4">
                                <dt class="text-gray-500">ISO</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[active]?.iso"></dd>
                            </div>

                            <div x-show="metadata[active]?.width && metadata[active]?.height" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Dimensions</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[active]?.width + ' × ' + metadata[active]?.height"></dd>
                            </div>

                            <div x-show="metadata[active]?.filesize" class="flex justify-between gap-4">
                                <dt class="text-gray-500">File size</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[active]?.filesize"></dd>
                            </div>

                            <div x-show="metadata[active]?.latitude !== null" class="flex justify-between gap-4">
                                <dt class="text-gray-500">Location</dt>
                                <dd class="text-gray-300 text-right" x-text="metadata[active]?.latitude + ', ' + metadata[active]?.longitude"></dd>
                            </div>

                        </dl>

                        <div class="mt-auto px-5 py-4 border-t border-gray-800 flex flex-col gap-2">

                            <a
                                x-show="metadata[active]?.gps_url"
                                :href="metadata[active]?.gps_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="flex items-center justify-center gap-2 text-sm font-medium text-gray-200 bg-gray-800 hover:bg-gray-700 rounded-lg px-4 py-2 transition"
                            >
                                📍 View on map
                            </a>

                            <a
                                :href="downloadUrl(active)"
                                class="flex items-center justify-center gap-2 text-sm font-medium text-gray-200 bg-gray-800 hover:bg-gray-700 rounded-lg px-4 py-2 transition"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download" viewBox="0 0 16 16">
                                    <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/>
                                    <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708z"/>
                                </svg>
                                Download original
                            </a>

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

        images: <?= json_encode(
            array_values($imagesLightbox ?? []),
            JSON_UNESCAPED_SLASHES
        ) ?>,

        names: <?= json_encode(
            $images ?? [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?>,

        alts: <?= json_encode(
            $imagesAlt ?? [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?>,

        metadata: <?= json_encode(
            array_map(
                fn($m) => array_merge($m, ['filesize' => formatBytes($m['filesize'] ?? null)]),
                $imagesMetadata ?? []
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?>,

        init() {
            this.openFromHash();
            window.addEventListener('hashchange', () => this.openFromHash());
        },

        openFromHash() {
            const hash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
            if (!hash) {
                this.active = null;
                return;
            }
            const index = this.names.indexOf(hash);
            if (index !== -1) {
                this.open(index, false);
            }
        },

        open(index, updateHash = true) {
            this.active = index;
            document.body.classList.add('overflow-hidden');
            if (updateHash) {
                history.pushState(null, '', '#' + encodeURIComponent(this.names[index]));
            }
        },

        close() {
            this.active = null;
            document.body.classList.remove('overflow-hidden');
            history.pushState(null, '', window.location.pathname + window.location.search);
        },

        previous() {
            if (this.active === null) return;

            this.active =
                (this.active - 1 + this.images.length) %
                this.images.length;
            history.replaceState(null, '', '#' + encodeURIComponent(this.names[this.active]));
        },

        next() {
            if (this.active === null) return;

            this.active =
                (this.active + 1) %
                this.images.length;
            history.replaceState(null, '', '#' + encodeURIComponent(this.names[this.active]));
        },

        downloadUrl(index) {
            return '?album=' + encodeURIComponent(this.album) +
                '&download=' + encodeURIComponent(this.names[index]);
        }
    }
}
</script>

<style>
[x-cloak] {
    display: none !important;
}
</style>

</body>
</html>
