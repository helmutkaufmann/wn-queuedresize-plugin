<?php namespace Mercator\QueuedResize;

use Illuminate\Support\Facades\Facade;
use System\Classes\PluginBase;
use Illuminate\Support\Facades\Storage;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name' => 'Queued Resize',
            'description' => 'Asynchrones Image-Resizing',
            'author' => 'Mercator a.k.a Helmut Kaufmann',
            'icon' => 'icon-image',
        ];
    }

    public function registerMarkupTags()
    {
        return [
            'filters' => [ 'qresize' => [$this, 'processQueuedResize'] ],
            'functions' => [ 'qresize' => [$this, 'processQueuedResize'] ],
        ];
    }

    /**
     * qresize(src, w, h, opts = { mode: 'auto', quality: 60, disk?: 's3' })
     */
    public function processQueuedResize($src, $w = null, $h = null, array $opts = [])
    {
        /** @var \Mercator\QueuedResize\Classes\ImageResizer $resizer */
        $resizer = app(\Mercator\QueuedResize\Classes\ImageResizer::class);

        $W = $w && $w > 0 ? (int) $w : null;
        $H = $h && $h > 0 ? (int) $h : null;
        ksort($opts);
        
        // Determine disk
        $disk = isset($opts['disk']) && is_string($opts['disk']) && $opts['disk'] !== ''
            ? (string) $opts['disk']
            : (string) config('mercator.queuedresize::config.disk', 'local');
        $resizer->setDisk($disk); // Set disk on resizer *early*

        // Determine format
        $format = strtolower($opts['format'] ?? 'jpg');
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'image/webp') && $format === 'best') {
            $format = 'webp';
        } elseif ($format === 'best') {
            $format = 'jpg';
        }
        if (!in_array($format, ['jpg', 'webp', 'png', 'gif', 'avif'])) $format = 'jpg';
        if ($format == 'jpeg') $format = 'jpg';
        
        $opts['format'] = $format; // Ensure format is in opts for hashing
        ksort($opts); // Re-sort

        // Get mtime/size
        $mtime = null;
        $size = null;
        try {
            if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                // Cannot get stats from URL
            } elseif (Storage::disk($disk)->exists($src)) {
                $mtime = Storage::disk($disk)->lastModified($src);
                $size = Storage::disk($disk)->size($src);
            } elseif (file_exists($src)) { // Fallback for absolute local paths
                $mtime = filemtime($src);
                $size = filesize($src);
            }
        } catch (\Exception $e) {
            \Log::warning('QueuedResize: Could not get stats in Twig tag.', ['src' => $src, 'disk' => $disk, 'error' => $e->getMessage()]);
        }
        
        $hash = $resizer->hash($src, $W, $H, $opts, $mtime, $size);

        // ensure meta JSON next to image
        $metaRel = $resizer->nestedPath('resized', $hash, 'json');
        if (!Storage::disk($disk)->exists($metaRel)) {
            $resizer->ensureCacheDir($hash, $format); // Use format here
            Storage::disk($disk)->put(
                $metaRel,
                json_encode([
                    'src'=>$src,
                    'w'=>$W,
                    'h'=>$H,
                    'opts'=>$opts,
                    'disk'=>$disk,
                    'mtime' => $mtime,
                    'size' => $size
                ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
            );
        }

        // Check existence using format
        if (!$resizer->exists($hash, $format)) {
            dispatch(
                (new \Mercator\QueuedResize\Jobs\ProcessImageResize($src, $W, $H, $opts))
                    ->onQueue(config('mercator.queuedresize::config.queue', 'imaging'))
            );
        }

        return $resizer->cachedUrl($hash);
    }
}