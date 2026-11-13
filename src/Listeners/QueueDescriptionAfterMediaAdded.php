<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Support\Target;

/**
 * Queues a description when media is added to a target that reads the original (no conversion configured).
 */
final readonly class QueueDescriptionAfterMediaAdded
{
    public function __construct(private AiCaptioning $captioning) {}

    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $target = Target::for($event->media);

        if ($target === null || Target::isUndescribedBrandedCopy($event->media) || $target->conversion !== null) {
            return;
        }

        $this->captioning->queue($event->media, $target->profile);
    }
}
