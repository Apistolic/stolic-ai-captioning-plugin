<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Branding;

enum BrandingPosition: string
{
    case TopLeft = 'top-left';
    case TopRight = 'top-right';
    case BottomLeft = 'bottom-left';
    case BottomRight = 'bottom-right';

    public function isTop(): bool
    {
        return $this === self::TopLeft || $this === self::TopRight;
    }

    public function isLeft(): bool
    {
        return $this === self::TopLeft || $this === self::BottomLeft;
    }
}
