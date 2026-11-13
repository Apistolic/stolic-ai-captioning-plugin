<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Actions;

use Filament\Actions\Action;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Actions\ApplyDescription;
use Stolic\AiCaptioning\Filament\Concerns\ResolvesMedia;

/**
 * Row action "Use AI caption" / "Use AI alt text": overwrites the value with the suggestion after confirmation.
 *
 *   UseAiSuggestionAction::forField('caption')
 *   UseAiSuggestionAction::forField('alt')->media(fn (SchoolDetail $record) => $record->getFirstMedia('featured_image'))
 */
class UseAiSuggestionAction extends Action
{
    use ResolvesMedia;

    public static function forField(string $field): static
    {
        return static::make('useAi'.Str::studly($field))->field($field);
    }

    public static function getDefaultName(): ?string
    {
        return 'useAiCaption';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => 'Use AI '.static::fieldLabel($this->getField()));

        $this->icon('heroicon-o-sparkles');

        $this->requiresConfirmation();

        $this->modalHeading(fn (): string => 'Use AI '.static::fieldLabel($this->getField()));

        $this->modalDescription(function (mixed $record): string {
            $media = $this->resolveMedia($record);

            return $media === null ? '' : static::confirmationText($media, $this->getField());
        });

        $this->visible(function (mixed $record): bool {
            $media = $this->resolveMedia($record);

            return $media !== null && app(ApplyDescription::class)->suggestionDiffers($media, $this->getField());
        });

        $this->successNotificationTitle(fn (): string => 'AI '.static::fieldLabel($this->getField()).' applied');

        $this->action(function (mixed $record): void {
            $media = $this->resolveMedia($record);

            if ($media !== null && app(ApplyDescription::class)->useSuggestion($media, $this->getField())) {
                $this->success();

                return;
            }

            $this->failure();
        });
    }

    /**
     * "Overwrite caption 'ABC School Image' with 'Students reading in a library'?" (or "Set caption to '…'?" when empty).
     */
    public static function confirmationText(Media $media, string $field): string
    {
        $policy = app(ApplyDescription::class);
        $label = static::fieldLabel($field);
        $current = $policy->value($media, $field);
        $suggestion = (string) $policy->suggestion($media, $field);

        if ($current === null) {
            return "Set {$label} to '{$suggestion}'?";
        }

        return "Overwrite {$label} '{$current}' with '{$suggestion}'?";
    }
}
