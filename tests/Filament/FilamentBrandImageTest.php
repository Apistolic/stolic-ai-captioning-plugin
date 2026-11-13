<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Stolic\AiCaptioning\Tests\Fixtures\Livewire\MediaTable;
use Stolic\AiCaptioning\Tests\Fixtures\Models\Post;

use function Pest\Livewire\livewire;

it('brands a copy of the row image from "Brand image" and notifies', function (): void {
    requireImagick();
    Queue::fake();
    $media = addImage('gallery', ['caption' => 'ABC School Image']);

    livewire(MediaTable::class)
        ->mountAction(TestAction::make('brandImage')->table($media))
        ->assertMountedActionModalSee('Adds a copy of this image with the logo to "branded". The original is not changed.')
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertNotified('Branded copy added');

    $branded = Post::query()->find($media->model_id)->getMedia('branded');
    expect($branded)->toHaveCount(1)
        ->and($branded->first()->getCustomProperty('branded_from'))->toBe($media->id)
        ->and($branded->first()->getCustomProperty('caption'))->toBe('ABC School Image');
});

it('hides "Brand image" for media that is not an image', function (): void {
    $media = makeMediaRow(mimeType: 'application/pdf');

    livewire(MediaTable::class)->assertActionHidden(TestAction::make('brandImage')->table($media));
});
