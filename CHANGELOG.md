# Changelog

All notable changes to `apistolic/stolic-ai-captioning-plugin` are documented here. The package follows semantic versioning.

## 0.1.0 (unreleased)

First version.

- `ImageDescriber` contract with a default `laravel/ai` (^0.8) implementation (`LaravelAiImageDescriber`, `ImageDescriberAgent`): profile-driven instructions with `{context}` placeholders, structured output schema, provider (or failover list), model and timeout.
- `default` profile returning `caption`, `alt`, `kind`, `people_visible` and `concerns`; host apps can add profiles (e.g. room classification).
- `ImageDescription` DTO.
- `ApplyDescription`: the overwrite rule in one place (fill empty, refresh `ai`-origin values, never replace a person's value; keep the suggestion), plus `useSuggestion()` and `markEditedBy()`.
- Spatie Media Library integration: configured targets (model, collections, conversion, profile), listeners on `ConversionHasBeenCompletedEvent` / `MediaHasBeenAddedEvent`, unique queued `DescribeMediaJob`; media on remote disks is downloaded to a temporary file.
- Guards: `enabled` config plus `AiCaptioning::enabledUsing()`; a daily call cap (cache counter) with `AiCaptioning::dailyCapUsing()`.
- Events: `ImageDescribed`, `ImageDescriptionSkipped` (with `SkipReason`), `ImageDescriptionFailed`.
- `ai-captioning:describe` backfill command (dry run by default; `--apply`, `--model`, `--collection`, `--profile`, `--only-missing`, `--limit`).
- Filament 5 pieces (optional): `AiCaptioningPlugin`, `AiDescriptionColumn`, `UseAiSuggestionAction`, `UseAiSuggestionBulkAction`, `DescribeNowAction`.
