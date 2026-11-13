<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Enums;

enum SkipReason: string
{
    case Disabled = 'disabled';
    case DailyCapReached = 'daily_cap_reached';
    case NotAnImage = 'not_an_image';
}
