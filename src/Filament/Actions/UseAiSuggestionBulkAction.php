<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Actions;

use Filament\Actions\BulkAction;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Actions\ApplyDescription;
use Stolic\AiCaptioning\Filament\Concerns\ResolvesMedia;

/**
 * Bulk "Use AI caption" / "Use AI alt text" for the selected rows only, after one confirmation that counts
 * how many person-written values it would replace.
 */
class UseAiSuggestionBulkAction extends BulkAction
{
    use ResolvesMedia;

    public static function forField(string $field): static
    {
        return static::make('useAi'.Str::studly($field).'Bulk')->field($field);
    }

    public static function getDefaultName(): ?string
    {
        return 'useAiCaptionBulk';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => 'Use AI '.static::fieldLabel($this->getField()));

        $this->icon('heroicon-o-sparkles');

        $this->requiresConfirmation();

        $this->modalHeading(fn (): string => 'Use AI '.static::fieldLabel($this->getField()));

        $this->modalDescription(fn (iterable $records): string => static::confirmationText($this->mediaFrom($records), $this->getField()));

        $this->deselectRecordsAfterCompletion();

        $this->action(function (iterable $records): void {
            $policy = app(ApplyDescription::class);
            $applied = 0;

            foreach ($this->mediaFrom($records) as $media) {
                if ($policy->suggestionDiffers($media, $this->getField()) && $policy->useSuggestion($media, $this->getField())) {
                    $applied++;
                }
            }

            $this->successNotificationTitle("AI {$this->fieldLabel($this->getField())} applied to {$applied} ".Str::plural('image', $applied));
            $this->success();
        });
    }

    /**
     * "Use the AI caption for 5 selected images? 2 person-written captions will be replaced."
     *
     * @param  iterable<Media>  $media
     */
    public static function confirmationText(iterable $media, string $field): string
    {
        $policy = app(ApplyDescription::class);
        $label = static::fieldLabel($field);
        $selected = 0;
        $withSuggestion = 0;
        $humanReplaced = 0;

        foreach ($media as $item) {
            $selected++;

            if (! $policy->suggestionDiffers($item, $field)) {
                continue;
            }

            $withSuggestion++;

            if ($policy->hasHumanValue($item, $field)) {
                $humanReplaced++;
            }
        }

        $text = "Use the AI {$label} for {$selected} selected ".Str::plural('image', $selected).'?';

        if ($withSuggestion < $selected) {
            $skipped = $selected - $withSuggestion;
            $text .= " {$skipped} ".($skipped === 1 ? 'has' : 'have').' no different suggestion and will be left as is.';
        }

        if ($humanReplaced === 0) {
            return $text.' No person-written '.Str::plural($label, 2).' will be replaced.';
        }

        return $text." {$humanReplaced} person-written ".Str::plural($label, $humanReplaced).' will be replaced.';
    }

    /**
     * @param  iterable<mixed>  $records
     * @return list<Media>
     */
    protected function mediaFrom(iterable $records): array
    {
        $media = [];

        foreach ($records as $record) {
            if (($item = $this->resolveMedia($record)) !== null) {
                $media[] = $item;
            }
        }

        return $media;
    }
}
