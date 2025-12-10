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
    | The filesystem disk used to read originals and write resized images,
    | unless a different disk is explicitly passed via the "disk" option.
    |
    */

    'disk' => env('IMAGE_RESIZE_DISK', 'local'),

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