<?php namespace Mercator\QueuedResize\Classes;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImDriver;

class ImageResizer
{
    protected string $disk;
    protected ImageManager $manager;

    public function __construct()
    {
        $drv = strtolower((string) config("mercator.queuedresize::config.driver", "gd"));
        $driver = $drv === "imagick" ? new ImDriver() : new GdDriver();
        $this->manager = new ImageManager($driver);
        $this->disk = (string) config('mercator.queuedresize::config.disk', 'local');
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
     */
    protected function getSourceStats(string $src): array
    {
        $mtime = null;
        $size = null;

        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            // Cannot get mtime/size from URL reliably
            return ['mtime' => null, 'size' => null];
        }

        try {
            if (Storage::disk($this->disk)->exists($src)) {
                $mtime = Storage::disk($this->disk)->lastModified($src);
                $size = Storage::disk($this->disk)->size($src);
                return ['mtime' => $mtime, 'size' => $size];
            }
        } catch (\Exception $e) {
             // Fails on disks that don't support it, or if file not found
             \Log::warning('QueuedResize: Could not get stats from disk.', ['src' => $src, 'disk' => $this->disk, 'error' => $e->getMessage()]);
        }

        // Fallback for absolute local paths
        if (file_exists($src)) {
            return ['mtime' => filemtime($src), 'size' => filesize($src)];
        }

        return ['mtime' => null, 'size' => null];
    }

    public function hash(string $src, ?int $w, ?int $h, array $opts, ?int $mtime = null, ?int $size = null): string
    {
        // Disk is intentionally NOT part of the hash
        // so the same job across disks shares the same key.
        ksort($opts);
        $opts2 = $opts;
        unset($opts2['disk']);
        //
        // *** FIX: Added missing concatenation operator before (int) $h ***
        //
        return sha1($src . '|' . (int) $w . '|' . (int) $h . '|' . (int) $mtime . '|' . (int) $size . '|' . json_encode($opts2));
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
        return Storage::disk($this->disk)->path($this->nestedPath('resized', $hash, $ext));
    }

    public function cachedUrl(string $hash): string
    {
        return url('/queuedresize/' . $hash);
    }

    public function ensureCacheDir(string $hash, string $ext = 'jpg'): void
    {
        $dir = \dirname(Storage::disk($this->disk)->path($this->nestedPath('resized', $hash, $ext)));
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function exists(string $hash, string $ext = 'jpg'): bool
    {
        $rel = $this->nestedPath('resized', $hash, $ext);
        return Storage::disk($this->disk)->exists($rel);
    }

    public function resizeNow(string $src, ?int $w, ?int $h, array $opts): string
    {
        $W = $w && $w > 0 ? $w : null;
        $H = $h && $h > 0 ? $h : null;
        ksort($opts);
        if (isset($opts['disk']) && is_string($opts['disk']) && $opts['disk'] !== '') {
            $this->setDisk($opts['disk']);
        }

        // Get mtime/size for hash
        ['mtime' => $mtime, 'size' => $size] = $this->getSourceStats($src);

        // Determine format
        $format = strtolower($opts['format'] ?? 'jpg');
        if ($format === 'best') $format = 'jpg'; // Fallback
        if (!in_array($format, ['jpg', 'webp', 'png', 'gif', 'avif'])) $format = 'jpg';
        if ($format == 'jpeg') $format = 'jpg';
        
        $opts['format'] = $format; // Ensure format is in opts for hashing
        ksort($opts); // Re-sort after adding format

        $hash = $this->hash($src, $W, $H, $opts, $mtime, $size);
        $this->ensureCacheDir($hash, $format);
        $out = $this->cachedPathFromHash($hash, $format);
        if ($this->exists($hash, $format)) {
            return $out;
        }

        $input = $this->readSource($src);
        $img = $this->manager->read($input);

        $mode = $opts['mode'] ?? 'auto';
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

        // write meta JSON next to the image, same disk and folder
        $metaRel = $this->nestedPath('resized', $hash, 'json');
        Storage::disk($this->disk)->put(
            $metaRel,
            json_encode([
                'src'=>$src,
                'w'=>$W,
                'h'=>$H,
                'opts'=>$opts,
                'disk'=>$this->disk,
                'mtime' => $mtime,
                'size' => $size
            ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
        );

        return $out;
    }

    protected function readSource(string $src)
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src; // Intervention can read from URL
        }

        // Try to read from the set disk
        try {
            if (Storage::disk($this->disk)->exists($src)) {
                 // Return the raw content, as Intervention can read this
                return Storage::disk($this->disk)->get($src);
            }
        } catch (\Exception $e) {
             // Could be a non-local disk where path() fails, etc.
             // Fallback to checking local filesystem
        }

        // Fallback for absolute local paths
        if (file_exists($src)) {
            return $src;
        }
        
        throw new \RuntimeException('Source not found on disk "' . $this->disk . '": ' . $src);
    }
}