<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The period's estimated spend crossed warn_at x limit. Dispatched once per period.
 *
 * The package sends no mail: listen for this event in the host app (see the README).
 */
final class BudgetWarning
{
    use Dispatchable;

    /**
     * @param  string  $period  'month' or 'total'.
     * @param  string  $periodKey  e.g. '2026-11', or 'total'.
     */
    public function __construct(
        public readonly float $spendUsd,
        public readonly float $limitUsd,
        public readonly string $period,
        public readonly string $periodKey,
        public readonly float $warnAt,
    ) {}
}
