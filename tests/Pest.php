<?php

declare(strict_types=1);

use Stolic\AiCaptioning\Tests\Filament\FilamentTestCase;
use Stolic\AiCaptioning\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(FilamentTestCase::class)->in('Filament');
