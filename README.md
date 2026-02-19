# VipsRenderer (BASE3 Plugin)

GPL-3.0 licensed BASE3 plugin providing a **libvips CLI** based image renderer.

It implements the `MediaFoundation\Api\IImageRenderer` interface and renders images based on
`MediaFoundation\Builder\ImageEdit`. The implementation is file-based (paths in/out), fast, and well-suited
for background jobs that re-render publish-ready derivatives.

## Features

Supports the edit operations exposed by `ImageEdit`:

- Crop (pixel crop + normalized crop)
- Rotate (0/90/180/270) + Flip H/V
- Resize (fit/fill/stretch)
- “Mood” (fast linear adjustment) + brightness/contrast/exposure/gamma (subset)
- Watermark overlay (PNG recommended, anchoring + offsets, opacity, scale)
- Export (JPEG/WEBP/PNG, quality, strip metadata, optional sRGB attempt)

## Requirements

- Linux server with libvips CLI tools installed:
  - `vips`
  - `vipsheader`
- PHP 8.3+ (works with CLI/worker usage; web usage depends on your BASE3 setup)

Ubuntu/Debian example:

```bash
apt update
apt install -y libvips-tools
````

## Namespace / Class

Renderer implementation:

* `VipsRenderer\Renderer\VipsImageRenderer`

Implements:

* `MediaFoundation\Api\IImageRenderer`

Consumes edit spec:

* `MediaFoundation\Builder\ImageEdit`

## Usage

Minimal example:

```php
use MediaFoundation\Builder\ImageEdit;
use VipsRenderer\Renderer\VipsImageRenderer;

$renderer = new VipsImageRenderer(tmpDir: '/tmp');

$edit = ImageEdit::create()
  ->publish()
  ->mood(1.12, -0.03)
  ->watermark('/path/to/watermark.png', -50, -50, 'se')
  ->jpeg(90, true, true);

$renderer->render('/tmp/in.jpg', $edit, '/tmp/out.jpg');
```

Preview example:

```php
$edit = ImageEdit::create()
  ->preview(1600)
  ->mood(1.10, -0.02)
  ->jpeg(85);

$renderer->render($in, $edit, $previewOut);
```

## Notes

* This plugin is intended for **already-developed images** (e.g. JPEG). RAW development is out of scope.
* Rendering is deterministic: same input + same `ImageEdit` => same output.
* Recommended flow: render to a temp file and then replace atomically in your storage layer.

## License

GPL-3.0. See `LICENSE`.
