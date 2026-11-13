<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Infolists;

use Filament\Infolists\Components\TextEntry;
use Stolic\AiCaptioning\AiCaptioning;

/**
 * A badge: "Running", "Paused (budget reached)" or "Paused", with the pause reason as a tooltip.
 */
class AiCaptioningStateEntry extends TextEntry
{
    public static function make(?string $name = 'ai_captioning_state'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('AI captions');

        $this->badge();

        $this->state(fn (): string => app(AiCaptioning::class)->budgetStatus()->stateLabel());

        $this->color(fn (): string => match (app(AiCaptioning::class)->budgetStatus()->pauseKind) {
            null => 'success',
            'budget' => 'danger',
            default => 'warning',
        });

        $this->tooltip(fn (): ?string => app(AiCaptioning::class)->budgetStatus()->pauseReason);
    }
}
