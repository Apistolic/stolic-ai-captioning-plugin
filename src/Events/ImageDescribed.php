<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Data\ImageDescription;

final class ImageDescribed
{
    use Dispatchable;

    /**
     * @param  array<string, string>  $outcomes  Field => filled, refreshed or kept.
     */
    public function __construct(
        public readonly Media $media,
        public readonly ImageDescription $description,
        public readonly array $outcomes,
    ) {}
}
