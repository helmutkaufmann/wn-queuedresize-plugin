<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue name
    |--------------------------------------------------------------------------
    |
    | The queue on which resize jobs will be dispatched.
    |
    */
    'queue' => env('IMAGE_RESIZE_QUEUE', 'imaging'),

    /*
    |--------------------------------------------------------------------------
    | Concurrency Limit
    |--------------------------------------------------------------------------
    |
    | The maximum number of simultaneous image resize jobs that can run across 
    | all workers at any given time. This uses a semaphore lock to prevent 
    | overwhelming server CPU/memory during bulk processing.
    |
    */
    'concurrency' => (int) env('IMAGE_RESIZE_CONCURRENCY', 3),

    /*
    |--------------------------------------------------------------------------
    | Backoff Time
    |--------------------------------------------------------------------------
    |
    | The number of seconds a job should wait before being released back to the 
    | queue when the concurrency limit is reached.
    |
    */
    'backoff' => (int) env('IMAGE_RESIZE_BACKOFF', 5),

    /*
    |--------------------------------------------------------------------------
    | Image driver
    |--------------------------------------------------------------------------
    |
    | The Intervention Image driver to use: "gd" or "imagick".
    |
    */
    'driver' => env('IMAGE_RESIZE_DRIVER', 'imagick'),

    /*
    |--------------------------------------------------------------------------
    | Default storage disk
    |--------------------------------------------------------------------------
    |
    | Default filesystem disk used by the plugin when no per-call "disk"
    | option is provided. It is used for:
    | - reading originals
    | - writing resized images
    | - writing resize metadata (JSON)
    | - default lookup by /queuedresize/{hash}
    |
    */
    'disk' => env('IMAGE_RESIZE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Additional lookup disks
    |--------------------------------------------------------------------------
    |
    | Extra disks that /queuedresize/{hash} will also search (in addition to
    | the default disk) when resolving metadata/images. Needed when you use
    | per-call disk overrides.
    |
    */
    'disks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('IMAGE_RESIZE_DISKS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Default quality
    |--------------------------------------------------------------------------
    |
    | Fallback JPEG / WebP quality when none is provided in the options.
    | This is optional; if you don't wire it into the code, you can remove it.
    |
    */
    'quality' => (int) env('IMAGE_RESIZE_QUALITY', 80),

];