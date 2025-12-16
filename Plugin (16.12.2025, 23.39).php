<?php namespace Mercator\QueuedResize;

use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'Queued Resize',
            'description' => 'Asynchrones Image-Resizing',
            'author'      => 'Mercator a.k.a Helmut Kaufmann',
            'icon'        => 'icon-image',
        ];
    }

    public function registerMarkupTags()
    {
        return [
            'filters'   => ['qresize' => [$this, 'processQueuedResize']],
            'functions' => ['qresize' => [$this, 'processQueuedResize']],
        ];
    }

    public function register()
    {
        $this->registerConsoleCommand('queuedresize.warmup', \Mercator\QueuedResize\Console\WarmUp::class);
        $this->registerConsoleCommand('queuedresize.prune', \Mercator\QueuedResize\Console\Prune::class);
        $this->registerConsoleCommand('queuedresize.clear', \Mercator\QueuedResize\Console\Clear::class);
    }

    protected function client_supports_webp(): bool
    {
        if (!isset($_SERVER['HTTP_ACCEPT'])) {
            return false;
        }

        return stripos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }

    /**
     * Normalize a src so qresize can be used like resize:
     */
    protected function normalizeSrcForDisk(string $src, string $disk): string
    {
        $src = (string) $src;
        $isHttp = str_starts_with($src, 'http://') || str_starts_with($src, 'https://');

        try {
            $marker   = '__qresize_marker__';
            $probeUrl = \Storage::disk($disk)->url($marker);

            $pos      = strpos($probeUrl, $marker);
            $diskBase = $pos !== false ? substr($probeUrl, 0, $pos) : $probeUrl;
            $diskBase = rtrim($diskBase, '/');

            if ($diskBase !== '' && str_starts_with($src, $diskBase)) {
                $rel = substr($src, strlen($diskBase));
                $rel = ltrim($rel, '/');
                return rawurldecode($rel);
            }
        } catch (\Exception $e) {
            // If disk->url() isn't supported, fall back
        }

        if ($isHttp) {
            return $src;
        }

        $path = ltrim($src, '/');
        return rawurldecode($path);
    }

    public function processQueuedResize($src, $w = null, $h = null, array $opts = [])
    {
        /** @var \Mercator\QueuedResize\Classes\ImageResizer $resizer */
        $resizer = app(\Mercator\QueuedResize\Classes\ImageResizer::class);

        $W = $w && $w > 0 ? (int) $w : null;
        $H = $h && $h > 0 ? (int) $h : null;
        ksort($opts);

        $disk = isset($opts['disk']) && is_string($opts['disk']) && $opts['disk'] !== ''
            ? (string) $opts['disk']
            : (string) config('mercator.queuedresize::config.disk', 'local');

        $resizer->setDisk($disk);

        $src = $this->normalizeSrcForDisk((string) $src, $disk);

        $format = strtolower($opts['format'] ?? 'best');
        switch ($format) {
            case 'best':
                $format = $this->client_supports_webp() ? 'webp' : 'jpg';
                break;
            case 'avif':
            case 'jpeg':
                $format = 'jpg';
                break;
            default:
                break;
        }

        $opts['format'] = $format;
        ksort($opts);

        ['mtime' => $mtime, 'size' => $size] = $resizer->getSourceStats($src);
        $hash = $resizer->hash($src, $W, $H, $opts, $mtime, $size);
        $metaRel = $resizer->nestedPath('resized', $hash, 'json');

        if (!\Storage::disk($disk)->exists($metaRel)) {
            $resizer->ensureCacheDir($hash, $format);

            \Storage::disk($disk)->put(
                $metaRel,
                json_encode([
                    'src'   => $src,
                    'w'     => $W,
                    'h'     => $H,
                    'opts'  => $opts,
                    'disk'  => $disk,
                    'mtime' => $mtime,
                    'size'  => $size,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        if (!$resizer->exists($hash, $format)) {
            \Log::info("qresize dispatching: $src on disk $disk");
            dispatch(
                (new \Mercator\QueuedResize\Jobs\ProcessImageResize($src, $W, $H, $opts))
                    ->onQueue(config('mercator.queuedresize::config.queue', 'imaging'))
            );
        }

        return $resizer->cachedUrl($hash);
    }
}