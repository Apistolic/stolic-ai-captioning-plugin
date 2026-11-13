<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Branding\BrandingOptions;
use Stolic\AiCaptioning\Branding\BrandMedia;

/**
 * Brands a copy of one media item in the background (see BrandMedia).
 */
final class BrandMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  string|int  $logo  A local logo path, or the id of a media item holding the logo.
     * @param  array<string, mixed>  $options  BrandingOptions::toArray().
     */
    public function __construct(
        public readonly int $mediaId,
        public readonly string|int $logo,
        public readonly string $targetCollection,
        public readonly array $options = [],
    ) {
        $this->onConnection(config('ai-captioning.queue.connection'));
        $this->onQueue(config('ai-captioning.queue.name'));
    }

    public function handle(BrandMedia $brandMedia): void
    {
        /** @var class-string<Media> $mediaClass */
        $mediaClass = config('media-library.media_model', Media::class);
        $media = $mediaClass::query()->find($this->mediaId);
        $logo = is_int($this->logo) ? $mediaClass::query()->find($this->logo) : $this->logo;

        if ($media === null || $logo === null) {
            return;
        }

        $brandMedia->handle($media, $logo, $this->targetCollection, BrandingOptions::fromArray($this->options));
    }
}
