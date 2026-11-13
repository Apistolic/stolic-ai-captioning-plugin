<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Branding;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use Stolic\AiCaptioning\Branding\Contracts\ImageBrander;
use Stolic\AiCaptioning\Support\SvgSanitizer;

/**
 * The default brander, using the PHP imagick extension only (no ImageMagick CLI).
 *
 * A deterministic recipe ported from stolic-homes' BrandImageWithLogoBadge: a logo (made white by default,
 * keeping its alpha) and an optional white label, on a rounded semi-opaque badge or an edge gradient,
 * in a corner. All sizes are fractions of the image width (tuned on 1024 px), so it scales to any image.
 * SVG logos are rasterized at a high density and scaled down, so the edges stay sharp.
 * It always writes a new file and never touches the source.
 *
 * Inputs may come from arbitrary websites, so nothing is read by path: SVG logos are cleaned with SvgSanitizer
 * and rendered from the cleaned string; other files must be PNG, JPEG, GIF, WebP or AVIF/HEIC (checked by
 * their leading bytes) and are read as blobs of that format.
 */
final class ImagickImageBrander implements ImageBrander
{
    /**
     * How many times larger than the final size an SVG logo is rasterized before scaling down.
     */
    private const int SUPERSAMPLE = 6;

    private const float GAP_RATIO = 0.0156;

    private const float PADDING_Y_TO_X = 0.7;

    /**
     * A badge wider than this fraction of the image would dominate it: the label shrinks to fit.
     */
    private const float MAX_BADGE_WIDTH_RATIO = 0.45;

    /**
     * The gradient band is this many times the height of the logo block (plus margins).
     */
    private const float GRADIENT_HEIGHT_FACTOR = 2.5;

    public function brand(string $sourcePath, string $logoPath, string $outputPath, BrandingOptions $options): string
    {
        if (! extension_loaded('imagick')) {
            throw BrandingFailed::imagickMissing();
        }

        if (! is_file($sourcePath)) {
            throw BrandingFailed::fileNotFound('Source image', $sourcePath);
        }

        if (! is_file($logoPath)) {
            throw BrandingFailed::fileNotFound('Logo', $logoPath);
        }

        if (realpath($sourcePath) === realpath($outputPath) || $sourcePath === $outputPath) {
            throw BrandingFailed::wouldOverwriteSource($sourcePath);
        }

        $image = $this->readRaster($sourcePath, 'Source image');
        $image->autoOrient();
        $image->setImagePage(0, 0, 0, 0);

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        $padX = (int) round($width * $options->padding);
        $padY = (int) round($width * $options->padding * self::PADDING_Y_TO_X);
        $margin = (int) round($width * $options->margin);
        $gap = (int) round($width * self::GAP_RATIO);

        $logo = $this->renderLogo(
            $logoPath,
            max(1, (int) round($width * $options->logoWidth)),
            max(1, (int) round($width * $options->logoMaxHeight)),
            $options->forceWhite,
        );

        $content = $this->composeContent($logo, $options, $width, $padX, $gap);

        match ($options->shade) {
            ShadeStyle::Badge => $this->placeOnBadge($image, $content, $options, $width, $padX, $padY, $margin),
            ShadeStyle::Gradient => $this->placeOnGradient($image, $content, $options, $padX, $padY, $margin),
            ShadeStyle::None => $this->place($image, $content, $options->position, $margin, $margin),
        };

        $this->write($image, $outputPath, $options);

        $logo->clear();
        $content->clear();
        $image->clear();

        return $outputPath;
    }

    /**
     * The logo, fitted into maxWidth x maxHeight, with transparent borders trimmed, optionally recolored white.
     */
    private function renderLogo(string $path, int $maxWidth, int $maxHeight, bool $forceWhite): Imagick
    {
        $bytes = (string) file_get_contents($path);

        if ($this->isSvg($path, $bytes)) {
            $svg = SvgSanitizer::sanitize($bytes);

            $probe = new Imagick;
            $probe->setBackgroundColor(new ImagickPixel('transparent'));
            $probe->setFormat('svg');
            $probe->pingImageBlob($svg);
            $scale = min($maxWidth / max(1, $probe->getImageWidth()), $maxHeight / max(1, $probe->getImageHeight()));
            $probe->clear();

            $density = min(4800.0, max(72.0, 72 * $scale * self::SUPERSAMPLE));

            $logo = new Imagick;
            $logo->setBackgroundColor(new ImagickPixel('transparent'));
            $logo->setResolution($density, $density);
            $logo->setFormat('svg');
            $logo->readImageBlob($svg);
        } else {
            $logo = $this->readRaster($path, 'Logo', $bytes);
        }

        $logo->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

        // Trim transparent borders only (trimming an opaque logo would cut it down to its edge color).
        if ($logo->getImagePixelColor(0, 0)->getColorValue(Imagick::COLOR_ALPHA) == 0.0) {
            $logo->trimImage(0);
            $logo->setImagePage(0, 0, 0, 0);
        }

        if ($forceWhite) {
            $white = new Imagick;
            $white->newImage($logo->getImageWidth(), $logo->getImageHeight(), new ImagickPixel('white'));
            // Atop: the white source is drawn only where the logo has alpha; the logo's alpha is kept.
            $logo->compositeImage($white, Imagick::COMPOSITE_ATOP, 0, 0);
            $white->clear();
        }

        $logo->resizeImage($maxWidth, $maxHeight, Imagick::FILTER_LANCZOS, 1, true);
        $logo->setImagePage(0, 0, 0, 0);

        return $logo;
    }

    /**
     * The logo and the optional label side by side on a transparent canvas, vertically centered.
     */
    private function composeContent(Imagick $logo, BrandingOptions $options, int $imageWidth, int $padX, int $gap): Imagick
    {
        $label = $options->label !== null ? trim($options->label) : '';

        if ($label === '') {
            return clone $logo;
        }

        $fontPath = $options->fontPath ?? config('ai-captioning.branding.font_path');

        if (! is_string($fontPath) || $fontPath === '' || ! is_file($fontPath)) {
            throw BrandingFailed::fontMissing(is_string($fontPath) ? $fontPath : null);
        }

        $fontSize = max(1.0, $imageWidth * $options->labelSize);
        $text = $this->renderText($label, $fontPath, $fontSize);
        $maxTextWidth = max(1, (int) floor($imageWidth * self::MAX_BADGE_WIDTH_RATIO) - 2 * $padX - $logo->getImageWidth() - $gap);

        if ($text->getImageWidth() > $maxTextWidth) {
            $fontSize = max(1.0, floor($fontSize * $maxTextWidth / $text->getImageWidth()));
            $text->clear();
            $text = $this->renderText($label, $fontPath, $fontSize);
        }

        $contentWidth = $logo->getImageWidth() + $gap + $text->getImageWidth();
        $contentHeight = max($logo->getImageHeight(), $text->getImageHeight());

        $content = new Imagick;
        $content->newImage($contentWidth, $contentHeight, new ImagickPixel('transparent'));
        $content->setImageFormat('png');
        $content->compositeImage($logo, Imagick::COMPOSITE_OVER, 0, intdiv($contentHeight - $logo->getImageHeight(), 2));
        $content->compositeImage($text, Imagick::COMPOSITE_OVER, $logo->getImageWidth() + $gap, intdiv($contentHeight - $text->getImageHeight(), 2));
        $text->clear();

        return $content;
    }

    private function renderText(string $text, string $fontPath, float $fontSize): Imagick
    {
        $draw = new ImagickDraw;
        $draw->setFont($fontPath);
        $draw->setFontSize($fontSize);
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->setTextAntialias(true);

        $canvas = new Imagick;
        $canvas->newImage(1, 1, new ImagickPixel('transparent'));
        $metrics = $canvas->queryFontMetrics($draw, $text);
        $canvas->clear();

        $image = new Imagick;
        $image->newImage(max(1, (int) ceil($metrics['textWidth'])), max(1, (int) ceil($metrics['textHeight'])), new ImagickPixel('transparent'));
        $image->setImageFormat('png');
        $image->annotateImage($draw, 0, (float) $metrics['ascender'], 0, $text);

        return $image;
    }

    private function placeOnBadge(Imagick $image, Imagick $content, BrandingOptions $options, int $imageWidth, int $padX, int $padY, int $margin): void
    {
        $boxWidth = $content->getImageWidth() + 2 * $padX;
        $boxHeight = $content->getImageHeight() + 2 * $padY;
        $radius = (int) round($imageWidth * $options->cornerRadius);

        $badge = new Imagick;
        $badge->newImage($boxWidth, $boxHeight, new ImagickPixel('transparent'));
        $badge->setImageFormat('png');

        $draw = new ImagickDraw;
        $draw->setFillColor($this->shadePixel($options->shadeColor, 1.0));
        $draw->setFillOpacity($options->shadeOpacity);
        $draw->roundRectangle(0, 0, $boxWidth - 1, $boxHeight - 1, $radius, $radius);
        $badge->drawImage($draw);
        $badge->compositeImage($content, Imagick::COMPOSITE_OVER, $padX, $padY);

        $this->place($image, $badge, $options->position, $margin, $margin);
        $badge->clear();
    }

    private function placeOnGradient(Imagick $image, Imagick $content, BrandingOptions $options, int $padX, int $padY, int $margin): void
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $bandHeight = min($height, (int) round(($content->getImageHeight() + 2 * ($margin + $padY)) * self::GRADIENT_HEIGHT_FACTOR));
        $color = $this->shadePixel($options->shadeColor, 1.0)->getColor();
        $rgb = "{$color['r']},{$color['g']},{$color['b']}";

        $band = new Imagick;
        $band->newPseudoImage($width, max(1, $bandHeight), "gradient:rgba({$rgb},0)-rgba({$rgb},{$options->shadeOpacity})");

        if ($options->position->isTop()) {
            $band->flipImage();
        }

        $image->compositeImage($band, Imagick::COMPOSITE_OVER, 0, $options->position->isTop() ? 0 : $height - $bandHeight);
        $band->clear();

        $this->place($image, $content, $options->position, $margin + $padX, $margin + $padY);
    }

    private function place(Imagick $image, Imagick $overlay, BrandingPosition $position, int $offsetX, int $offsetY): void
    {
        $x = $position->isLeft() ? $offsetX : $image->getImageWidth() - $overlay->getImageWidth() - $offsetX;
        $y = $position->isTop() ? $offsetY : $image->getImageHeight() - $overlay->getImageHeight() - $offsetY;

        $image->compositeImage($overlay, Imagick::COMPOSITE_OVER, $x, $y);
    }

    private function write(Imagick $image, string $outputPath, BrandingOptions $options): void
    {
        $format = strtolower($options->format ?? pathinfo($outputPath, PATHINFO_EXTENSION));
        $format = match ($format) {
            '' => strtolower($image->getImageFormat()),
            'jpg' => 'jpeg',
            default => $format,
        };

        if ($format === 'jpeg') {
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        }

        $image->setFormat($format);
        $image->setImageFormat($format);
        $image->setImageCompressionQuality($options->quality);
        $image->stripImage();

        $directory = dirname($outputPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $image->writeImage($outputPath);
    }

    private function shadePixel(string $color, float $opacity): ImagickPixel
    {
        $pixel = new ImagickPixel($color);
        $pixel->setColorValue(Imagick::COLOR_ALPHA, $opacity);

        return $pixel;
    }

    /**
     * Read the first frame of a PNG, JPEG, GIF, WebP or AVIF/HEIC file as a blob of that format; anything else
     * (SVG, MVG, PostScript, text, ...) is refused, so ImageMagick never picks a coder from untrusted content.
     */
    private function readRaster(string $path, string $what, ?string $bytes = null): Imagick
    {
        $bytes ??= (string) file_get_contents($path);
        $format = self::rasterFormat($bytes);

        if ($format === null) {
            throw new BrandingFailed("{$what} is not a PNG, JPEG, GIF, WebP or AVIF image: {$path}");
        }

        $image = new Imagick;
        $image->setBackgroundColor(new ImagickPixel('transparent'));
        $image->setFormat($format);
        $image->readImageBlob($bytes);

        if ($image->getNumberImages() > 1) {
            $image->setIteratorIndex(0);
            $first = $image->getImage();
            $image->clear();
            $image = $first;
        }

        return $image;
    }

    /**
     * The raster format from a file's leading bytes, or null.
     */
    public static function rasterFormat(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['avif', 'avis'], true) => 'avif',
            substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'mif1', 'msf1'], true) => 'heic',
            default => null,
        };
    }

    private function isSvg(string $path, string $bytes): bool
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return true;
        }

        return self::rasterFormat($bytes) === null && stripos(substr($bytes, 0, 4096), '<svg') !== false;
    }
}
