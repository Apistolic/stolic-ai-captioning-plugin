<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Stolic\AiCaptioning\Filament\Actions\UseAiSuggestionAction;
use Stolic\AiCaptioning\Filament\Actions\UseAiSuggestionBulkAction;
use Stolic\AiCaptioning\Filament\AiCaptioningPlugin;
use Stolic\AiCaptioning\Jobs\DescribeMediaJob;
use Stolic\AiCaptioning\Tests\Fixtures\Livewire\MediaTable;

use function Pest\Livewire\livewire;

it('shows the current value with the differing AI suggestion', function (): void {
    $media = makeMediaRow(customProperties: [
        'caption' => 'ABC School Image',
        'caption_origin' => 'school',
        'ai_caption' => 'Students reading in a library',
    ]);

    livewire(MediaTable::class)
        ->assertTableColumnStateSet('caption', 'ABC School Image', $media)
        ->assertTableColumnHasDescription('caption', 'AI: Students reading in a library', $media);
});

it('confirms before overwriting a caption with the AI suggestion', function (): void {
    $media = makeMediaRow(customProperties: [
        'caption' => 'ABC School Image',
        'caption_origin' => 'staff',
        'ai_caption' => 'Students reading in a library',
    ]);

    livewire(MediaTable::class)
        ->mountAction(TestAction::make('useAiCaption')->table($media))
        ->assertMountedActionModalSee("Overwrite caption 'ABC School Image' with 'Students reading in a library'?")
        ->callMountedAction()
        ->assertHasNoErrors();

    $media->refresh();
    expect($media->getCustomProperty('caption'))->toBe('Students reading in a library')
        ->and($media->getCustomProperty('caption_origin'))->toBe('ai');
});

it('hides the row action when the suggestion matches or is missing', function (): void {
    $media = makeMediaRow(customProperties: ['caption' => 'Same', 'caption_origin' => 'ai', 'ai_caption' => 'Same']);

    livewire(MediaTable::class)
        ->assertActionHidden(TestAction::make('useAiCaption')->table($media))
        ->assertActionHidden(TestAction::make('useAiAlt')->table($media));
});

it('confirms the bulk overwrite with the count of person-written values and applies to the selection only', function (): void {
    $human = makeMediaRow(customProperties: ['caption' => 'ABC School Image', 'caption_origin' => 'school', 'ai_caption' => 'AI one']);
    $empty = makeMediaRow(customProperties: ['ai_caption' => 'AI two']);
    $unselected = makeMediaRow(customProperties: ['caption' => 'Keep me', 'caption_origin' => 'staff', 'ai_caption' => 'AI three']);

    livewire(MediaTable::class)
        ->selectTableRecords([$human->id, $empty->id])
        ->mountAction(TestAction::make('useAiCaptionBulk')->table()->bulk())
        ->assertMountedActionModalSee('Use the AI caption for 2 selected images? 1 person-written caption will be replaced.')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($human->refresh()->getCustomProperty('caption'))->toBe('AI one')
        ->and($empty->refresh()->getCustomProperty('caption'))->toBe('AI two')
        ->and($unselected->refresh()->getCustomProperty('caption'))->toBe('Keep me');
});

it('queues a describe job from "Describe now"', function (): void {
    Queue::fake();
    $media = makeMediaRow();

    livewire(MediaTable::class)
        ->callAction(TestAction::make('describeNow')->table($media))
        ->assertHasNoErrors();

    Queue::assertPushed(DescribeMediaJob::class, fn (DescribeMediaJob $job): bool => $job->mediaId === $media->id);
});

it('builds confirmation texts for empty values and alt text', function (): void {
    $empty = makeMediaRow(customProperties: ['ai_alt' => 'Children at tables']);
    $same = makeMediaRow(customProperties: ['alt' => 'X', 'alt_origin' => 'ai', 'ai_alt' => 'X']);

    expect(UseAiSuggestionAction::confirmationText($empty, 'alt'))->toBe("Set alt text to 'Children at tables'?")
        ->and(UseAiSuggestionBulkAction::confirmationText([$empty, $same], 'alt'))
        ->toBe('Use the AI alt text for 2 selected images? 1 has no different suggestion and will be left as is. No person-written alt texts will be replaced.')
        ->and(UseAiSuggestionAction::forField('alt')->getLabel())->toBe('Use AI alt text')
        ->and(AiCaptioningPlugin::make()->getId())->toBe('ai-captioning');
});
