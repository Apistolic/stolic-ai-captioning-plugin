<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Infolists;

use Filament\Infolists\Components\TextEntry;
use Stolic\AiCaptioning\AiCaptioning;

/**
 * "AI caption spend: $3.21 of $10.00 (2026-11)", red when the limit is reached, orange past the warning level.
 *
 * Works in infolists and in form schemas (e.g. a settings page): AiCaptionSpendEntry::make().
 */
class AiCaptionSpendEntry extends TextEntry
{
    public static function make(?string $name = 'ai_caption_spend'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('AI caption spend');

        $this->state(function (): string {
            $status = app(AiCaptioning::class)->budgetStatus();

            return $status->spendLabel().($status->period === 'total' ? ' (all time)' : " ({$status->periodKey})");
        });

        $this->color(function (): ?string {
            $status = app(AiCaptioning::class)->budgetStatus();
            $fraction = $status->usedFraction();

            return match (true) {
                $fraction !== null && $fraction >= 1 => 'danger',
                $status->isOverWarning() => 'warning',
                default => null,
            };
        });
    }
}
