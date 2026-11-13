<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Contracts;

use Stolic\AiCaptioning\Data\ImageDescription;

/**
 * Looks at one image file and returns structured facts about it.
 */
interface ImageDescriber
{
    /**
     * @param  string  $absolutePath  A readable local file.
     * @param  array<string, string>  $context  Facts about where the image is used (e.g. ['school' => 'ABC School', 'city' => 'Austin']).
     * @param  string  $profile  A profile name from config('ai-captioning.profiles').
     */
    public function describe(string $absolutePath, string $mimeType, array $context, string $profile = 'default'): ImageDescription;
}
