<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Stolic\AiCaptioning\Actions\ApplyDescription;
use Stolic\AiCaptioning\Data\ImageDescription;

function aiDescription(string $caption = 'Students reading in a library', string $alt = 'Children at tables with open books'): ImageDescription
{
    return ImageDescription::fromFields('default', [
        'caption' => $caption,
        'alt' => $alt,
        'kind' => 'photo',
        'people_visible' => true,
        'concerns' => ['slightly blurry'],
    ], model: 'gemini-test', provider: 'gemini', describedAt: CarbonImmutable::parse('2026-11-13 10:00:00'));
}

it('fills empty values with the suggestion and marks them as ai', function (): void {
    $media = makeMediaRow();

    $outcomes = app(ApplyDescription::class)->apply($media, aiDescription());

    $media->refresh();
    expect($outcomes)->toBe(['caption' => 'filled', 'alt' => 'filled'])
        ->and($media->getCustomProperty('caption'))->toBe('Students reading in a library')
        ->and($media->getCustomProperty('caption_origin'))->toBe('ai')
        ->and($media->getCustomProperty('alt'))->toBe('Children at tables with open books')
        ->and($media->getCustomProperty('alt_origin'))->toBe('ai')
        ->and($media->getCustomProperty('ai_caption'))->toBe('Students reading in a library')
        ->and($media->getCustomProperty('ai_kind'))->toBe('photo')
        ->and($media->getCustomProperty('ai_people_visible'))->toBeTrue()
        ->and($media->getCustomProperty('ai_concerns'))->toBe(['slightly blurry'])
        ->and($media->getCustomProperty('ai_model'))->toBe('gemini-test')
        ->and($media->getCustomProperty('ai_profile'))->toBe('default')
        ->and($media->getCustomProperty('ai_described_at'))->toStartWith('2026-11-13T10:00:00');
});

it('refreshes values whose origin is ai', function (): void {
    $media = makeMediaRow(customProperties: ['caption' => 'Old AI caption', 'caption_origin' => 'ai']);

    $outcomes = app(ApplyDescription::class)->apply($media, aiDescription('New AI caption'));

    expect($outcomes['caption'])->toBe('refreshed')
        ->and($media->refresh()->getCustomProperty('caption'))->toBe('New AI caption');
});

it('never overwrites a value written by a person, but stores the suggestion', function (?string $origin): void {
    $properties = ['caption' => 'ABC School Image'];

    if ($origin !== null) {
        $properties['caption_origin'] = $origin;
    }

    $media = makeMediaRow(customProperties: $properties);

    $outcomes = app(ApplyDescription::class)->apply($media, aiDescription());

    $media->refresh();
    expect($outcomes['caption'])->toBe('kept')
        ->and($media->getCustomProperty('caption'))->toBe('ABC School Image')
        ->and($media->getCustomProperty('caption_origin'))->toBe($origin)
        ->and($media->getCustomProperty('ai_caption'))->toBe('Students reading in a library');
})->with([
    'school origin' => 'school',
    'staff origin' => 'staff',
    'no origin recorded' => null,
]);

it('overwrites a human value with the suggestion when explicitly asked', function (): void {
    $media = makeMediaRow(customProperties: [
        'caption' => 'ABC School Image',
        'caption_origin' => 'staff',
        'ai_caption' => 'Students reading in a library',
    ]);

    $applied = app(ApplyDescription::class)->useSuggestion($media, 'caption');

    $media->refresh();
    expect($applied)->toBeTrue()
        ->and($media->getCustomProperty('caption'))->toBe('Students reading in a library')
        ->and($media->getCustomProperty('caption_origin'))->toBe('ai');
});

it('does nothing when there is no suggestion to use', function (): void {
    $media = makeMediaRow(customProperties: ['caption' => 'ABC School Image', 'caption_origin' => 'staff']);

    expect(app(ApplyDescription::class)->useSuggestion($media, 'caption'))->toBeFalse()
        ->and($media->refresh()->getCustomProperty('caption'))->toBe('ABC School Image');
});

it('marks a human edit so later runs keep the value', function (): void {
    $media = makeMediaRow(customProperties: ['caption' => 'Old AI caption', 'caption_origin' => 'ai']);
    $policy = app(ApplyDescription::class);

    $media->setCustomProperty('caption', 'Our new library');
    $policy->markEditedBy($media, 'caption', 'school');
    $policy->apply($media->refresh(), aiDescription('Another AI caption'));

    $media->refresh();
    expect($media->getCustomProperty('caption'))->toBe('Our new library')
        ->and($media->getCustomProperty('caption_origin'))->toBe('school')
        ->and($media->getCustomProperty('ai_caption'))->toBe('Another AI caption')
        ->and($policy->hasHumanValue($media, 'caption'))->toBeTrue()
        ->and($policy->suggestionDiffers($media, 'caption'))->toBeTrue();
});

it('rejects an empty origin and unknown fields', function (): void {
    $media = makeMediaRow();
    $policy = app(ApplyDescription::class);

    expect(fn () => $policy->markEditedBy($media, 'caption', ' '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $policy->useSuggestion($media, 'title'))->toThrow(InvalidArgumentException::class);
});
