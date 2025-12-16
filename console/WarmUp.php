<?php namespace Mercator\QueuedResize\Console;

use Illuminate\Console\Command;
use Mercator\QueuedResize\Classes\ImageResizer;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class WarmUp extends Command
{
    protected $name = 'queuedresize:warmup';
    protected $description = 'Batch resize images in a directory (Single Pass).';

    public function handle()
    {
        $path = $this->argument('path');
        $disk = $this->option('disk') ?: config('mercator.queuedresize::config.disk', 'local');
        $width = $this->option('width');
        $height = $this->option('height');
        
        // Restore standard behavior: False by default, True if flag present
        $recursive = (bool) $this->option('recursive');
        
        // Capture the force flag
        $force = (bool) $this->option('force');

        if (!$width && !$height) {
            $this->error('Please specify --width and/or --height');
            return;
        }

        $ws = explode(',', $width ?? '');
        $hs = explode(',', $height ?? '');
        
        // Support comma-separated formats
        $formats = explode(',', $this->option('format') ?: 'best');
        array_walk($formats, function(&$value) { $value = trim($value); });

        $dims = [];
        foreach ($ws as $i => $w) {
            $h = $hs[$i] ?? end($hs);
            $dims[] = ['w' => (int)$w ?: null, 'h' => (int)$h ?: null];
        }

        $baseOpts = [
            'mode'    => $this->option('mode') ?: 'auto',
            'quality' => (int)($this->option('quality') ?: 80),
            'disk'    => $disk,
            'force'   => $force, // <--- Passes the flag to ImageResizer
        ];

        /** @var ImageResizer $resizer */
        $resizer = app(ImageResizer::class);
        $resizer->setDisk($disk);

        $this->info("Warming up directory: $path on disk: $disk");
        $this->line("Recursive: " . ($recursive ? 'Yes' : 'No'));
        $this->line("Force: " . ($force ? 'Yes' : 'No'));
        $this->line("Formats: " . implode(', ', $formats));

        $count = $resizer->batchResizeDirectory(
            $path, 
            $dims, 
            $formats, 
            $baseOpts, 
            $recursive, 
            $this->output
        );

        $this->newLine();
        $this->info("Warmup complete. Processed $count images.");
    }

    protected function getArguments()
    {
        return [
            ['path', InputArgument::REQUIRED, 'Path relative to disk root'],
        ];
    }

    protected function getOptions()
    {
        return [
            ['disk', null, InputOption::VALUE_OPTIONAL, 'Storage disk name'],
            ['width', null, InputOption::VALUE_OPTIONAL, 'Target width (comma separated)'],
            ['height', null, InputOption::VALUE_OPTIONAL, 'Target height (comma separated)'],
            ['mode', null, InputOption::VALUE_OPTIONAL, 'Resize mode (auto, crop, fit)', 'auto'],
            ['quality', null, InputOption::VALUE_OPTIONAL, 'Quality (0-100)', 80],
            ['format', null, InputOption::VALUE_OPTIONAL, 'Formats (e.g. "jpg,webp")', 'best'],
            ['recursive', 'r', InputOption::VALUE_NONE, 'Scan subdirectories'],
            ['force', 'f', InputOption::VALUE_NONE, 'Force regeneration (ignore existing cache)'],
        ];
    }
}