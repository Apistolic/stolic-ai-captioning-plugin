<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Stolic\AiCaptioning\Branding\BrandingOptions;
use Stolic\AiCaptioning\Branding\BrandMedia;
use Stolic\AiCaptioning\Branding\ShadeStyle;
use Stolic\AiCaptioning\Jobs\BrandMediaJob;
use Stolic\AiCaptioning\Jobs\DescribeMediaJob;
use Stolic\AiCaptioning\Tests\Fixtures\Models\Post;

beforeEach(function (): void {
    requireImagick();
    Queue::fake();
});

it('brands a copy into a new media item on the same model and keeps the source', function (): void {
    config()->set('ai-captioning.branding.copy_properties', ['source']);
    $media = addImage('gallery', [
        'caption' => 'ABC School Image',
        'caption_origin' => 'school',
        'alt' => 'Students in a library',
        'alt_origin' => 'ai',
        'ai_alt' => 'Students in a library',
        'source' => 'og image',
        'unrelated' => 'not copied',
    ]);
    $sourceHash = md5_file($media->getPath());

    $branded = app(BrandMedia::class)->handle($media, brandingLogoPngPath(), 'branded', new BrandingOptions(shade: ShadeStyle::Gradient));

    expect($branded->id)->not->toBe($media->id)
        ->and($branded->model_id)->toBe($media->model_id)
        ->and($branded->collection_name)->toBe('branded')
        ->and($branded->file_name)->toBe('ai-captioning-fixture-'.getmypid().'-branded.jpg')
        ->and($branded->getCustomProperty('branded_from'))->toBe($media->id)
        ->and($branded->getCustomProperty('branding'))->toMatchArray(['shade' => 'gradient', 'position' => 'bottom-right'])
        ->and($branded->getCustomProperty('caption'))->toBe('ABC School Image')
        ->and($branded->getCustomProperty('caption_origin'))->toBe('school')
        ->and($branded->getCustomProperty('alt_origin'))->toBe('ai')
        ->and($branded->getCustomProperty('ai_alt'))->toBe('Students in a library')
        ->and($branded->getCustomProperty('source'))->toBe('og image')
        ->and($branded->hasCustomProperty('unrelated'))->toBeFalse()
        ->and(is_file($branded->getPath()))->toBeTrue()
        ->and(array_slice((array) getimagesize($branded->getPath()), 0, 2))->toBe([40, 30])
        ->and(md5_file($media->refresh()->getPath()))->toBe($sourceHash)
        ->and(app(BrandMedia::class)->existingCopy($media, 'branded')?->is($branded))->toBeTrue();
});

it('takes the logo from a media item and does not describe the branded copy again', function (): void {
    config()->set('ai-captioning.targets', [['model' => Post::class, 'collections' => ['branded']]]);
    $post = Post::query()->create(['title' => 'A school']);
    $logo = $post->addMedia(brandingLogoPngPath())->preservingOriginal()->toMediaCollection('documents');
    $media = addImage('gallery', post: $post);

    $branded = app(BrandMedia::class)->handle($media, $logo, 'branded');

    expect($branded->getCustomProperty('branded_from'))->toBe($media->id);
    Queue::assertNotPushed(DescribeMediaJob::class);
});

it('brands from a queued job', function (): void {
    $media = addImage('gallery');

    (new BrandMediaJob($media->id, brandingLogoPngPath(), 'branded', ['position' => 'top-left']))->handle(app(BrandMedia::class));

    $branded = Post::query()->find($media->model_id)->getFirstMedia('branded');
    expect($branded?->getCustomProperty('branding')['position'])->toBe('top-left');
});
