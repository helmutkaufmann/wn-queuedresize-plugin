<?php

use Illuminate\Support\Facades\Route;
use Mercator\QueuedResize\Classes\ImageResizer;
use Mercator\QueuedResize\Jobs\ProcessImageResize;
use Illuminate\Support\Facades\Storage;

Route::get('/queuedresize/{hash}', function (string $hash) {
    /** @var ImageResizer $r */
    $r = app(ImageResizer::class);

    $defaultDisk = (string) config('mercator.queuedresize::config.disk', 'local');
    $extra = (array) config('mercator.queuedresize::config.disks', []);
    $disks = array_values(array_unique(array_filter(array_merge([$defaultDisk], $extra))));

    $relMeta = $r->nestedPath('resized', $hash, 'json');

    // 1) Find meta on any disk
    $meta = null;
    $metaDiskFoundOn = null; // The disk where we *found* the meta
    foreach ($disks as $d) {
        if (Storage::disk($d)->exists($relMeta)) {
            $meta = json_decode(Storage::disk($d)->get($relMeta), true);
            $metaDiskFoundOn = $d;
            break;
        }
    }

    if (!$meta) {
        \Log::warning('queuedresize: No meta file found for hash.', ['hash' => $hash, 'disks' => $disks]);
        return response('Not Found', 404);
    }

    // 2) We have meta. The *intended* disk is IN the meta.
    $intendedDisk = $meta['disk'] ?? $defaultDisk;
    $r->setDisk($intendedDisk);

    // Determine format from meta
    $format = strtolower($meta['opts']['format'] ?? 'jpg');
    $relImg = $r->nestedPath('resized', $hash, $format);

    // 3) Check if image exists on its *intended* disk
    if (Storage::disk($intendedDisk)->exists($relImg)) {
        return Storage::disk($intendedDisk)->response(
            $relImg,
            null, // name
            ['Cache-Control' => 'public, max-age=31536000, immutable']
        );
    }

    /**
     * 4) Image does not exist. Render it sync, but limit concurrency (memory).
     *
     * Configure via .env:
     *   IMAGE_RESIZE_MAX_CONCURRENCY=1  (or 2)
     *   IMAGE_RESIZE_LOCK_WAIT=60       (seconds to wait for a slot)
     */
    $maxConcurrency = max(1, (int) env('IMAGE_RESIZE_MAX_CONCURRENCY', 2));
    $waitSeconds    = max(0, (int) env('IMAGE_RESIZE_LOCK_WAIT', 60));
    $pollMicros     = max(10_000, (int) env('IMAGE_RESIZE_LOCK_POLL_US', 200_000));

    $acquireSlot = function (int $slots, int $waitSeconds, int $pollMicros) {
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
    };

    $releaseSlot = function ($acquiredFp, $allHandles) {
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
    };

    // Acquire 1–2 concurrency slot(s) BEFORE resizing
    [$slotFp, $handles] = $acquireSlot($maxConcurrency, $waitSeconds, $pollMicros);

    if (!$slotFp) {
        // Server busy: ask client to retry
        return response('Busy', 503)->header('Retry-After', '5');
    }

    try {
        // Re-check after acquiring slot (another request may have produced it)
        if (!Storage::disk($intendedDisk)->exists($relImg)) {
            try {
                // resizeNow will use the $intendedDisk set on $r
                $r->resizeNow($meta['src'] ?? '', $meta['w'] ?? null, $meta['h'] ?? null, $meta['opts'] ?? []);
            } catch (\Throwable $e) {
                \Log::error('queuedresize sync failed', ['hash' => $hash, 'err' => $e->getMessage()]);

                // If you want "no queue at all", delete the dispatch block below and just return 500.
                dispatch(
                    (new ProcessImageResize(
                        $meta['src'] ?? '',
                        $meta['w'] ?? null,
                        $meta['h'] ?? null,
                        $meta['opts'] ?? []
                    ))->onQueue(config('mercator.queuedresize::config.queue', 'imaging'))
                );

                return response('Accepted', 202, ['Retry-After' => '5']);
            }
        }
    } finally {
        $releaseSlot($slotFp, $handles);
    }

    // 5) Serve if created now
    if (Storage::disk($intendedDisk)->exists($relImg)) {
        return Storage::disk($intendedDisk)->response(
            $relImg,
            null,
            ['Cache-Control' => 'public, max-age=31536000, immutable']
        );
    }

    \Log::error('queuedresize: Sync render OK, but file still not found.', [
        'hash' => $hash,
        'disk' => $intendedDisk,
        'path' => $relImg
    ]);

    return response('Processing Error', 500);
});