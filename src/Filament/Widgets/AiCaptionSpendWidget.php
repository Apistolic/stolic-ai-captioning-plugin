<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Data\BudgetStatus;

/**
 * Dashboard widget: spend / limit for the budget period, and whether AI captions are running or paused.
 * Register it in a panel (->widgets([AiCaptionSpendWidget::class])) or a page's header widgets.
 */
class AiCaptionSpendWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'AI captions';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $status = app(AiCaptioning::class)->budgetStatus();
        $fraction = $status->usedFraction();

        return [
            Stat::make('Spend '.($status->period === 'total' ? '(all time)' : "({$status->periodKey})"), $status->spendLabel())
                ->description($fraction === null ? "{$status->periodCalls} calls" : round($fraction * 100)."% of the budget, {$status->periodCalls} calls")
                ->color(match (true) {
                    $fraction !== null && $fraction >= 1 => 'danger',
                    $status->isOverWarning() => 'warning',
                    default => 'success',
                }),
            Stat::make('State', $status->stateLabel())
                ->description($status->pauseReason ?? 'Estimated total '.BudgetStatus::money($status->totalSpendUsd))
                ->color($status->paused ? 'danger' : 'success'),
        ];
    }
}
