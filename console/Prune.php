<?php namespace Mercator\QueuedResize\Console;

use Illuminate\Console\Command;
use Mercator\QueuedResize\Classes\ImageResizer;
use Symfony\Component\Console\Input\InputOption;

class Prune extends Command
{
    protected $name = 'queuedresize:prune';
    protected $description = 'Remove resized images where the source file no longer exists.';

    public function handle()
    {
        $disk = $this->option('disk') ?: config('mercator.queuedresize::config.disk', 'local');
        $dryRun = $this->option('dry-run');

        /** @var ImageResizer $resizer */
        $resizer = app(ImageResizer::class);
        $resizer->setDisk($disk);

        $this->info("Pruning orphans on disk: $disk " . ($dryRun ? '(DRY RUN)' : ''));

        $count = $resizer->prune($dryRun, $this->output);

        $this->info("Pruned $count orphans.");
    }

    protected function getOptions()
    {
        return [
            ['disk', null, InputOption::VALUE_OPTIONAL, 'Storage disk name'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Check without deleting'],
        ];
    }
}