<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Tests\Fixtures\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use Stolic\AiCaptioning\Filament\Schemas\AiCaptionSpendSection;

/**
 * A host-style settings page using the package's spend section.
 */
final class BudgetSettings extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            AiCaptionSpendSection::make(),
        ]);
    }

    public function render(): string
    {
        return '<div>{{ $this->content }}</div>';
    }
}
