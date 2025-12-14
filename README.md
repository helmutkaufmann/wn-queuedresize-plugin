# Queued Resize for WinterCMS

Asynchronous image (and PDF) resizing for WinterCMS, with automatic WebP support and media-library integration.

This plugin adds a `qresize` Twig **filter and function** that behaves like Winter’s built-in `resize`, but:

* Resizing is done via the queue (no heavy work in the HTTP request).
* Results are cached on disk and reused.
* It can generate thumbnails from PDF files (first page).
* It can output WebP when the browser supports it.
* It works with different filesystem disks.

---

## Features

* `qresize` Twig **filter** and **function**
* Drop-in replacement for `resize` in templates
* Works with:
  * Media paths (`media/foo/bar.jpg`)
  * URLs from `| media` (e.g. `/storage/app/media/...`)
  * External URLs (`https://example.com/image.jpg`)
* PDF → image thumbnail (first page)
* WebP output when the client sends `Accept: image/webp`
* Multi-disk support via `disk` option
* Caching on disk (resized files + JSON metadata)
* Avoids repeated image processing under load

---

## Requirements

* WinterCMS (PHP 8.2+)
* PHP GD or Imagick (via [Intervention Image](http://image.intervention.io/))
* A working queue setup in WinterCMS (e.g. `php artisan queue:work`)
* **For PDF thumbnails:**
  * PHP Imagick extension
  * Ghostscript / ImageMagick properly installed on the server

> **Note:** If PDF support is missing, PDF inputs will fail with a runtime error until Imagick is available.

---

## Configuration

### Plugin Config File

The plugin reads settings via `config('mercator.queuedresize::config.*')`. These are defined in:

`plugins/mercator/queuedresize/config/config.php`

(or in an app-level override at `config/mercator/queuedresize/config.php`).

**Example `config.php`:**
```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue name
    |--------------------------------------------------------------------------
    |
    | The queue on which resize jobs will be dispatched.
    |
    */
    'queue' => env('IMAGE_RESIZE_QUEUE', 'imaging'),

    /*
    |--------------------------------------------------------------------------
    | Image driver
    |--------------------------------------------------------------------------
    |
    | The Intervention Image driver to use: "gd" or "imagick".
    |
    */
    'driver' => env('IMAGE_RESIZE_DRIVER', 'gd'),

    /*
    |--------------------------------------------------------------------------
    | Default storage disk
    |--------------------------------------------------------------------------
    |
    | Default filesystem disk used when no per-call "disk" option is provided.
    | It is used for:
    | - reading originals
    | - writing resized images
    | - writing resize metadata (JSON)
    | - default lookup by /queuedresize/{hash}
    |
    */
    'disk' => env('IMAGE_RESIZE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Additional lookup disks
    |--------------------------------------------------------------------------
    |
    | Extra disks that /queuedresize/{hash} will also search (in addition to
    | the default disk) when resolving metadata/images.
    |
    | Needed when you use per-call disk overrides, especially if the disk is
    | part of the cache hash (recommended).
    |
    */
    'disks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('IMAGE_RESIZE_DISKS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Default quality
    |--------------------------------------------------------------------------
    |
    | Fallback JPEG / WebP quality when none is provided in the options.
    |
    */
    'quality' => (int) env('IMAGE_RESIZE_QUALITY', 60),

];
```

### .env Variables
The plugin does not read `.env` directly; it uses `config()`. The mapping to `.env` happens in the config file shown above.

**Supported environment variables:**

```dotenv
# Queue name used for resize jobs
IMAGE_RESIZE_QUEUE=imaging

# Image driver: gd or imagick
IMAGE_RESIZE_DRIVER=gd

# Default filesystem disk for originals + resized images
IMAGE_RESIZE_DISK=media

# Additional disks searched by /queuedresize/{hash}
# (comma-separated, no spaces recommended)
IMAGE_RESIZE_DISKS=portfolio,s3

# Default image quality (0–100)
IMAGE_RESIZE_QUALITY=60
```

---

## Installation
### Install via Composer
```bash
composer require mercator/wn-queuedresize-plugin
```

### Start the Queue Worker
```bash
php artisan queue:work
```

Or run workers via systemd / Supervisor / your process manager.

### Run Updates and Clear Caches
```bash
php artisan winter:up
php artisan cache:clear
php artisan config:clear
```

---

## Usage in Twig
The plugin registers `qresize` as a filter and as a function.

### As a Filter (Drop-in style)
```twig
{# qresize as a filter: src | qresize(width, height, options) #}

<img src="{{ 'media/example.jpg' | qresize(800, 600) }}" alt="">

{# With options #}
<img src="{{ 'media/example.jpg' | qresize(800, 600, { mode: 'crop', quality: 80 }) }}" alt="">
```

** Drop-in replacement for built-in `resize`:**
```twig
{# Before #}
<img src="{{ 'media/example.jpg' | resize(800, 600) }}" alt="">

{# After (queued) #}
<img src="{{ 'media/example.jpg' | qresize(800, 600) }}" alt="">
```

** Works with `| media`:**
```twig
<img src="{{ record.image | media | qresize(800, 600) }}" alt="">
```

### As a Function
```twig
{# qresize(src, width, height, options) #}

<img src="{{ qresize('media/example.jpg', 800, 600) }}" alt="">

{# With options #}
<img src="{{ qresize('media/example.jpg', 800, 600, { mode: 'crop', quality: 80 }) }}" alt="">
```

**Useful with variables:**

```twig
{% set src   = record.image | media %}
{% set width = 800 %}
{% set opts  = { mode: 'crop', quality: 75 } %}

<img src="{{ qresize(src, width, null, opts) }}" alt="">
```

---

## Arguments and Options
### Source (`src`)`src` can be:

* A media path: `media/example.jpg`
* A URL created by `| media`, e.g. `/storage/app/media/example.jpg`
* A full external URL: `https://example.com/image.jpg`

### Width and Height
* `null` or `0` means “no constraint” on that dimension (aspect ratio preserved).

```twig
{{ 'media/example.jpg' | qresize(800, 600) }}   {# target box #}
{{ 'media/example.jpg' | qresize(800, null) }}  {# fixed width, auto height #}
{{ 'media/example.jpg' | qresize(null, 400) }}  {# fixed height, auto width #}
```

### Options ArrayThe 4th parameter is the options array:

```twig
{{ 'media/example.jpg' | qresize(800, 600, {
    mode: 'crop',        // 'auto' (default) or 'crop'
    quality: 70,         // JPEG/WebP quality 0–100
    format: 'best',      // 'best', 'jpg', 'png', 'gif', 'webp', 'jpeg', 'avif'
    disk: 'media'        // override default disk
}) }}
```

**`mode`**
* `auto` (default): scale down to fit within width/height.
* `crop`: crop to exact width/height (centered) when both are given.


**`quality`**
* Output quality for JPEG and WebP. Defaults to `IMAGE_RESIZE_QUALITY`.


**`format`**
* `best` (default): serve WebP if the client supports it, otherwise JPEG.
* `jpg`, `png`, `gif`, `webp`, `jpeg`, `avif`: explicit formats.


**`disk`**
* Override the default disk from config for this call.

> **Note (multi-disk):** If you use `{ disk: 'portfolio' }` while your default disk is local, make sure `IMAGE_RESIZE_DISKS` includes `portfolio`, so `/queuedresize/{hash}` can resolve the cached files.

---

## PDF Thumbnails
If Imagick is available, the plugin can treat PDF files like images by rendering the first page.

```twig
<img src="{{ 'media/docs/report.pdf' | qresize(null, 200) }}" alt="Report">

```

**Requirements:**

1. PHP Imagick extension.
2. Ghostscript / ImageMagick installed and working.

---

## Multi-disk Usage
If your originals live on a non-default disk, pass `disk`:

```twig
<img src="{{ 'uploads/gallery/image1.jpg' | qresize(1200, 800, { disk: 's3' }) }}" alt="">

```

If you have a public URL from that disk:

```twig
{% set urlForS3 = someModel.s3_image_url %}
<img src="{{ qresize(urlForS3, 800, 600, { disk: 's3' }) }}" alt="">

```

The plugin uses the disk’s configured `url` to map URLs back to storage paths. If mapping fails, fix the disk’s `url` in `config/filesystems.php`.

---

## WebP “Best Format” Mode
If `format` is omitted or set to `'best'`:

* If the client’s `Accept` header includes `image/webp`, output is WebP.
* Otherwise, it falls back to JPEG.

```twig
<img src="{{ 'media/example.jpg' | qresize(800, 600, { format: 'best' }) }}" alt="">

```

---

## Caching and Storage Layout
Resized images are stored on the configured disk under a nested directory structure, based on a hash:

```text
resized/ab/cd/ef/abcdef1234567890...webp
resized/ab/cd/ef/abcdef1234567890...json

```

The hash is derived from:

* Source (path or URL)
* Requested width / height
* Options (including disk, if enabled in your hash implementation)
* mtime and size snapshot (where available)

The `.json` file contains metadata such as:

* `src` (original source)
* `w`, `h` (dimensions)
* `opts` (options)
* `disk`
* `mtime`, `size`

If an identical resize is requested again, the existing file is reused and no new job is queued. To clear cached resized images, delete the `resized` directory on the relevant disk.

---

## Queue Behaviour and Concurrency
When you call `qresize` with a new combination of source + options:

1. The plugin writes the JSON metadata next to where the image will live.
2. It dispatches a `ProcessImageResize` job on the configured queue.
3. It immediately returns the URL for the resized image.

Rendering happens asynchronously in the queue worker.

**Notes:**

* A single `php artisan queue:work` process handles jobs one at a time.
* Concurrency comes from running multiple workers:

```bash
php artisan queue:work --queue=imaging
php artisan queue:work --queue=imaging
php artisan queue:work --queue=imaging
```

---

## Troubleshooting
**“Source not found on disk …”**
* Verify what you pass into `qresize` (`media/foo.jpg`, `file.path`, `someField | media`, etc.).
* Confirm the file exists on the disk used (`IMAGE_RESIZE_DISK` or `disk` option).


* **PDFs not rendering**
* Confirm Imagick is installed and enabled in PHP.
* Confirm ImageMagick + Ghostscript are installed and working.
* Check queue and PHP logs for Imagick exceptions.


* **Nothing is being resized**
* Verify at least one queue worker is running.
* Ensure the worker listens on the correct queue (`IMAGE_RESIZE_QUEUE`).
* Run `php artisan queue:work --verbose` and inspect `storage/logs/`.



---

## LicenseThe MIT License (MIT)

Copyright (C) 2025 Helmut Kaufmann, https://mercator.li, software@mercator.li

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the “Software”), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

```

```