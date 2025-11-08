<?php namespace Mercator\QueuedResize;

use System\Classes\PluginBase;
use Mercator\QueuedResize\Support\TwigTag;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            "name" => "Queued Resize",
            "description" => "Asynchrones Bild-Resizing für Twig mit Queue und Datei-basiertem Concurrency-Limit.",
            "author" => "Mercator",
            "icon" => "icon-image",
        ];
    }

    public function registerMarkupTags()
    {
        return [
            "filters" => [
                "queued_resize" => function ($src, $w = null, $h = null, array $opts = []) {
                    /** @var \Mercator\QueuedResize\Classes\ImageResizer $r */
                    $r = app(\Mercator\QueuedResize\Classes\ImageResizer::class);

                    // 0 → null normalisieren (Hash bleibt stabil)
                    $W = $w && $w > 0 ? (int) $w : null;
                    $H = $h && $h > 0 ? (int) $h : null;
                    ksort($opts);

                    $hash = $r->hash($src, $W, $H, $opts);
                    $outPath = $r->cachedPathFromHash($hash);

                    // Metadaten sichern, damit die Route weiß, was zu tun ist
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

                    // Wenn noch nicht vorhanden, Job enqueuen
                    if (!file_exists($outPath)) {
                        dispatch(
                            (new \Mercator\QueuedResize\Jobs\ProcessImageResize($src, $W, $H, $opts))->onQueue(
                                config("mercator.queuedresize::config.queue", "imaging")
                            )
                        );
                    }

                    // Immer die URL zurückgeben
                    return $r->cachedUrl($hash);
                },
            ],
        ];
    }
}