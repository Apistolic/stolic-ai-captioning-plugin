<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Data;

use Carbon\CarbonImmutable;

/**
 * A snapshot of the spend budget (for the command, Filament and host dashboards).
 */
final readonly class BudgetStatus
{
    public function __construct(
        public string $period,
        public string $periodKey,
        public float $periodSpendUsd,
        public float $totalSpendUsd,
        public int $periodCalls,
        public ?float $limitUsd,
        public float $warnAt,
        public bool $paused,
        public ?string $pauseKind = null,
        public ?string $pauseReason = null,
        public ?CarbonImmutable $pausedAt = null,
    ) {}

    /**
     * The share of the limit spent this period (0..n), null without a limit.
     */
    public function usedFraction(): ?float
    {
        if ($this->limitUsd === null) {
            return null;
        }

        return $this->limitUsd > 0 ? $this->periodSpendUsd / $this->limitUsd : 1.0;
    }

    public function isOverWarning(): bool
    {
        $fraction = $this->usedFraction();

        return $fraction !== null && $fraction >= $this->warnAt;
    }

    /**
     * "$3.21 of $10.00" or "$3.21 (no limit)".
     */
    public function spendLabel(): string
    {
        $spend = self::money($this->periodSpendUsd);

        return $this->limitUsd === null ? "{$spend} (no limit)" : $spend.' of '.self::money($this->limitUsd);
    }

    /**
     * "Running", "Paused (budget reached)" or "Paused".
     */
    public function stateLabel(): string
    {
        return match (true) {
            ! $this->paused => 'Running',
            $this->pauseKind === 'budget' => 'Paused (budget reached)',
            default => 'Paused',
        };
    }

    public static function money(float $usd): string
    {
        return '$'.number_format($usd, abs($usd) < 1 && $usd != 0.0 ? 4 : 2);
    }
}
