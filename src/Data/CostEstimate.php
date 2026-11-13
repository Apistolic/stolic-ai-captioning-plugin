<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Data;

/**
 * The estimated cost of one describe call.
 */
final readonly class CostEstimate
{
    public const string SOURCE_USAGE = 'usage';

    public const string SOURCE_FALLBACK = 'fallback';

    public function __construct(
        public float $usd,
        public string $provider,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public string $source = self::SOURCE_FALLBACK,
    ) {}

    /**
     * True when the flat fallback estimate was used (no price for the model, or no usage reported).
     */
    public function isFallback(): bool
    {
        return $this->source === self::SOURCE_FALLBACK;
    }

    /**
     * @return array{usd: float, provider: string, model: string, input_tokens: int, output_tokens: int, source: string}
     */
    public function toArray(): array
    {
        return [
            'usd' => $this->usd,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'source' => $this->source,
        ];
    }
}
