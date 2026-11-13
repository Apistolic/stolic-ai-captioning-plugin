<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Budget;

use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Stolic\AiCaptioning\Data\CostEstimate;

/**
 * The durable spend ledger: one row per day (application timezone), provider and model.
 */
final class SpendLedger
{
    public function add(CostEstimate $cost, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        $keys = [
            'date' => $at->toDateString(),
            'provider' => mb_substr($cost->provider, 0, 64),
            'model' => mb_substr($cost->model, 0, 128),
        ];

        $this->table()->insertOrIgnore([...$keys, 'created_at' => $at, 'updated_at' => $at]);

        $this->table()->where($keys)->incrementEach([
            'calls' => 1,
            'fallback_calls' => $cost->isFallback() ? 1 : 0,
            'input_tokens' => $cost->inputTokens,
            'output_tokens' => $cost->outputTokens,
            'estimated_usd' => $cost->usd,
        ], ['updated_at' => $at]);
    }

    /**
     * Estimated USD between two dates (inclusive), or all time when both are null.
     */
    public function spend(?CarbonInterface $from = null, ?CarbonInterface $to = null): float
    {
        return round((float) $this->between($from, $to)->sum('estimated_usd'), 6);
    }

    public function calls(?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        return (int) $this->between($from, $to)->sum('calls');
    }

    public function table(): Builder
    {
        return $this->connection()->table((string) config('ai-captioning.database.spend_table', 'ai_captioning_spend'));
    }

    private function between(?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $this->table()
            ->when($from !== null, fn (Builder $query) => $query->where('date', '>=', $from?->toDateString()))
            ->when($to !== null, fn (Builder $query) => $query->where('date', '<=', $to?->toDateString()));
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(config('ai-captioning.database.connection'));
    }
}
