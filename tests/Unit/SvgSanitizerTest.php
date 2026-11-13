<?php

declare(strict_types=1);

use Stolic\AiCaptioning\Exceptions\UnsafeSvg;
use Stolic\AiCaptioning\Support\SvgSanitizer;

const SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10">';

it('removes image references to local files and URLs', function (string $element): void {
    $clean = SvgSanitizer::sanitize(SVG_OPEN.$element.'<rect width="10" height="10" fill="#d00"/></svg>');

    expect($clean)->not->toContain('/etc/')
        ->not->toContain('file:')
        ->not->toContain('example.com')
        ->toContain('<rect');
})->with([
    'file: href' => ['<image href="file:///etc/passwd" width="10" height="10"/>'],
    'xlink:href to a path' => ['<image xlink:href="/etc/hosts" width="10" height="10"/>'],
    'http href' => ['<image href="http://example.com/logo.png" width="10" height="10"/>'],
    'external use' => ['<use xlink:href="https://example.com/sprite.svg#logo"/>'],
    'svg data uri' => ['<image href="data:image/svg+xml;base64,PHN2Zy8+" width="10" height="10"/>'],
    'xinclude' => ['<xi:include xmlns:xi="http://www.w3.org/2001/XInclude" href="file:///etc/passwd" parse="text"/>'],
    'xml-stylesheet' => ['<?xml-stylesheet href="http://example.com/a.css"?>'],
]);

it('refuses DOCTYPE and entity declarations (XXE)', function (): void {
    SvgSanitizer::sanitize('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>');
})->throws(UnsafeSvg::class, 'DOCTYPE');

it('removes scripts, event handlers and foreignObject', function (): void {
    $clean = SvgSanitizer::sanitize(SVG_OPEN
        .'<script>alert(1)</script>'
        .'<foreignObject width="10" height="10"><iframe xmlns="http://www.w3.org/1999/xhtml" src="file:///etc/passwd"/></foreignObject>'
        .'<rect width="10" height="10" fill="#d00" onload="alert(2)" onclick="alert(3)"/>'
        .'<set attributeName="href" to="file:///etc/passwd"/>'
        .'</svg>');

    expect($clean)->not->toContain('script')
        ->not->toContain('alert')
        ->not->toContain('foreignObject')
        ->not->toContain('/etc/passwd')
        ->toContain('fill="#d00"');
});

it('keeps only fragment urls in styles and attributes', function (): void {
    $clean = SvgSanitizer::sanitize(SVG_OPEN
        .'<style>@import url(http://example.com/a.css); rect { fill: url(#grad); stroke: url("file:///etc/passwd"); }</style>'
        .'<rect width="10" height="10" style="fill: url(http://example.com/a.png); opacity: 0.5"/>'
        .'<rect width="10" height="10" fill="url(#grad)" mask="url(file:///etc/passwd)"/>'
        .'<rect width="10" height="10" style="background: u\72l(http://example.com/a.png)"/>'
        .'</svg>');

    expect($clean)->not->toContain('example.com')
        ->not->toContain('/etc/passwd')
        ->not->toContain('@import')
        ->not->toContain('mask=')
        ->toContain('fill: url(#grad)')
        ->toContain('fill="url(#grad)"')
        ->toContain('opacity: 0.5');
});

it('keeps internal references and raster data uris', function (): void {
    $png = 'data:image/png;base64,iVBORw0KGgo=';
    $clean = SvgSanitizer::sanitize(SVG_OPEN.'<defs><path id="a" d="M0 0h5v5z"/></defs><use href="#a"/><image href="'.$png.'"/></svg>');

    expect($clean)->toContain('href="#a"')->toContain($png);
});

it('refuses what it cannot parse safely', function (string $svg): void {
    SvgSanitizer::sanitize($svg);
})->with([
    'not xml' => ['not an svg'],
    'empty' => [''],
    'another root element' => ['<html><body/></html>'],
])->throws(UnsafeSvg::class);

it('renders a normal logo exactly as before', function (): void {
    requireSvgRendering();
    $original = (string) file_get_contents(brandingLogoSvgPath());

    $render = function (string $svg): string {
        $image = new Imagick;
        $image->setBackgroundColor(new ImagickPixel('transparent'));
        $image->setFormat('svg');
        $image->readImageBlob($svg);

        return $image->getImageSignature();
    };

    expect($render(SvgSanitizer::sanitize($original)))->toBe($render($original));
});
