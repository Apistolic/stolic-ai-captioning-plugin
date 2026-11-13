<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Data;

use Carbon\CarbonImmutable;

/**
 * The structured answer for one image.
 */
final readonly class ImageDescription
{
    /**
     * @param  list<string>  $concerns
     * @param  array<string, mixed>  $fields  Every structured field returned by the profile (after normalisation).
     */
    public function __construct(
        public string $profile,
        public array $fields,
        public ?string $caption = null,
        public ?string $alt = null,
        public ?string $kind = null,
        public ?bool $peopleVisible = null,
        public array $concerns = [],
        public ?string $model = null,
        public ?string $provider = null,
        public CarbonImmutable $describedAt = new CarbonImmutable,
    ) {}

    /**
     * Build a description from the structured fields of a profile's answer.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function fromFields(
        string $profile,
        array $fields,
        ?string $model = null,
        ?string $provider = null,
        ?CarbonImmutable $describedAt = null,
    ): self {
        $concerns = $fields['concerns'] ?? [];

        return new self(
            profile: $profile,
            fields: $fields,
            caption: self::stringOrNull($fields['caption'] ?? null),
            alt: self::stringOrNull($fields['alt'] ?? null),
            kind: self::stringOrNull($fields['kind'] ?? null),
            peopleVisible: isset($fields['people_visible']) ? (bool) $fields['people_visible'] : null,
            concerns: is_array($concerns) ? array_values(array_filter(array_map(
                static fn (mixed $concern): string => trim((string) $concern),
                $concerns,
            ), static fn (string $concern): bool => $concern !== '')) : [],
            model: $model,
            provider: $provider,
            describedAt: $describedAt ?? CarbonImmutable::now(),
        );
    }

    /**
     * A returned field by name (including profile-specific ones such as 'room').
     */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->fields[$field] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->fields,
            'profile' => $this->profile,
            'model' => $this->model,
            'provider' => $this->provider,
            'described_at' => $this->describedAt->toIso8601String(),
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
