<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Support;

use Laravel\Ai\Enums\Lab;
use Stolic\AiCaptioning\Exceptions\UnknownProfile;

/**
 * One configured describe profile.
 */
final readonly class Profile
{
    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, array{value: string, origin: string, suggestion: string}>  $fields
     * @param  array<string, string>  $metadata
     * @param  Lab|array<int|string, mixed>|string|null  $provider
     */
    public function __construct(
        public string $name,
        public string $instructions,
        public string $prompt,
        public array $schema,
        public array $fields,
        public array $metadata,
        public Lab|array|string|null $provider = null,
        public ?string $model = null,
        public int $timeout = 60,
    ) {}

    public static function named(string $name): self
    {
        $config = config("ai-captioning.profiles.{$name}");

        if (! is_array($config)) {
            throw UnknownProfile::named($name);
        }

        $model = $config['model'] ?? null;

        return new self(
            name: $name,
            instructions: (string) ($config['instructions'] ?? ''),
            prompt: (string) ($config['prompt'] ?? 'Describe this image.'),
            schema: (array) ($config['schema'] ?? []),
            fields: (array) ($config['fields'] ?? config('ai-captioning.properties.fields', [])),
            metadata: (array) ($config['metadata'] ?? config('ai-captioning.properties.metadata', [])),
            provider: blank($config['provider'] ?? null) ? null : $config['provider'],
            model: blank($model) ? null : (string) $model,
            timeout: (int) ($config['timeout'] ?? 60),
        );
    }

    /**
     * The instructions with {context} replaced by a bullet list and {key} by single context values.
     *
     * @param  array<string, string|null>  $context
     */
    public function instructionsFor(array $context): string
    {
        $context = array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $context),
            static fn (string $value): bool => $value !== '',
        );

        $lines = [];

        foreach ($context as $label => $value) {
            $lines[] = is_int($label) ? "- {$value}" : '- '.str_replace('_', ' ', $label).": {$value}";
        }

        $replacements = ['{context}' => $lines === [] ? '- (none given)' : implode("\n", $lines)];

        foreach ($context as $label => $value) {
            if (is_string($label)) {
                $replacements['{'.$label.'}'] = $value;
            }
        }

        return strtr($this->instructions, $replacements);
    }
}
