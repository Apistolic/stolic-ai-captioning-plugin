<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Stolic\AiCaptioning\AiCaptioning;

/**
 * "Pause AI captions": a manual pause (kept across budget periods) until resumed.
 */
class PauseAiCaptioningAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'pauseAiCaptioning';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Pause AI captions');

        $this->icon('heroicon-o-pause-circle');

        $this->color('warning');

        $this->requiresConfirmation();

        $this->modalDescription('New images are not described until AI captions are resumed.');

        $this->schema([
            TextInput::make('reason')->label('Reason')->maxLength(255),
        ]);

        $this->visible(fn (): bool => ! app(AiCaptioning::class)->isPaused());

        $this->successNotificationTitle('AI captions paused');

        $this->action(function (array $data, AiCaptioning $captioning): void {
            $captioning->pause((string) ($data['reason'] ?? ''));
            $this->success();
        });
    }
}
