<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Stolic\AiCaptioning\Jobs\DescribeMediaJob;

beforeEach(function (): void {
    Queue::fake();
});

it('only reports what it would queue without --apply', function (): void {
    makeMediaRow('gallery');
    makeMediaRow('featured');

    $this->artisan('ai-captioning:describe')
        ->expectsOutputToContain('Dry run: 2 media item(s) would be queued')
        ->assertSuccessful();

    Queue::assertNotPushed(DescribeMediaJob::class);
});

it('queues jobs with --apply, honouring collection, only-missing and limit filters', function (): void {
    $missingCaption = makeMediaRow('gallery', ['alt' => 'Alt text']);
    makeMediaRow('gallery', ['caption' => 'Caption', 'alt' => 'Alt text']);
    makeMediaRow('featured');
    makeMediaRow('documents');
    makeMediaRow('gallery', mimeType: 'application/pdf');

    $this->artisan('ai-captioning:describe', ['--collection' => ['gallery'], '--only-missing' => true, '--apply' => true])
        ->expectsOutputToContain('Queued 1 describe job(s)')
        ->assertSuccessful();

    Queue::assertPushed(DescribeMediaJob::class, 1);
    Queue::assertPushed(DescribeMediaJob::class, fn (DescribeMediaJob $job): bool => $job->mediaId === $missingCaption->id);
});

it('stops at --limit', function (): void {
    makeMediaRow('gallery');
    makeMediaRow('gallery');
    makeMediaRow('gallery');

    $this->artisan('ai-captioning:describe', ['--limit' => 2, '--apply' => true])->assertSuccessful();

    Queue::assertPushed(DescribeMediaJob::class, 2);
});

it('fails for a model that is not a configured target', function (): void {
    $this->artisan('ai-captioning:describe', ['--model' => 'App\\Models\\Unknown'])->assertFailed();
});
