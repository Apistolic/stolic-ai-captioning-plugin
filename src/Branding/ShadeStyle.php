<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Branding;

enum ShadeStyle: string
{
    /** A rounded, semi-opaque box behind the logo (and label). */
    case Badge = 'badge';

    /** A fade along the top or bottom edge (the side the logo is on), from transparent to the shade color. */
    case Gradient = 'gradient';

    /** No backdrop. */
    case None = 'none';
}
