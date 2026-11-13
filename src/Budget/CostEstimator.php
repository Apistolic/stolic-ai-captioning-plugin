<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Budget;

use Laravel\Ai\Responses\Data\Usage;
use Stolic\AiCaptioning\Data\CostEstimate;

/**
 * Estimates the USD cost of one describe call from the reported token usage and config('ai-captioning.pricing').
 *
 * cost = (prompt + cached input tokens) / 1M x input price
 *      + (completion + reasoning tokens) / 1M x output price
 *      + per-image price
 *
 * Without a price for the model, or without usage (all zeros, as fakes and some providers report),
 * the flat config('ai-captioning.budget.fallback_cost_usd') is used, so a call is never counted as free.
 */
final class CostEstimator
{
    public function estimate(?string $provider, ?string $model, ?Usage $usage, int $images = 1): CostEstimate
    {
        $provider = (string) $provider;
        $model = (string) $model;
        $inputTokens = $usage === null ? 0 : $usage->promptTokens + $usage->cacheReadInputTokens + $usage->cacheWriteInputTokens;
        $outputTokens = $usage === null ? 0 : $usage->completionTokens + $usage->reasoningTokens;
        $price = $this->priceFor($provider, $model);

        if ($price === null || $inputTokens + $outputTokens === 0) {
            return $this->fallback($provider, $model, $inputTokens, $outputTokens);
        }

        $usd = $inputTokens / 1_000_000 * $price['input_per_million']
            + $outputTokens / 1_000_000 * $price['output_per_million']
            + $images * $price['per_image'];

        return new CostEstimate(
            usd: round($usd, 6),
            provider: $provider,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            source: CostEstimate::SOURCE_USAGE,
        );
    }

    /**
     * The flat per-call estimate.
     */
    public function fallback(?string $provider, ?string $model, int $inputTokens = 0, int $outputTokens = 0): CostEstimate
    {
        return new CostEstimate(
            usd: round(max(0.0, (float) config('ai-captioning.budget.fallback_cost_usd', 0.002)), 6),
            provider: (string) $provider,
            model: (string) $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            source: CostEstimate::SOURCE_FALLBACK,
        );
    }

    /**
     * @return array{input_per_million: float, output_per_million: float, per_image: float}|null
     */
    public function priceFor(string $provider, string $model): ?array
    {
        $pricing = (array) config('ai-captioning.pricing', []);
        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;

        foreach (array_filter([$provider !== '' ? "{$provider}/{$model}" : null, $model]) as $key) {
            $price = $pricing[$key] ?? null;

            if (! is_array($price) || ! is_numeric($price['input_per_million'] ?? null) || ! is_numeric($price['output_per_million'] ?? null)) {
                continue;
            }

            return [
                'input_per_million' => (float) $price['input_per_million'],
                'output_per_million' => (float) $price['output_per_million'],
                'per_image' => is_numeric($price['per_image'] ?? null) ? (float) $price['per_image'] : 0.0,
            ];
        }

        return null;
    }
}
