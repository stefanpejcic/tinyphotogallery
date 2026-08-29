```php
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

        <!-- Albums -->

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

        <!-- Album -->

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

                <?php endforeach; ?>

            </div>

            <!-- Lightbox -->

            <div
                x-show="active !== null"
                x-cloak
                @keydown.escape.window="close()"
                @keydown.arrow-left.window="previous()"
                @keydown.arrow-right.window="next()"
                class="fixed inset-0 z-50 bg-black/95 flex items-center justify-center"
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

                <!-- Image -->

                <template x-if="active !== null">
                    <img
                        :src="images[active]"
                        class="max-w-[90vw] max-h-[90vh] object-contain"
                        :alt="names[active]"
                    >
                </template>

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

        names: <?= json_encode($images ?? [], JSON_UNESCAPED_SLASHES) ?>,

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
```
