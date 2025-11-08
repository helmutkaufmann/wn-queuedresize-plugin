# Mercator QueuedResize

Queued image resizing for WinterCMS.  
Images are processed asynchronously using Laravel’s queue system.

---

## Installation
Clone GitHub repository into ``plugins/mercator/qresize``

```bash
composer require intervention/image
php artisan plugin:refresh Mercator.QueuedResize
php artisan migrate
````

Set the queue driver in `.env`:

```
QUEUE_CONNECTION=database
```

Run migrations if needed:

```bash
php artisan queue:table
php artisan migrate
```

---

## Run the Queue Worker

Start the background worker:

```bash
php artisan queue:work database --queue=imaging --sleep=1 --tries=3 --timeout=180
```

Keep it running via systemd or another process manager if you want it persistent.

---

## Usage in Twig

### Resize an image

```twig
{{ 'media/gallery/photo.jpg' | q_resize(1200, 800) }}
```

### Crop and fill

```twig
{{ 'media/gallery/photo.jpg' | q_resize(800, 800, { mode: 'crop' }) }}
{{ 'media/gallery/photo.jpg' | q_resize(800, 800, { mode: 'fill', bg: '#ffffff' }) }}
```

### Just set width or height

```twig
{{ 'media/gallery/photo.jpg' | q_resize(1200) }}       {# width only #}
{{ 'media/gallery/photo.jpg' | q_resize(null, 800) }}  {# height only #}
```

### WebP or format override

```twig
{{ 'media/gallery/photo.jpg' | q_resize(1200, null, { format: 'webp' }) }}
```

---

## Usage in PHP

```php
use Mercator\QueuedResize\Classes\ImageResizer;

$resizer = app(ImageResizer::class);
$url = $resizer->resize('media/gallery/photo.jpg', 1200, 800, ['mode' => 'fit']);
echo $url;
```

The method returns a q resize URL like:

```
https://example.com/qresize/ab12cd34ef56...
```

On first access the job is q and returns HTTP 202 until finished.
Once processed, the resized file is served from `storage/app/resized/`.

---

## Notes

* Jobs use the `imaging` queue by default.
* Meta files are stored in `storage/app/resized/meta/`.
* Generated images appear in `storage/app/resized/`.
* Failed jobs can be listed and retried:

  ```bash
  php artisan queue:failed
  php artisan queue:retry all
  ```
