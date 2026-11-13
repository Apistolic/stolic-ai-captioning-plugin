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
composer require apistolic/stolic-ai-captioning-plugin:^0.2
php artisan vendor:publish --tag=ai-captioning-config
php artisan migrate   # the spend ledger and pause state (the package loads its migration itself)
```

To keep the migration in the app instead: `php artisan vendor:publish --tag=ai-captioning-migrations` (it skips tables that already exist, so the auto-loaded copy and the published one do not clash; set `database.run_migrations` to false to stop auto-loading).

Until a version is tagged, require `dev-main`.

Set the key of the provider you use in `config/ai.php` (e.g. `GEMINI_API_KEY`).

## How it works

1. A media item is added to a configured model and collection.
2. When the configured conversion (e.g. `web`) has been generated (or straight away when no conversion is configured), a unique queued `DescribeMediaJob` is dispatched for it.
3. The job checks the guards (enabled, pause and spend budget, daily cap), copies the image locally if it lives on a remote disk (S3 / R2), asks the model with the profile's instructions plus host context, and applies the answer with the overwrite rule.
4. The call's estimated cost is added to the spend ledger; at the warning level `BudgetWarning` is dispatched, at the limit captioning pauses and `BudgetExceeded` is dispatched.
5. `ImageDescribed` (with token usage and cost), `ImageDescriptionSkipped` or `ImageDescriptionFailed` is dispatched for the host app to log.

## Configuration

`config/ai-captioning.php`:

| Key | Meaning |
|---|---|
| `enabled` | Master switch (`AI_CAPTIONING_ENABLED`) |
| `daily_cap` | Max describe calls per day, `null` for none (`AI_CAPTIONING_DAILY_CAP`, default 200) |
| `cache_store` | Cache store for the daily counter (default store when null) |
| `budget.enabled`, `budget.limit_usd`, `budget.period`, `budget.warn_at`, `budget.fallback_cost_usd` | Spend budget (below); default $10.00 per calendar month, warn at 80%, $0.002 per call when the cost cannot be computed |
| `pricing` | Model => `input_per_million`, `output_per_million`, `per_image` in USD. **Placeholder values: verify them** |
| `database.connection`, `database.run_migrations`, `database.spend_table`, `database.state_table` | Where the ledger and pause state live |
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

## Spend budget

Every describe call is estimated in USD and stored in a durable ledger (`ai_captioning_spend`: one row per day, provider and model with calls, input/output tokens and `estimated_usd`). The pause state is one row in `ai_captioning_state`.

**Cost of one call** (`Budget\CostEstimator`), from the token usage laravel/ai reports on the response (`$response->usage`, passed on as `ImageDescription::$usage`):

```
input  = prompt tokens + cached input tokens (counted at the full input price)
output = completion tokens + reasoning ("thinking") tokens
usd    = input / 1M x input_per_million + output / 1M x output_per_million + per_image
```

When the model has no `pricing` entry, or the provider reported no usage (zero tokens, as fakes do), the flat `budget.fallback_cost_usd` is counted instead. A call that throws is counted at the fallback too, since the provider may have charged for it. The estimate never counts a call as free.

> **Set the prices.** The shipped `pricing` values are placeholders. Check Google's Gemini pricing page and your invoices, then set the entries for the models you use (`gemini-3.5-flash` is laravel/ai's default Gemini model). Keys may be `model` or `provider/model`.

**Rules:**

- Before a call: when paused, or the period's spend is at or above the limit, the call is skipped (`SkipReason::BudgetExceeded`, or `SkipReason::Paused` for a manual pause) and the model is not asked. Reaching the limit pauses.
- After a call: the estimate is added to the ledger. When the period's spend crosses `warn_at` x limit, `BudgetWarning` is dispatched once per period. When it reaches the limit, captioning is paused (persisted) and `BudgetExceeded` is dispatched once per period.
- A **budget pause** holds only while the spend is at or above the limit: with `period = month` it lifts by itself in the next month; it also lifts when the limit is raised. A **manual pause** stays until it is resumed. Resuming while still over the limit pauses again on the next call (without a second email).
- `budget.limit_usd = null` means no limit (spend is still recorded); `budget.enabled = false` turns off the ledger and the limit (a manual pause still applies).

**Controls:**

```php
use Stolic\AiCaptioning\Facades\AiCaptioning;

AiCaptioning::budgetUsing(fn (): ?float => app(ImageCaptionSettings::class)->ai_budget_usd); // host override of the limit
AiCaptioning::pause('Checking captions by hand');
AiCaptioning::resume();
AiCaptioning::isPaused();
AiCaptioning::spend();          // the configured period; also 'month', 'total', 'today', '2026-11'
AiCaptioning::budgetStatus();   // BudgetStatus: spend, limit, period, paused, reason, ...
```

```bash
php artisan ai-captioning:budget                    # spend this period and in total, limit, warning level, state
php artisan ai-captioning:budget --pause --reason="Reviewing captions"
php artisan ai-captioning:budget --resume
```

**Email is the host's job.** The package dispatches `BudgetWarning` (`spendUsd`, `limitUsd`, `period`, `periodKey`, `warnAt`) and `BudgetExceeded` (`spendUsd`, `limitUsd`, `period`, `periodKey`) and never sends mail itself. A ready mail notification, `Notifications\AiCaptioningBudgetNotification`, can be routed by the host (see the THI example below), or write your own mailable.

Other code that calls the `ImageDescriber` directly (not through `AiCaptioning::describe()`) bypasses the budget; check `AiCaptioning::budget()->check()` before and call `AiCaptioning::budget()->recordCall($description->provider, $description->model, $description->usage)` after.

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
AiCaptioning::budgetUsing(fn (): ?float => app(ImageCaptionSettings::class)->ai_budget_usd);

Event::listen(ImageDescribed::class, LogImageDescriptionApiCall::class);        // write to api_calls ($event->cost->usd, $event->description->usage)
Event::listen(ImageDescriptionFailed::class, LogImageDescriptionApiCall::class);
```

Email the report recipients when the budget runs low or captioning pauses, through the app's mailer:

```php
use Illuminate\Support\Facades\Notification;
use Stolic\AiCaptioning\Events\{BudgetExceeded, BudgetWarning};
use Stolic\AiCaptioning\Notifications\AiCaptioningBudgetNotification;

Event::listen([BudgetWarning::class, BudgetExceeded::class], function (BudgetWarning|BudgetExceeded $event): void {
    Notification::route('mail', app(ReportSettings::class)->recipients) // THI's report recipients
        ->notify(AiCaptioningBudgetNotification::fromEvent($event));
});
```

(Or `Mail::to($recipients)->send(new AiBudgetExceededMail($event->spendUsd, $event->limitUsd, $event->periodKey))` with your own mailable. Queue the listener if mail is slow; the events are dispatched inside the describe job.)

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

There is no settings page: the host app owns its settings and wires them through `enabledUsing()` / `dailyCapUsing()` / `budgetUsing()`.

### Spend and pause

```php
use Stolic\AiCaptioning\Filament\Schemas\AiCaptionSpendSection;
use Stolic\AiCaptioning\Filament\Widgets\AiCaptionSpendWidget;

// In a settings page's form schema: "AI caption spend: $3.21 of $10.00 (2026-11)", a Running / Paused badge,
// and Pause / Resume buttons in the section header.
AiCaptionSpendSection::make(),

// Or the parts: AiCaptionSpendEntry::make(), AiCaptioningStateEntry::make() (Filament\Infolists),
// PauseAiCaptioningAction::make(), ResumeAiCaptioningAction::make() (Filament\Actions).

// A dashboard widget (spend / limit, state):
$panel->widgets([AiCaptionSpendWidget::class]);
```

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
