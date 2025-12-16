
# Queued Resize Plugin for WinterCMS (Mercator.QueuedResize)
This plugin provides an asynchronous and memory-efficient solution for handling image resizing in WinterCMS. By offloading resource-intensive image processing to the Laravel Queue, it prevents synchronous page loads from becoming slow and avoids server exhaustion, making it ideal for large media libraries and high-traffic environments.

### Features 
| Summary| Category | Feature | Benefit |
| --- | --- | --- |
| **Performance** | Asynchronous Processing | User does not wait for image creation; page loads remain fast. |
| **Optimization** | Local Disk Path Handling | Avoids unnecessary memory consumption by reading file paths instead of entire file content into PHP. |
| **Resilience** | Memory-Efficient PDF Handling | Optimizes ImageMagick to process PDFs at low DPI and only the first page, preventing memory leaks and crashes. |
| **Control** | CLI Tools (`warmup`, `clear`, `prune`) | Provides complete cache and maintenance control via the command line. |
| **Stability** | Concurrency Control | Uses a semaphore lock to cap simultaneous resize jobs, protecting server CPU/RAM from overload. |

---

## 1. Installation and Configuration (`.env`)
The plugin uses a dedicated configuration file (`config.php`) which pulls its values from your main `.env` file. You should place these variables in your environment file to easily manage your setup across different deployments (staging, production).

### `.env` Variables
| Variable | Default Value | Description | Used In |
| --- | --- | --- | --- |
| `IMAGE_RESIZE_QUEUE` | `imaging` | The name of the queue the resize jobs will be dispatched to. | `ProcessImageResize.php` |
| `IMAGE_RESIZE_DRIVER` | `gd` | The Intervention Image driver to use: `gd` or `imagick`. | `ImageResizer.php` |
| `IMAGE_RESIZE_DISK` | `local` | The default storage disk for reading source files and writing cache files. | `All components` |
| `IMAGE_RESIZE_QUALITY` | `80` | The fallback JPEG/WebP output quality (0-100). | `ImageResizer.php` |
| `IMAGE_RESIZE_CONCURRENCY` | `3` | **(Critical)** The maximum number of simultaneous resize jobs that can run at once. | `ProcessImageResize.php` |
| `IMAGE_RESIZE_BACKOFF` | `5` | The number of seconds a job should wait before being re-released to the queue if the concurrency limit is reached. | `ProcessImageResize.php` |

### Starting Queue Workers
The plugin is useless without a running queue worker. You should use a process manager (like Supervisor) to ensure this command runs continuously:
```bash
# Start a worker process dedicated to the 'imaging' queue
php artisan queue:work --queue=imaging --tries=3 --timeout=3600

```
---

## 2. Twig Filter: `qresize` - Detailed Parameters
The `qresize` filter is the primary tool for using the plugin in your templates. It instantly returns a URL, deferring the actual work to the background.

### Twig Syntax
```twig
{{ image.path | qresize(width, height, options) }}

```

### Parameters
| Parameter | Type | Required | Description | Example |
| --- | --- | --- | --- | --- |
| **`src`** | `string` | Yes | The source path to the original image file. | `image.path` |
| **`width`** | `int/null` | No | The desired output width in pixels. Must be provided if `height` is not. | `400` |
| **`height`** | `int/null` | No | The desired output height in pixels. Must be provided if `width` is not. | `300` |
| **`options`** | `array` | No | An array of key/value pairs to control the resizing process. | `{'mode': 'crop', 'quality': 90}` |

### Options Array Keys (`opts`)
These control the *specific* resizing job:
| Key | Type | Description | Default (from config) |
| --- | --- | --- | --- |
| **`mode`** | `string` | Resizing mode: determines how the image fits the target dimensions. Options are: `auto`, `crop`, or `fit`. | `'auto'` |
| **`quality`** | `int` | Output quality for JPEG/WebP (0-100). | `80` |
| **`format`** | `string` | Output format: `jpg`, `webp`, `png`, `gif`, or `best` (uses WebP if client supports it, otherwise JPG). | `'best'` |
| **`disk`** | `string` | **Overrides** the default disk to read the source from and write the output to for this single image request. | `'local'` |

---

## 3. Command Line Interface (CLI) - All Parameters
The CLI commands are used for mass generation and maintenance.

### A. `queuedresize:warmup` (Batch Processing)
Recursively generates resized images for a directory.
| Parameter | Alias | Required | Description | Example Value |
| --- | --- | --- | --- | --- |
| **`path`** | (argument) | Yes | The relative folder path to scan for original images (e.g., inside `storage/app/media`). | `"media/gallery"` |
| **`--width`** |  | No | Target width(s) (comma-separated for multiple versions). | `400,800` |
| **`--height`** |  | No | Target height(s) (comma-separated for multiple versions). | `200` |
| **`--format`** |  | No | Output formats (comma-separated). **Note:** Each format is generated for every size. | `jpg,webp` |
| **`--recursive`** | `-r` | No | Scans subdirectories within the specified `path`. | `(flag only)` |
| **`--force`** | `-f` | No | **Forces** regeneration and overwrites existing cache files, even if the cache is valid. | `(flag only)` |
| **`--disk`** |  | No | The specific storage disk to operate on. | `uploads` |
| **`--mode`** |  | No | Resize mode (auto, crop, fit). | `crop` |
| **`--quality`** |  | No | Output quality (0-100). | `90` |

** Example Usage (Best Practice):**

```bash
# Execute with high memory and force regeneration of two sizes (400px wide, 800px wide)
php -d memory_limit=1024M artisan queuedresize:warmup "media/gallery" --width=400,800 --format=jpg,webp --recursive --force

```

### B. `queuedresize:clear` (Cache Cleanup)
Removes resized images based on filter criteria.

| Parameter | Alias | Required | Description | Example Value |
| --- | --- | --- | --- | --- |
| **`--disk`** |  | No | The storage disk to clear the cache from. | `local` |
| **`--width`** |  | No | Filter and clear only images matching this target width. | `400` |
| **`--height`** |  | No | Filter and clear only images matching this target height. | `300` |
| **`--path`** |  | No | Filter by a segment of the original source file path. | `products/banners` |
| **`--dry-run`** |  | No | Displays which files would be cleared without deleting them. | `(flag only)` |

**Example Usage:**

```bash
# Clear all cached images related to the 'uploads/temp' directory (Dry Run)
php artisan queuedresize:clear --path=uploads/temp --dry-run

```

###  C. `queuedresize:prune` (Orphan Removal)
Removes cached resized images where the original source file no longer exists (i.e., orphans).

| Parameter | Alias | Required | Description | Example Value |
| --- | --- | --- | --- | --- |
| **`--disk`** |  | No | The storage disk to prune the cache on. | `media` |
| **`--dry-run`** |  | No | Displays which orphan files would be deleted without actually deleting them. | `(flag only)` |

**Example Usage:**

```bash
# Delete all orphan files on the default disk
php artisan queuedresize:prune

```