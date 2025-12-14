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
        \Log::warning("queuedresize: No meta file found for hash $hash on disk $disks.");
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
    
    // 4) Image does not exist. Try to render it sync.
    try {
        // resizeNow will use the $intendedDisk set on $r
        $r->resizeNow($meta['src'] ?? '', $meta['w'] ?? null, $meta['h'] ?? null, $meta['opts'] ?? []);
    } catch (\Throwable $e) {
        \Log::error('queuedresize sync failed', ['hash' => $hash, 'err' => $e->getMessage()]);
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

    // 5) Serve if created now
    if (Storage::disk($intendedDisk)->exists($relImg)) {
        return Storage::disk($intendedDisk)->response(
            $relImg,
            null,
            ['Cache-Control' => 'public, max-age=31536000, immutable']
        );
    }
    
    \Log::error('queuedresize: Sync render OK, but file still not found.', ['hash' => $hash, 'disk' => $intendedDisk, 'path' => $relImg]);
    return response('Processing Error', 500);
});