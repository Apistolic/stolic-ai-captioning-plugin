<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Jobs\DescribeMediaJob;
use Stolic\AiCaptioning\Tests\Fixtures\Models\Post;

it('queues one job when the configured conversion of a target collection is generated', function (): void {
    Queue::fake();

    $media = addImage('gallery');

    Queue::assertPushed(DescribeMediaJob::class, 1);
    Queue::assertPushed(DescribeMediaJob::class, fn (DescribeMediaJob $job): bool => $job->mediaId === $media->id && $job->profile === 'default');
});

it('does not queue for collections or models that are not targets', function (): void {
    Queue::fake();

    addImage('documents');
    config()->set('ai-captioning.targets', [['model' => 'App\\Models\\Other', 'collections' => [], 'conversion' => 'web']]);
    addImage('gallery');

    Queue::assertNotPushed(DescribeMediaJob::class);
});

it('queues when media is added to a target without a conversion', function (): void {
    Queue::fake();
    config()->set('ai-captioning.targets', [['model' => Post::class, 'collections' => ['gallery'], 'profile' => 'default']]);

    $media = addImage('gallery');

    Queue::assertPushed(DescribeMediaJob::class, 1);
    Queue::assertPushed(DescribeMediaJob::class, fn (DescribeMediaJob $job): bool => $job->mediaId === $media->id);
});

it('does not queue on media added when the target waits for a conversion', function (): void {
    Queue::fake();
    $media = makeMediaRow('gallery');

    event(new MediaHasBeenAddedEvent($media));

    Queue::assertNotPushed(DescribeMediaJob::class);
});

it('queues a media item only once while a job for it is pending', function (): void {
    Queue::fake();
    $media = makeMediaRow('gallery');

    app(AiCaptioning::class)->queue($media);
    app(AiCaptioning::class)->queue($media);

    Queue::assertPushed(DescribeMediaJob::class, 1);
    expect((new DescribeMediaJob($media->id))->uniqueId())->toBe((string) $media->id);
});
