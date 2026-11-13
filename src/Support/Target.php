<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Support;

use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One configured automatic target: a model class, its collections, the conversion to read and the profile.
 */
final readonly class Target
{
    /**
     * @param  list<string>  $collections  Empty means every collection.
     */
    public function __construct(
        public string $model,
        public array $collections,
        public ?string $conversion,
        public string $profile,
    ) {}

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        $defaultProfile = (string) config('ai-captioning.default_profile', 'default');

        return array_values(array_map(static fn (array $target): self => new self(
            model: (string) $target['model'],
            collections: array_values((array) ($target['collections'] ?? [])),
            conversion: blank($target['conversion'] ?? null) ? null : (string) $target['conversion'],
            profile: (string) ($target['profile'] ?? $defaultProfile),
        ), (array) config('ai-captioning.targets', [])));
    }

    public static function for(Media $media): ?self
    {
        foreach (self::all() as $target) {
            if ($target->matches($media)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * A branded copy (BrandMedia) is not described again unless ai-captioning.branding.describe_branded_copies is on.
     */
    public static function isUndescribedBrandedCopy(Media $media): bool
    {
        return $media->hasCustomProperty('branded_from') && ! config('ai-captioning.branding.describe_branded_copies', false);
    }

    public function matches(Media $media): bool
    {
        return $this->matchesModelType((string) $media->model_type)
            && ($this->collections === [] || in_array($media->collection_name, $this->collections, true));
    }

    /**
     * Compare a stored model_type (a class name or a morph-map alias) with this target's model.
     */
    public function matchesModelType(string $modelType): bool
    {
        $class = Relation::getMorphedModel($modelType) ?? $modelType;

        return $class === $this->model || $modelType === $this->model;
    }

    /**
     * The model_type values this target's media can be stored under (class name and morph alias).
     *
     * @return list<string>
     */
    public function modelTypes(): array
    {
        $alias = array_search($this->model, Relation::morphMap(), true);

        return array_values(array_unique(array_filter([$this->model, is_string($alias) ? $alias : null])));
    }
}
