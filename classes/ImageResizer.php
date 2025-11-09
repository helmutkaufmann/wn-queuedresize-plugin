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

    public function hash(string $src, ?int $w, ?int $h, array $opts): string
    {
        // Disk is intentionally NOT part of the hash
        // so the same job across disks shares the same key.
        ksort($opts);
        $opts2 = $opts;
        unset($opts2['disk']);
        return sha1($src . '|' . (int) $w . '|' . (int) $h . '|' . json_encode($opts2));
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

    public function cachedPathFromHash(string $hash): string
    {
        return Storage::disk($this->disk)->path($this->nestedPath('resized', $hash, 'jpg'));
    }

    public function cachedUrl(string $hash): string
    {
        return url('/queuedresize/' . $hash);
    }

    public function ensureCacheDir(string $hash): void
    {
        $dir = \dirname(Storage::disk($this->disk)->path($this->nestedPath('resized', $hash, 'jpg')));
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function exists(string $hash): bool
    {
        $rel = $this->nestedPath('resized', $hash, 'jpg');
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

        $hash = $this->hash($src, $W, $H, $opts);
        $this->ensureCacheDir($hash);
        $out = $this->cachedPathFromHash($hash);
        if ($this->exists($hash)) {
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

        $img->toJpeg($quality)->save($out);
        // write meta JSON next to the image, same disk and folder
        $metaRel = $this->nestedPath('resized', $hash, 'json');
        Storage::disk($this->disk)->put(
            $metaRel,
            json_encode(['src'=>$src, 'w'=>$W, 'h'=>$H, 'opts'=>$opts, 'disk'=>$this->disk], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
        );

        return $out;
    }

    protected function readSource(string $src)
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }
        if (str_starts_with($src, 'media/')) {
            $path = Storage::disk($this->disk)->path($src);
            if (!file_exists($path)) {
                throw new \RuntimeException('Source not found: ' . $path);
            }
            return $path;
        }
        if (Storage::disk($this->disk)->exists($src)) {
            return Storage::disk($this->disk)->path($src);
        }
        if (file_exists($src)) {
            return $src;
        }
        throw new \RuntimeException('Source not found: ' . $src);
    }
}
