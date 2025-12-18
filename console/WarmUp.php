<?php namespace Mercator\QueuedResize\Console;

use Illuminate\Console\Command;
use Mercator\QueuedResize\Classes\ImageResizer;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class WarmUp extends Command
{
    protected $name = 'queuedresize:warmup';
    protected $description = 'Batch resize images in a directory.';

    public function handle()
    {
        $path = $this->argument('path');
        $width = $this->option('width');
        $height = $this->option('height');
        $recursive = (bool) $this->option('recursive');
        $force = (bool) $this->option('force');

        if (!$width && !$height) {
            $this->error('Please specify --width and/or --height');
            return;
        }

        $ws = explode(',', $width ?? '');
        $hs = explode(',', $height ?? '');
        $formats = explode(',', $this->option('format') ?: 'best');
        
        $dims = [];
        foreach ($ws as $i => $w) {
            $h = $hs[$i] ?? end($hs);
            $dims[] = ['w' => (int)$w ?: null, 'h' => (int)$h ?: null];
        }

        $resizer = app(ImageResizer::class);
        $resizer->setDisk($this->option('disk') ?: config('mercator.queuedresize::config.disk', 'local'));

        $this->info("Warming up directory: $path (Force: " . ($force ? 'Yes' : 'No') . ")");

        $resizer->batchResizeDirectory($path, $dims, $formats, [
            'mode'    => $this->option('mode'),
            'quality' => (int)$this->option('quality'),
            'force'   => $force,
        ], $recursive, $this->output);

        $this->info("Warmup complete.");
    }

    protected function getArguments() { return [['path', InputArgument::REQUIRED, 'Relative path']]; }

    protected function getOptions()
    {
        return [
            ['disk', null, InputOption::VALUE_OPTIONAL, 'Storage disk'],
            ['width', null, InputOption::VALUE_OPTIONAL, 'Target width(s)'],
            ['height', null, InputOption::VALUE_OPTIONAL, 'Target height(s)'],
            ['mode', null, InputOption::VALUE_OPTIONAL, 'Resize mode', 'auto'],
            ['quality', null, InputOption::VALUE_OPTIONAL, 'Quality', 80],
            ['format', null, InputOption::VALUE_OPTIONAL, 'Formats', 'best'],
            ['recursive', 'r', InputOption::VALUE_NONE, 'Recursive scan'],
            ['force', 'f', InputOption::VALUE_NONE, 'Force regeneration'],
        ];
    }
}