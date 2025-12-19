<?php namespace Mercator\QueuedResize;

use System\Classes\PluginBase;
use App;
use Mercator\QueuedResize\Classes\ImageResizer;
use Log;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'Queued Resize',
            'description' => 'Asynchrones Image-Resizing',
            'author'      => 'Mercator',
            'icon'        => 'icon-image',
        ];
    }

    public function register()
    {
        $this->registerConsoleCommand('queuedresize.warmup', \Mercator\QueuedResize\Console\WarmUp::class);
        $this->registerConsoleCommand('queuedresize.prune', \Mercator\QueuedResize\Console\Prune::class);
        $this->registerConsoleCommand('queuedresize.clear', \Mercator\QueuedResize\Console\Clear::class);
        
        App::bind('queuedresize_path', function() {
            return app(ImageResizer::class)->qresizePath(...func_get_args());
        });
    }

    public function registerMarkupTags()
    {
        return [
            'filters'   => ['qresize' => [$this, 'processQueuedResize']],
            'functions' => ['qresize' => [$this, 'processQueuedResize']],
        ];
    }

    protected function client_supports_webp(): bool
    {
        if (!isset($_SERVER['HTTP_ACCEPT'])) {
            return false;
        }
        return stripos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }

    protected function normalizeSrcForDisk(string $src, string $disk): string
    {
        $src = (string) $src;
        try {
            $marker   = '__qresize_marker__';
            $probeUrl = \Storage::disk($disk)->url($marker);
            $pos      = strpos($probeUrl, $marker);
            $diskBase = $pos !== false ? substr($probeUrl, 0, $pos) : $probeUrl;
            $diskBase = rtrim($diskBase, '/');

            if ($diskBase !== '' && str_starts_with($src, $diskBase)) {
                $rel = substr($src, strlen($diskBase));
                return rawurldecode(ltrim($rel, '/'));
            }
        } catch (\Exception $e) {}

        return rawurldecode(ltrim($src, '/'));
    }

    public function processQueuedResize($src, $w = null, $h = null, array $opts = [])
    {
        $resizer = app(ImageResizer::class);
        $W = $w && $w > 0 ? (int) $w : 0;
        $H = $h && $h > 0 ? (int) $h : 0;
        
        $disk = (string) ($opts['disk'] ?? config('mercator.queuedresize::config.disk', 'local'));
        $resizer->setDisk($disk);
        $src = $this->normalizeSrcForDisk((string) $src, $disk);

        $format = strtolower($opts['format'] ?? 'best');
        if ($format === 'best') $format = $this->client_supports_webp() ? 'webp' : 'jpg';
        $opts['format'] = $format;
        ksort($opts);

        ['mtime' => $mtime, 'size' => $size] = $resizer->getSourceStats($src);
        $hash = $resizer->hash($src, $W, $H, $opts, $mtime, $size);
        $metaRel = $resizer->nestedPath('resized', $hash, 'json');

        if (!\Storage::disk($disk)->exists($metaRel)) {
            $resizer->ensureCacheDir($hash, $format);
            \Storage::disk($disk)->put($metaRel, json_encode([
                'src' => $src, 'w' => $W, 'h' => $H, 'opts' => $opts, 'disk' => $disk, 'mtime' => $mtime, 'size' => $size
            ], JSON_UNESCAPED_SLASHES));
        }

        if (!$resizer->exists($hash, $format)) {
            dispatch((new \Mercator\QueuedResize\Jobs\ProcessImageResize($src, $W, $H, $opts))
                ->onQueue(config('mercator.queuedresize::config.queue', 'imaging')));
            Log::info("qresize $src on $disk");
        }

        return $resizer->cachedUrl($hash);
    }
}