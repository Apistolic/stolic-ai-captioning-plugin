<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Data\CostEstimate;
use Stolic\AiCaptioning\Data\ImageDescription;

final class ImageDescribed
{
    use Dispatchable;

    /**
     * @param  array<string, string>  $outcomes  Field => filled, refreshed or kept.
     * @param  CostEstimate|null  $cost  The estimated cost added to the spend ledger (token counts are in $description->usage).
     */
    public function __construct(
        public readonly Media $media,
        public readonly ImageDescription $description,
        public readonly array $outcomes,
        public readonly ?CostEstimate $cost = null,
    ) {}
}
