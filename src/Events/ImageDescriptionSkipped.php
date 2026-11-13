<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Enums\SkipReason;

final class ImageDescriptionSkipped
{
    use Dispatchable;

    public function __construct(
        public readonly Media $media,
        public readonly SkipReason $reason,
        public readonly string $profile,
    ) {}
}
