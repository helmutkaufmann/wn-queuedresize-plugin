# Queued Resize for WinterCMS

Asynchronous image (and PDF) resizing for WinterCMS, with automatic WebP support and media-library integration.

This plugin adds a `qresize` Twig **filter and function** that behaves like Winter’s built-in `resize`, but:

  * Resizing is done via the queue (no heavy work in the HTTP request).
  * Results are cached on disk and reused.
  * It can generate thumbnails from PDF files (first page).
  * It can output WebP when the browser supports it.
  * It works with different filesystem disks.

-----

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

-----

## Requirements

  * WinterCMS (PHP 8.2+)
  * PHP GD or Imagick (via [Intervention Image](http://image.intervention.io/))
  * A working queue setup in WinterCMS (e.g. `php artisan queue:work`)
  * **For PDF thumbnails:**
      * PHP Imagick extension
      * Ghostscript / ImageMagick properly installed on the server

> **Note:** If PDF support is missing, PDF inputs will fail with a runtime error until Imagick is available.

-----

## Configuration

### Plugin Config File

The plugin reads its settings via `config('mercator.queuedresize::config.*')`. These are defined in the plugin config file:

`plugins/mercator/queuedresize/config/config.php`

(or in an app-level override, if you create `config/mercator/queuedresize/config.php`).

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
    | The filesystem disk used to read originals and write resized images,
    | unless a different disk is explicitly passed via the "disk" option.
    |
    */
    'disk' => env('IMAGE_RESIZE_DISK', 'local'),

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
````

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

# Default image quality (0–100)
IMAGE_RESIZE_QUALITY=60
```

You can adjust these per environment without changing plugin code. Filesystem and queue base configuration still follow standard Winter/Laravel patterns and may also use `.env`.

-----

## Installation

1.  **Install via Composer:**
    Run the following command in your WinterCMS project root:

    ```bash
    composer require mercator/wn-queuedresize-plugin
    ```

    This will install the plugin into `plugins/mercator/queuedresize`.

2.  **Start the Queue Worker:**
    Make sure your queue worker is running.

    ```bash
    php artisan queue:work
    ```

    *Or configure it with your process manager of choice (systemd, Supervisor, etc.).*

3.  **Run Migrations (if applicable) and Clear Caches:**

    ```bash
    php artisan winter:up
    php artisan cache:clear
    php artisan config:clear
    ```

-----

## Usage in Twig

The plugin registers `qresize` both as a filter and as a function. Both hit the same underlying method.

### As a Filter (Drop-in style)

```twig
{# qresize as a filter: src | qresize(width, height, options) #}

<img src="{{ 'media/example.jpg' | qresize(800, 600) }}" alt="">

{# With options #}
<img src="{{ 'media/example.jpg' | qresize(800, 600, { mode: 'crop', quality: 80 }) }}" alt="">
```

This is usually a drop-in replacement for the built-in resize:

```twig
{# Before #}
<img src="{{ 'media/example.jpg' | resize(800, 600) }}" alt="">

{# After (queued) #}
<img src="{{ 'media/example.jpg' | qresize(800, 600) }}" alt="">
```

It also works with `| media`:

```twig
<img src="{{ record.image | media | qresize(800, 600) }}" alt="">
```

### As a Function

Same arguments, function syntax:

```twig
{# qresize(src, width, height, options) #}

<img src="{{ qresize('media/example.jpg', 800, 600) }}" alt="">

{# With options #}
<img src="{{ qresize('media/example.jpg', 800, 600, { mode: 'crop', quality: 80 }) }}" alt="">
```

Useful when working with variables:

```twig
{% set src   = record.image | media %}
{% set width = 800 %}
{% set opts  = { mode: 'crop', quality: 75 } %}

<img src="{{ qresize(src, width, null, opts) }}" alt="">
```

-----

## Arguments and Options

### Source (`src`)

`src` can be:

  * A media path: `media/example.jpg`
  * A URL created by `| media`, e.g. `/storage/app/media/example.jpg`
  * A full external URL: `https://example.com/image.jpg`

The plugin internally normalises these into something it can feed to Intervention Image or Imagick.

### Width and Height

  * `null` or `0` means “no constraint” on that dimension (aspect ratio is preserved).

<!-- end list -->

```twig
{{ 'media/example.jpg' | qresize(800, 600) }}   {# target box #}
{{ 'media/example.jpg' | qresize(800, null) }}  {# fixed width, auto height #}
{{ 'media/example.jpg' | qresize(null, 400) }}  {# fixed height, auto width #}
```

### Options Array

The 4th parameter is the options array:

```twig
{{ 'media/example.jpg' | qresize(800, 600, {
    mode: 'crop',        // 'auto' (default) or 'crop'
    quality: 70,         // JPEG/WebP quality 0–100
    format: 'best',      // 'best', 'jpg', 'png', 'gif', 'webp', 'jpeg', 'avif'
    disk: 'media'        // override default disk
}) }}
```

  * **`mode`**
      * `auto` (default): scale down to fit within width/height.
      * `crop`: crop to exact width/height (centered) when both are given.
  * **`quality`**
      * Output quality for JPEG and WebP. Defaults to `IMAGE_RESIZE_QUALITY` or the plugin config.
  * **`format`**
      * `best` (default): serve WebP if the client supports it, otherwise JPEG.
      * `jpg`, `png`, `gif`, `webp`, `jpeg`, `avif`: explicit formats.
  * **`disk`**
      * Override the default disk from config for this particular call.

-----

## PDF Thumbnails

If Imagick is available, the plugin can treat PDF files like images by rendering the first page to a JPEG internally.

**Example:**

```twig
{# Thumbnail from first page of a PDF #}
<img src="{{ 'media/docs/report.pdf' | qresize(null, 200) }}" alt="Report">
```

**Example for a directory browser:**

```twig
{% if file.isPdf %}
    <img src="{{ file.path | qresize(null, 150) }}" alt="{{ file.displayName }}">
{% endif %}
```

**Requirements for PDF support:**

1.  PHP Imagick extension.
2.  Ghostscript / ImageMagick configured and accessible.

**Internally:**

1.  The first page of the PDF is rendered via Imagick.
2.  The resulting JPEG is sent through the normal resize pipeline.

-----

## Multi-disk Usage

If your originals live on a non-default disk (e.g. S3), pass `disk` in the options:

```twig
{# Image on S3 (disk: 's3') #}
<img src="{{ 'uploads/gallery/image1.jpg' | qresize(1200, 800, { disk: 's3' }) }}" alt="">
```

If you have a public URL from that disk:

```twig
{% set urlForS3 = someModel.s3_image_url %}
<img src="{{ qresize(urlForS3, 800, 600, { disk: 's3' }) }}" alt="">
```

The plugin uses the disk’s base URL to map the URL back to a storage path before reading. If this fails, fix the disk’s `url` configuration in `config/filesystems.php`.

-----

## WebP “Best Format” Mode

If `format` is omitted or set to `'best'`:

  * If the client’s `Accept` header includes `image/webp`, the plugin outputs WebP.
  * Otherwise, it falls back to JPEG.

**Example:**

```twig
<img src="{{ 'media/example.jpg' | qresize(800, 600, { format: 'best' }) }}" alt="">
```

This gives WebP to capable browsers without extra work in your templates.

-----

## Caching and Storage Layout

Resized images are stored on the configured disk under a nested directory structure, based on a hash:

```text
resized/ab/cd/ef/abcdef1234567890...webp
resized/ab/cd/ef/abcdef1234567890...json
```

The hash is derived from:

  * Source (path or URL)
  * Requested width / height
  * Options (excluding the disk key in the hash)
  * mtime and size snapshot (where available)

The `.json` file contains metadata:

  * src (original source)
  * w, h (dimensions)
  * opts (options)
  * disk
  * mtime, size

If an identical resize is requested again, the existing file is reused and no new job is queued. To clear cached resized images, delete the `resized` directory on the relevant disk.

-----

## Queue Behaviour and Concurrency

When you call `qresize` with a new combination of source + options:

1.  The plugin writes the JSON metadata next to where the image will live.
2.  It dispatches a `ProcessImageResize` job on the configured queue.
3.  It immediately returns the URL for the resized image.

Rendering happens asynchronously in the queue worker.

**Important notes:**

  * A single `php artisan queue:work` process handles jobs one at a time.
  * Concurrency comes from running multiple workers:

<!-- end list -->

```bash
php artisan queue:work --queue=imaging
php artisan queue:work --queue=imaging
php artisan queue:work --queue=imaging
```

This gives you up to 3 jobs in parallel on the `imaging` queue.

If you want faster throughput for heavy image jobs, increase the number of workers or use a process manager that can scale workers.

-----

## Troubleshooting

  * **“Source not found on disk …”**

      * Check what you actually pass into `qresize`:
          * `media/foo.jpg`
          * `file.path` from your media lists
          * or `someField | media | qresize(...)`
      * Confirm that the path exists on the configured disk (`IMAGE_RESIZE_DISK` or `disk` option).

  * **PDFs not rendering**

      * Confirm Imagick is installed and enabled in PHP.
      * Confirm ImageMagick + Ghostscript are installed and working.
      * Check PHP and queue logs for Imagick-related exceptions.

  * **Nothing is being resized**

      * Verify at least one queue worker is running.
      * Ensure the worker listens on the correct queue name (`IMAGE_RESIZE_QUEUE` / `config('mercator.queuedresize::config.queue')`).
      * Inspect `storage/logs/` or run `php artisan queue:work --verbose` for errors.

-----

## License

**The MIT License (MIT)**

Copyright (C) 2025 Helmut Kaufmann, [https://mercator.li](https://mercator.li), software@mercator.li

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

```
```