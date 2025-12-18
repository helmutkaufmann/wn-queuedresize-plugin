<?php namespace Mercator\QueuedResize\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mercator\QueuedResize\Classes\ImageResizer;

class ProcessImageResize implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected string $src;
    protected ?int $w;
    protected ?int $h;
    protected array $opts;
    protected string $disk;

    public function __construct(string $src, ?int $w, ?int $h, array $opts = [])
    {
        $this->src = $src;
        $this->w = $w;
        $this->h = $h;
        $this->opts = $opts;
        $this->disk = $opts['disk'] ?? config('mercator.queuedresize::config.disk', 'local');
    }

    public function handle(ImageResizer $resizer)
    {
        $limit = (int) config('mercator.queuedresize::config.concurrency', 3);
        $backoff = (int) config('mercator.queuedresize::config.backoff', 5);
        $lockFile = storage_path('framework/cache/queuedresize.semaphore');

        if (!is_dir(dirname($lockFile))) @mkdir(dirname($lockFile), 0775, true);
        
        $fh = fopen($lockFile, 'c+');
        if (!$fh || !flock($fh, LOCK_EX)) {
            if ($fh) fclose($fh);
            return $this->release($backoff);
        }

        $count = (int) trim(stream_get_contents($fh) ?: '0');
        if ($count >= $limit) {
            flock($fh, LOCK_UN); fclose($fh);
            return $this->release($backoff);
        }

        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, (string)($count + 1)); fflush($fh);
        flock($fh, LOCK_UN); fclose($fh);

        try {
            $resizer->setDisk($this->disk);
            $resizer->resizeNow($this->src, $this->w, $this->h, $this->opts);
        } finally {
            $fh2 = fopen($lockFile, 'c+');
            if ($fh2 && flock($fh2, LOCK_EX)) {
                $c2 = max(0, (int)trim(stream_get_contents($fh2) ?: '0') - 1);
                ftruncate($fh2, 0); rewind($fh2);
                fwrite($fh2, (string)$c2); fflush($fh2); flock($fh2, LOCK_UN);
            }
            if ($fh2) fclose($fh2);
        }
    }
}