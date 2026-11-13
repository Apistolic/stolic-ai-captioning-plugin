# Stolic AI captioning

AI image descriptions (captions, alt text, and any other structured facts) for [Spatie Media Library](https://spatie.be/docs/laravel-medialibrary) media, using [`laravel/ai`](https://github.com/laravel/ai), with optional Filament 5 columns and actions.

When an image is added to a configured collection, a vision model suggests a caption and alt text. They fill empty values. **A value a person wrote is never overwritten without a person confirming it.**

> **Private package.** Used by the Stolic apps (THI, stolic-homes). It is not published on Packagist; install it from the private GitHub repository.

## Requirements

- PHP 8.4+, Laravel 12 or 13
- `laravel/ai` ^0.8, `spatie/laravel-medialibrary` ^11
- Optional: `filament/filament` ^5 for the classes in `Stolic\AiCaptioning\Filament`

## Install

Add the private repository and require the package (Composer needs GitHub access to the repo, e.g. through an SSH key or `composer config --global github-oauth.github.com <token>`):

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/Apistolic/stolic-ai-captioning-plugin.git"
    }
]
```

```bash
composer require apistolic/stolic-ai-captioning-plugin:^0.1
php artisan vendor:publish --tag=ai-captioning-config
```

Until a version is tagged, require `dev-main`.

Set the key of the provider you use in `config/ai.php` (e.g. `GEMINI_API_KEY`).

## How it works

1. A media item is added to a configured model and collection.
2. When the configured conversion (e.g. `web`) has been generated (or straight away when no conversion is configured), a unique queued `DescribeMediaJob` is dispatched for it.
3. The job checks the guards (enabled, daily cap), copies the image locally if it lives on a remote disk (S3 / R2), asks the model with the profile's instructions plus host context, and applies the answer with the overwrite rule.
4. `ImageDescribed`, `ImageDescriptionSkipped` or `ImageDescriptionFailed` is dispatched for the host app to log.

## Configuration

`config/ai-captioning.php`:

| Key | Meaning |
|---|---|
| `enabled` | Master switch (`AI_CAPTIONING_ENABLED`) |
| `daily_cap` | Max describe calls per day, `null` for none (`AI_CAPTIONING_DAILY_CAP`, default 200) |
| `cache_store` | Cache store for the daily counter (default store when null) |
| `queue.connection`, `queue.name` | Where `DescribeMediaJob` runs |
| `auto_describe` | Register the Media Library listeners (default true) |
| `targets` | List of `['model' => ..., 'collections' => [...], 'conversion' => 'web', 'profile' => 'default']` |
| `context_resolver` | A class implementing `Contracts\ContextResolver` |
| `default_profile`, `profiles` | Describe profiles (below) |
| `properties.fields`, `properties.metadata` | Custom property keys (below) |

### Profiles

A profile is one way of describing an image:

```php
'profiles' => [
    'default' => [
        'provider' => 'gemini',            // a laravel/ai provider name, or a failover list
        'model' => null,                   // null: the provider's default text model
        'timeout' => 60,
        'prompt' => 'Describe this image.',
        'instructions' => "You describe one image...\nContext:\n{context}\n...",
        'schema' => [
            'caption' => ['type' => 'string', 'max' => 120],
            'alt' => ['type' => 'string', 'max' => 125],
            'kind' => ['type' => 'string', 'enum' => ['photo', 'logo', 'screenshot', 'graphic', 'text-heavy']],
            'people_visible' => ['type' => 'boolean'],
            'concerns' => ['type' => 'array', 'items' => 'string'],
        ],
        // optional: 'fields' => [...], 'metadata' => [...] (default to the 'properties' section)
    ],
],
```

- `{context}` becomes a bullet list of the context values (`- school: ABC School`); `{school}` etc. insert single values.
- Schema field options: `type` (string, boolean, integer, number, array), `description`, `enum`, `max` (strings longer than this are shortened, since providers do not all enforce it), `items`, `required` (default true).
- The shipped `default` profile returns `caption` (one plain factual sentence, at most 120 characters), `alt` (at most 125), `kind`, `people_visible` and `concerns`.

### Custom properties

| Property (default key) | Meaning |
|---|---|
| `caption`, `alt` | The values used for publishing |
| `caption_origin`, `alt_origin` | Who wrote the current value: `ai`, or a host origin such as `school` or `staff` |
| `ai_caption`, `ai_alt` | The latest suggestions, kept even when not applied |
| `ai_kind`, `ai_people_visible`, `ai_concerns` | The rest of the answer |
| `ai_described_at`, `ai_model`, `ai_profile` | When and what produced it |

## The overwrite rule

All writes go through `Stolic\AiCaptioning\Actions\ApplyDescription`:

- An **empty** value is filled with the suggestion; origin `ai`.
- A value whose origin is **`ai`** is refreshed by a later run.
- **Any other value** (origin `school`, `staff`, ..., or a non-empty value with no origin) is never replaced. Only the suggestion key is updated.
- `useSuggestion($media, 'caption')` overwrites with the stored suggestion and sets origin `ai`. Call it only after a person confirmed (the Filament actions do).
- `markEditedBy($media, 'caption', 'staff')` records that a person wrote the current value. Call it wherever people edit captions.

Helpers: `value()`, `suggestion()`, `origin()`, `hasHumanValue()`, `suggestionDiffers()`.

## Wiring a host app (THI example)

```php
// config/ai-captioning.php
'targets' => [[
    'model' => App\Models\SchoolDetail::class,
    'collections' => ['featured_image', 'view_gallery', 'thi_view_gallery'],
    'conversion' => 'web',
]],
'context_resolver' => App\Ai\SchoolImageContext::class,
```

```php
// App\Ai\SchoolImageContext
final class SchoolImageContext implements \Stolic\AiCaptioning\Contracts\ContextResolver
{
    public function resolve(Media $media): array
    {
        $school = $media->model;

        return [
            'school' => $school?->name,
            'city' => $school?->city,
            'state' => $school?->state,
            'account type' => $school?->account_type?->label(),
            'source' => $media->getCustomProperty('source'), // og image, homepage screenshot, school upload
        ];
    }
}
```

```php
// AppServiceProvider::boot()
use Stolic\AiCaptioning\Facades\AiCaptioning;

AiCaptioning::enabledUsing(fn (): bool => app(ImageCaptionSettings::class)->ai_captions_enabled);
AiCaptioning::dailyCapUsing(fn (): ?int => app(ImageCaptionSettings::class)->daily_describe_limit);

Event::listen(ImageDescribed::class, LogImageDescriptionApiCall::class);        // write to api_calls
Event::listen(ImageDescriptionFailed::class, LogImageDescriptionApiCall::class);
```

A closure works instead of a resolver class: `AiCaptioning::resolveContextUsing(fn (Media $media): array => [...])`.

Where people edit captions (the portal, `/thi`), call `app(ApplyDescription::class)->markEditedBy($media, 'caption', 'school')` after saving the new value.

To swap the model call entirely (another SDK, a test double), bind your own `Stolic\AiCaptioning\Contracts\ImageDescriber`.

### Describing now or in bulk

```php
AiCaptioning::queue($media);              // queue one item
AiCaptioning::describe($media, 'default'); // run now (guards and events apply)
```

```bash
php artisan ai-captioning:describe                                   # dry run: counts per target
php artisan ai-captioning:describe --collection=view_gallery --only-missing --limit=50 --apply
```

Options: `--model=` (class or morph alias of a configured target), `--collection=*`, `--profile=`, `--only-missing` (a profile field such as caption or alt is empty), `--limit=`, `--apply`.

## Filament

`Stolic\AiCaptioning\Filament` is only loaded when you use it. Registering the plugin is optional; it registers nothing global in 0.1:

```php
->plugin(\Stolic\AiCaptioning\Filament\AiCaptioningPlugin::make())
```

Drop the pieces into a table whose rows are `Media`, or point them at a row's media with `->media(fn (Model $record): ?Media => ...)`:

```php
use Stolic\AiCaptioning\Filament\Actions\{DescribeNowAction, UseAiSuggestionAction, UseAiSuggestionBulkAction};
use Stolic\AiCaptioning\Filament\Columns\AiDescriptionColumn;

$table
    ->columns([
        AiDescriptionColumn::make('caption'),   // current value, "AI: ..." below when the suggestion differs
        AiDescriptionColumn::make('alt'),
    ])
    ->recordActions([
        UseAiSuggestionAction::forField('caption'), // "Overwrite caption 'ABC School Image' with '...'?"
        UseAiSuggestionAction::forField('alt'),
        DescribeNowAction::make(),                  // queues the job
    ])
    ->toolbarActions([
        UseAiSuggestionBulkAction::forField('caption'), // "Use the AI caption for 5 selected images? 2 person-written captions will be replaced."
        UseAiSuggestionBulkAction::forField('alt'),
    ]);
```

The row action shows only when a suggestion exists and differs from the current value. The bulk action applies to the selected rows only.

There is no settings page: the host app owns its settings and wires them through `enabledUsing()` / `dailyCapUsing()`.

## stolic-homes notes

- Requires `laravel/ai` ^0.8; stolic-homes is on 0.4.5 and must be upgraded first (agent attributes and `LocalImage` usage carry over).
- `ClassifyRoom` becomes a profile:

```php
'room' => [
    'provider' => 'gemini',
    'instructions' => 'You classify a single photograph of a house into exactly one room type. Allowed values: kitchen, bedroom, ... If unclear, return unlabeled.',
    'schema' => [
        'room' => ['type' => 'string', 'enum' => RoomType::values()],
        'alt' => ['type' => 'string', 'max' => 125],
    ],
    'fields' => ['alt' => ['value' => 'alt', 'origin' => 'alt_origin', 'suggestion' => 'ai_alt']],
    'metadata' => ['room' => 'ai_room', 'described_at' => 'ai_described_at', 'model' => 'ai_model', 'profile' => 'ai_profile'],
],
```

- Read the room with `$description->get('room')` from `app(ImageDescriber::class)->describe($path, $mime, [], 'room')` in `DetectRoomFromImage`, or from the `ai_room` custom property after a queued run.

## Testing

```bash
composer install
vendor/bin/pest
vendor/bin/pint
```

In a host app, fake the model with laravel/ai's fake for the package agent:

```php
use Stolic\AiCaptioning\Agents\ImageDescriberAgent;

ImageDescriberAgent::fake([['caption' => 'A library', 'alt' => 'Shelves of books', 'kind' => 'photo', 'people_visible' => false, 'concerns' => []]]);
// ...
ImageDescriberAgent::assertPrompted(fn ($prompt) => str_contains($prompt->agent->instructions(), 'ABC School'));
```

Or bind a fake `ImageDescriber`.
