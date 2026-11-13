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
    | Spend budget
    |--------------------------------------------------------------------------
    |
    | Every describe call is estimated in USD (see 'pricing') and added to a
    | ledger table (ai_captioning_spend). Before a call, AI captioning is
    | skipped when it is paused or the period's spend has reached the limit.
    | After a call, BudgetWarning is dispatched once per period when the
    | spend crosses warn_at x limit, and when it reaches the limit captioning
    | is paused (persisted in ai_captioning_state) and BudgetExceeded is
    | dispatched once. The host app sends email on those events.
    |
    | 'period': 'month' (calendar month, application timezone; a budget pause
    | lifts by itself in the next month) or 'total' (all time).
    | 'limit_usd': null for no limit (spend is still recorded). A host app can
    | override it with AiCaptioning::budgetUsing(fn (): ?float => ...).
    | A budget pause also lifts when the limit is raised above the spend.
    | A manual pause (AiCaptioning::pause(), ai-captioning:budget --pause)
    | stays until it is resumed.
    |
    */

    'budget' => [
        'enabled' => (bool) env('AI_CAPTIONING_BUDGET_ENABLED', true),
        'limit_usd' => env('AI_CAPTIONING_BUDGET_USD', 10.00),
        'period' => env('AI_CAPTIONING_BUDGET_PERIOD', 'month'),
        'warn_at' => 0.8,

        /*
         * The flat estimate for one call, used when the model has no price
         * below or the provider reported no token usage, and for calls that
         * failed (the provider may still have charged for them).
         */
        'fallback_cost_usd' => 0.002,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prices per model (USD)
    |--------------------------------------------------------------------------
    |
    | Model name => price per 1M input tokens, per 1M output tokens (thinking
    | tokens are billed as output) and an optional flat price per image.
    | Keys may also be 'provider/model'. Cached input tokens are counted at
    | the full input price, so the estimate does not come out too low.
    |
    | PLACEHOLDER VALUES: verify against Google's Gemini pricing
    | (https://ai.google.dev/gemini-api/docs/pricing) and your invoices
    | before relying on them. A model missing here uses the fallback above.
    |
    */

    'pricing' => [
        'gemini-3.5-flash' => ['input_per_million' => 0.50, 'output_per_million' => 3.00, 'per_image' => null], // PLACEHOLDER: verify against Google's Gemini pricing
        'gemini-2.5-flash' => ['input_per_million' => 0.30, 'output_per_million' => 2.50, 'per_image' => null], // PLACEHOLDER: verify against Google's Gemini pricing
        'gemini-2.5-flash-lite' => ['input_per_million' => 0.10, 'output_per_million' => 0.40, 'per_image' => null], // PLACEHOLDER: verify against Google's Gemini pricing
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | The spend ledger and the pause state. The package loads its migration
    | itself; to publish it instead, run
    | php artisan vendor:publish --tag=ai-captioning-migrations
    | (the migration skips tables that already exist, so both are safe).
    |
    */

    'database' => [
        'connection' => env('AI_CAPTIONING_DB_CONNECTION'),
        'run_migrations' => true,
        'spend_table' => 'ai_captioning_spend',
        'state_table' => 'ai_captioning_state',
    ],

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
    | Branding (logo overlay)
    |--------------------------------------------------------------------------
    |
    | Not AI: puts a logo (white by default) on a shaded badge or gradient in a
    | corner of a copy of an image (Stolic\AiCaptioning\Branding). Needs the
    | PHP imagick extension. Sizes are fractions of the image width.
    |
    | 'source_conversion': the conversion to brand when generated (null: the
    | original). 'disk': where branded copies go (null: the collection's disk).
    | 'font_path': a TTF/OTF file for text labels. 'copy_properties': extra
    | custom property keys copied to the branded copy (caption / alt values,
    | origins, suggestions and AI metadata are always copied).
    | 'describe_branded_copies': whether branded copies added to a target
    | collection are described again (false: they keep the copied text).
    |
    */

    'branding' => [
        'source_conversion' => null,
        'disk' => null,
        'font_path' => env('AI_CAPTIONING_BRAND_FONT'),
        'copy_properties' => [],
        'describe_branded_copies' => false,
        'defaults' => [
            'position' => 'bottom-right',   // top-left, top-right, bottom-left, bottom-right
            'logo_width' => 0.16,           // max logo width
            'logo_max_height' => 0.08,      // max logo height (fraction of the image width)
            'padding' => 0.0195,
            'margin' => 0.0234,
            'shade' => 'badge',             // badge, gradient, none
            'shade_color' => '#000000',
            'shade_opacity' => 0.55,
            'corner_radius' => 0.0117,
            'force_white' => true,
            'label_size' => 0.033,
            'format' => null,               // null: keep the source format
            'quality' => 90,
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
