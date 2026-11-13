<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Branding\Contracts;

use Stolic\AiCaptioning\Branding\BrandingOptions;

/**
 * Puts a logo (and optional label) on a copy of an image. Never modifies the source.
 */
interface ImageBrander
{
    /**
     * @param  string  $sourcePath  A readable local image.
     * @param  string  $logoPath  A local SVG or PNG (with transparency).
     * @param  string  $outputPath  Where to write the branded image (must differ from the source).
     * @return string The output path.
     */
    public function brand(string $sourcePath, string $logoPath, string $outputPath, BrandingOptions $options): string;
}
