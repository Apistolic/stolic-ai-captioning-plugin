<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Listeners;

use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Support\Target;

/**
 * Queues a description when a target's configured conversion has been generated.
 */
final readonly class QueueDescriptionAfterConversion
{
    public function __construct(private AiCaptioning $captioning) {}

    public function handle(ConversionHasBeenCompletedEvent $event): void
    {
        $target = Target::for($event->media);

        if ($target === null || Target::isUndescribedBrandedCopy($event->media) || $target->conversion === null || $target->conversion !== $event->conversion->getName()) {
            return;
        }

        $this->captioning->queue($event->media, $target->profile);
    }
}
