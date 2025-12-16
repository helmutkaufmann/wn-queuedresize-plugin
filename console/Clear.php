<?php namespace Mercator\QueuedResize\Console;

use Illuminate\Console\Command;
use Mercator\QueuedResize\Classes\ImageResizer;
use Symfony\Component\Console\Input\InputOption;

class Clear extends Command
{
    protected $name = 'queuedresize:clear';
    protected $description = 'Clear resized images based on criteria.';

    public function handle()
    {
        $disk = $this->option('disk') ?: config('mercator.queuedresize::config.disk', 'local');
        $dryRun = $this->option('dry-run');

        $filters = [];
        if ($this->option('width')) $filters['w'] = (int)$this->option('width');
        if ($this->option('height')) $filters['h'] = (int)$this->option('height');
        if ($this->option('path')) $filters['path'] = $this->option('path');

        if (empty($filters) && !$this->confirm('No filters provided. This will clear ALL resized images on this disk. Continue?')) {
            return;
        }

        /** @var ImageResizer $resizer */
        $resizer = app(ImageResizer::class);
        $resizer->setDisk($disk);

        $this->info("Clearing cache on disk: $disk " . ($dryRun ? '(DRY RUN)' : ''));

        $count = $resizer->clearCache($filters, $dryRun, $this->output);

        $this->info("Cleared $count items.");
    }

    protected function getOptions()
    {
        return [
            ['disk', null, InputOption::VALUE_OPTIONAL, 'Storage disk name'],
            ['width', null, InputOption::VALUE_OPTIONAL, 'Filter by width'],
            ['height', null, InputOption::VALUE_OPTIONAL, 'Filter by height'],
            ['path', null, InputOption::VALUE_OPTIONAL, 'Filter by part of source path'],
            ['dry-run', null, InputOption::VALUE_NONE, 'Check without deleting'],
        ];
    }
}