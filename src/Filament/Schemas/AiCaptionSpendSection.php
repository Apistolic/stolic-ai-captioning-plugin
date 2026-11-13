<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Schemas;

use Filament\Schemas\Components\Section;
use Stolic\AiCaptioning\Filament\Actions\PauseAiCaptioningAction;
use Stolic\AiCaptioning\Filament\Actions\ResumeAiCaptioningAction;
use Stolic\AiCaptioning\Filament\Infolists\AiCaptioningStateEntry;
use Stolic\AiCaptioning\Filament\Infolists\AiCaptionSpendEntry;

/**
 * A ready section for a host settings page: spend / limit, the paused badge, and Pause / Resume.
 *
 *   ->components([AiCaptionSpendSection::make(), ...])
 */
final class AiCaptionSpendSection
{
    public static function make(string $heading = 'AI caption spend'): Section
    {
        return Section::make($heading)
            ->key('aiCaptionSpend')
            ->schema([
                AiCaptionSpendEntry::make(),
                AiCaptioningStateEntry::make(),
            ])
            ->columns(2)
            ->afterHeader([
                PauseAiCaptioningAction::make(),
                ResumeAiCaptioningAction::make(),
            ]);
    }
}
