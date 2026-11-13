<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Enums;

enum SkipReason: string
{
    case Disabled = 'disabled';
    case DailyCapReached = 'daily_cap_reached';
    case NotAnImage = 'not_an_image';

    /** The period's spend reached the budget limit (captioning is paused until the next period or a higher limit). */
    case BudgetExceeded = 'budget_exceeded';

    /** Paused by a person (AiCaptioning::pause(), ai-captioning:budget --pause). */
    case Paused = 'paused';
}
