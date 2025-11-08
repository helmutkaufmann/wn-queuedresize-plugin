<?php return [
    "queue" => env("IMAGE_RESIZE_QUEUE", "imaging"),
    "concurrency" => (int) env("IMAGE_RESIZE_CONCURRENCY", 2),
    "backoff" => (int) env("IMAGE_RESIZE_BACKOFF_SECONDS", 5),
    "driver" => env("IMAGE_RESIZE_DRIVER", "gd"),
];