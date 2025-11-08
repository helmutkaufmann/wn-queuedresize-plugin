<?php namespace Mercator\QueuedResize\Support;

use Mercator\QueuedResize\Classes\ImageResizer;
use Mercator\QueuedResize\Jobs\ProcessImageResize;

class TwigTag
{
    public static function queuedResize($src, $w = null, $h = null, $opts = [])
    {
        /** @var ImageResizer $resizer */
        $resizer = app(ImageResizer::class);
        $hash = $resizer->hash($src, $w, $h, $opts);
        if (!$resizer->exists($hash)) {
            dispatch(new ProcessImageResize($src, $w, $h, (array) $opts));
        }
        return $resizer->cachedUrl($hash);
    }
}