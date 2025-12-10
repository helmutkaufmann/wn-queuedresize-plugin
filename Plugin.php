<?php namespace Mercator\QueuedResize;

use Illuminate\Support\Facades\Storage;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'Queued Resize',
            'description' => 'Asynchrones Image-Resizing',
            'author'      => 'Mercator a.k.a Helmut Kaufmann',
            'icon'        => 'icon-image',
        ];
    }

    public function registerMarkupTags()
    {
        return [
            'filters'   => ['qresize' => [$this, 'processQueuedResize']],
            'functions' => ['qresize' => [$this, 'processQueuedResize']],
        ];
    }

    protected function client_supports_webp(): bool
    {
        if (!isset($_SERVER['HTTP_ACCEPT'])) {
            return false;
        }

        return stripos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }

    /**
     * Normalize a src so qresize can be used like resize:
     * - Accepts:
     *   * "media/foo.jpg" (storage path)
     *   * "/storage/app/media/foo.jpg" or any disk URL
     *   * full http(s) URLs (kept as-is unless they match disk base URL)
     * - For disk URLs, returns a storage path relative to the disk root.
     * - Decodes URL-encoding (%20 → space) for the storage path.
     */
    protected function normalizeSrcForDisk(string $src, string $disk): string
    {
        $src = (string) $src;

        // Full external HTTP(S) URL path we might be able to map back to this disk
        $isHttp = str_starts_with($src, 'http://') || str_starts_with($src, 'https://');

        try {
            // Derive disk base URL by probing an artificial marker
            $marker   = '__qresize_marker__';
            $probeUrl = Storage::disk($disk)->url($marker);

            $pos      = strpos($probeUrl, $marker);
            $diskBase = $pos !== false ? substr($probeUrl, 0, $pos) : $probeUrl;
            $diskBase = rtrim($diskBase, '/');

            // If the input starts with the disk base URL, strip it
            if ($diskBase !== '' && str_starts_with($src, $diskBase)) {
                $rel = substr($src, strlen($diskBase));
                $rel = ltrim($rel, '/');

                // Decode any URL encoding so Storage gets the real filename
                return rawurldecode($rel);
            }
        } catch (\Exception $e) {
            // If disk->url() isn't supported, fall back to treating input as path/URL directly
        }

        // If it’s an http(s) URL but not for this disk, keep it as remote URL
        if ($isHttp) {
            return $src;
        }

        // Otherwise treat it as a storage path, trimming leading slash and decoding
        $path = ltrim($src, '/');

        return rawurldecode($path);
    }

    /**
     * qresize(src, w, h, opts = { mode: 'auto', quality: 60, disk?: 's3' })
     *
     * Drop-in replacement for resize:
     * - Accepts same kind of input (|media URL, raw media path, http URL)
     * - Works across different disks via the disk option and disk base URL detection.
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

        $resizer->setDisk($disk);

        // Normalize src so that:
        // - URLs for this disk become storage paths (media/...)
        // - http(s) URLs for other hosts stay URLs
        // - raw media paths stay media paths, url-decoded
        $src = $this->normalizeSrcForDisk((string) $src, $disk);

        // Determine format
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
                // jpg/png/gif/webp passed through
                break;
        }

        $opts['format'] = $format;
        ksort($opts);

        // Get mtime/size via shared logic
        ['mtime' => $mtime, 'size' => $size] = $resizer->getSourceStats($src);

        // Hash uses the normalized src
        $hash = $resizer->hash($src, $W, $H, $opts, $mtime, $size);

        // Ensure meta JSON next to image
        $metaRel = $resizer->nestedPath('resized', $hash, 'json');

        if (!Storage::disk($disk)->exists($metaRel)) {
            $resizer->ensureCacheDir($hash, $format);

            Storage::disk($disk)->put(
                $metaRel,
                json_encode([
                    'src'   => $src,
                    'w'     => $W,
                    'h'     => $H,
                    'opts'  => $opts,
                    'disk'  => $disk,
                    'mtime' => $mtime,
                    'size'  => $size,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        // Queue job if resized image does not yet exist
        if (!$resizer->exists($hash, $format)) {
            dispatch(
                (new \Mercator\QueuedResize\Jobs\ProcessImageResize($src, $W, $H, $opts))
                    ->onQueue(config('mercator.queuedresize::config.queue', 'imaging'))
            );
        }

        // Return cached URL (like resize does)
        return $resizer->cachedUrl($hash);
    }
}