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

    public function __construct(string $src, ?int $w, ?int $h, array $opts = [])
    {
        $this->onQueue(config("mercator.queuedresize::config.queue", "imaging"));
        $this->src = $src;
        $this->w = $w;
        $this->h = $h;
        $this->opts = $opts;
    }

    public function handle(ImageResizer $resizer)
    {
        $limit = (int) config("mercator.queuedresize::config.concurrency", 2);
        $backoff = (int) config("mercator.queuedresize::config.backoff", 5);
        $lockFile = storage_path("framework/cache/queuedresize.semaphore");
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0775, true);
        }
        $fh = fopen($lockFile, "c+");
        if (!$fh) {
            $this->release($backoff);
            return;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            $this->release($backoff);
            return;
        }
        $buf = stream_get_contents($fh);
        $count = $buf !== false && strlen($buf) > 0 ? (int) trim($buf) : 0;
        if ($count >= $limit) {
            flock($fh, LOCK_UN);
            fclose($fh);
            $this->release($backoff);
            return;
        }
        $count++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $count);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        try {
            $resizer->resizeNow($this->src, $this->w, $this->h, $this->opts);
        } finally {
            $fh2 = fopen($lockFile, "c+");
            if ($fh2 && flock($fh2, LOCK_EX)) {
                $buf2 = stream_get_contents($fh2);
                $count2 = $buf2 !== false && strlen($buf2) > 0 ? (int) trim($buf2) : 0;
                $count2 = max(0, $count2 - 1);
                ftruncate($fh2, 0);
                rewind($fh2);
                fwrite($fh2, (string) $count2);
                fflush($fh2);
                flock($fh2, LOCK_UN);
            }
            if ($fh2) {
                fclose($fh2);
            }
        }
    }
}