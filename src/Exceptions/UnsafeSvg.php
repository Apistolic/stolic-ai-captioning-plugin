<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Exceptions;

use RuntimeException;

final class UnsafeSvg extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("Refusing to use this SVG: {$reason}.");
    }
}
