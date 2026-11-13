<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Branding;

/**
 * How to brand an image. Sizes are fractions of the image width (tuned on 1024 px wide images), so the
 * result looks the same at any resolution.
 *
 *   BrandingOptions::fromConfig(['position' => 'top-left', 'shade' => 'gradient'])
 *   new BrandingOptions(label: 'ABC School', fontPath: '/path/Inter-SemiBold.ttf')
 */
final readonly class BrandingOptions
{
    /**
     * @param  float  $logoWidth  Maximum logo width, as a fraction of the image width.
     * @param  float  $logoMaxHeight  Maximum logo height, as a fraction of the image width.
     * @param  float  $padding  Space between the badge edge and its content (horizontal; vertical is 70% of it).
     * @param  float  $margin  Distance from the image edges.
     * @param  string  $shadeColor  Any ImageMagick color ('#000000', 'black', 'rgb(10,20,30)').
     * @param  float  $shadeOpacity  0 (invisible) to 1 (solid).
     * @param  float  $cornerRadius  Badge corner radius.
     * @param  bool  $forceWhite  Recolor every non-transparent logo pixel white, keeping its alpha.
     * @param  string|null  $label  Optional text next to the logo (white).
     * @param  string|null  $fontPath  A TTF/OTF file for the label (falls back to ai-captioning.branding.font_path).
     * @param  float  $labelSize  The label's font size.
     * @param  string|null  $format  Output format (jpg, png, webp, avif); null: from the output file's extension.
     * @param  int  $quality  Output quality for lossy formats (1-100).
     */
    public function __construct(
        public BrandingPosition $position = BrandingPosition::BottomRight,
        public float $logoWidth = 0.16,
        public float $logoMaxHeight = 0.08,
        public float $padding = 0.0195,
        public float $margin = 0.0234,
        public ShadeStyle $shade = ShadeStyle::Badge,
        public string $shadeColor = '#000000',
        public float $shadeOpacity = 0.55,
        public float $cornerRadius = 0.0117,
        public bool $forceWhite = true,
        public ?string $label = null,
        public ?string $fontPath = null,
        public float $labelSize = 0.033,
        public ?string $format = null,
        public int $quality = 90,
    ) {}

    /**
     * config('ai-captioning.branding.defaults') with the given overrides (snake_case or camelCase keys).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fromConfig(array $overrides = []): self
    {
        return self::fromArray([...(array) config('ai-captioning.branding.defaults', []), ...$overrides]);
    }

    /**
     * @param  array<string, mixed>  $values  snake_case or camelCase keys; unknown keys are ignored.
     */
    public static function fromArray(array $values): self
    {
        $normalised = [];

        foreach ($values as $key => $value) {
            $normalised[lcfirst(str_replace('_', '', ucwords((string) $key, '_')))] = $value;
        }

        $defaults = new self;
        $float = static fn (string $key, float $default): float => is_numeric($normalised[$key] ?? null) ? (float) $normalised[$key] : $default;
        $string = static fn (string $key): ?string => is_string($normalised[$key] ?? null) && trim($normalised[$key]) !== '' ? trim($normalised[$key]) : null;

        return new self(
            position: ($normalised['position'] ?? null) instanceof BrandingPosition
                ? $normalised['position']
                : (BrandingPosition::tryFrom((string) ($normalised['position'] ?? '')) ?? $defaults->position),
            logoWidth: $float('logoWidth', $defaults->logoWidth),
            logoMaxHeight: $float('logoMaxHeight', $defaults->logoMaxHeight),
            padding: $float('padding', $defaults->padding),
            margin: $float('margin', $defaults->margin),
            shade: ($normalised['shade'] ?? null) instanceof ShadeStyle
                ? $normalised['shade']
                : (ShadeStyle::tryFrom((string) ($normalised['shade'] ?? '')) ?? $defaults->shade),
            shadeColor: $string('shadeColor') ?? $defaults->shadeColor,
            shadeOpacity: min(1.0, max(0.0, $float('shadeOpacity', $defaults->shadeOpacity))),
            cornerRadius: $float('cornerRadius', $defaults->cornerRadius),
            forceWhite: array_key_exists('forceWhite', $normalised) ? (bool) $normalised['forceWhite'] : $defaults->forceWhite,
            label: $string('label'),
            fontPath: $string('fontPath'),
            labelSize: $float('labelSize', $defaults->labelSize),
            format: $string('format'),
            quality: is_numeric($normalised['quality'] ?? null) ? min(100, max(1, (int) $normalised['quality'])) : $defaults->quality,
        );
    }

    /**
     * A copy with some values changed (snake_case or camelCase keys).
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): self
    {
        return self::fromArray([...$this->toArray(), ...$values]);
    }

    /**
     * Serializable summary (stored on branded media as the 'branding' custom property, and passed to queued jobs).
     *
     * @return array<string, bool|float|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position->value,
            'logo_width' => $this->logoWidth,
            'logo_max_height' => $this->logoMaxHeight,
            'padding' => $this->padding,
            'margin' => $this->margin,
            'shade' => $this->shade->value,
            'shade_color' => $this->shadeColor,
            'shade_opacity' => $this->shadeOpacity,
            'corner_radius' => $this->cornerRadius,
            'force_white' => $this->forceWhite,
            'label' => $this->label,
            'font_path' => $this->fontPath,
            'label_size' => $this->labelSize,
            'format' => $this->format,
            'quality' => $this->quality,
        ];
    }
}
