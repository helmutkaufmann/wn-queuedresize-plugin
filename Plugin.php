<?php namespace Mercator\QueuedResize;

use Illuminate\Support\Facades\Facade;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            "name" => "Queued Resize",
            "description" => "Asynchrones Image-Resizing",
            "author" => "Mercator a.k.a Helmut Kaufmann",
            "icon" => "icon-image",
        ];
    }

    public function registerMarkupTags()
    {
        return [
            "filters" => [
                "qresize" => [$this, "processQueuedResize"],
            ],
            "functions" => [
                "qresize" => [$this, "processQueuedResize"],
            ],
        ];
    }

    // Shared logic for both filter and function
    public function processQueuedResize($src, $w = null, $h = null, array $opts = [])
    {
        /** @var \Mercator\QueuedResize\Classes\ImageResizer $resizer */
        $resizer = app(\Mercator\QueuedResize\Classes\ImageResizer::class);

        $W = $w && $w > 0 ? (int) $w : null;
        $H = $h && $h > 0 ? (int) $h : null;

        ksort($opts); // Sorting options for consistent hashing

        $hash = $resizer->hash($src, $W, $H, $opts);
        $outPath = $resizer->cachedPathFromHash($hash);

        $metaDir = storage_path("app/resized/meta");
        if (!is_dir($metaDir)) {
            @mkdir($metaDir, 0775, true);
        }
        $metaFile = $metaDir . "/" . $hash . ".json";

        if (!file_exists($metaFile)) {
            file_put_contents(
                $metaFile,
                json_encode(
                    [
                        "src" => $src,
                        "w" => $W,
                        "h" => $H,
                        "opts" => $opts,
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );
        }

        if (!file_exists($outPath)) {
            dispatch(
                (new \Mercator\QueuedResize\Jobs\ProcessImageResize($src, $W, $H, $opts))->onQueue(
                    config("mercator.queuedresize::config.queue", "imaging")
                )
            );
        }

        return $resizer->cachedUrl($hash);
    }
}