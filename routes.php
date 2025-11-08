<?php
use Illuminate\Support\Facades\Route;
use Mercator\QueuedResize\Classes\ImageResizer;
use Mercator\QueuedResize\Jobs\ProcessImageResize;

Route::get("/queuedresize/{hash}", function (string $hash) {
    /** @var ImageResizer $r */
    $r = app(ImageResizer::class);
    $out = $r->cachedPathFromHash($hash);

    // 1) Bereits vorhanden?
    if (is_file($out)) {
        return response()->file($out, ["Cache-Control" => "public, max-age=31536000"]);
    }

    // 2) Meta laden
    $metaFile = storage_path("app/resized/meta/" . $hash . ".json");
    $meta = is_file($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;

    if ($meta) {
        // 3) Sync-Fallback (erzwingt Erzeugung ohne Queue)
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
        // kein Meta → kein Wissen über Parameter
        return response("Accepted", 202, [
            "Cache-Control" => "no-cache, private",
            "Retry-After" => "5",
        ]);
    }

    // 4) Ausliefern (nach erfolgreicher Sync-Erzeugung)
    if (is_file($out)) {
        return response()->file($out, ["Cache-Control" => "public, max-age=31536000"]);
    }

    // Falls ungewöhnlich immer noch nicht da:
    return response("Accepted", 202, [
        "Cache-Control" => "no-cache, private",
        "Retry-After" => "5",
    ]);
});