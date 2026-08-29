# tinyphotogallery

A photo gallery in a single PHP file. No database, no build step, no dependencies to install: drop `index.php` on any PHP host, add a `photos/` folder, and you have a fast, password-protectable gallery with albums, nested albums, EXIF metadata, and a lightbox.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License](https://img.shields.io/badge/license-MIT-blue)


<table>
<tr>
<td width="50%">

### Galeries

<img width="953" height="1047" alt="slika" src="https://github.com/user-attachments/assets/f04335f2-b4a1-41ce-8f15-c6060fb90aab" />

</td>
<td width="50%">
    
### Single photo

<img width="953" height="1047" alt="slika" src="https://github.com/user-attachments/assets/7bf0431d-d005-4963-847d-281b4352fcf9" />

</td>
</tr>
</table>





## Features

- **Zero setup**: one file, no composer, no database. Just PHP + GD.
- **Folder-based albums**: every subfolder in `photos/` becomes an album, with unlimited nesting.
- **Fast on large albums**: thumbnails are generated on demand and cached to disk; only images that actually scroll into view (or are opened) ever get processed. A 1,000-photo album doesn't resize 1,000 photos on page load.
- **Direct photo links**: every photo has a shareable `#filename` URL that deep-links straight into the lightbox, without loading the rest of the grid.
- **EXIF metadata**: camera, focal length, aperture, shutter speed, ISO, dimensions, file size, and GPS location (with a map link), shown per-photo in the lightbox. Uses `exiftool` if available, falls back to PHP's built-in `exif_read_data`.
- **Optional password protection**: single shared password, hashed, with basic rate-limiting on attempts.
- **Optional search engine indexing**: off by default (`noindex`); flip one config value to allow indexing.
- **Downloads**: one-click original file download from the lightbox.
- **Pagination**: configurable photos-per-page for big albums.
- **SEO/social basics**: meta description, canonical URL, Open Graph tags with a cover image.

## Requirements

- PHP 8.1+
- GD extension (for thumbnail generation)
- *Optional:* `exif_read_data` (usually bundled) or [`exiftool`](https://exiftool.org/) on the server `PATH` for richer/faster metadata

## Installation

1. Copy `index.php` to your web server.
2. Create a `photos/` folder next to it.
3. Add subfolders — each one is an album. Put images inside (`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`).
4. Visit the site. That's it.

```
your-site/
├── index.php
└── photos/
    ├── Japan 2024/
    │   ├── tokyo.jpg
    │   └── kyoto.jpg
    └── Family/
        └── Reunion/
            └── group-photo.jpg
```

Nested folders (like `Family/Reunion` above) show up as sub-albums.

## Configuration

Edit the `$config` array at the top of `index.php`:

```php
$config = [
    'indexing' => false,       // allow search engines to index the gallery
    'password' => null,        // set a password hash to protect the gallery (see below)
    'site_url' => '',          // e.g. 'https://photos.example.com' — used for canonical/OG URLs
    'photos_per_page' => 100,  // pagination size per album
    'debug' => false,          // show PHP errors on screen
];
```

### Password protection

Generate a hash and paste it into `'password'`:

```bash
php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
```

```php
'password' => '$2y$10$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ01',
```

Leave it as `null` to disable the password wall entirely.

## How thumbnails work

Thumbnails are generated the first time they're requested and cached under `photos/.thumbs/`, mirroring your album structure. Three fixed sizes are used (grid, album-cover, lightbox) so nothing arbitrary can be requested. Combined with native `loading="lazy"` on the `<img>` tags, only photos that are actually visible (or actually opened) ever get resized. You can safely delete `photos/.thumbs/` at any time; it will be rebuilt automatically, and orphaned thumbnails (source photo deleted) are cleaned up automatically in the background.

## License

MIT
