<?php namespace Mercator\QueuedResize\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use Mercator\QueuedResize\Classes\ImageResizer;

class SignedResizeController extends Controller
{
    public function show(string $hash, Request $request)
    {
        if (!URL::hasValidSignature($request)) abort(403);

        /** @var ImageResizer $r */
        $r = app(ImageResizer::class);

        $defaultDisk = (string) config('mercator.queuedresize::config.disk', 'local');
        $extra       = (array) config('mercator.queuedresize::config.disks', []);
        $disks       = array_values(array_unique(array_filter(array_merge([$defaultDisk], $extra))));

        // 1) Find meta on any disk
        $relMeta = $r->nestedPath('resized', $hash, 'json');

        $meta = null;
        $metaFoundOn = null;
        foreach ($disks as $d) {
            if (Storage::disk($d)->exists($relMeta)) {
                $meta = json_decode(Storage::disk($d)->get($relMeta), true);
                $metaFoundOn = $d;
                break;
            }
        }
        if (!$meta) abort(404);

        // 2) Determine format from meta
        $format = strtolower($meta['opts']['format'] ?? 'jpg');
        if ($format === 'best') $format = 'jpg'; // old meta safety

        $relImg = $r->nestedPath('resized', $hash, $format);

        // 3) Candidate disk priority:
        //    - meta['disk'] (if set)
        //    - disk where meta was found
        //    - all configured disks
        $preferred = [];
        if (!empty($meta['disk'])) $preferred[] = (string) $meta['disk'];
        if ($metaFoundOn) $preferred[] = $metaFoundOn;
        $preferred = array_values(array_unique(array_filter($preferred)));

        $candidates = array_values(array_unique(array_merge($preferred, $disks)));

        // 4) Find the image file across disks
        $foundDisk = null;
        foreach ($candidates as $d) {
            if (Storage::disk($d)->exists($relImg)) {
                $foundDisk = $d;
                break;
            }
        }

        // 5) If missing, do a synchronous resize (NO WORKER REQUIRED),
        //    but protect server with concurrency limiter.
        if (!$foundDisk) {

            $targetDisk = (string) ($meta['disk'] ?? ($metaFoundOn ?? $defaultDisk));
            if (!in_array($targetDisk, $disks, true)) {
                $targetDisk = $defaultDisk;
            }

            $r->setDisk($targetDisk);

            $maxConcurrency = max(1, (int) env('IMAGE_RESIZE_MAX_CONCURRENCY', 2));
            $waitSeconds    = max(0, (int) env('IMAGE_RESIZE_LOCK_WAIT', 60));
            $pollMicros     = max(10_000, (int) env('IMAGE_RESIZE_LOCK_POLL_US', 200_000));

            [$slotFp, $handles] = $this->acquireResizeSlot($maxConcurrency, $waitSeconds, $pollMicros);

            if ($slotFp) {
                try {
                    // Try to create output
                    $r->ensureCacheDir($hash, $format);
                    $r->resizeNow($meta['src'] ?? '', $meta['w'] ?? null, $meta['h'] ?? null, $meta['opts'] ?? []);

                } finally {
                    $this->releaseResizeSlot($slotFp, $handles);
                }

                // Re-check existence on the target disk
                if (Storage::disk($targetDisk)->exists($relImg)) {
                    $foundDisk = $targetDisk;
                }
            }

            if (!$foundDisk) {
                abort(404);
            }
        }

        return Storage::disk($foundDisk)->response($relImg, null, [
            'Content-Type' => $this->mimeFromExt($format),
            'Cache-Control' => 'private, max-age=60',
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * Acquire a concurrency slot for synchronous resizing.
     * Uses N lock files in storage/app/qresize-slot-*.lock.
     */
    protected function acquireResizeSlot(int $slots, int $waitSeconds, int $pollMicros): array
    {
        $start = time();
        $handles = [];

        for ($i = 1; $i <= $slots; $i++) {
            $handles[$i] = fopen(storage_path("app/qresize-slot-{$i}.lock"), 'c');
        }

        while (true) {
            foreach ($handles as $fp) {
                if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
                    return [$fp, $handles];
                }
            }

            if ((time() - $start) >= $waitSeconds) {
                foreach ($handles as $fp) {
                    if (is_resource($fp)) fclose($fp);
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
                if (is_resource($fp)) fclose($fp);
            }
        }
    }

    protected function mimeFromExt(string $ext): string
    {
        return match ($ext) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream'
        };
    }
}