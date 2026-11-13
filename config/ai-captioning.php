<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. A host app can add its own check (for example a Spatie
    | settings toggle) with AiCaptioning::enabledUsing(fn () => ...).
    | Both must allow a call before the model is asked.
    |
    */

    'enabled' => (bool) env('AI_CAPTIONING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Daily call cap
    |--------------------------------------------------------------------------
    |
    | The maximum number of describe calls per day (application timezone),
    | counted in the cache. Null means no cap. A host app can override it
    | with AiCaptioning::dailyCapUsing(fn (): ?int => ...).
    |
    */

    'daily_cap' => env('AI_CAPTIONING_DAILY_CAP', 200),

    'cache_store' => env('AI_CAPTIONING_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('AI_CAPTIONING_QUEUE_CONNECTION'),
        'name' => env('AI_CAPTIONING_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic targets
    |--------------------------------------------------------------------------
    |
    | Media of these models and collections is described automatically.
    | With a conversion, the job is queued when that conversion has been
    | generated and the conversion file is sent (smaller and cheaper);
    | without one, the job is queued when the media is added and the
    | original is sent. A missing conversion file falls back to the original.
    |
    | Example:
    |   [
    |       'model' => App\Models\SchoolDetail::class,
    |       'collections' => ['featured_image', 'view_gallery'],
    |       'conversion' => 'web',
    |       'profile' => 'default',
    |   ],
    |
    */

    'auto_describe' => true,

    'targets' => [],

    /*
    |--------------------------------------------------------------------------
    | Context resolver
    |--------------------------------------------------------------------------
    |
    | A class implementing Stolic\AiCaptioning\Contracts\ContextResolver
    | that returns facts about the media's owner (school name, city, ...).
    | They fill the {context} placeholder of a profile's instructions.
    | A closure can be registered instead with
    | AiCaptioning::resolveContextUsing(fn (Media $media): array => ...).
    |
    */

    'context_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Profiles
    |--------------------------------------------------------------------------
    |
    | A profile is one way of describing an image: instructions (with a
    | {context} placeholder, and {key} placeholders for single context
    | values), the structured fields to return, and the provider / model.
    |
    | Schema field options: type (string|boolean|integer|number|array),
    | description, enum, max (strings: characters), items (array item type),
    | required (default true).
    |
    | 'fields' maps a returned field to the media custom properties it may
    | write (value, origin and suggestion keys). 'metadata' maps returned
    | fields (and described_at, model, profile) to custom properties that
    | are always written. Both default to the 'properties' section below.
    |
    */

    'default_profile' => 'default',

    'profiles' => [

        'default' => [
            'provider' => env('AI_CAPTIONING_PROVIDER', 'gemini'),
            'model' => env('AI_CAPTIONING_MODEL'),
            'timeout' => 60,
            'prompt' => 'Describe this image.',
            'instructions' => <<<'PROMPT'
                You describe one image for a website's media library.
                Context about where the image is used:
                {context}

                Return:
                - caption: one short, plain, factual sentence for a gallery (at most 120 characters). No marketing claims, no guesses about names or places that are not visible or given in the context.
                - alt: what the image shows, for screen readers (at most 125 characters). Do not start with "Image of" or "Picture of".
                - kind: photo, logo, screenshot, graphic or text-heavy.
                - people_visible: true when one or more people are recognisably visible.
                - concerns: short notes on anything that makes the image unsuitable (blurry, mostly text, watermark, unsafe content). An empty list when there are none.
                PROMPT,
            'schema' => [
                'caption' => ['type' => 'string', 'max' => 120, 'description' => 'One plain factual sentence, at most 120 characters.'],
                'alt' => ['type' => 'string', 'max' => 125, 'description' => 'Alt text, at most 125 characters.'],
                'kind' => ['type' => 'string', 'enum' => ['photo', 'logo', 'screenshot', 'graphic', 'text-heavy']],
                'people_visible' => ['type' => 'boolean'],
                'concerns' => ['type' => 'array', 'items' => 'string'],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Custom property keys
    |--------------------------------------------------------------------------
    |
    | 'fields': for each returned field that is applied to the media item,
    | the key holding the published value, the key recording who wrote it
    | (the origin: 'ai' or a host-defined human origin such as 'staff'),
    | and the key keeping the latest AI suggestion.
    |
    | 'metadata': returned fields stored as-is on every run, plus what
    | produced the answer.
    |
    */

    'properties' => [
        'fields' => [
            'caption' => ['value' => 'caption', 'origin' => 'caption_origin', 'suggestion' => 'ai_caption'],
            'alt' => ['value' => 'alt', 'origin' => 'alt_origin', 'suggestion' => 'ai_alt'],
        ],
        'metadata' => [
            'kind' => 'ai_kind',
            'people_visible' => 'ai_people_visible',
            'concerns' => 'ai_concerns',
            'described_at' => 'ai_described_at',
            'model' => 'ai_model',
            'profile' => 'ai_profile',
        ],
    ],

];
