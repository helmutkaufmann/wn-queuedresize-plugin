<?php return [
    "queue" => env("IMAGE_RESIZE_QUEUE", "imaging"),
    "concurrency" => (int) env("IMAGE_RESIZE_CONCURRENCY", 3),
    "backoff" => (int) env("IMAGE_RESIZE_BACKOFF_SECONDS", 5),
    "driver" => env("IMAGE_RESIZE_DRIVER", "gd"),
    // Default disk if none is passed via opts
    "disk" => env("IMAGE_RESIZE_DISK", "local"),
    // Optional list of disks to search when resolving a hash (comma-separated in .env)
    "disks" => array_filter(array_map("trim", explode(",", (string) env("IMAGE_RESIZE_DISKS", "")))),
];
