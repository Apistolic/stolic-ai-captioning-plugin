<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Commands;

use Illuminate\Console\Command;
use Stolic\AiCaptioning\Budget\BudgetManager;
use Stolic\AiCaptioning\Data\BudgetStatus;

/**
 * Shows the AI captioning spend and pause state; --pause / --resume change it.
 */
final class BudgetCommand extends Command
{
    protected $signature = 'ai-captioning:budget
        {--pause : Pause AI captioning until resumed}
        {--reason= : Why (stored with --pause)}
        {--resume : Lift a manual or budget pause}';

    protected $description = 'Show the AI captioning spend, budget limit and pause state';

    public function handle(BudgetManager $budget): int
    {
        if ($this->option('pause') && $this->option('resume')) {
            $this->components->error('Pass either --pause or --resume, not both.');

            return self::FAILURE;
        }

        if ($this->option('pause')) {
            $budget->pause((string) ($this->option('reason') ?: 'Paused from the command line'));
            $this->components->info('AI captioning paused.');
        }

        if ($this->option('resume')) {
            $budget->resume();
            $this->components->info('AI captioning resumed.');
        }

        $status = $budget->status();

        if ($this->option('resume') && $status->limitUsd !== null && $status->periodSpendUsd >= $status->limitUsd) {
            $this->components->warn('The spend is at or above the limit: the next call pauses again. Raise the limit to continue.');
        }

        $this->components->twoColumnDetail('Period', $status->period === 'total' ? 'total (all time)' : "month ({$status->periodKey})");
        $this->components->twoColumnDetail('Spend this period', BudgetStatus::money($status->periodSpendUsd)." ({$status->periodCalls} calls)");
        $this->components->twoColumnDetail('Spend total', BudgetStatus::money($status->totalSpendUsd));
        $this->components->twoColumnDetail('Limit', $status->limitUsd === null ? 'none' : BudgetStatus::money($status->limitUsd));

        if ($status->limitUsd !== null) {
            $this->components->twoColumnDetail('Warn at', round($status->warnAt * 100).'% ('.BudgetStatus::money($status->limitUsd * $status->warnAt).')');
        }

        $this->components->twoColumnDetail('State', $status->paused
            ? "<fg=red>{$status->stateLabel()}</> since {$status->pausedAt?->toDateTimeString()}"
            : '<fg=green>Running</>');

        if ($status->paused && $status->pauseReason !== null) {
            $this->components->twoColumnDetail('Reason', $status->pauseReason);
        }

        return self::SUCCESS;
    }
}
