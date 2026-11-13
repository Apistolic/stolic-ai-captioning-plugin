<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Stolic\AiCaptioning\AiCaptioning enabledUsing(\Closure $resolver)
 * @method static \Stolic\AiCaptioning\AiCaptioning dailyCapUsing(\Closure $resolver)
 * @method static \Stolic\AiCaptioning\AiCaptioning resolveContextUsing(\Closure $resolver)
 * @method static \Stolic\AiCaptioning\AiCaptioning budgetUsing(\Closure $resolver)
 * @method static void pause(string $reason = '')
 * @method static void resume()
 * @method static bool isPaused()
 * @method static float spend(?string $period = null)
 * @method static float|null budgetLimit()
 * @method static \Stolic\AiCaptioning\Data\BudgetStatus budgetStatus()
 * @method static \Stolic\AiCaptioning\Budget\BudgetManager budget()
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
