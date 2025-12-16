<?php namespace Mercator\QueuedResize\Classes;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\File as HttpFile;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImDriver;
use Imagick;
use Symfony\Component\Console\Style\OutputStyle;

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
     * Get stats for hashing (mtime + size).
     */
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
        } catch (\Exception $e) {
            // Ignore on remote disks (S3)
        }
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
        // 1. URLs
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }

        $storage = Storage::disk($this->disk);

        // OPTIMIZATION: Try to use absolute path for local disks (avoids loading file content into PHP memory)
        try {
            $fullPath = $storage->path($src);
            if (file_exists($fullPath)) {
                return $fullPath; // Returns file system path
            }
        } catch (\Exception $e) {}

        // Fallback: Read file content into memory (for S3 / Remote disks)
        try {
            if ($storage->exists($src)) {
                return $storage->get($src); // Returns file content (string/blob)
            }
        } catch (\Exception $e) {}

        if (file_exists($src)) {
            return $src;
        }

        throw new \RuntimeException('Source not found on disk "' . $this->disk . '": ' . $src);
    }

    /**
     * Core Resizing Logic
     */
    public function resizeNow(string $src, ?int $w, ?int $h, array $opts): string
    {
        $W = $w && $w > 0 ? $w : null;
        $H = $h && $h > 0 ? $h : null;

        // 1. EXTRACT FORCE FLAG (before hashing)
        $force = !empty($opts['force']);
        unset($opts['force']); // Critical: Remove force from options so it doesn't change the hash

        ksort($opts);

        if (isset($opts['disk']) && is_string($opts['disk']) && $opts['disk'] !== '') {
            $this->setDisk($opts['disk']);
        }

        ['mtime' => $mtime, 'size' => $size] = $this->getSourceStats($src);

        // Determine File Type (PDF support)
        $srcPathForExt = parse_url($src, PHP_URL_PATH) ?? $src;
        $srcExt        = strtolower(pathinfo($srcPathForExt, PATHINFO_EXTENSION));

        // Determine Format
        $format = strtolower($opts['format'] ?? 'best');
        switch ($format) {
            case 'best':
                $format = $this->client_supports_webp() ? 'webp' : 'jpg';
                break;
            case 'avif':
            case 'jpeg':
                $format = 'jpg';
                break;
            default: break;
        }

        $opts['format'] = $format;
        ksort($opts);

        // Hashing
        $hash = $this->hash($src, $W, $H, $opts, $mtime, $size);
        $relPath = $this->nestedPath('resized', $hash, $format);

        // 2. CHECK FORCE FLAG
        if (!$force && Storage::disk($this->disk)->exists($relPath)) {
             return $this->cachedPathFromHash($hash, $format);
        }
        
        $this->ensureCacheDir($hash, $format);
        $input = $this->readSource($src);

        // PDF Processing (Optimized for Memory)
        if ($srcExt === 'pdf') {
            if (!class_exists(Imagick::class)) {
                throw new \RuntimeException('PDF support requires Imagick extension.');
            }
            $imagick = new Imagick();
            
            try {
                // OPTIMIZATION 1: Set low resolution (72 DPI) before reading
                $imagick->setResolution(72, 72); 
                $imagick->setBackgroundColor('white');

                if (@is_file($input)) {
                    // OPTIMIZATION 2: Load ONLY page 0 directly from disk path + '[0]'
                    $imagick->readImage($input . '[0]'); 
                } else {
                    // Fallback for non-local disks (blobs)
                    $imagick->readImageBlob($input);
                    $imagick->setIteratorIndex(0);
                }
                
                // Flatten layers
                $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompressionQuality(90);
                
                // Overwrite $input with the generated JPG blob
                $input = $imagick->getImageBlob();
                
                $imagick->clear();
                $imagick->destroy();
                
            } catch (\Exception $e) {
                // Throw exception to be caught in batchResizeDirectory
                throw new \RuntimeException('Failed to process PDF: ' . $src . ' - ' . $e->getMessage()); 
            }
        }

        // Intervention Processing
        $img = $this->manager->read($input);
        
        $mode    = $opts['mode'] ?? 'auto';
        $quality = (int) ($opts['quality'] ?? 60);

        switch ($mode) {
            case 'crop':
                if ($W && $H) $img = $img->cover($W, $H, 'center');
                else $img = $img->scaleDown($W, $H);
                break;
            case 'fit':
            default:
                $img = $img->scaleDown($W, $H);
                break;
        }

        // Save to Temp & Upload
        $tempFile = tempnam(sys_get_temp_dir(), 'resize_out_');
        
        try {
            switch ($format) {
                case 'webp': $img->toWebp($quality)->save($tempFile); break;
                case 'png':  $img->toPng()->save($tempFile); break;
                case 'gif':  $img->toGif()->save($tempFile); break;
                case 'jpg': 
                default:     $img->toJpeg($quality)->save($tempFile); break;
            }

            Storage::disk($this->disk)->putFileAs(
                dirname($relPath), 
                new HttpFile($tempFile), 
                basename($relPath)
            );

        } finally {
            if (file_exists($tempFile)) @unlink($tempFile);
        }

        // Write Meta
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

        return $this->cachedPathFromHash($hash, $format);
    }

    /**
     * Batch Resize Directory (Optimized for Scanning)
     */
    public function batchResizeDirectory(string $path, array $dims, array $formats, array $baseOpts, bool $recursive, ?OutputStyle $output = null)
    {
        $storage = Storage::disk($this->disk);
        
        if ($output) $output->write("Scanning files... ");
        
        $files = $recursive ? $storage->allFiles($path) : $storage->files($path);
        
        // Filter by Extension (Fast, CPU-only check)
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'avif'];
        
        $targetFiles = array_filter($files, function($f) use ($allowedExtensions) {
            // Skip the resized cache directory itself
            if (str_contains($f, 'resized/')) return false;

            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            return in_array($ext, $allowedExtensions);
        });
        
        $total = count($targetFiles);
        if ($output) {
            $output->writeln("Found $total images.");
            $bar = $output->createProgressBar($total);
            $bar->start();
        }

        $count = 0;
        
        foreach ($targetFiles as $file) {
            // Loop Dimensions
            foreach ($dims as $dim) {
                $w = $dim['w'];
                $h = $dim['h'];

                // Loop Formats
                foreach ($formats as $fmt) {
                    $opts = $baseOpts;
                    $opts['format'] = trim($fmt);

                    try {
                        $this->resizeNow($file, $w, $h, $opts);
                        $count++;
                    } catch (\Exception $e) {
                        // Silent fail (if PDF failed, it was caught in resizeNow)
                    }
                }
            }

            if ($output) $bar->advance();

            // Aggressive Garbage Collection
            if ($count % 50 === 0) {
                gc_collect_cycles();
            }
        }

        if ($output) {
            $bar->finish();
            $output->newLine();
        }

        return $count;
    }

    /**
     * Prune Orphans
     */
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

    /**
     * Clear Cache
     */
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