<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Budget;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Ai\Responses\Data\Usage;
use Stolic\AiCaptioning\Data\BudgetStatus;
use Stolic\AiCaptioning\Data\CostEstimate;
use Stolic\AiCaptioning\Enums\SkipReason;
use Stolic\AiCaptioning\Events\BudgetExceeded;
use Stolic\AiCaptioning\Events\BudgetWarning;

/**
 * The spend budget: the ledger, the limit, warnings and the persisted pause.
 *
 * Rules:
 *  - before a call, check() refuses when paused, or when the period's spend has reached the limit (which pauses);
 *  - after a call, record*() adds the estimate to the ledger, dispatches BudgetWarning once per period when the
 *    spend crosses warn_at x limit, and pauses and dispatches BudgetExceeded once per period at the limit;
 *  - a budget pause holds only while the spend is at or above the limit: it lifts by itself in a new month
 *    (period 'month') or when the limit is raised. A manual pause stays until resume().
 */
final class BudgetManager
{
    public const string PAUSE_BUDGET = 'budget';

    public const string PAUSE_MANUAL = 'manual';

    private const int STATE_ID = 1;

    private ?Closure $limitResolver = null;

    public function __construct(
        private readonly SpendLedger $ledger,
        private readonly CostEstimator $estimator,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Override the limit (e.g. from host settings); null means no limit.
     *
     * @param  Closure(): (float|int|string|null)  $resolver
     */
    public function limitUsing(?Closure $resolver): self
    {
        $this->limitResolver = $resolver;

        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool) config('ai-captioning.budget.enabled', true);
    }

    public function limit(): ?float
    {
        $limit = $this->limitResolver !== null ? ($this->limitResolver)() : config('ai-captioning.budget.limit_usd');

        return $limit === null || $limit === '' ? null : max(0.0, (float) $limit);
    }

    /**
     * 'month' or 'total'.
     */
    public function period(): string
    {
        return config('ai-captioning.budget.period') === 'total' ? 'total' : 'month';
    }

    public function periodKey(?CarbonInterface $at = null): string
    {
        return $this->period() === 'total' ? 'total' : ($at ?? now())->format('Y-m');
    }

    public function warnAt(): float
    {
        return min(1.0, max(0.0, (float) config('ai-captioning.budget.warn_at', 0.8)));
    }

    /**
     * Estimated USD spend.
     *
     * @param  string|null  $period  null (the configured period), 'month', 'total', 'today' or a month such as '2026-11'.
     */
    public function spend(?string $period = null): float
    {
        [$from, $to] = $this->rangeFor($period ?? $this->period());

        return $this->ledger->spend($from, $to);
    }

    public function calls(?string $period = null): int
    {
        [$from, $to] = $this->rangeFor($period ?? $this->period());

        return $this->ledger->calls($from, $to);
    }

    public function isPaused(): bool
    {
        return $this->currentState()->paused_at !== null;
    }

    /**
     * 'budget', 'manual' or null when running.
     */
    public function pauseKind(): ?string
    {
        $state = $this->currentState();

        return $state->paused_at === null ? null : $state->pause_kind;
    }

    /**
     * Pause AI captioning until resume() (a manual pause; a new period does not lift it).
     */
    public function pause(string $reason = '', string $kind = self::PAUSE_MANUAL): void
    {
        $this->ensureState();
        $this->state()->where('id', self::STATE_ID)->update([
            'paused_at' => now(),
            'pause_kind' => $kind,
            'pause_reason' => trim($reason) === '' ? null : trim($reason),
            'paused_period' => $this->periodKey(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Lift any pause. While the spend is still at or above the limit, the next call pauses again
     * (without a second BudgetExceeded in the same period): raise the limit to continue.
     */
    public function resume(): void
    {
        $this->ensureState();
        $this->state()->where('id', self::STATE_ID)->update([
            'paused_at' => null,
            'pause_kind' => null,
            'pause_reason' => null,
            'paused_period' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Before a call: null when allowed, else why not (a reached limit pauses).
     */
    public function check(): ?SkipReason
    {
        $state = $this->currentState();

        if ($state->paused_at !== null) {
            return $state->pause_kind === self::PAUSE_BUDGET ? SkipReason::BudgetExceeded : SkipReason::Paused;
        }

        $limit = $this->limit();

        if ($this->isEnabled() && $limit !== null && ($spend = $this->spend()) >= $limit) {
            $this->pauseForBudget($spend, $limit);

            return SkipReason::BudgetExceeded;
        }

        return null;
    }

    /**
     * After a call: estimate its cost from the reported usage, add it to the ledger and apply the limit.
     */
    public function recordCall(?string $provider, ?string $model, ?Usage $usage): CostEstimate
    {
        return $this->record($this->estimator->estimate($provider, $model, $usage));
    }

    /**
     * After a failed call: count the flat fallback estimate, since the provider may have charged for it.
     */
    public function recordFailedCall(?string $provider, ?string $model): CostEstimate
    {
        return $this->record($this->estimator->fallback($provider, $model));
    }

    public function record(CostEstimate $cost): CostEstimate
    {
        if (! $this->isEnabled()) {
            return $cost;
        }

        $this->ledger->add($cost);
        $this->applyLimit();

        return $cost;
    }

    public function status(): BudgetStatus
    {
        $state = $this->currentState();
        $paused = $state->paused_at !== null;

        return new BudgetStatus(
            period: $this->period(),
            periodKey: $this->periodKey(),
            periodSpendUsd: $this->spend(),
            totalSpendUsd: $this->spend('total'),
            periodCalls: $this->calls(),
            limitUsd: $this->isEnabled() ? $this->limit() : null,
            warnAt: $this->warnAt(),
            paused: $paused,
            pauseKind: $paused ? $state->pause_kind : null,
            pauseReason: $paused ? $state->pause_reason : null,
            pausedAt: $paused ? CarbonImmutable::parse($state->paused_at) : null,
        );
    }

    private function applyLimit(): void
    {
        $limit = $this->limit();

        if ($limit === null) {
            return;
        }

        $spend = $this->spend();

        if ($spend >= $limit) {
            $this->pauseForBudget($spend, $limit);
        } elseif ($spend >= $limit * $this->warnAt() && $this->markOnce('warned_period')) {
            $this->events->dispatch(new BudgetWarning($spend, $limit, $this->period(), $this->periodKey(), $this->warnAt()));
        }
    }

    private function pauseForBudget(float $spend, float $limit): void
    {
        $periodKey = $this->periodKey();

        $this->ensureState();
        $this->state()->where('id', self::STATE_ID)->whereNull('paused_at')->update([
            'paused_at' => now(),
            'pause_kind' => self::PAUSE_BUDGET,
            'pause_reason' => 'Estimated spend '.BudgetStatus::money($spend).' reached the limit '.BudgetStatus::money($limit)." ({$periodKey}).",
            'paused_period' => $periodKey,
            'updated_at' => now(),
        ]);

        $this->markOnce('warned_period');

        if ($this->markOnce('exceeded_period')) {
            $this->events->dispatch(new BudgetExceeded($spend, $limit, $this->period(), $periodKey));
        }
    }

    /**
     * The state row, after lifting a budget pause that no longer applies (new month, raised limit, budget off).
     */
    private function currentState(): object
    {
        $state = $this->stateRow();

        if ($state->paused_at === null || $state->pause_kind !== self::PAUSE_BUDGET) {
            return $state;
        }

        $limit = $this->limit();

        if ($this->isEnabled() && $limit !== null && $this->spend() >= $limit) {
            return $state;
        }

        $spend = $this->spend();
        $stillWarned = $limit !== null && $spend >= $limit * $this->warnAt();

        $this->state()->where('id', self::STATE_ID)->where('pause_kind', self::PAUSE_BUDGET)->update([
            'paused_at' => null,
            'pause_kind' => null,
            'pause_reason' => null,
            'paused_period' => null,
            'exceeded_period' => null,
            ...($stillWarned ? [] : ['warned_period' => null]),
            'updated_at' => now(),
        ]);

        return $this->stateRow();
    }

    /**
     * Set the column to the current period key; true only for the first caller in this period.
     */
    private function markOnce(string $column): bool
    {
        $periodKey = $this->periodKey();

        $this->ensureState();

        return $this->state()
            ->where('id', self::STATE_ID)
            ->where(fn (Builder $query) => $query->whereNull($column)->orWhere($column, '!=', $periodKey))
            ->update([$column => $periodKey, 'updated_at' => now()]) > 0;
    }

    /**
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    private function rangeFor(string $period): array
    {
        return match (true) {
            $period === 'total' => [null, null],
            $period === 'month' => [now()->startOfMonth(), now()->endOfMonth()],
            $period === 'today' => [now()->startOfDay(), now()->endOfDay()],
            preg_match('/^\d{4}-\d{2}$/', $period) === 1 => [
                ($month = CarbonImmutable::createFromFormat('!Y-m', $period, now()->getTimezone()))->startOfMonth(),
                $month->endOfMonth(),
            ],
            default => throw new InvalidArgumentException("Unknown budget period [{$period}]. Use 'month', 'total', 'today' or 'YYYY-MM'."),
        };
    }

    private function stateRow(): object
    {
        $this->ensureState();

        return $this->state()->where('id', self::STATE_ID)->first();
    }

    private function ensureState(): void
    {
        $this->state()->insertOrIgnore(['id' => self::STATE_ID, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function state(): Builder
    {
        return DB::connection(config('ai-captioning.database.connection'))
            ->table((string) config('ai-captioning.database.state_table', 'ai_captioning_state'));
    }
}
