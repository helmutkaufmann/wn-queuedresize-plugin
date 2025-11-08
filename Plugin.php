<?php namespace Mercator\QueuedResize;

use System\Classes\PluginBase;
use Mercator\QueuedResize\Classes\ImageResizer;
use Mercator\QueuedResize\Jobs\ProcessImageResize;

class Plugin extends PluginBase
{
    public function registerMarkupTags()
    {
        return [
            'filters' => [
                'qresize' => [$this, 'queuedResizeFilter'],
            ],
            'functions' => [
                'qresize' => [$this, 'queuedResizeFilter'],
            ],
        ];
    }

    /**
     * Handles queued resize both as filter and function.
     */
    public function queuedResizeFilter(string $src, ?int $w = null, ?int $h = null, array $opts = []): string
    {
        /** @var ImageResizer $r */
        $r = app(ImageResizer::class);

        // normalize zeros for stability
        $W = $w && $w > 0 ? (int) $w : null;
        $H = $h && $h > 0 ? (int) $h : null;
        ksort($opts);

        $hash = $r->hash($src, $W, $H, $opts);
        $outPath = $r->cachedPathFromHash($hash);

        // store metadata
        $metaDir = storage_path('app/resized/meta');
        if (!is_dir($metaDir)) {
            @mkdir($metaDir, 0775, true);
        }

        $metaFile = $metaDir . '/' . $hash . '.json';
        if (!file_exists($metaFile)) {
            file_put_contents($metaFile, json_encode([
                'src'  => $src,
                'w'    => $W,
                'h'    => $H,
                'opts' => $opts,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // enqueue if missing
        if (!file_exists($outPath)) {
            dispatch(
                (new ProcessImageResize($src, $W, $H, $opts))
                    ->onQueue(config('mercator.queuedresize::config.queue', 'imaging'))
            );
        }

        // always return URL
        return $r->cachedUrl($hash);
    }
}
