<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Prompts\AgentPrompt;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Agents\ImageDescriberAgent;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Contracts\ImageDescriber;
use Stolic\AiCaptioning\Enums\SkipReason;
use Stolic\AiCaptioning\Events\ImageDescribed;
use Stolic\AiCaptioning\Events\ImageDescriptionFailed;
use Stolic\AiCaptioning\Events\ImageDescriptionSkipped;
use Stolic\AiCaptioning\Jobs\DescribeMediaJob;

beforeEach(function (): void {
    config()->set('ai-captioning.auto_describe', false);
    ImageDescriberAgent::fake(fn (): array => [
        'caption' => 'Students reading in a library',
        'alt' => 'Children at tables with open books',
        'kind' => 'photo',
        'people_visible' => true,
        'concerns' => [],
    ]);
});

it('describes the web conversion with host context and applies the result', function (): void {
    Event::fake([ImageDescribed::class]);
    app(AiCaptioning::class)->resolveContextUsing(fn (Media $media): array => ['school' => 'ABC School']);
    $media = addImage('gallery', ['caption' => 'ABC School Image', 'caption_origin' => 'school']);

    (new DescribeMediaJob($media->id))->handle(app(AiCaptioning::class));

    $media->refresh();
    expect($media->getCustomProperty('caption'))->toBe('ABC School Image')
        ->and($media->getCustomProperty('ai_caption'))->toBe('Students reading in a library')
        ->and($media->getCustomProperty('alt'))->toBe('Children at tables with open books')
        ->and($media->getCustomProperty('alt_origin'))->toBe('ai');

    ImageDescriberAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->first()->path === $media->getPath('web')
        && $prompt->attachments->first()->mimeType() === 'image/webp'
        && str_contains($prompt->agent->instructions(), '- school: ABC School'));
    Event::assertDispatched(ImageDescribed::class, fn (ImageDescribed $event): bool => $event->media->is($media)
        && $event->outcomes === ['caption' => 'kept', 'alt' => 'filled']);
});

it('falls back to the original when the conversion is missing', function (): void {
    $media = addImage('gallery');
    $media->markAsConversionNotGenerated('web')->save();

    app(AiCaptioning::class)->describe($media->refresh());

    ImageDescriberAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->first()->path === $media->getPath());
});

it('skips when disabled in config or by the host hook', function (bool $configEnabled, bool $hostEnabled): void {
    Event::fake([ImageDescriptionSkipped::class]);
    config()->set('ai-captioning.enabled', $configEnabled);
    app(AiCaptioning::class)->enabledUsing(fn (): bool => $hostEnabled);
    $media = makeMediaRow();

    expect(app(AiCaptioning::class)->describe($media))->toBeNull();

    ImageDescriberAgent::assertNeverPrompted();
    Event::assertDispatched(ImageDescriptionSkipped::class, fn (ImageDescriptionSkipped $event): bool => $event->reason === SkipReason::Disabled);
})->with([
    'config off' => [false, true],
    'host toggle off' => [true, false],
]);

it('skips once the daily cap is reached', function (): void {
    Event::fake([ImageDescriptionSkipped::class, ImageDescribed::class]);
    app(AiCaptioning::class)->dailyCapUsing(fn (): int => 1);

    app(AiCaptioning::class)->describe(addImage('gallery'));
    $result = app(AiCaptioning::class)->describe(addImage('gallery'));

    expect($result)->toBeNull()
        ->and(app(AiCaptioning::class)->callsToday())->toBe(1);
    Event::assertDispatchedTimes(ImageDescribed::class, 1);
    Event::assertDispatched(ImageDescriptionSkipped::class, fn (ImageDescriptionSkipped $event): bool => $event->reason === SkipReason::DailyCapReached);
});

it('skips media that is not an image', function (): void {
    Event::fake([ImageDescriptionSkipped::class]);

    app(AiCaptioning::class)->describe(makeMediaRow(mimeType: 'application/pdf'));

    ImageDescriberAgent::assertNeverPrompted();
    Event::assertDispatched(ImageDescriptionSkipped::class, fn (ImageDescriptionSkipped $event): bool => $event->reason === SkipReason::NotAnImage);
});

it('dispatches a failed event and rethrows when describing fails', function (): void {
    Event::fake([ImageDescriptionFailed::class]);
    app()->instance(ImageDescriber::class, new class implements ImageDescriber
    {
        public function describe(string $absolutePath, string $mimeType, array $context, string $profile = 'default'): never
        {
            throw new RuntimeException('Provider down');
        }
    });

    expect(fn () => app(AiCaptioning::class)->describe(addImage('gallery')))->toThrow(RuntimeException::class, 'Provider down');

    Event::assertDispatched(ImageDescriptionFailed::class, fn (ImageDescriptionFailed $event): bool => $event->exception->getMessage() === 'Provider down');
});

it('ignores a job for media that no longer exists', function (): void {
    (new DescribeMediaJob(999))->handle(app(AiCaptioning::class));

    ImageDescriberAgent::assertNeverPrompted();
});
