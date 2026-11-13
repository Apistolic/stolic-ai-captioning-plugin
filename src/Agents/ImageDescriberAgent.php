<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * A vision agent whose instructions, schema, provider and model come from a profile.
 *
 * Fake it in tests with ImageDescriberAgent::fake([...]).
 */
final class ImageDescriberAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<string, array<string, mixed>>  $fields  Profile schema field definitions.
     * @param  Lab|array<int|string, mixed>|string|null  $providerName
     */
    public function __construct(
        private readonly string $instructions,
        private readonly array $fields,
        private readonly Lab|array|string|null $providerName = null,
        private readonly ?string $modelName = null,
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $types = [];

        foreach ($this->fields as $name => $definition) {
            $types[$name] = $this->typeFor($schema, $name, $definition);
        }

        return $types;
    }

    /**
     * Read by laravel/ai's Promptable to choose the provider (an array lists failover providers); null uses config('ai.default').
     */
    public function provider(): Lab|array|string|null
    {
        return $this->providerName;
    }

    /**
     * Read by laravel/ai's Promptable to choose the model; null uses the provider's default text model.
     */
    public function model(): ?string
    {
        return $this->modelName;
    }

    public function timeout(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function typeFor(JsonSchema $schema, string $name, array $definition): Type
    {
        $type = match ($definition['type'] ?? 'string') {
            'string' => $schema->string(),
            'boolean' => $schema->boolean(),
            'integer' => $schema->integer(),
            'number' => $schema->number(),
            'array' => $schema->array()->items($this->itemType($schema, $definition['items'] ?? 'string')),
            default => throw new InvalidArgumentException("Unsupported schema type [{$definition['type']}] for field [{$name}]."),
        };

        if (isset($definition['description'])) {
            $type->description((string) $definition['description']);
        }

        if (isset($definition['enum'])) {
            $type->enum($definition['enum']);
        }

        if (($definition['required'] ?? true) === true) {
            $type->required();
        }

        return $type;
    }

    private function itemType(JsonSchema $schema, string $itemType): Type
    {
        return match ($itemType) {
            'boolean' => $schema->boolean(),
            'integer' => $schema->integer(),
            'number' => $schema->number(),
            default => $schema->string(),
        };
    }
}
