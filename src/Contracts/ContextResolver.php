<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Contracts;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Supplies host-app facts about a media item's owner for the describe prompt.
 */
interface ContextResolver
{
    /**
     * @return array<string, string|null> Label => value, e.g. ['school' => 'ABC School', 'city' => 'Austin']. Empty values are dropped.
     */
    public function resolve(Media $media): array;
}
