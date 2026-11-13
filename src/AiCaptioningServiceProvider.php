<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning;

use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Stolic\AiCaptioning\Actions\ApplyDescription;
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
            ->hasCommand(DescribeMediaCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AiCaptioning::class);
        $this->app->singleton(ApplyDescription::class);
        $this->app->bindIf(ImageDescriber::class, LaravelAiImageDescriber::class);
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
