<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\AiCaptioning;

/**
 * Describes one media item. Unique per media id while queued.
 */
final class DescribeMediaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(
        public readonly int $mediaId,
        public readonly ?string $profile = null,
    ) {
        $this->onConnection(config('ai-captioning.queue.connection'));
        $this->onQueue(config('ai-captioning.queue.name'));
    }

    public function uniqueId(): string
    {
        return (string) $this->mediaId;
    }

    public function handle(AiCaptioning $captioning): void
    {
        /** @var class-string<Media> $mediaClass */
        $mediaClass = config('media-library.media_model', Media::class);
        $media = $mediaClass::query()->find($this->mediaId);

        if ($media === null) {
            return;
        }

        $captioning->describe($media, $this->profile);
    }
}
