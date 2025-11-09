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

    $relImg = $r->nestedPath('resized', $hash, 'jpg');
    $relMeta = $r->nestedPath('resized', $hash, 'json');

    // 1) Already resized on any known disk?
    foreach ($disks as $d) {
        if (Storage::disk($d)->exists($relImg)) {
            $r->setDisk($d);
            return response()->file(
                Storage::disk($d)->path($relImg),
                ['Cache-Control' => 'public, max-age=31536000']
            );
        }
    }

    // 2) Find meta on any disk, then try sync render or queue
    $meta = null;
    $metaDisk = null;
    foreach ($disks as $d) {
        if (Storage::disk($d)->exists($relMeta)) {
            $meta = json_decode(Storage::disk($d)->get($relMeta), true);
            $metaDisk = $d;
            break;
        }
    }

    if ($meta) {
        try {
            $r->setDisk($metaDisk ?: $defaultDisk);
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
    } else {
        return response('Accepted', 202, ['Retry-After' => '5']);
    }

    // 3) Serve if created now
    if ($metaDisk && Storage::disk($metaDisk)->exists($relImg)) {
        return response()->file(
            Storage::disk($metaDisk)->path($relImg),
            ['Cache-Control' => 'public, max-age=31536000']
        );
    }

    return response('Accepted', 202, ['Retry-After' => '5']);
});
