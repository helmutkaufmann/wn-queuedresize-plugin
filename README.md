# Mercator QueuedResize

Queued image resizing plugin for **WinterCMS**.

This plugin performs asynchronous image resizing with on-demand generation, multi-disk support, source-file change detection (via mtime/size), and meta tracking for reproducibility. It now also allows extraction of the first page of a PDF and save as JPG.

---

## 🚀 Installation

1. Copy this plugin to  
   `plugins/mercator/queuedresize/`

2. Configure your filesystem disks in  
   `config/filesystems.php`  
   (e.g., `local`, `s3`, `backups`).

3. Add environment variables to `.env`:

```
IMAGE_RESIZE_QUEUE=imaging
IMAGE_RESIZE_CONCURRENCY=3
IMAGE_RESIZE_BACKOFF_SECONDS=5
IMAGE_RESIZE_DRIVER=gd
IMAGE_RESIZE_DISK=local
IMAGE_RESIZE_DISKS=local,s3,backups
```

4. Run queue worker:

```
php artisan queue:work --queue=imaging
```

5. Clear caches:

```
php artisan cache:clear
php artisan config:clear

```

---

## ⚙️ Configuration Parameters

| Name                             | Default     | Type       | Description                                                                                 |
| -------------------------------- | ----------- | ---------- | ------------------------------------------------------------------------------------------- |
| **IMAGE_RESIZE_QUEUE** | `"imaging"` | string     | Queue name used for resize jobs.                                                            |
| **IMAGE_RESIZE_CONCURRENCY** | `3`         | integer    | Maximum concurrent resize jobs.                                                             |
| **IMAGE_RESIZE_BACKOFF_SECONDS** | `5`         | integer    | Seconds before retry when busy.                                                             |
| **IMAGE_RESIZE_DRIVER** | `"gd"`      | string     | Image driver (`gd` or `imagick`).                                                           |
| **IMAGE_RESIZE_DISK** | `"local"`   | string     | Default storage disk.                                                                       |
| **IMAGE_RESIZE_DISKS** | `""`        | CSV string | Comma-separated list of disks to search *for meta files*. Example: `"local,s3,backups"`. |

All parameters live in `plugins/mercator/queuedresize/config/config.php`.

---

## 🧩 Usage

### In Twig templates

```
{# Default disk, default format (jpg) #}
{{ qresize('media/uploads/pic.jpg', 1600) }}

{# Specify disk, quality, and format (webp) #}
{{ qresize('media/uploads/pic.jpg', 1600, null, {'disk': 's3', 'quality': 75, 'format': 'webp'}) }}

{# Auto-select format based on browser (WebP or JPG) #}
{{ qresize('media/uploads/pic.jpg', 800, 600, {'mode': 'crop', 'format': 'best'}) }}
```

When a new image is requested, it queues a `ProcessImageResize` job.
Subsequent requests return the cached version instantly.

-----

## 🧠 Available Runtime Options (`opts`)

| Option      | Type   | Default       | Description                                                 |
| ----------- | ------ | ------------- | ----------------------------------------------------------- |
| **mode** | string | `"auto"`      | Resize mode: `auto` (scaleDown), `fit` (scaleDown), or `crop`. |
| **quality** | int    | `60`          | Output quality (1–100). Used for `jpg` and `webp`.         |
| **disk** | string | *(config)* | Target filesystem disk. Defaults to `IMAGE_RESIZE_DISK`.      |
| **format** | string | `"jpg"`       | Output format: `jpg`, `webp`, `png`, `gif`, or `best`.      |
| *(others)* | mixed  | —             | Custom values stored in meta JSON but ignored by processor. |

### Resize Modes

| Mode   | Description                                    |
| ------ | ---------------------------------------------- |
| `auto` | Scales proportionally to fit width or height.  |
| `fit`  | Scales within bounds, preserving aspect ratio. |
| `crop` | Crops to exact dimensions (centered).          |

### Format Option

| Format  | Description                                                         |
| ------- | ------------------------------------------------------------------- |
| `jpg`   | (Default) Outputs a JPEG image.                                     |
| `webp`  | Outputs a WebP image.                                               |
| `png`   | Outputs a PNG image.                                                |
| `gif`   | Outputs a GIF image.                                                |
| `best`  | Serves `webp` if the browser `Accept` header includes it, else `jpg`. |

-----

## 🧾 Meta JSON

Each resized image produces a matching `.json` file with details. The hash is now based on all parameters *plus* the source file's `mtime` and `size` to bust the cache when the original is updated.

```
{
  "src": "media/uploads/pic.jpg",
  "w": 1600,
  "h": null,
  "opts": {
    "disk": "s3",
    "format": "webp",
    "quality": 75
  },
  "disk": "s3",
  "mtime": 1678886400,
  "size": 123456
}
```

-----

## 📁 Storage Layout

Two-character subdirectories by hash. The file extension matches the `format` option.

```
resized/<aa>/<bb>/<cc>/<hash>.<ext>
resized/<aa>/<bb>/<cc>/<hash>.json
```

(e.g., `resized/f3/0a/1b/f30a1b...c9.webp` and `resized/f3/0a/1b/f30a1b...c9.json`)

Both are stored on the **same disk** (the one specified in `opts` or the default).

-----

## 🧰 Queue Control

| Env                            | Default     | Purpose                  |
| ------------------------------ | ----------- | ------------------------ |
| `IMAGE_RESIZE_QUEUE`           | `"imaging"` | Queue name for jobs.     |
| `IMAGE_RESIZE_CONCURRENCY`     | `3`         | Max jobs in parallel.    |
| `IMAGE_RESIZE_BACKOFF_SECONDS` | `5`         | Retry delay on overload. |

Run the worker:

```
php artisan queue:work --queue=imaging
```

-----

## 🔍 Multi-Disk Behavior

When serving `/queuedresize/{hash}`:

1.  The system searches all configured disks (from `IMAGE_RESIZE_DISK` and `IMAGE_RESIZE_DISKS`) to find the **meta file** (`<hash>.json`).
2.  Once the meta file is found, it **reads the `disk` property** from inside that JSON file. This is the *intended* disk.
3.  It then **only** looks for the resized image (e.g., `<hash>.webp`) on that *one intended disk*.
4.  If the image is not found on that disk, it queues a job to generate it and save it *to that same disk*.

This ensures that an image requested for `s3` is only ever generated, stored, and served from `s3`, even if other disks are configured.

-----

## 💡 Examples

### Twig

```
{# Crop to 800x600 #}
{{ qresize('media/uploads/hero.jpg', 800, 600, {'mode': 'crop'}) }}

{# Create a 400px high WebP on S3 with 90% quality #}
{{ qresize('media/uploads/logo.png', null, 400, {'disk': 's3', 'quality': 90, 'format': 'webp'}) }}

{# Let the browser decide between WebP and JPG #}
{{ qresize('media/uploads/avatar.jpg', 150, 150, {'mode': 'crop', 'format': 'best'}) }}
```

### PHP (Dispatching a Job)

```
dispatch(new \Mercator\QueuedResize\Jobs\ProcessImageResize(
    'media/uploads/banner.jpg', 1200, null, ['mode'=>'fit', 'disk'=>'backups', 'format'=>'jpg']
));
```

-----

## ✅ Summary

| Feature                                      | Supported |
| -------------------------------------------- | --------- |
| Asynchronous queue processing                | ✅         |
| On-demand image resizing                     | ✅         |
| Multi-disk (local, s3, etc.) meta-first logic| ✅         |
| Source file change detection (mtime/size)    | ✅         |
| Multiple output formats (JPG, WebP, PNG)     | ✅         |
| "Best" format browser negotiation            | ✅         |
| Two-character subdirectory nesting           | ✅         |
| Meta JSON beside image                       | ✅         |
| GD / Imagick drivers                         | ✅         |

-----

© 2015 by mercator.li / Helmut Kaufmann
