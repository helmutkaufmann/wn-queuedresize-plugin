<?php namespace Mercator\QueuedResize\Classes;

use Illuminate\Support\Facades\URL;
use Mercator\QueuedResize\Classes\ImageResizer;

class QSignedResizeTwig
{
    protected function client_supports_webp(): bool
    {
        if (!isset($_SERVER['HTTP_ACCEPT'])) {
            return false;
        }
        return stripos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }

    protected function normalizeSrcForDisk(string $src, string $disk): string
    {
        $src = (string) $src;
        try {
            $marker   = '__qresize_marker__';
            $probeUrl = \Storage::disk($disk)->url($marker);
            $pos      = strpos($probeUrl, $marker);
            $diskBase = $pos !== false ? substr($probeUrl, 0, $pos) : $probeUrl;
            $diskBase = rtrim($diskBase, '/');

            if ($diskBase !== '' && str_starts_with($src, $diskBase)) {
                $rel = substr($src, strlen($diskBase));
                return rawurldecode(ltrim($rel, '/'));
            }
        } catch (\Exception $e) {}

        return rawurldecode(ltrim($src, '/'));
    }

    /**
     * Acquire a concurrency slot for synchronous resizing.
     * Uses N lock files in storage/app/qresize-slot-*.lock.
     */
    protected function acquireResizeSlot(int $slots, int $waitSeconds, int $pollMicros): array
    {
        $start = time();

        // Open N lock files once
        $handles = [];
        for ($i = 1; $i <= $slots; $i++) {
            $handles[$i] = fopen(storage_path("app/qresize-slot-{$i}.lock"), 'c');
        }

        while (true) {
            foreach ($handles as $fp) {
                if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
                    return [$fp, $handles]; // acquired + all handles (for cleanup)
                }
            }

            if ((time() - $start) >= $waitSeconds) {
                foreach ($handles as $fp) {
                    if (is_resource($fp)) {
                        fclose($fp);
                    }
                }
                return [null, null];
            }

            usleep($pollMicros);
        }
    }

    protected function releaseResizeSlot($acquiredFp, $allHandles): void
    {
        if (is_resource($acquiredFp)) {
            flock($acquiredFp, LOCK_UN);
        }
        if (is_array($allHandles)) {
            foreach ($allHandles as $fp) {
                if (is_resource($fp)) {
                    fclose($fp);
                }
            }
        }
    }

    public function qsresize($src, $w = null, $h = null, $opts = [])
    {
        // Default expiry from Secret plugin config if not explicitly set
        $defaultMinutes = (int) config('mercator.secret::config.expiry', 15);

        // Allow override per call
        $minutes = array_key_exists('expires', $opts)
            ? (int) $opts['expires']
            : $defaultMinutes;

        unset($opts['expires']);

        $resizer = app(ImageResizer::class);
        $W = $w && $w > 0 ? (int) $w : 0;
        $H = $h && $h > 0 ? (int) $h : 0;

        $disk = (string) ($opts['disk'] ?? config('mercator.queuedresize::config.disk', 'local'));
        $resizer->setDisk($disk);
        $src = $this->normalizeSrcForDisk((string) $src, $disk);

        $format = strtolower($opts['format'] ?? 'best');
        if ($format === 'best') {
            $format = $this->client_supports_webp() ? 'webp' : 'jpg';
        }
        $opts['format'] = $format;
        ksort($opts);

        ['mtime' => $mtime, 'size' => $size] = $resizer->getSourceStats($src);
        $hash = $resizer->hash($src, $W, $H, $opts, $mtime, $size);

        // Ensure resize metadata exists
        $metaRel = $resizer->nestedPath('resized', $hash, 'json');
        if (!\Storage::disk($disk)->exists($metaRel)) {
            $resizer->ensureCacheDir($hash, $format);
            \Storage::disk($disk)->put($metaRel, json_encode([
                'src' => $src, 'w' => $W, 'h' => $H, 'opts' => $opts, 'disk' => $disk, 'mtime' => $mtime, 'size' => $size
            ], JSON_UNESCAPED_SLASHES));
        }

        // === Synchronous generation with concurrency limiter ===
        if (!$resizer->exists($hash, $format)) {

            // Configure via .env (same defaults as /queuedresize route)
            $maxConcurrency = max(1, (int) env('IMAGE_RESIZE_MAX_CONCURRENCY', 2));
            $waitSeconds    = max(0, (int) env('IMAGE_RESIZE_LOCK_WAIT', 60));
            $pollMicros     = max(10_000, (int) env('IMAGE_RESIZE_LOCK_POLL_US', 200_000));

            [$slotFp, $handles] = $this->acquireResizeSlot($maxConcurrency, $waitSeconds, $pollMicros);

            if ($slotFp) {
                try {
                    // Another request may have finished it while we waited
                    if (!$resizer->exists($hash, $format)) {
                        $resizer->ensureCacheDir($hash, $format);
                        $resizer->resizeNow($src, $W, $H, $opts);
                    }
                } finally {
                    $this->releaseResizeSlot($slotFp, $handles);
                }
            }
            // If no slot acquired: we still return a signed URL.
            // The client may retry; /qsresize will return 404 until generated.
        }

        // Return a signed URL to the SignedResizeController
        return URL::temporarySignedRoute(
            'mercator.qsresize',
            now()->addMinutes($minutes),
            ['hash' => $hash]
        );
    }
}