<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class Post extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery');
        $this->addMediaCollection('featured')->singleFile();
        $this->addMediaCollection('documents');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('web')
            ->fit(Fit::Max, 200, 200)
            ->format('webp')
            ->nonQueued();

        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 50, 50)
            ->nonQueued();
    }
}
