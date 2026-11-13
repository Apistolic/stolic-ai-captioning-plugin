<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class ImageDescriptionFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Media $media,
        public readonly Throwable $exception,
        public readonly string $profile,
    ) {}
}
