<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stolic\AiCaptioning\Branding\BrandingOptions;
use Stolic\AiCaptioning\Branding\BrandMedia;
use Stolic\AiCaptioning\Filament\Concerns\ResolvesMedia;
use Stolic\AiCaptioning\Jobs\BrandMediaJob;
use Throwable;

/**
 * Row action "Brand image": adds a copy of the row's image with the logo to a collection (the original is kept).
 *
 *   BrandImageAction::make()
 *       ->logo(fn (Media $media): ?Media => $media->model->getFirstMedia('school_logo'))  // or a local path
 *       ->targetCollection('branded_gallery')                                             // or a closure
 *       ->brandingOptions(['shade' => 'gradient'])                                        // optional
 *       ->queued()                                                                        // optional
 *
 * With ->logoOptions(fn (Media $media): array => [path => label]) the modal offers a logo to pick instead.
 */
class BrandImageAction extends Action
{
    use ResolvesMedia;

    protected Closure|string|Media|null $logoResolver = null;

    protected Closure|array|null $logoOptionsResolver = null;

    protected Closure|string|null $targetCollectionResolver = null;

    /** @var Closure|BrandingOptions|array<string, mixed>|null */
    protected Closure|BrandingOptions|array|null $brandingOptionsResolver = null;

    protected bool $shouldQueue = false;

    public static function getDefaultName(): ?string
    {
        return 'brandImage';
    }

    /**
     * @param  Closure(Media, mixed): (string|Media|null)|string|Media  $logo  Injected: $media, $record.
     */
    public function logo(Closure|string|Media $logo): static
    {
        $this->logoResolver = $logo;

        return $this;
    }

    /**
     * @param  Closure(Media, mixed): array<string, string>|array<string, string>  $options  Local logo path => label.
     */
    public function logoOptions(Closure|array $options): static
    {
        $this->logoOptionsResolver = $options;

        return $this;
    }

    /**
     * @param  Closure(Media, mixed): string|string  $collection  Injected: $media, $record.
     */
    public function targetCollection(Closure|string $collection): static
    {
        $this->targetCollectionResolver = $collection;

        return $this;
    }

    /**
     * @param  Closure(Media, mixed): (BrandingOptions|array<string, mixed>)|BrandingOptions|array<string, mixed>  $options
     */
    public function brandingOptions(Closure|BrandingOptions|array $options): static
    {
        $this->brandingOptionsResolver = $options;

        return $this;
    }

    public function queued(bool $condition = true): static
    {
        $this->shouldQueue = $condition;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Brand image');

        $this->icon('heroicon-o-paint-brush');

        $this->requiresConfirmation();

        $this->modalHeading('Brand image');

        $this->modalDescription(function (mixed $record): string {
            $media = $this->resolveMedia($record);
            $collection = $media === null ? null : $this->resolveTargetCollection($media, $record);

            return 'Adds a copy of this image with the logo'.($collection !== null ? " to \"{$collection}\"" : '').'. The original is not changed.';
        });

        $this->schema(function (mixed $record): array {
            $media = $this->resolveMedia($record);
            $options = $media === null || $this->logoOptionsResolver === null
                ? []
                : (array) $this->evaluate($this->logoOptionsResolver, ['media' => $media, 'record' => $record], [Media::class => $media]);

            return $options === [] ? [] : [
                Select::make('logo')->label('Logo')->options($options)->required(),
            ];
        });

        $this->visible(function (mixed $record): bool {
            $media = $this->resolveMedia($record);

            return $media !== null && str_starts_with((string) $media->mime_type, 'image/');
        });

        $this->action(function (mixed $record, array $data, BrandMedia $brandMedia): void {
            $media = $this->resolveMedia($record);

            if ($media === null) {
                return;
            }

            $logo = is_string($data['logo'] ?? null) && $data['logo'] !== ''
                ? $data['logo']
                : $this->evaluate($this->logoResolver, ['media' => $media, 'record' => $record], [Media::class => $media]);
            $collection = $this->resolveTargetCollection($media, $record);

            if ((! is_string($logo) || $logo === '') && ! $logo instanceof Media) {
                Notification::make()->title('No logo to brand with')->danger()->send();
                $this->failure();

                return;
            }

            if ($collection === null) {
                Notification::make()->title('No collection for the branded copy')->danger()->send();
                $this->failure();

                return;
            }

            $options = $this->resolveBrandingOptions($media, $record);

            if ($this->shouldQueue) {
                BrandMediaJob::dispatch((int) $media->getKey(), $logo instanceof Media ? (int) $logo->getKey() : $logo, $collection, $options->toArray());
                Notification::make()->title('Branding queued')->body("The branded copy will appear in \"{$collection}\".")->success()->send();
                $this->success();

                return;
            }

            try {
                $brandMedia->handle($media, $logo, $collection, $options);
            } catch (Throwable $exception) {
                report($exception);
                Notification::make()->title('Branding failed')->body($exception->getMessage())->danger()->send();
                $this->failure();

                return;
            }

            Notification::make()->title('Branded copy added')->body("Added to \"{$collection}\".")->success()->send();
            $this->success();
        });
    }

    protected function resolveTargetCollection(Media $media, mixed $record): ?string
    {
        $collection = $this->evaluate($this->targetCollectionResolver, ['media' => $media, 'record' => $record], [Media::class => $media]);

        return is_string($collection) && $collection !== '' ? $collection : null;
    }

    protected function resolveBrandingOptions(Media $media, mixed $record): BrandingOptions
    {
        $options = $this->evaluate($this->brandingOptionsResolver, ['media' => $media, 'record' => $record], [Media::class => $media]);

        return match (true) {
            $options instanceof BrandingOptions => $options,
            is_array($options) => BrandingOptions::fromConfig($options),
            default => BrandingOptions::fromConfig(),
        };
    }
}
