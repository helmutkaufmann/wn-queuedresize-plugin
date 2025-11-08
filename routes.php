<?php

use Illuminate\Support\Facades\Route;
use Mercator\QueuedResize\Classes\ImageResizer;
use Mercator\QueuedResize\Jobs\ProcessImageResize;

Route::get("/queuedresize/{hash}", function (string $hash) {
    /** @var ImageResizer $r */
    $r = app(ImageResizer::class);
    $out = $r->cachedPathFromHash($hash);

    // 1) Already resized?
    if (is_file($out)) {
        return response()->file($out, ["Cache-Control" => "public, max-age=31536000"]);
    }

    // 2) Load meta data
    $metaFile = storage_path("app/resized/meta/" . $hash . ".json");
    $meta = is_file($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;

    if ($meta) {
        // 3) Fall-back.... no queue
        try {
            $r->resizeNow($meta["src"] ?? "", $meta["w"] ?? null, $meta["h"] ?? null, $meta["opts"] ?? []);
        } catch (\Throwable $e) {
            // optional: Loggen
            \Log::error("queuedresize sync failed", ["hash" => $hash, "err" => $e->getMessage()]);
            // Fallback: enqueuen, falls später ein Worker läuft
            dispatch(
                (new ProcessImageResize(
                    $meta["src"] ?? "",
                    $meta["w"] ?? null,
                    $meta["h"] ?? null,
                    $meta["opts"] ?? []
                ))->onQueue(config("mercator.queuedresize::config.queue", "imaging"))
            );

            return response("Accepted", 202, [
                "Cache-Control" => "no-cache, private",
                "Retry-After" => "5",
            ]);
        }
    } else {
        // No meta data
        return response("Accepted", 202, [
            "Cache-Control" => "no-cache, private",
            "Retry-After" => "5",
        ]);
    }

    // 4) Deliver teh resized image
    if (is_file($out)) {
        return response()->file($out, ["Cache-Control" => "public, max-age=31536000"]);
    }

    // In case it is not yet there in exeptional cases...
    return response("Accepted", 202, [
        "Cache-Control" => "no-cache, private",
        "Retry-After" => "5",
    ]);
});