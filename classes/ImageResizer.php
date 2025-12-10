<?php namespace Mercator\QueuedResize\Classes;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImDriver;
use Imagick;

class ImageResizer
{
    protected string $disk;
    protected ImageManager $manager;

    public function __construct()
    {
        $drv    = strtolower((string) config('mercator.queuedresize::config.driver', 'gd'));
        $driver = $drv === 'imagick' ? new ImDriver() : new GdDriver();

        $this->manager = new ImageManager($driver);
        $this->disk    = (string) config('mercator.queuedresize::config.disk', 'local');
    }

    public function setDisk(string $disk): void
    {
        $this->disk = $disk;
    }

    public function getDisk(): string
    {
        return $this->disk;
    }

    /**
     * Get mtime and size stats for the source file.
     * Expects a storage path relative to the disk root, or a local absolute path.
     * HTTP(S) URLs return null stats (still valid for hashing).
     */
    public function getSourceStats(string $src): array
    {
        $mtime = null;
        $size  = null;

        // No reliable mtime/size for remote URLs
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return ['mtime' => null, 'size' => null];
        }

        try {
            if (Storage::disk($this->disk)->exists($src)) {
                $mtime = Storage::disk($this->disk)->lastModified($src);
                $size  = Storage::disk($this->disk)->size($src);

                return ['mtime' => $mtime, 'size' => $size];
            }
        } catch (\Exception $e) {
            \Log::warning('QueuedResize: Could not get stats from disk.', [
                'src'   => $src,
                'disk'  => $this->disk,
                'error' => $e->getMessage(),
            ]);
        }

        if (file_exists($src)) {
            return ['mtime' => filemtime($src), 'size' => filesize($src)];
        }

        return ['mtime' => null, 'size' => null];
    }

    /**
     * Stable hash for one resize job.
     * Disk is intentionally NOT part of the hash.
     */
    public function hash(string $src, ?int $w, ?int $h, array $opts, ?int $mtime = null, ?int $size = null): string
    {
        ksort($opts);
        $opts2 = $opts;
        unset($opts2['disk']); // disk should not affect hash

        return sha1(
            $src . '|' .
            (int) $w . '|' .
            (int) $h . '|' .
            (int) $mtime . '|' .
            (int) $size . '|' .
            json_encode($opts2)
        );
    }

    public function hashDirs(string $hash): array
    {
        $h = strtolower(preg_replace('/[^a-f0-9]/i', '', $hash));
        $a = substr($h, 0, 2) ?: '00';
        $b = substr($h, 2, 2) ?: '00';
        $c = substr($h, 4, 2) ?: '00';

        return [$a, $b, $c];
    }

    public function nestedPath(string $base, string $hash, string $ext): string
    {
        [$a, $b, $c] = $this->hashDirs($hash);

        return trim($base, '/') . "/{$a}/{$b}/{$c}/{$hash}.{$ext}";
    }

    public function cachedPathFromHash(string $hash, string $ext = 'jpg'): string
    {
        return Storage::disk($this->disk)->path(
            $this->nestedPath('resized', $hash, $ext)
        );
    }

    public function cachedUrl(string $hash): string
    {
        return url('/queuedresize/' . $hash);
    }

    public function ensureCacheDir(string $hash, string $ext = 'jpg'): void
    {
        $dir = \dirname(
            Storage::disk($this->disk)->path(
                $this->nestedPath('resized', $hash, $ext)
            )
        );

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function exists(string $hash, string $ext = 'jpg'): bool
    {
        $rel = $this->nestedPath('resized', $hash, $ext);

        return Storage::disk($this->disk)->exists($rel);
    }

    protected function client_supports_webp(): bool
    {
        if (!isset($_SERVER['HTTP_ACCEPT'])) {
            return false;
        }

        return stripos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }

    /**
     * Main resize entry point used by the queued job.
     * $src can be:
     * - storage path (e.g. "media/foo.jpg")
     * - absolute filesystem path
     * - http(s) URL
     */
    public function resizeNow(string $src, ?int $w, ?int $h, array $opts): string
    {
        $W = $w && $w > 0 ? $w : null;
        $H = $h && $h > 0 ? $h : null;
        ksort($opts);

        // Disk override in options
        if (isset($opts['disk']) && is_string($opts['disk']) && $opts['disk'] !== '') {
            $this->setDisk($opts['disk']);
        }

        // File stats for hashing
        ['mtime' => $mtime, 'size' => $size] = $this->getSourceStats($src);

        // Detect source extension (for PDF handling)
        $srcPathForExt = parse_url($src, PHP_URL_PATH) ?? $src;
        $srcExt        = strtolower(pathinfo($srcPathForExt, PATHINFO_EXTENSION));

        // Determine output format
        $format = strtolower($opts['format'] ?? 'best');
        switch ($format) {
            case 'best':
                $format = $this->client_supports_webp() ? 'webp' : 'jpg';
                break;
            case 'avif':
            case 'jpeg':
                $format = 'jpg';
                break;
            default:
                // jpg/png/gif/webp etc are passed through
                break;
        }

        $opts['format'] = $format;
        ksort($opts);

        // Hash must use the same $src string as the filter
        $hash = $this->hash($src, $W, $H, $opts, $mtime, $size);

        $this->ensureCacheDir($hash, $format);
        $out = $this->cachedPathFromHash($hash, $format);

        if ($this->exists($hash, $format)) {
            return $out;
        }

        // Read source (may be path, URL, or binary contents)
        $input = $this->readSource($src);

        // If source is a PDF: render first page to JPEG blob using Imagick
        if ($srcExt === 'pdf') {
            if (!class_exists(Imagick::class)) {
                throw new \RuntimeException('PDF support requires the Imagick PHP extension.');
            }

            $imagick = new Imagick();

            $tmpPdf = tempnam(sys_get_temp_dir(), 'pdfsrc_');
            if ($tmpPdf === false) {
                throw new \RuntimeException('Could not create temporary file for PDF rendering.');
            }

            file_put_contents($tmpPdf, $input);
            $imagick->readImage($tmpPdf . '[0]');
            @unlink($tmpPdf);

            $imagick->setImageColorspace(Imagick::COLORSPACE_RGB);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
            $imagick->setImageCompressionQuality(90);

            // Now we have a JPEG blob – feed this into Intervention
            $input = $imagick->getImageBlob();

            $imagick->clear();
            $imagick->destroy();
        }

        // At this point, $input is a "normal" image (binary or path)
        $img = $this->manager->read($input);

        $mode    = $opts['mode'] ?? 'auto';
        $quality = (int) ($opts['quality'] ?? 60);

        switch ($mode) {
            case 'crop':
                if ($W && $H) {
                    $img = $img->cover($W, $H, 'center');
                } else {
                    $img = $img->scaleDown($W, $H);
                }
                break;
            case 'fit':
            default:
                $img = $img->scaleDown($W, $H);
                break;
        }

        // Save with correct format
        switch ($format) {
            case 'webp':
                $img->toWebp($quality)->save($out);
                break;
            case 'png':
                $img->toPng()->save($out);
                break;
            case 'gif':
                $img->toGif()->save($out);
                break;
            case 'jpg':
            default:
                $img->toJpeg($quality)->save($out);
                break;
        }

        // Write meta JSON next to the image, same disk and folder
        $metaRel = $this->nestedPath('resized', $hash, 'json');

        Storage::disk($this->disk)->put(
            $metaRel,
            json_encode([
                'src'   => $src,
                'w'     => $W,
                'h'     => $H,
                'opts'  => $opts,
                'disk'  => $this->disk,
                'mtime' => $mtime,
                'size'  => $size,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $out;
    }

    /**
     * Read source image.
     * - HTTP(S) → return URL (Intervention can read it directly)
     * - Otherwise try Storage disk, then local filesystem.
     */
    protected function readSource(string $src)
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src; // Intervention can read from URL
        }

        // Try to read from the configured disk
        try {
            if (Storage::disk($this->disk)->exists($src)) {
                // Return the raw content, as Intervention can read this
                return Storage::disk($this->disk)->get($src);
            }
        } catch (\Exception $e) {
            // Fallback to checking local filesystem
        }

        // Fallback for absolute local paths
        if (file_exists($src)) {
            return $src;
        }

        throw new \RuntimeException(
            'Source not found on disk "' . $this->disk . '": ' . $src
        );
    }
}