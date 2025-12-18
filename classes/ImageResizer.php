<?php namespace Mercator\QueuedResize\Classes;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\File as HttpFile;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImDriver;
use Imagick;
use Symfony\Component\Console\Style\OutputStyle;
use Log;

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

    public function getSourceStats(string $src): array
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return ['mtime' => null, 'size' => null];
        }

        try {
            if (Storage::disk($this->disk)->exists($src)) {
                $mtime = Storage::disk($this->disk)->lastModified($src);
                $size  = Storage::disk($this->disk)->size($src);
                return ['mtime' => $mtime, 'size' => $size];
            }
        } catch (\Exception $e) {}

        if (file_exists($src)) {
            return ['mtime' => filemtime($src), 'size' => filesize($src)];
        }

        return ['mtime' => null, 'size' => null];
    }

    public function hash(string $src, ?int $w, ?int $h, array $opts, ?int $mtime = null, ?int $size = null): string
    {
        if (!array_key_exists('disk', $opts) || $opts['disk'] === null || $opts['disk'] === '') {
            $opts['disk'] = $this->disk;
        }
        ksort($opts);

        return sha1(
            $src . '|' .
            (int) $w . '|' .
            (int) $h . '|' .
            (int) $mtime . '|' .
            (int) $size . '|' .
            json_encode($opts)
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
        try {
            $path = Storage::disk($this->disk)->path(
                $this->nestedPath('resized', $hash, $ext)
            );
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        } catch (\Exception $e) {}
    }

    public function exists(string $hash, string $ext = 'jpg'): bool
    {
        $rel = $this->nestedPath('resized', $hash, $ext);
        return Storage::disk($this->disk)->exists($rel);
    }

    protected function client_supports_webp(): bool
    {
        if (app()->runningInConsole()) {
            return true; 
        }
        if (!isset($_SERVER['HTTP_ACCEPT'])) {
            return false;
        }
        return stripos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }

    protected function readSource(string $src)
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }

        $storage = Storage::disk($this->disk);

        try {
            $fullPath = $storage->path($src);
            if (file_exists($fullPath)) {
                return $fullPath; 
            }
        } catch (\Exception $e) {}

        try {
            if ($storage->exists($src)) {
                return $storage->get($src); 
            }
        } catch (\Exception $e) {}

        if (file_exists($src)) {
            return $src;
        }

        throw new \RuntimeException('Source not found: ' . $src);
    }

    public function resizeNow(string $src, ?int $w, ?int $h, array $opts): string
    {
        $W = $w && $w > 0 ? $w : null;
        $H = $h && $h > 0 ? $h : null;

        $force = !empty($opts['force']);
        unset($opts['force']); 

        ksort($opts);

        if (isset($opts['disk']) && is_string($opts['disk']) && $opts['disk'] !== '') {
            $this->setDisk($opts['disk']);
        }

        ['mtime' => $mtime, 'size' => $size] = $this->getSourceStats($src);

        $srcPathForExt = parse_url($src, PHP_URL_PATH) ?? $src;
        $srcExt        = strtolower(pathinfo($srcPathForExt, PATHINFO_EXTENSION));

        $format = strtolower($opts['format'] ?? 'best');
        switch ($format) {
            case 'best': $format = $this->client_supports_webp() ? 'webp' : 'jpg'; break;
            case 'avif':
            case 'jpeg': $format = 'jpg'; break;
            default: break;
        }
        $opts['format'] = $format;
        ksort($opts);

        $hash = $this->hash($src, $W, $H, $opts, $mtime, $size);
        $relPath = $this->nestedPath('resized', $hash, $format);

        if (!$force && Storage::disk($this->disk)->exists($relPath)) {
             return $this->cachedPathFromHash($hash, $format);
        }
        
        Log::info("qresize $src");
        
        $this->ensureCacheDir($hash, $format);
        $input = $this->readSource($src);
        $pdfTempFile = null;

        if ($srcExt === 'pdf') {
            if (!class_exists(Imagick::class)) {
                throw new \RuntimeException('Imagick needed for PDF.');
            }
            $imagick = new Imagick();
            $pdfTempFile = tempnam(sys_get_temp_dir(), 'pdf_qres_');

            try {
                $imagick->setResolution(72, 72); 
                $imagick->setBackgroundColor('white');
                if (@is_file($input)) {
                    $imagick->readImage($input . '[0]'); 
                } else {
                    $imagick->readImageBlob($input);
                    $imagick->setIteratorIndex(0);
                }
                $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompressionQuality(90);
                $imagick->writeImage($pdfTempFile); 

                $input = $pdfTempFile;
                $imagick->clear(); $imagick->destroy();
            } catch (\Exception $e) {
                if (file_exists($pdfTempFile)) @unlink($pdfTempFile);
                throw $e;
            }
        }

        $img = $this->manager->read($input);
        $mode = $opts['mode'] ?? 'auto';
        $quality = (int) ($opts['quality'] ?? 80);

        if ($mode === 'crop' && $W && $H) {
            $img = $img->cover($W, $H, 'center');
        } else {
            $img = $img->scaleDown($W, $H);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'qres_out_');
        try {
            switch ($format) {
                case 'webp': $img->toWebp($quality)->save($tempFile); break;
                case 'png':  $img->toPng()->save($tempFile); break;
                case 'jpg': 
                default:     $img->toJpeg($quality)->save($tempFile); break;
            }
            Storage::disk($this->disk)->putFileAs(dirname($relPath), new HttpFile($tempFile), basename($relPath));
        } finally {
            if (file_exists($tempFile)) @unlink($tempFile);
            if ($pdfTempFile && file_exists($pdfTempFile)) @unlink($pdfTempFile);
        }

        $metaRel = $this->nestedPath('resized', $hash, 'json');
        Storage::disk($this->disk)->put($metaRel, json_encode([
            'src' => $src, 'w' => $W, 'h' => $H, 'opts' => $opts, 'disk' => $this->disk, 'mtime' => $mtime, 'size' => $size
        ], JSON_UNESCAPED_SLASHES));

        return $this->cachedPathFromHash($hash, $format);
    }

    public function qresizePath(string $path, ?int $w, ?int $h, array $opts = [], bool $recursive = false, string $disk = null): int
    {
        $disk = $disk ?: $this->disk;
        $this->setDisk($disk);
        $storage = Storage::disk($disk);
        $targetPath = $path;
        
        if ($storage->exists($path) && !$storage->isDirectory($path)) {
            $targetPath = dirname($path);
            if ($targetPath === '.' || empty($targetPath)) $targetPath = '';
        }
        
        if (!$storage->isDirectory($targetPath) && !empty($targetPath)) return 0;

        $scanPath = empty($targetPath) ? '' : $targetPath;
        $files = $recursive ? $storage->allFiles($scanPath) : $storage->files($scanPath);
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'avif'];
        
        $count = 0;
        foreach ($files as $file) {
            if (str_contains($file, 'resized/')) continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;

            $this->queueResizeJob($file, $w, $h, $opts);
            $count++;
        }
        return $count;
    }

    protected function queueResizeJob(string $src, ?int $w, ?int $h, array $opts = []): void
    {
        $opts['disk'] = $opts['disk'] ?? $this->disk;
        $format = $opts['format'] ?? 'best';
        if ($format === 'best') $format = $this->client_supports_webp() ? 'webp' : 'jpg';
        $opts['format'] = $format;

        ['mtime' => $mtime, 'size' => $size] = $this->getSourceStats($src);
        $hash = $this->hash($src, $w, $h, $opts, $mtime, $size);
        $metaRel = $this->nestedPath('resized', $hash, 'json');
        
        if (!Storage::disk($this->disk)->exists($metaRel)) {
            $this->ensureCacheDir($hash, $format);
            Storage::disk($this->disk)->put($metaRel, json_encode([
                'src' => $src, 'w' => $w, 'h' => $h, 'opts' => $opts, 'disk' => $this->disk, 'mtime' => $mtime, 'size' => $size
            ], JSON_UNESCAPED_SLASHES));
        }

        if (!$this->exists($hash, $format)) {
            dispatch((new \Mercator\QueuedResize\Jobs\ProcessImageResize($src, $w, $h, $opts))
                ->onQueue(config('mercator.queuedresize::config.queue', 'imaging')));
        }
    }

    public function batchResizeDirectory(string $path, array $dims, array $formats, array $baseOpts, bool $recursive, ?OutputStyle $output = null)
    {
        $storage = Storage::disk($this->disk);
        if ($output) $output->write("Scanning files... ");
        
        $files = $recursive ? $storage->allFiles($path) : $storage->files($path);
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'avif'];
        
        $targetFiles = array_filter($files, function($f) use ($allowed) {
            if (str_contains($f, 'resized/')) return false;
            return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed);
        });
        
        $total = count($targetFiles);
        if ($output) {
            $output->writeln("Found $total images.");
            $bar = $output->createProgressBar($total);
            $bar->start();
        }

        $count = 0;
        foreach ($targetFiles as $file) {
            foreach ($dims as $dim) {
                foreach ($formats as $fmt) {
                    $opts = $baseOpts;
                    $opts['format'] = trim($fmt);
                    try {
                        $this->resizeNow($file, $dim['w'], $dim['h'], $opts);
                        $count++;
                    } catch (\Exception $e) {}
                }
            }
            if ($output) $bar->advance();
            if ($count % 50 === 0) gc_collect_cycles();
        }
        if ($output) { $bar->finish(); $output->newLine(); }
        return $count;
    }

    public function prune(bool $dryRun, ?OutputStyle $output = null)
    {
        $storage = Storage::disk($this->disk);
        $allFiles = $storage->allFiles('resized');
        
        $deleted = 0;
        foreach ($allFiles as $file) {
            if (!str_ends_with($file, '.json')) continue;

            try {
                $meta = json_decode($storage->get($file), true);
                if (!$meta || !isset($meta['src'])) continue;

                $src = $meta['src'];
                $srcExists = true;
                if (!str_starts_with($src, 'http')) {
                    $srcExists = $storage->exists($src);
                }

                if (!$srcExists) {
                    $format = $meta['opts']['format'] ?? 'jpg';
                    $hash = pathinfo($file, PATHINFO_FILENAME);
                    $imagePath = $this->nestedPath('resized', $hash, $format);

                    if ($output) $output->writeln(($dryRun ? '[DRY] ' : '') . "Pruning orphan: $src ($file)");

                    if (!$dryRun) {
                        $storage->delete($file);
                        $storage->delete($imagePath);
                    }
                    $deleted++;
                }
            } catch (\Exception $e) {}
        }
        return $deleted;
    }

    public function clearCache(array $filters, bool $dryRun, ?OutputStyle $output = null)
    {
        $storage = Storage::disk($this->disk);
        $allFiles = $storage->allFiles('resized');
        
        $deleted = 0;
        foreach ($allFiles as $file) {
            if (!str_ends_with($file, '.json')) continue;

            $content = $storage->get($file);
            $meta = json_decode($content, true);
            if (!$meta) continue;

            $matches = true;
            if (isset($filters['w']) && ($meta['w'] ?? null) != $filters['w']) $matches = false;
            if (isset($filters['h']) && ($meta['h'] ?? null) != $filters['h']) $matches = false;
            if (isset($filters['path']) && !str_contains($meta['src'] ?? '', $filters['path'])) $matches = false;

            if ($matches) {
                $format = $meta['opts']['format'] ?? 'jpg';
                $hash = pathinfo($file, PATHINFO_FILENAME);
                $imagePath = $this->nestedPath('resized', $hash, $format);

                if ($output) $output->writeln(($dryRun ? '[DRY] ' : '') . "Deleting: " . ($meta['src'] ?? 'unknown'));

                if (!$dryRun) {
                    $storage->delete($file);
                    $storage->delete($imagePath);
                }
                $deleted++;
            }
        }
        return $deleted;
    }
}