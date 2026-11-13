<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Tests\Fixtures\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Filament\Actions\BrandImageAction;
use Stolic\AiCaptioning\Filament\Actions\DescribeNowAction;
use Stolic\AiCaptioning\Filament\Actions\UseAiSuggestionAction;
use Stolic\AiCaptioning\Filament\Actions\UseAiSuggestionBulkAction;
use Stolic\AiCaptioning\Filament\Columns\AiDescriptionColumn;

/**
 * A host-style media table using the package's column and actions.
 */
final class MediaTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(Media::query())
            ->columns([
                AiDescriptionColumn::make('caption'),
                AiDescriptionColumn::make('alt'),
            ])
            ->recordActions([
                UseAiSuggestionAction::forField('caption'),
                UseAiSuggestionAction::forField('alt'),
                DescribeNowAction::make(),
                BrandImageAction::make()
                    ->logo(fn (Media $media): string => brandingLogoPngPath())
                    ->targetCollection(fn (Media $media): string => 'branded'),
            ])
            ->toolbarActions([
                UseAiSuggestionBulkAction::forField('caption'),
            ]);
    }

    public function render(): string
    {
        return '<div>{{ $this->table }}</div>';
    }
}
