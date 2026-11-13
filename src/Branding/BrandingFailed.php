<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Branding;

use RuntimeException;

final class BrandingFailed extends RuntimeException
{
    public static function imagickMissing(): self
    {
        return new self('Branding images needs the PHP imagick extension (ext-imagick).');
    }

    public static function fileNotFound(string $what, string $path): self
    {
        return new self("{$what} not found: {$path}");
    }

    public static function wouldOverwriteSource(string $path): self
    {
        return new self("The output path is the source image ({$path}); branding always writes a new file.");
    }

    public static function fontMissing(?string $fontPath): self
    {
        return new self('A text label needs a font file: set the fontPath option or ai-captioning.branding.font_path (got '.($fontPath ?? 'null').').');
    }

    public static function noLogo(): self
    {
        return new self('No logo to brand with.');
    }
}
