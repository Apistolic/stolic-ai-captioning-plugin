# Changelog

All notable changes to `apistolic/stolic-ai-captioning-plugin` are documented here. The package follows semantic versioning.

## 0.2.0 (unreleased)

### Spend budget with automatic pause

- The token usage laravel/ai reports is passed on: `ImageDescription::$usage` (`Laravel\Ai\Responses\Data\Usage`), and `ImageDescribed::$cost` (`CostEstimate`).
- `Budget\CostEstimator`: USD per call from usage and `pricing` (per model: input / output price per 1M tokens, optional per-image price; placeholder values to verify), with a flat `budget.fallback_cost_usd` when the model has no price or no usage was reported, and for failed calls.
- Durable ledger `ai_captioning_spend` (per day, provider and model) and pause state `ai_captioning_state`; migration auto-loaded and publishable (`ai-captioning-migrations`).
- `budget.limit_usd` (default 10.00), `budget.period` (`month` or `total`), `budget.warn_at` (0.8); host override `AiCaptioning::budgetUsing()`.
- Before a call: skipped when paused or over the limit (`SkipReason::BudgetExceeded`, `SkipReason::Paused`). After a call: `BudgetWarning` once per period; at the limit a persisted pause and `BudgetExceeded` once per period. A budget pause lifts in a new month or when the limit is raised; a manual pause stays.
- `AiCaptioning::pause()`, `resume()`, `isPaused()`, `spend()`, `budgetLimit()`, `budgetStatus()`, `budget()`.
- `ai-captioning:budget` command (`--pause`, `--reason`, `--resume`).
- Optional `Notifications\AiCaptioningBudgetNotification` (mail) for hosts to route; no mail is sent by the package.
- Filament: `AiCaptionSpendWidget`, `AiCaptionSpendSection`, `AiCaptionSpendEntry`, `AiCaptioningStateEntry`, `PauseAiCaptioningAction`, `ResumeAiCaptioningAction`.

## 0.1.0 (2026-11-13)

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
