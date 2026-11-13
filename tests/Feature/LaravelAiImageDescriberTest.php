<?php

declare(strict_types=1);

use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Prompts\AgentPrompt;
use Stolic\AiCaptioning\Agents\ImageDescriberAgent;
use Stolic\AiCaptioning\Contracts\ImageDescriber;
use Stolic\AiCaptioning\Exceptions\UnknownProfile;

it('sends the image and context to the agent and returns a description', function (): void {
    ImageDescriberAgent::fake([[
        'caption' => '  Students reading in a library  ',
        'alt' => 'Children at tables with open books',
        'kind' => 'photo',
        'people_visible' => true,
        'concerns' => [],
    ]])->preventStrayPrompts();
    config()->set('ai-captioning.profiles.default.model', 'gemini-flash-test');

    $description = app(ImageDescriber::class)->describe(
        fixtureImagePath(),
        'image/jpeg',
        ['school' => 'ABC School', 'city' => 'Austin', 'state' => ''],
    );

    expect($description->caption)->toBe('Students reading in a library')
        ->and($description->alt)->toBe('Children at tables with open books')
        ->and($description->kind)->toBe('photo')
        ->and($description->peopleVisible)->toBeTrue()
        ->and($description->concerns)->toBe([])
        ->and($description->profile)->toBe('default')
        ->and($description->model)->toBe('gemini-flash-test')
        ->and($description->provider)->toBe('gemini');

    ImageDescriberAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $instructions = $prompt->agent->instructions();

        return $prompt->provider->name() === 'gemini'
            && $prompt->model === 'gemini-flash-test'
            && $prompt->attachments->first() instanceof LocalImage
            && $prompt->attachments->first()->path === fixtureImagePath()
            && str_contains($instructions, "- school: ABC School\n- city: Austin")
            && ! str_contains($instructions, 'state');
    });
});

it('shortens strings longer than the profile maximum', function (): void {
    ImageDescriberAgent::fake([[
        'caption' => str_repeat('word ', 40),
        'alt' => 'Alt',
        'kind' => 'photo',
        'people_visible' => false,
        'concerns' => ['', 'mostly text'],
    ]]);

    $description = app(ImageDescriber::class)->describe(fixtureImagePath(), 'image/jpeg', []);

    expect(mb_strlen((string) $description->caption))->toBeLessThanOrEqual(120)
        ->and($description->caption)->toEndWith('…')
        ->and($description->concerns)->toBe(['mostly text']);
});

it('supports app-defined profiles with their own fields', function (): void {
    config()->set('ai-captioning.profiles.room', [
        'provider' => 'gemini',
        'instructions' => 'Classify the room of this {kind_of_home}.',
        'schema' => [
            'room' => ['type' => 'string', 'enum' => ['kitchen', 'bedroom', 'unlabeled']],
            'alt' => ['type' => 'string', 'max' => 125],
        ],
    ]);
    ImageDescriberAgent::fake([['room' => 'kitchen', 'alt' => 'A white kitchen']]);

    $description = app(ImageDescriber::class)->describe(fixtureImagePath(), 'image/jpeg', ['kind_of_home' => 'house'], 'room');

    expect($description->get('room'))->toBe('kitchen')
        ->and($description->alt)->toBe('A white kitchen')
        ->and($description->caption)->toBeNull();

    ImageDescriberAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->instructions() === 'Classify the room of this house.');
});

it('rejects unknown profiles', function (): void {
    app(ImageDescriber::class)->describe(fixtureImagePath(), 'image/jpeg', [], 'missing');
})->throws(UnknownProfile::class);
