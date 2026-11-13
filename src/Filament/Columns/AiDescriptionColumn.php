<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Columns;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Str;
use Stolic\AiCaptioning\Actions\ApplyDescription;
use Stolic\AiCaptioning\Filament\Concerns\ResolvesMedia;

/**
 * Shows the current caption / alt text, with the AI suggestion below when it differs.
 *
 *   AiDescriptionColumn::make('caption')
 *   AiDescriptionColumn::make('alt_text')->field('alt')->media(fn (SchoolDetail $record) => $record->getFirstMedia('featured_image'))
 */
class AiDescriptionColumn extends TextColumn
{
    use ResolvesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        if (config("ai-captioning.properties.fields.{$this->getName()}") !== null) {
            $this->describedField = $this->getName();
        }

        $this->label(fn (): string => Str::ucfirst(static::fieldLabel($this->getField())));

        $this->state(function (mixed $record): ?string {
            $media = $this->resolveMedia($record);

            return $media === null ? null : app(ApplyDescription::class)->value($media, $this->getField());
        });

        $this->placeholder('—');

        $this->wrap();

        $this->description(function (mixed $record): ?string {
            $media = $this->resolveMedia($record);
            $policy = app(ApplyDescription::class);

            if ($media === null || ! $policy->suggestionDiffers($media, $this->getField())) {
                return null;
            }

            return 'AI: '.$policy->suggestion($media, $this->getField());
        });
    }
}
