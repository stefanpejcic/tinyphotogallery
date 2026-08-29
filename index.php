<?php

$photosDir = __DIR__ . '/photos';
$photosUrl = 'photos';

$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

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

function albums(string $photosDir): array
{
    if (!is_dir($photosDir)) {
        return [];
    }

    $albums = [];

    foreach (scandir($photosDir) ?: [] as $directory) {
        if ($directory === '.' || $directory === '..') {
            continue;
        }

        $path = $photosDir . DIRECTORY_SEPARATOR . $directory;

        if (!is_dir($path) || str_starts_with($directory, '.')) {
            continue;
        }

        $albums[] = $directory;
    }

    natcasesort($albums);

    return array_values($albums);
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

function getImageMetadata(string $path): array
{
    $metadata = [
        'camera' => null,
        'date' => null,
        'iso' => null,
        'focal_length' => null,
        'aperture' => null,
        'shutter' => null,
        'width' => null,
        'height' => null,
        'latitude' => null,
        'longitude' => null,
        'gps_url' => null,
    ];

    $imageInfo = @getimagesize($path);

    if ($imageInfo) {
        $metadata['width'] = $imageInfo[0] ?? null;
        $metadata['height'] = $imageInfo[1] ?? null;
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

$album = $_GET['album'] ?? null;

if ($album !== null) {
    // Prevent directory traversal.
    $album = basename($album);
    $albumPath = $photosDir . DIRECTORY_SEPARATOR . $album;

    if (!is_dir($albumPath)) {
        http_response_code(404);
        die('Album not found.');
    }

    $images = imageFiles($albumPath, $allowedExtensions);
} else {
    $albumList = albums($photosDir);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= $album ? e($album) . ' - Gallery' : 'Gallery' ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>

<body class="min-h-screen bg-gray-950 text-white">

<div
    x-data="gallery()"
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
                    $albumPath = $photosDir . DIRECTORY_SEPARATOR . $albumName;
                    $albumImages = imageFiles($albumPath, $allowedExtensions);
                    $cover = $albumImages[0] ?? null;
                    ?>

                    <a
                        href="?album=<?= rawurlencode($albumName) ?>"
                        class="group block rounded-xl overflow-hidden bg-gray-900 border border-gray-800 hover:border-gray-600 transition"
                    >
                        <div class="aspect-square bg-gray-800 overflow-hidden">

                            <?php if ($cover): ?>

                                <img
                                    src="<?= e($photosUrl . '/' . rawurlencode($albumName) . '/' . rawurlencode($cover)) ?>"
                                    alt="<?= e($albumName) ?>"
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
                                <?= count($albumImages) ?>
                                photo<?= count($albumImages) === 1 ? '' : 's' ?>
                            </p>
                        </div>
                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    <?php else: ?>

        <header class="mb-8">

            <a
                href="./"
                class="inline-flex items-center text-gray-400 hover:text-white mb-6"
            >
                ← Back to albums
            </a>

            <h1 class="text-3xl font-bold">
                <?= e($album) ?>
            </h1>

            <p class="mt-2 text-gray-400">
                <?= count($images) ?>
                photo<?= count($images) === 1 ? '' : 's' ?>
            </p>

        </header>

        <?php if (!$images): ?>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-10 text-center">
                <div class="text-5xl mb-4">📷</div>
                <h2 class="text-xl font-semibold">No images</h2>
            </div>

        <?php else: ?>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">

                <?php foreach ($images as $index => $image): ?>

                    <?php
                    $imageUrl =
                        $photosUrl . '/' .
                        rawurlencode($album) . '/' .
                        rawurlencode($image);

                    $metadata = getImageMetadata(
                        $albumPath . DIRECTORY_SEPARATOR . $image
                    );
                    ?>

                    <button
                        type="button"
                        @click="open(<?= $index ?>)"
                        class="group aspect-square overflow-hidden rounded-lg bg-gray-900 focus:outline-none focus:ring-2 focus:ring-white"
                    >
                        <img
                            src="<?= e($imageUrl) ?>"
                            alt="<?= e($image) ?>"
                            loading="lazy"
                            class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                        >
                    </button>

                    <?php
                    $imagesMetadata[$index] = $metadata;
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
                class="fixed inset-0 z-50 bg-black/95 flex items-center justify-center p-4"
            >

                <!-- Close -->

                <button
                    @click="close()"
                    class="absolute top-5 right-5 z-50 text-white/70 hover:text-white text-3xl"
                >
                    ×
                </button>

                <!-- Previous -->

                <button
                    @click="previous()"
                    class="absolute left-3 sm:left-6 z-50 p-4 text-white/70 hover:text-white text-4xl"
                >
                    ‹
                </button>

                <div class="max-w-[90vw] max-h-[92vh] flex flex-col items-center">

                    <!-- Image -->

                    <template x-if="active !== null">
                        <img
                            :src="images[active]"
                            class="max-w-[90vw] max-h-[72vh] object-contain rounded-lg"
                            :alt="names[active]"
                        >
                    </template>

                    <!-- Metadata -->

                    <template x-if="active !== null">
                        <div
                            class="mt-4 w-full max-w-2xl rounded-xl bg-gray-900/90 border border-gray-800 px-5 py-4"
                        >
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <h2
                                        class="font-medium truncate"
                                        x-text="names[active]"
                                    ></h2>

                                    <p
                                        x-show="metadata[active]?.date"
                                        x-text="metadata[active]?.date"
                                        class="text-sm text-gray-500 mt-1"
                                    ></p>
                                </div>

                                <a
                                    x-show="metadata[active]?.gps_url"
                                    :href="metadata[active]?.gps_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="shrink-0 text-sm text-blue-400 hover:text-blue-300"
                                >
                                    📍 Map
                                </a>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm text-gray-400">

                                <span
                                    x-show="metadata[active]?.camera"
                                    x-text="'📷 ' + metadata[active]?.camera"
                                ></span>

                                <span
                                    x-show="metadata[active]?.focal_length"
                                    x-text="'🔭 ' + metadata[active]?.focal_length"
                                ></span>

                                <span
                                    x-show="metadata[active]?.aperture"
                                    x-text="'◉ ' + metadata[active]?.aperture"
                                ></span>

                                <span
                                    x-show="metadata[active]?.shutter"
                                    x-text="'⚡ ' + metadata[active]?.shutter"
                                ></span>

                                <span
                                    x-show="metadata[active]?.iso"
                                    x-text="'ISO ' + metadata[active]?.iso"
                                ></span>

                                <span
                                    x-show="metadata[active]?.width && metadata[active]?.height"
                                    x-text="metadata[active]?.width + ' × ' + metadata[active]?.height"
                                ></span>

                            </div>

                            <div
                                x-show="metadata[active]?.latitude !== null"
                                class="mt-3 pt-3 border-t border-gray-800 text-xs text-gray-500"
                            >
                                📍
                                <span x-text="metadata[active]?.latitude"></span>,
                                <span x-text="metadata[active]?.longitude"></span>
                            </div>

                        </div>
                    </template>

                </div>

                <!-- Next -->

                <button
                    @click="next()"
                    class="absolute right-3 sm:right-6 z-50 p-4 text-white/70 hover:text-white text-4xl"
                >
                    ›
                </button>

                <!-- Counter -->

                <div
                    class="absolute bottom-5 left-1/2 -translate-x-1/2 text-sm text-gray-400"
                    x-text="active !== null ? `${active + 1} / ${images.length}` : ''"
                ></div>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
function gallery() {
    return {
        active: null,

        images: <?= json_encode(
            array_map(
                fn($image) =>
                    $photosUrl . '/' .
                    rawurlencode($album ?? '') . '/' .
                    rawurlencode($image),
                $images ?? [],
            ),
            JSON_UNESCAPED_SLASHES
        ) ?>,

        names: <?= json_encode(
            $images ?? [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?>,

        metadata: <?= json_encode(
            $imagesMetadata ?? [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?>,

        open(index) {
            this.active = index;
            document.body.classList.add('overflow-hidden');
        },

        close() {
            this.active = null;
            document.body.classList.remove('overflow-hidden');
        },

        previous() {
            if (this.active === null) return;

            this.active =
                (this.active - 1 + this.images.length) %
                this.images.length;
        },

        next() {
            if (this.active === null) return;

            this.active =
                (this.active + 1) %
                this.images.length;
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
