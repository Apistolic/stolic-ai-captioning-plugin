<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Actions\ApplyDescription;
use Stolic\AiCaptioning\Budget\BudgetManager;
use Stolic\AiCaptioning\Contracts\ContextResolver;
use Stolic\AiCaptioning\Contracts\ImageDescriber;
use Stolic\AiCaptioning\Data\BudgetStatus;
use Stolic\AiCaptioning\Data\ImageDescription;
use Stolic\AiCaptioning\Enums\SkipReason;
use Stolic\AiCaptioning\Events\ImageDescribed;
use Stolic\AiCaptioning\Events\ImageDescriptionFailed;
use Stolic\AiCaptioning\Events\ImageDescriptionSkipped;
use Stolic\AiCaptioning\Jobs\DescribeMediaJob;
use Stolic\AiCaptioning\Support\LocalImageFile;
use Stolic\AiCaptioning\Support\Profile;
use Stolic\AiCaptioning\Support\Target;
use Throwable;

/**
 * Entry point: guards (enabled, spend budget, daily cap), context, describing and applying a media item.
 *
 * Host apps register their hooks in a service provider's boot():
 *   AiCaptioning::enabledUsing(fn (): bool => app(ImageCaptionSettings::class)->ai_captions_enabled);
 *   AiCaptioning::dailyCapUsing(fn (): ?int => app(ImageCaptionSettings::class)->daily_limit);
 *   AiCaptioning::budgetUsing(fn (): ?float => app(ImageCaptionSettings::class)->monthly_budget_usd);
 *   AiCaptioning::resolveContextUsing(fn (Media $media): array => [...]);
 */
final class AiCaptioning
{
    private ?Closure $enabledResolver = null;

    private ?Closure $dailyCapResolver = null;

    private ?Closure $contextResolver = null;

    public function __construct(private readonly Container $container) {}

    /**
     * Add a host check (e.g. a settings toggle); it applies on top of config('ai-captioning.enabled').
     *
     * @param  Closure(): bool  $resolver
     */
    public function enabledUsing(Closure $resolver): self
    {
        $this->enabledResolver = $resolver;

        return $this;
    }

    /**
     * Override the daily cap (null = no cap).
     *
     * @param  Closure(): ?int  $resolver
     */
    public function dailyCapUsing(Closure $resolver): self
    {
        $this->dailyCapResolver = $resolver;

        return $this;
    }

    /**
     * Override the spend limit in USD for the budget period (null = no limit), e.g. from host settings.
     *
     * @param  Closure(): (float|int|string|null)  $resolver
     */
    public function budgetUsing(Closure $resolver): self
    {
        $this->budget()->limitUsing($resolver);

        return $this;
    }

    /**
     * Pause AI captioning until resume() (describe calls are skipped with SkipReason::Paused).
     */
    public function pause(string $reason = ''): void
    {
        $this->budget()->pause($reason);
    }

    /**
     * Lift a manual or budget pause.
     */
    public function resume(): void
    {
        $this->budget()->resume();
    }

    public function isPaused(): bool
    {
        return $this->budget()->isPaused();
    }

    /**
     * Estimated USD spend for null (the configured budget period), 'month', 'total', 'today' or 'YYYY-MM'.
     */
    public function spend(?string $period = null): float
    {
        return $this->budget()->spend($period);
    }

    public function budgetLimit(): ?float
    {
        return $this->budget()->limit();
    }

    public function budgetStatus(): BudgetStatus
    {
        return $this->budget()->status();
    }

    public function budget(): BudgetManager
    {
        return $this->container->make(BudgetManager::class);
    }

    /**
     * Supply context for a media item with a closure instead of a ContextResolver class.
     *
     * @param  Closure(Media): array<string, string|null>  $resolver
     */
    public function resolveContextUsing(Closure $resolver): self
    {
        $this->contextResolver = $resolver;

        return $this;
    }

    public function isEnabled(): bool
    {
        if (! config('ai-captioning.enabled', true)) {
            return false;
        }

        return $this->enabledResolver === null || (bool) ($this->enabledResolver)();
    }

    public function dailyCap(): ?int
    {
        $cap = $this->dailyCapResolver !== null ? ($this->dailyCapResolver)() : config('ai-captioning.daily_cap');

        return $cap === null || $cap === '' ? null : max(0, (int) $cap);
    }

    /**
     * The number of describe calls made today.
     */
    public function callsToday(): int
    {
        return (int) $this->cache()->get($this->dailyCounterKey(), 0);
    }

    /**
     * @return array<string, string>
     */
    public function contextFor(Media $media): array
    {
        $context = [];

        if ($this->contextResolver !== null) {
            $context = ($this->contextResolver)($media);
        } elseif (is_string($class = config('ai-captioning.context_resolver')) && $class !== '') {
            $resolver = $this->container->make($class);

            if ($resolver instanceof ContextResolver) {
                $context = $resolver->resolve($media);
            }
        }

        return array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), (array) $context),
            static fn (string $value): bool => $value !== '',
        );
    }

    /**
     * Queue a describe job for the media item.
     */
    public function queue(Media $media, ?string $profile = null): void
    {
        DescribeMediaJob::dispatch((int) $media->getKey(), $profile);
    }

    /**
     * Describe the media item now and apply the result (the job calls this).
     *
     * @return ImageDescription|null Null when skipped.
     *
     * @throws Throwable After dispatching ImageDescriptionFailed.
     */
    public function describe(Media $media, ?string $profile = null): ?ImageDescription
    {
        $target = Target::for($media);
        $profile ??= $target?->profile ?? (string) config('ai-captioning.default_profile', 'default');

        if (! $this->isEnabled()) {
            return $this->skip($media, SkipReason::Disabled, $profile);
        }

        if (! str_starts_with((string) $media->mime_type, 'image/')) {
            return $this->skip($media, SkipReason::NotAnImage, $profile);
        }

        if (($budgetReason = $this->budget()->check()) !== null) {
            return $this->skip($media, $budgetReason, $profile);
        }

        if (! $this->reserveCall()) {
            return $this->skip($media, SkipReason::DailyCapReached, $profile);
        }

        $file = null;

        try {
            $file = LocalImageFile::for($media, $target?->conversion);

            try {
                $description = $this->container->make(ImageDescriber::class)->describe(
                    $file->path,
                    $file->mimeType,
                    $this->contextFor($media),
                    $profile,
                );
            } catch (Throwable $exception) {
                $this->budget()->recordFailedCall(...$this->providerAndModelOf($profile));

                throw $exception;
            }

            $cost = $this->budget()->recordCall($description->provider, $description->model, $description->usage);

            $outcomes = $this->container->make(ApplyDescription::class)->apply($media, $description);
        } catch (Throwable $exception) {
            ImageDescriptionFailed::dispatch($media, $exception, $profile);

            throw $exception;
        } finally {
            $file?->cleanup();
        }

        ImageDescribed::dispatch($media, $description, $outcomes, $cost);

        return $description;
    }

    /**
     * Count one call against today's cap; false when the cap is reached.
     */
    private function reserveCall(): bool
    {
        $cap = $this->dailyCap();
        $key = $this->dailyCounterKey();
        $cache = $this->cache();

        $cache->add($key, 0, now()->endOfDay());

        if ($cap === null) {
            $cache->increment($key);

            return true;
        }

        if ((int) $cache->get($key, 0) >= $cap) {
            return false;
        }

        if ((int) $cache->increment($key) > $cap) {
            $cache->decrement($key);

            return false;
        }

        return true;
    }

    /**
     * The provider and model a profile would use, for ledger rows of failed calls.
     *
     * @return array{0: string, 1: string}
     */
    private function providerAndModelOf(string $profile): array
    {
        try {
            $resolved = Profile::named($profile);
        } catch (Throwable) {
            return ['unknown', 'unknown'];
        }

        $provider = $resolved->provider;

        if (is_array($provider)) {
            $first = array_key_first($provider);
            $provider = is_string($first) ? $first : ($provider[$first] ?? null);
        }

        $provider = match (true) {
            $provider instanceof Lab => (string) $provider->value,
            is_string($provider) && $provider !== '' => $provider,
            default => (string) config('ai.default', 'unknown'),
        };

        return [$provider, $resolved->model ?? 'default'];
    }

    private function skip(Media $media, SkipReason $reason, string $profile): null
    {
        ImageDescriptionSkipped::dispatch($media, $reason, $profile);

        return null;
    }

    private function dailyCounterKey(): string
    {
        return 'ai-captioning:calls:'.now()->toDateString();
    }

    private function cache(): CacheRepository
    {
        return Cache::store(config('ai-captioning.cache_store'));
    }
}
