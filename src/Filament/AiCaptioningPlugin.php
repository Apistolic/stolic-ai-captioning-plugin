<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registers nothing global; host resources and tables use the column and actions in this namespace.
 */
final class AiCaptioningPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'ai-captioning';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
