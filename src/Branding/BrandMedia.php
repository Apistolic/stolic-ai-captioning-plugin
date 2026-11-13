<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Branding;

use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Branding\Contracts\ImageBrander;
use Stolic\AiCaptioning\Support\LocalImageFile;

/**
 * Brands a copy of a media item's image into a new media item on the same model. The source is never changed.
 *
 * The copy gets the custom properties 'branded_from' (the source media id) and 'branding' (the options),
 * plus the source's caption / alt values, origins and suggestions, the AI metadata and
 * config('ai-captioning.branding.copy_properties').
 *
 *   app(BrandMedia::class)->handle($media, $school->getFirstMedia('school_logo'), 'branded_gallery');
 */
final class BrandMedia
{
    public function __construct(private readonly ImageBrander $brander) {}

    /**
     * @param  string|Media  $logo  A local SVG/PNG path, or a media item holding the logo (on any disk).
     */
    public function handle(Media $media, string|Media $logo, string $targetCollection, ?BrandingOptions $options = null): Media
    {
        $options ??= BrandingOptions::fromConfig();
        $model = $media->model;

        if (! $model instanceof HasMedia) {
            throw new BrandingFailed("Media [{$media->getKey()}] has no owner model to add the branded copy to.");
        }

        $source = null;
        $logoFile = null;
        $outputPath = null;

        try {
            $source = LocalImageFile::for($media, $this->sourceConversion($media));
            $logoFile = $logo instanceof Media ? LocalImageFile::for($logo) : null;
            $logoPath = $logoFile?->path ?? $logo;

            if (! is_string($logoPath) || $logoPath === '') {
                throw BrandingFailed::noLogo();
            }

            $extension = $this->extensionFor($options, $source->path);
            $outputPath = sys_get_temp_dir().'/ai-captioning-branded-'.Str::uuid().'.'.$extension;

            $this->brander->brand($source->path, $logoPath, $outputPath, $options);

            /** @var Media $branded */
            $branded = $model->addMedia($outputPath)
                ->usingName((string) $media->name)
                ->usingFileName(Str::slug(pathinfo((string) $media->file_name, PATHINFO_FILENAME) ?: 'image').'-branded.'.$extension)
                ->withCustomProperties([
                    ...$this->copiedProperties($media),
                    'branded_from' => $media->getKey(),
                    'branding' => $options->toArray(),
                ])
                ->toMediaCollection($targetCollection, (string) config('ai-captioning.branding.disk', ''));

            return $branded;
        } finally {
            $source?->cleanup();
            $logoFile?->cleanup();

            if ($outputPath !== null && is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    /**
     * The latest branded copy of the media item in a collection, if one exists.
     */
    public function existingCopy(Media $media, string $targetCollection): ?Media
    {
        $model = $media->model;

        if (! $model instanceof HasMedia) {
            return null;
        }

        return $model->getMedia($targetCollection)
            ->last(fn (Media $candidate): bool => (int) $candidate->getCustomProperty('branded_from') === (int) $media->getKey());
    }

    private function sourceConversion(Media $media): ?string
    {
        $conversion = config('ai-captioning.branding.source_conversion');

        return is_string($conversion) && $conversion !== '' && $media->hasGeneratedConversion($conversion) ? $conversion : null;
    }

    private function extensionFor(BrandingOptions $options, string $sourcePath): string
    {
        $extension = strtolower($options->format ?? pathinfo($sourcePath, PATHINFO_EXTENSION));

        return match ($extension) {
            '' => 'png',
            'jpeg' => 'jpg',
            default => $extension,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function copiedProperties(Media $media): array
    {
        $keys = (array) config('ai-captioning.branding.copy_properties', []);

        foreach ((array) config('ai-captioning.properties.fields', []) as $fieldKeys) {
            $keys = [...$keys, ...array_values((array) $fieldKeys)];
        }

        $keys = [...$keys, ...array_values((array) config('ai-captioning.properties.metadata', []))];
        $properties = [];

        foreach (array_unique(array_filter($keys, 'is_string')) as $key) {
            if ($media->hasCustomProperty($key)) {
                $properties[$key] = $media->getCustomProperty($key);
            }
        }

        return $properties;
    }
}
