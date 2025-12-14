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
    | Image driver
    |--------------------------------------------------------------------------
    |
    | The Intervention Image driver to use: "gd" or "imagick".
    |
    */

    'driver' => env('IMAGE_RESIZE_DRIVER', 'gd'),

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

    'quality' => (int) env('IMAGE_RESIZE_QUALITY', 60),

];