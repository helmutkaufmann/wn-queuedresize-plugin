<?php namespace Mercator\QueuedResize;

use Illuminate\Support\Facades\Facade;
use System\Classes\PluginBase;
use Illuminate\Support\Facades\Storage;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name' => 'Queued Resize',
            'description' => 'Asynchrones Image-Resizing',
            'author' => 'Mercator a.k.a Helmut Kaufmann',
            'icon' => 'icon-image',
        ];
    }

    public function registerMarkupTags()
    {
        return [
            'filters' => [ 'qresize' => [$this, 'processQueuedResize'] ],
            'functions' => [ 'qresize' => [$this, 'processQueuedResize'] ],
        ];
    }

    /**
     * qresize(src, w, h, opts = { mode: 'auto', quality: 60, disk?: 's3' })
     */
    public function processQueuedResize($src, $w = null, $h = null, array $opts = [])
    {
        /** @var \Mercator\QueuedResize\Classes\ImageResizer $resizer */
        $resizer = app(\Mercator\QueuedResize\Classes\ImageResizer::class);

        $W = $w && $w > 0 ? (int) $w : null;
        $H = $h && $h > 0 ? (int) $h : null;
        ksort($opts);

        $hash = $resizer->hash($src, $W, $H, $opts);
        $disk = isset($opts['disk']) && is_string($opts['disk']) && $opts['disk'] !== ''
            ? (string) $opts['disk']
            : (string) config('mercator.queuedresize::config.disk', 'local');

        $resizer->setDisk($disk);

        // ensure meta JSON next to image
        $metaRel = $resizer->nestedPath('resized', $hash, 'json');
        $metaAbs = Storage::disk($disk)->path($metaRel);
        $metaDir = \dirname($metaAbs);
        if (!is_dir($metaDir)) {
            @mkdir($metaDir, 0775, true);
        }
        if (!Storage::disk($disk)->exists($metaRel)) {
            Storage::disk($disk)->put(
                $metaRel,
                json_encode(['src'=>$src, 'w'=>$W, 'h'=>$H, 'opts'=>$opts, 'disk'=>$disk], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
            );
        }

        $outPath = $resizer->cachedPathFromHash($hash);
        if (!file_exists($outPath)) {
            dispatch(
                (new \Mercator\QueuedResize\Jobs\ProcessImageResize($src, $W, $H, $opts))
                    ->onQueue(config('mercator.queuedresize::config.queue', 'imaging'))
            );
        }

        return $resizer->cachedUrl($hash);
    }
}
