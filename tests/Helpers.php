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
