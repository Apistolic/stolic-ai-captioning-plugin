<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Actions;

use Filament\Actions\Action;
use Stolic\AiCaptioning\AiCaptioning;
use Stolic\AiCaptioning\Data\BudgetStatus;

/**
 * "Resume AI captions": lifts a manual or budget pause. While the spend is still at or above the limit,
 * the next call pauses again, so the confirmation says to raise the limit first.
 */
class ResumeAiCaptioningAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resumeAiCaptioning';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resume AI captions');

        $this->icon('heroicon-o-play-circle');

        $this->color('success');

        $this->requiresConfirmation();

        $this->modalDescription(function (): string {
            $status = app(AiCaptioning::class)->budgetStatus();

            if ($status->limitUsd !== null && $status->periodSpendUsd >= $status->limitUsd) {
                return 'The spend ('.BudgetStatus::money($status->periodSpendUsd).') is at or above the limit ('
                    .BudgetStatus::money($status->limitUsd).'), so AI captions pause again on the next image. Raise the limit first.';
            }

            return 'New images are described again.';
        });

        $this->visible(fn (): bool => app(AiCaptioning::class)->isPaused());

        $this->successNotificationTitle('AI captions resumed');

        $this->action(function (AiCaptioning $captioning): void {
            $captioning->resume();
            $this->success();
        });
    }
}
