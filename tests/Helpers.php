<?php

declare(strict_types=1);

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Tests\Fixtures\Models\Post;

/**
 * A small real JPEG on disk.
 */
function fixtureImagePath(): string
{
    $path = sys_get_temp_dir().'/ai-captioning-fixture-'.getmypid().'.jpg';

    if (! is_file($path)) {
        $image = imagecreatetruecolor(40, 30);
        imagefilledrectangle($image, 0, 0, 40, 30, (int) imagecolorallocate($image, 30, 120, 200));
        imagejpeg($image, $path);
    }

    return $path;
}

/**
 * Add an image to a post's collection (conversions run synchronously).
 *
 * @param  array<string, mixed>  $customProperties
 */
function addImage(string $collection = 'gallery', array $customProperties = [], ?Post $post = null): Media
{
    $post ??= Post::query()->create(['title' => 'A post']);

    return $post->addMedia(fixtureImagePath())
        ->preservingOriginal()
        ->withCustomProperties($customProperties)
        ->toMediaCollection($collection);
}

/**
 * A media row without files or conversions (for policy and command tests).
 *
 * @param  array<string, mixed>  $customProperties
 */
function makeMediaRow(string $collection = 'gallery', array $customProperties = [], string $mimeType = 'image/jpeg', ?string $modelType = null): Media
{
    $post = Post::query()->create(['title' => 'A post']);

    return Media::query()->create([
        'model_type' => $modelType ?? Post::class,
        'model_id' => $post->id,
        'uuid' => (string) str()->uuid(),
        'collection_name' => $collection,
        'name' => 'image',
        'file_name' => 'image.jpg',
        'mime_type' => $mimeType,
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => 100,
        'manipulations' => [],
        'custom_properties' => $customProperties,
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

/**
 * A 400 x 300 solid blue PNG (the image to brand).
 */
function brandingSourcePath(): string
{
    $path = sys_get_temp_dir().'/ai-captioning-brand-source-'.getmypid().'.png';

    if (! is_file($path)) {
        $image = imagecreatetruecolor(400, 300);
        imagefilledrectangle($image, 0, 0, 400, 300, (int) imagecolorallocate($image, 30, 120, 200));
        imagepng($image, $path);
    }

    return $path;
}

/**
 * A 60 x 30 PNG logo: a red 40 x 20 rectangle on a transparent background.
 */
function brandingLogoPngPath(): string
{
    $path = sys_get_temp_dir().'/ai-captioning-brand-logo-'.getmypid().'.png';

    if (! is_file($path)) {
        $image = imagecreatetruecolor(60, 30);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 10, 5, 49, 24, (int) imagecolorallocate($image, 200, 0, 0));
        imagepng($image, $path);
    }

    return $path;
}

/**
 * A 40 x 20 red SVG logo (with an internal gradient reference).
 */
function brandingLogoSvgPath(): string
{
    return __DIR__.'/Fixtures/branding/logo.svg';
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function pixelAt(string $path, int $x, int $y): array
{
    $color = (new Imagick($path))->getImagePixelColor($x, $y)->getColor();

    return [(int) $color['r'], (int) $color['g'], (int) $color['b']];
}

function requireImagick(): void
{
    if (! extension_loaded('imagick')) {
        test()->markTestSkipped('The imagick extension is not installed.');
    }
}

function requireSvgRendering(): void
{
    requireImagick();

    if (Imagick::queryFormats('SVG') === []) {
        test()->markTestSkipped('This ImageMagick build has no SVG delegate, so SVG logos cannot be rendered.');
    }
}
