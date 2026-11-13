<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning;

use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Stolic\AiCaptioning\Actions\ApplyDescription;
use Stolic\AiCaptioning\Branding\Contracts\ImageBrander;
use Stolic\AiCaptioning\Branding\ImagickImageBrander;
use Stolic\AiCaptioning\Budget\BudgetManager;
use Stolic\AiCaptioning\Budget\CostEstimator;
use Stolic\AiCaptioning\Budget\SpendLedger;
use Stolic\AiCaptioning\Commands\BudgetCommand;
use Stolic\AiCaptioning\Commands\DescribeMediaCommand;
use Stolic\AiCaptioning\Contracts\ImageDescriber;
use Stolic\AiCaptioning\Listeners\QueueDescriptionAfterConversion;
use Stolic\AiCaptioning\Listeners\QueueDescriptionAfterMediaAdded;

final class AiCaptioningServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ai-captioning')
            ->hasConfigFile()
            ->hasMigration('create_ai_captioning_budget_tables')
            ->runsMigrations((bool) config('ai-captioning.database.run_migrations', true))
            ->hasCommands([DescribeMediaCommand::class, BudgetCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AiCaptioning::class);
        $this->app->singleton(ApplyDescription::class);
        $this->app->singleton(BudgetManager::class);
        $this->app->singleton(SpendLedger::class);
        $this->app->singleton(CostEstimator::class);
        $this->app->bindIf(ImageDescriber::class, LaravelAiImageDescriber::class);
        $this->app->bindIf(ImageBrander::class, ImagickImageBrander::class);
    }

    public function packageBooted(): void
    {
        if (! config('ai-captioning.auto_describe', true)) {
            return;
        }

        Event::listen(ConversionHasBeenCompletedEvent::class, QueueDescriptionAfterConversion::class);
        Event::listen(MediaHasBeenAddedEvent::class, QueueDescriptionAfterMediaAdded::class);
    }
}
