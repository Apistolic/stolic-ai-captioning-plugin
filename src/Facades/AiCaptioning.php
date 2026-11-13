<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Stolic\AiCaptioning\AiCaptioning enabledUsing(\Closure $resolver)
 * @method static \Stolic\AiCaptioning\AiCaptioning dailyCapUsing(\Closure $resolver)
 * @method static \Stolic\AiCaptioning\AiCaptioning resolveContextUsing(\Closure $resolver)
 * @method static bool isEnabled()
 * @method static int|null dailyCap()
 * @method static int callsToday()
 * @method static array<string, string> contextFor(\Spatie\MediaLibrary\MediaCollections\Models\Media $media)
 * @method static void queue(\Spatie\MediaLibrary\MediaCollections\Models\Media $media, ?string $profile = null)
 * @method static \Stolic\AiCaptioning\Data\ImageDescription|null describe(\Spatie\MediaLibrary\MediaCollections\Models\Media $media, ?string $profile = null)
 *
 * @see \Stolic\AiCaptioning\AiCaptioning
 */
final class AiCaptioning extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Stolic\AiCaptioning\AiCaptioning::class;
    }
}
