<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Exceptions;

use InvalidArgumentException;

final class UnknownProfile extends InvalidArgumentException
{
    public static function named(string $profile): self
    {
        return new self("The AI media describer profile [{$profile}] is not configured in ai-captioning.profiles.");
    }
}
