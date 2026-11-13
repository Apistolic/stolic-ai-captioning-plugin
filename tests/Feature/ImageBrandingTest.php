<?php

declare(strict_types=1);

use Stolic\AiCaptioning\Branding\BrandingFailed;
use Stolic\AiCaptioning\Branding\BrandingOptions;
use Stolic\AiCaptioning\Branding\BrandingPosition;
use Stolic\AiCaptioning\Branding\Contracts\ImageBrander;
use Stolic\AiCaptioning\Branding\ShadeStyle;

/*
 * The 400 x 300 source is solid blue (30, 120, 200); the logos are red 2:1 rectangles.
 * With the defaults the logo is 64 x 32, the badge 80 x 42 with a 9 px margin:
 * bottom-right badge at (311, 249), logo centre at (351, 270), a badge-only pixel at (314, 269).
 */

beforeEach(function (): void {
    requireImagick();
    $this->output = sys_get_temp_dir().'/ai-captioning-branded-'.uniqid().'.png';
});

afterEach(function (): void {
    @unlink($this->output);
});

function isWhite(array $rgb): bool
{
    return min($rgb) >= 240;
}

function isDarkenedBlue(array $rgb): bool
{
    return $rgb[0] < 30 && $rgb[1] < 80 && $rgb[2] < 120 && $rgb[2] > $rgb[0];
}

it('writes a new file with the source dimensions and leaves the source untouched', function (): void {
    $before = md5_file(brandingSourcePath());

    $result = app(ImageBrander::class)->brand(brandingSourcePath(), brandingLogoPngPath(), $this->output, new BrandingOptions);

    expect($result)->toBe($this->output)
        ->and(array_slice((array) getimagesize($this->output), 0, 2))->toBe([400, 300])
        ->and(md5_file(brandingSourcePath()))->toBe($before)
        ->and(pixelAt($this->output, 5, 5))->toBe([30, 120, 200]);
});

it('recolors the logo white on a semi-opaque badge', function (string $logo): void {
    if ($logo === 'svg') {
        requireSvgRendering();
    }

    app(ImageBrander::class)->brand(brandingSourcePath(), $logo === 'svg' ? brandingLogoSvgPath() : brandingLogoPngPath(), $this->output, new BrandingOptions);

    expect(isWhite(pixelAt($this->output, 351, 270)))->toBeTrue()
        ->and(isDarkenedBlue(pixelAt($this->output, 314, 269)))->toBeTrue()
        ->and(pixelAt($this->output, 305, 269))->toBe([30, 120, 200]);
})->with(['png', 'svg']);

it('keeps the logo colors without forceWhite', function (): void {
    app(ImageBrander::class)->brand(brandingSourcePath(), brandingLogoPngPath(), $this->output, new BrandingOptions(forceWhite: false));

    [$red, $green, $blue] = pixelAt($this->output, 351, 270);

    expect($red)->toBeGreaterThan(180)->and($green)->toBeLessThan(20)->and($blue)->toBeLessThan(20);
});

it('places the badge in the chosen corner', function (): void {
    app(ImageBrander::class)->brand(brandingSourcePath(), brandingLogoPngPath(), $this->output, new BrandingOptions(position: BrandingPosition::TopLeft));

    expect(isWhite(pixelAt($this->output, 49, 30)))->toBeTrue()
        ->and(pixelAt($this->output, 351, 270))->toBe([30, 120, 200]);
});

it('shades the bottom edge with a gradient', function (): void {
    app(ImageBrander::class)->brand(brandingSourcePath(), brandingLogoPngPath(), $this->output, new BrandingOptions(shade: ShadeStyle::Gradient));

    $bottom = pixelAt($this->output, 10, 299);
    $higher = pixelAt($this->output, 10, 200);

    expect(isWhite(pixelAt($this->output, 351, 270)))->toBeTrue()
        ->and(isDarkenedBlue($bottom))->toBeTrue()
        ->and($higher[2])->toBeGreaterThan($bottom[2])->toBeLessThanOrEqual(200)
        ->and(pixelAt($this->output, 10, 0))->toBe([30, 120, 200]);
});

it('adds a white text label next to the logo', function (): void {
    $font = collect(['/System/Library/Fonts/Supplemental/Arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf'])->first(fn (string $path): bool => is_file($path));

    if ($font === null) {
        $this->markTestSkipped('No TrueType font found for the label test.');
    }

    $plain = $this->output.'.plain.png';
    app(ImageBrander::class)->brand(brandingSourcePath(), brandingLogoPngPath(), $plain, new BrandingOptions);
    app(ImageBrander::class)->brand(brandingSourcePath(), brandingLogoPngPath(), $this->output, new BrandingOptions(label: 'ABC School', fontPath: $font));

    // The badge grows to the left to make room for the label.
    expect(pixelAt($plain, 250, 270))->toBe([30, 120, 200])
        ->and(isDarkenedBlue(pixelAt($this->output, 250, 252)))->toBeTrue();

    @unlink($plain);
});

it('requires a font for a label', function (): void {
    config()->set('ai-captioning.branding.font_path', null);

    app(ImageBrander::class)->brand(brandingSourcePath(), brandingLogoPngPath(), $this->output, new BrandingOptions(label: 'ABC School'));
})->throws(BrandingFailed::class, 'font');

it('writes the format of the output path', function (): void {
    $jpeg = $this->output.'.jpg';

    app(ImageBrander::class)->brand(brandingSourcePath(), brandingLogoPngPath(), $jpeg, new BrandingOptions(quality: 80));

    expect(getimagesize($jpeg)['mime'])->toBe('image/jpeg');
    @unlink($jpeg);
});

it('never draws content an SVG logo references outside itself', function (): void {
    requireSvgRendering();
    $logo = sys_get_temp_dir().'/ai-captioning-evil-'.uniqid().'.svg';
    file_put_contents($logo, '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="20">'
        .'<image href="file://'.brandingLogoPngPath().'" width="40" height="20"/>'
        .'<rect x="0" y="0" width="1" height="1" fill="#fff" fill-opacity="0"/></svg>');

    app(ImageBrander::class)->brand(brandingSourcePath(), $logo, $this->output, new BrandingOptions(forceWhite: false));

    $image = new Imagick($this->output);
    $reddish = false;

    foreach ($image->getPixelIterator() as $row) {
        foreach ($row as $pixel) {
            $color = $pixel->getColor();
            $reddish = $reddish || ($color['r'] > 120 && $color['g'] < 60);
        }
    }

    expect($reddish)->toBeFalse();
    @unlink($logo);
});

it('refuses a source or logo that is not a raster image or an SVG', function (): void {
    $fake = sys_get_temp_dir().'/ai-captioning-fake-'.uniqid().'.png';
    file_put_contents($fake, "push graphic-context\nviewbox 0 0 10 10\nimage over 0,0 0,0 'file:///etc/passwd'\npop graphic-context");

    try {
        expect(fn () => app(ImageBrander::class)->brand($fake, brandingLogoPngPath(), $this->output, new BrandingOptions))
            ->toThrow(BrandingFailed::class, 'not a PNG, JPEG')
            ->and(fn () => app(ImageBrander::class)->brand(brandingSourcePath(), $fake, $this->output, new BrandingOptions))
            ->toThrow(BrandingFailed::class, 'not a PNG, JPEG');
    } finally {
        @unlink($fake);
    }
});

it('refuses to write over the source', function (): void {
    app(ImageBrander::class)->brand(brandingSourcePath(), brandingLogoPngPath(), brandingSourcePath(), new BrandingOptions);
})->throws(BrandingFailed::class, 'always writes a new file');

it('builds options from config with overrides and round-trips them', function (): void {
    config()->set('ai-captioning.branding.defaults.shade', 'gradient');

    $options = BrandingOptions::fromConfig(['position' => 'top-left', 'shadeOpacity' => 2, 'force_white' => false]);

    expect($options->shade)->toBe(ShadeStyle::Gradient)
        ->and($options->position)->toBe(BrandingPosition::TopLeft)
        ->and($options->shadeOpacity)->toBe(1.0)
        ->and($options->forceWhite)->toBeFalse()
        ->and(BrandingOptions::fromArray($options->toArray()))->toEqual($options)
        ->and($options->with(['label' => 'ABC'])->label)->toBe('ABC');
});
