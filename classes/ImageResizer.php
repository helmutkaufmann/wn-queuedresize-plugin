<?php namespace Mercator\QueuedResize\Classes;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager; 
use Intervention\Image\Drivers\Gd\Driver as GdDriver; 
use Intervention\Image\Drivers\Imagick\Driver as ImDriver;

class ImageResizer
{
    protected ImageManager $manager;

    public function __construct()
    {
        $drv = strtolower((string) config("mercator.queuedresize::config.driver", "gd"));
        $driver = $drv === "imagick" ? new ImDriver() : new GdDriver();
        $this->manager = new ImageManager($driver);
    }

    public function hash(string $src, ?int $w, ?int $h, array $opts): string
    {
        ksort($opts);
        return sha1($src . "|" . (int) $w . "|" . (int) $h . "|" . json_encode($opts));
    }

    public function cachedPathFromHash(string $hash): string
    {
        return storage_path("app/resized/" . $hash . ".jpg");
    }

    public function cachedUrl(string $hash): string
    {
        return url("/queuedresize/" . $hash);
    }

    public function ensureCacheDir(): void
    {
        $dir = storage_path("app/resized");
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function exists(string $hash): bool
    {
        return file_exists($this->cachedPathFromHash($hash));
    }

    public function resizeNow(string $src, ?int $w, ?int $h, array $opts): string
    {
        $this->ensureCacheDir();
        $hash = $this->hash($src, $w, $h, $opts);
        $out = $this->cachedPathFromHash($hash);
        if ($this->exists($hash)) {
            return $out;
        }

        $input = $this->readSource($src);
        $img = $this->manager->read($input); // v3: read()

        // --- normalize 0 to null for Intervention ---
        $W = $w && $w > 0 ? $w : null;
        $H = $h && $h > 0 ? $h : null;

        $mode = $opts["mode"] ?? "auto";
        $quality = (int) ($opts["quality"] ?? 82);
        $upsize = (bool) ($opts["upsize"] ?? false);

        // --- apply transformation safely ---
        switch ($mode) {
            case "crop":
                if ($W && $H) {
                    $img = $img->cover($W, $H, "center");
                } else {
                    $img = $img->scaleDown($W, $H);
                }
                break;

            case "fit":
                $img = $img->scaleDown($W, $H);
                break;

            default:
                $img = $img->scaleDown($W, $H);
                break;
        }

        // --- save output ---
        $img->toJpeg($quality)->save($out);
        return $out;
    }

    protected function readSource(string $src)
    {
        if (str_starts_with($src, "http://") || str_starts_with($src, "https://")) {
            return $src; // v3 kann URL direkt lesen
        }
        if (str_starts_with($src, "media/")) {
            $path = storage_path("app/" . $src);
            if (!file_exists($path)) {
                throw new \RuntimeException("Source not found: " . $path);
            }
            return $path;
        }
        if (Storage::exists($src)) {
            return Storage::path($src);
        }
        if (file_exists($src)) {
            return $src;
        }
        throw new \RuntimeException("Source not found: " . $src);
    }
}